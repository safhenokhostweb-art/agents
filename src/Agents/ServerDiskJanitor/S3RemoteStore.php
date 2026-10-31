<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use RuntimeException;

/**
 * S3 API store (AWS, MinIO, ArvanCloud, … — any SigV4 endpoint), single
 * PUT of up to 5 GiB, streamed from disk.
 *
 * Integrity is enforced by the destination, not assumed: the signed
 * x-amz-content-sha256 and Content-MD5 make the server reject a body that
 * differs from what was hashed locally, x-amz-checksum-sha256 (when
 * enabled) makes it store a SHA-256 it computed itself, and stat() reads
 * that checksum and the ETag back for the handshake.
 */
final class S3RemoteStore implements RemoteStore
{
    public const SERVICE = 's3';

    private readonly string $scheme;

    private readonly string $host;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $prefix = '',
        private readonly bool $pathStyle = true,
        private readonly bool $checksumHeader = true,
        private readonly int $timeout = 3600,
    ) {
        $parts = parse_url(rtrim($endpoint, '/'));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('invalid S3 endpoint');
        }
        $this->scheme = strtolower($parts['scheme']);
        $this->host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public static function fromSettings(ArchiveSettings $settings): self
    {
        return new self(
            (string) $settings->get('remote.endpoint'),
            (string) $settings->get('remote.region'),
            (string) $settings->get('remote.bucket'),
            (string) $settings->get('remote.access_key'),
            (string) $settings->get('remote.secret_key'),
            (string) $settings->get('remote.prefix', ''),
            (bool) $settings->get('remote.path_style', true),
            (bool) $settings->get('remote.checksum_header', true),
            (int) $settings->get('remote.timeout_seconds', 3600),
        );
    }

    public function describe(): array
    {
        return ['driver' => 's3', 'endpoint' => $this->endpoint, 'region' => $this->region, 'bucket' => $this->bucket, 'prefix' => $this->prefix, 'path_style' => $this->pathStyle];
    }

    public function put(string $localPath, string $key, array $digests): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('the PHP curl extension is required for the s3 driver');
        }
        $headers = [
            'content-length' => (string) $digests['size'],
            'content-md5' => base64_encode((string) hex2bin($digests['md5'])),
            'x-amz-content-sha256' => $digests['sha256'],
        ];
        if ($this->checksumHeader) {
            $headers['x-amz-checksum-sha256'] = base64_encode((string) hex2bin($digests['sha256']));
        }
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot open {$localPath}");
        }
        try {
            $response = $this->request('PUT', $key, $headers, $digests['sha256'], [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => $digests['size'],
            ]);
        } finally {
            fclose($handle);
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException("upload refused with HTTP {$response['status']}".self::errorDetail($response['body']));
        }

        return ['http_status' => $response['status'], 'etag' => trim($response['headers']['etag'] ?? '', '"') ?: null];
    }

    public function stat(string $key): ?array
    {
        $headers = $this->checksumHeader ? ['x-amz-checksum-mode' => 'ENABLED'] : [];
        $response = $this->request('HEAD', $key, $headers, hash('sha256', ''), [CURLOPT_NOBODY => true]);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException("remote stat failed with HTTP {$response['status']}");
        }
        $h = $response['headers'];
        $etag = strtolower(trim($h['etag'] ?? '', '"'));
        $checksum = isset($h['x-amz-checksum-sha256']) ? base64_decode($h['x-amz-checksum-sha256'], true) : false;

        return [
            'size' => isset($h['content-length']) ? (int) $h['content-length'] : null,
            'sha256' => $checksum !== false && strlen($checksum) === 32 ? bin2hex($checksum) : null,
            // A single-part, unencrypted-at-rest PUT has the body's MD5 as ETag; multipart ETags carry a '-'.
            'md5' => preg_match('/^[0-9a-f]{32}$/', $etag) === 1 ? $etag : null,
            'source' => 'head',
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function request(string $method, string $key, array $headers, string $payloadHash, array $curlOptions): array
    {
        $object = $this->prefix.$key;
        $path = '/'.implode('/', array_map('rawurlencode', explode('/', $object)));
        $host = $this->host;
        if ($this->pathStyle) {
            $path = '/'.rawurlencode($this->bucket).$path;
        } else {
            $host = $this->bucket.'.'.$this->host;
        }
        $amzDate = gmdate('Ymd\THis\Z');
        $headers = array_change_key_case($headers) + ['host' => $host, 'x-amz-date' => $amzDate, 'x-amz-content-sha256' => $payloadHash];
        $headers['authorization'] = self::authorization($method, $path, '', $headers, $payloadHash, $this->accessKey, $this->secretKey, $this->region, $amzDate);

        $received = [];
        $curl = curl_init($this->scheme.'://'.$host.$path);
        $lines = [];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $lines[] = "{$name}: {$value}";
            }
        }
        $lines[] = 'Expect:';
        curl_setopt_array($curl, $curlOptions + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            throw new RuntimeException("S3 {$method} failed: {$error}");
        }

        return ['status' => $status, 'headers' => $received, 'body' => (string) $body];
    }

    /**
     * AWS Signature Version 4 Authorization header value.
     *
     * @param  array<string, string>  $headers  lowercase names; all are signed
     */
    public static function authorization(string $method, string $path, string $query, array $headers, string $payloadHash, string $accessKey, string $secretKey, string $region, string $amzDate): string
    {
        $headers = array_change_key_case($headers);
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.preg_replace('/\s+/', ' ', trim($value))."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, $query, $canonicalHeaders, $signedHeaders, $payloadHash]);
        $date = substr($amzDate, 0, 8);
        $scope = "{$date}/{$region}/".self::SERVICE.'/aws4_request';
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', self::SERVICE, hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secretKey, true), true), true), true);

        return "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope},SignedHeaders={$signedHeaders},Signature=".hash_hmac('sha256', $toSign, $key);
    }

    private static function errorDetail(string $body): string
    {
        $code = preg_match('#<Code>([^<]{1,100})</Code>#', $body, $c) === 1 ? $c[1] : null;
        $message = preg_match('#<Message>([^<]{1,300})</Message>#', $body, $m) === 1 ? $m[1] : null;

        return $code === null ? '' : " ({$code}".($message !== null ? ": {$message}" : '').')';
    }
}
