<?php

declare(strict_types=1);

// A minimal S3 endpoint for `php -S`: PUT and HEAD on path-style objects.
// Like S3 it rejects a body whose x-amz-content-sha256, Content-MD5 or
// x-amz-checksum-sha256 does not match, and it reports the checksum it
// computed itself on HEAD with x-amz-checksum-mode: ENABLED.
// FAKE_S3_DIR holds the objects; a file named `lie` there makes HEAD report
// a wrong checksum (to test the handshake).

$dir = (string) getenv('FAKE_S3_DIR');
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $dir.'/objects/'.sha1($path);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

file_put_contents($dir.'/requests.log', json_encode(['method' => $method, 'path' => $path, 'authorization' => $auth, 'content_sha256' => $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? null, 'date' => $_SERVER['HTTP_X_AMZ_DATE'] ?? null])."\n", FILE_APPEND);

$fail = static function (int $status, string $code): never {
    http_response_code($status);
    header('Content-Type: application/xml');
    echo "<?xml version=\"1.0\"?><Error><Code>{$code}</Code><Message>{$code}</Message></Error>";
    exit;
};
if (preg_match('#^AWS4-HMAC-SHA256 Credential=TESTKEY/\d{8}/test-region/s3/aws4_request,SignedHeaders=[a-z0-9;-]+,Signature=[0-9a-f]{64}$#', $auth) !== 1) {
    $fail(403, 'SignatureDoesNotMatch');
}

if ($method === 'PUT') {
    $body = (string) file_get_contents('php://input');
    if (($_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '') !== hash('sha256', $body)) {
        $fail(400, 'XAmzContentSHA256Mismatch');
    }
    if (($_SERVER['HTTP_CONTENT_MD5'] ?? '') !== base64_encode(md5($body, true))) {
        $fail(400, 'BadDigest');
    }
    if (isset($_SERVER['HTTP_X_AMZ_CHECKSUM_SHA256']) && $_SERVER['HTTP_X_AMZ_CHECKSUM_SHA256'] !== base64_encode(hash('sha256', $body, true))) {
        $fail(400, 'BadDigest');
    }
    @mkdir($dir.'/objects', 0777, true);
    file_put_contents($file, $body);
    header('ETag: "'.md5($body).'"');
    http_response_code(200);
    exit;
}

if ($method === 'HEAD') {
    if (! is_file($file)) {
        http_response_code(404);
        exit;
    }
    $body = (string) file_get_contents($file);
    header('ETag: "'.md5($body).'"');
    header('X-Object-Size: '.strlen($body));
    header('Content-Length: '.strlen($body));
    if (($_SERVER['HTTP_X_AMZ_CHECKSUM_MODE'] ?? '') === 'ENABLED') {
        $digest = is_file($dir.'/lie') ? hash('sha256', 'something else', true) : hash('sha256', $body, true);
        header('x-amz-checksum-sha256: '.base64_encode($digest));
    }
    http_response_code(200);
    exit;
}

$fail(405, 'MethodNotAllowed');
