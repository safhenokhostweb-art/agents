<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Support;

use RuntimeException;
use Workspace\Agents\Agents\ServerDiskJanitor\ArchivePipeline;
use Workspace\Agents\Agents\ServerDiskJanitor\ArchiveSettings;
use Workspace\Agents\Support\Process;

/**
 * A throwaway gpg keypair for the test run: the public key is what the
 * server gets, the private key stays here to prove an archive decrypts
 * (and that nothing else could). Generated once, removed at shutdown.
 */
final class GpgTestKey
{
    private static ?self $instance = null;

    private function __construct(
        public readonly string $gpg,
        public readonly string $home,
        public readonly string $publicKeyFile,
        public readonly string $secretKeyFile,
        public readonly string $fingerprint,
        private readonly ArchivePipeline $paths,
    ) {
    }

    /** null when no gpg is installed, so callers can skip */
    public static function get(): ?self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $resolved = ArchivePipeline::resolve(ArchiveSettings::load('', ['JANITOR_ARCHIVE_ENCRYPTION_TOOL' => 'gpg']), 'gzip');
        $gpg = $resolved['binaries']['gpg'] ?? null;
        if ($gpg === null) {
            return null;
        }
        $paths = new ArchivePipeline(['gpg' => $gpg], 'gpg', [], [], 'gzip', 6);
        // gpg-agent sockets live in the home; keep the path short.
        $home = str_replace('\\', '/', sys_get_temp_dir()).'/jk'.bin2hex(random_bytes(3));
        mkdir($home, 0700);
        $run = static function (array $arguments) use ($gpg, $home, $paths): string {
            $result = Process::run(array_merge([$gpg, '--batch', '--no-tty', '--homedir', $paths->toolPath('gpg', $home), '--passphrase', '', '--pinentry-mode', 'loopback'], $arguments), $home);
            if ($result['code'] !== 0) {
                throw new RuntimeException('gpg '.implode(' ', $arguments).' failed: '.$result['stderr']);
            }

            return $result['stdout'];
        };
        $run(['--quick-gen-key', 'Janitor Test <janitor-test@example.invalid>', 'ed25519', 'cert', 'never']);
        preg_match('/^fpr:+([0-9A-F]{40}):/m', $run(['--with-colons', '--list-keys']), $m);
        $fingerprint = $m[1] ?? throw new RuntimeException('no fingerprint for the test key');
        $run(['--quick-add-key', $fingerprint, 'cv25519', 'encr', 'never']);
        file_put_contents($home.'/public.asc', $run(['--armor', '--export', $fingerprint]));
        file_put_contents($home.'/secret.asc', $run(['--armor', '--export-secret-keys', $fingerprint]));

        self::$instance = new self($gpg, $home, $home.'/public.asc', $home.'/secret.asc', $fingerprint, $paths);
        register_shutdown_function(static function () use ($gpg, $home, $paths): void {
            $gpgconf = dirname($gpg).'/gpgconf'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($gpgconf)) {
                Process::run([$gpgconf, '--homedir', $paths->toolPath('gpg', $home), '--kill', 'gpg-agent'], $home);
            }
            ArchivePipeline::removeTree($home);
        });

        return self::$instance;
    }

    /** Decrypt $encrypted into $plain with the private key. */
    public function decrypt(string $encrypted, string $plain): void
    {
        $result = Process::run([$this->gpg, '--batch', '--no-tty', '--yes', '--quiet', '--homedir', $this->paths->toolPath('gpg', $this->home), '--pinentry-mode', 'loopback', '--passphrase', '', '--output', $this->paths->toolPath('gpg', $plain), '--decrypt', $this->paths->toolPath('gpg', $encrypted)], $this->home);
        if ($result['code'] !== 0) {
            throw new RuntimeException('decryption failed: '.$result['stderr']);
        }
    }
}
