<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use RuntimeException;
use Workspace\Agents\Support\Process;

/**
 * tar → gzip|zstd → gpg|age, streamed: the plaintext archive never touches
 * the disk, only the encrypted output does. Encryption is to public keys
 * only; a key file that contains a private key is refused, because a
 * server holding the private key could read its own backups.
 */
final class ArchivePipeline
{
    private const CHUNK = 1048576;

    public const POSIX_FALLBACKS = ['/usr/bin', '/usr/local/bin', '/bin'];
    public const WINDOWS_FALLBACKS = ['C:/Program Files/Git/usr/bin', 'C:/Program Files/GnuPG/bin', 'C:/Program Files (x86)/GnuPG/bin'];

    /** @param array<string, string> $binaries tool => resolved path */
    public function __construct(
        private readonly array $binaries,
        private readonly string $tool,
        private readonly array $recipientFiles,
        private readonly array $recipients,
        private readonly string $compression,
        private readonly int $level,
    ) {
    }

    /**
     * Resolve each binary from the settings override, then PATH, then the
     * standard locations.
     *
     * @return array{binaries: array<string, string>, missing: list<string>}
     */
    public static function resolve(ArchiveSettings $settings, string $compression): array
    {
        $needed = ['tar', $settings->tool()];
        if ($compression === 'zstd') {
            $needed[] = 'zstd';
        }
        $windows = PHP_OS_FAMILY === 'Windows';
        $found = $missing = [];
        foreach ($needed as $tool) {
            $override = $settings->get("binaries.{$tool}");
            $candidates = is_string($override) && $override !== '' ? [$override] : [];
            if ($candidates === []) {
                // Windows' own tar.exe is bsdtar; prefer GNU tar next to Git's gpg so the toolchain agrees on paths.
                $dirs = $windows ? array_merge(self::WINDOWS_FALLBACKS, explode(';', (string) getenv('PATH'))) : array_merge(explode(':', (string) getenv('PATH')), self::POSIX_FALLBACKS);
                foreach ($dirs as $dir) {
                    $dir = rtrim(trim($dir, " \""), '\\/');
                    if ($dir !== '') {
                        $candidates[] = $dir.'/'.$tool.($windows ? '.exe' : '');
                    }
                }
            }
            $match = null;
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && ($windows || is_executable($candidate))) {
                    $match = str_replace('\\', '/', $candidate);
                    break;
                }
            }
            $match === null ? $missing[] = $tool : $found[$tool] = $match;
        }

        return ['binaries' => $found, 'missing' => $missing];
    }

    /**
     * Fingerprints of the public keys that will be able to decrypt, after
     * checking every recipient is a public key.
     *
     * @return list<string>
     */
    public function recipientFingerprints(string $workDir): array
    {
        $fingerprints = [];
        foreach ($this->recipientFiles as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException("recipient key file {$file} is missing or unreadable");
            }
            $bytes = (string) file_get_contents($file);
            if (preg_match('/PRIVATE KEY|AGE-SECRET-KEY-/i', $bytes) === 1) {
                throw new RuntimeException("recipient key file {$file} contains a private key; only public keys may be on this server");
            }
        }
        if ($this->tool === 'age') {
            $recipients = $this->recipients;
            foreach ($this->recipientFiles as $file) {
                foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && ! str_starts_with($line, '#')) {
                        $recipients[] = $line;
                    }
                }
            }
            foreach ($recipients as $recipient) {
                if (preg_match('/^(age1[0-9a-z]{58}|ssh-(ed25519|rsa) \S+)/', $recipient) !== 1) {
                    throw new RuntimeException('age recipient is not a public key: '.substr($recipient, 0, 12).'…');
                }
                $fingerprints[] = 'age:'.substr(hash('sha256', $recipient), 0, 16);
            }

            return $fingerprints;
        }

        $home = $this->gpgHome($workDir);
        try {
            foreach ($this->recipientFiles as $file) {
                $result = Process::run([$this->binaries['gpg'], '--batch', '--no-tty', '--homedir', $this->toolPath('gpg', $home), '--with-colons', '--import-options', 'show-only', '--import', $this->toolPath('gpg', $file)], $workDir);
                $encrypting = false;
                $fingerprint = null;
                foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
                    $fields = explode(':', $line);
                    if (in_array($fields[0], ['sec', 'ssb'], true)) {
                        throw new RuntimeException("recipient key file {$file} contains a secret key");
                    }
                    // Field 12 holds the key capabilities; 'e' = may encrypt.
                    if (in_array($fields[0], ['pub', 'sub'], true) && str_contains($fields[11] ?? '', 'e')) {
                        $encrypting = true;
                    }
                    if ($fields[0] === 'fpr' && $fingerprint === null) {
                        $fingerprint = $fields[9] ?? null;
                    }
                }
                if (! $encrypting || $fingerprint === null) {
                    throw new RuntimeException("recipient key file {$file} has no usable encryption key".($result['stderr'] !== '' ? ': '.trim((string) strtok($result['stderr'], "\n")) : ''));
                }
                $fingerprints[] = 'gpg:'.$fingerprint;
            }
        } finally {
            self::removeTree($home);
        }

        return $fingerprints;
    }

    /**
     * Stream $directory into the encrypted file $output.
     *
     * @return array{compressed_bytes: int, encrypted_bytes: int, sha256: string, md5: string}
     */
    public function run(string $directory, string $output, string $workDir): array
    {
        $parent = dirname($directory);
        $name = basename($directory);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $logs = ['tar' => $workDir.'/tar.err', 'compress' => $workDir.'/compress.err', 'encrypt' => $workDir.'/encrypt.err'];
        $home = $this->tool === 'gpg' ? $this->gpgHome($workDir) : null;
        $processes = [];
        try {
            $tar = proc_open([$this->binaries['tar'], '-c', '-f', '-', '-C', $this->toolPath('tar', $parent), '--', $name], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $logs['tar'], 'w']], $tarPipes);
            if (! is_resource($tar)) {
                throw new RuntimeException('cannot start tar');
            }
            $processes['tar'] = $tar;
            $source = $tarPipes[1];
            $deflate = null;
            if ($this->compression === 'zstd') {
                $zstd = proc_open([$this->binaries['zstd'], '-q', '-c', '-'.$this->level, '-T0', '-'], [0 => $source, 1 => ['pipe', 'w'], 2 => ['file', $logs['compress'], 'w']], $zstdPipes);
                if (! is_resource($zstd)) {
                    throw new RuntimeException('cannot start zstd');
                }
                fclose($source);
                $processes['compress'] = $zstd;
                $source = $zstdPipes[1];
            } else {
                $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $this->level]);
            }

            $encrypt = proc_open($this->encryptCommand($output, $home), [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $logs['encrypt'], 'w']], $encryptPipes);
            if (! is_resource($encrypt)) {
                throw new RuntimeException('cannot start '.$this->tool);
            }
            $processes['encrypt'] = $encrypt;
            $sink = $encryptPipes[0];

            $compressed = 0;
            $broken = false;
            while (! $broken && ! feof($source)) {
                $chunk = fread($source, self::CHUNK);
                if ($chunk === false) {
                    break;
                }
                $data = $deflate !== null ? deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk;
                $compressed += strlen($data);
                $broken = ! self::writeAll($sink, $data);
            }
            if (! $broken && $deflate !== null) {
                $tail = deflate_add($deflate, '', ZLIB_FINISH);
                $compressed += strlen($tail);
                $broken = ! self::writeAll($sink, $tail);
            }
            fclose($source);
            fclose($sink);

            $codes = [];
            foreach ($processes as $stage => $process) {
                $codes[$stage] = proc_close($process);
            }
            $processes = [];
            foreach ($codes as $stage => $code) {
                if ($code !== 0) {
                    throw new RuntimeException("{$stage} exited with code {$code}: ".self::tail($logs[$stage]));
                }
            }
            if ($broken) {
                throw new RuntimeException("{$this->tool} stopped reading its input: ".self::tail($logs['encrypt']));
            }
            clearstatcache(true, $output);
            if (! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException("{$this->tool} produced no output");
            }

            return ['compressed_bytes' => $compressed, 'encrypted_bytes' => (int) filesize($output), 'sha256' => (string) hash_file('sha256', $output), 'md5' => (string) hash_file('md5', $output)];
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($logs as $log) {
                @unlink($log);
            }
            if ($home !== null) {
                self::removeTree($home);
            }
        }
    }

    /** @return list<string> */
    private function encryptCommand(string $output, ?string $home): array
    {
        if ($this->tool === 'age') {
            $command = [$this->binaries['age'], '--encrypt', '--output', $this->toolPath('age', $output)];
            foreach ($this->recipientFiles as $file) {
                array_push($command, '--recipients-file', $this->toolPath('age', $file));
            }
            foreach ($this->recipients as $recipient) {
                array_push($command, '--recipient', $recipient);
            }

            return $command;
        }
        $command = [$this->binaries['gpg'], '--batch', '--no-tty', '--yes', '--quiet', '--homedir', $this->toolPath('gpg', (string) $home), '--trust-model', 'always', '--compress-algo', 'none', '--output', $this->toolPath('gpg', $output)];
        foreach ($this->recipientFiles as $file) {
            array_push($command, '--recipient-file', $this->toolPath('gpg', $file));
        }
        $command[] = '--encrypt';

        return $command;
    }

    /**
     * A throwaway gpg home: encryption reads keys from files only, so the
     * server keyring is never consulted or modified. Kept short, since
     * gpg-agent socket paths have a length limit.
     */
    private function gpgHome(string $workDir): string
    {
        $home = $workDir.'/g'.bin2hex(random_bytes(3));
        if (! @mkdir($home, 0700)) {
            throw new RuntimeException("cannot create gpg home {$home}");
        }

        return $home;
    }

    /**
     * MSYS builds (Git for Windows' tar and gpg) do not understand drive
     * paths; hand them /c/… instead. Native tools get the path unchanged.
     */
    public function toolPath(string $tool, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY !== 'Windows' || ! str_contains(strtolower($this->binaries[$tool] ?? ''), '/usr/bin/')) {
            return $path;
        }

        return preg_replace_callback('#^([A-Za-z]):/#', static fn (array $m): string => '/'.strtolower($m[1]).'/', $path) ?? $path;
    }

    private static function writeAll($stream, string $data): bool
    {
        while ($data !== '') {
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                return false;
            }
            $data = substr($data, $written);
        }

        return true;
    }

    private static function tail(string $log): string
    {
        $text = trim((string) @file_get_contents($log));

        return $text === '' ? '(no output)' : substr($text, -500);
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path.'/'.$name);
            }
        }
        @rmdir($path);
    }
}
