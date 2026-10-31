<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use RuntimeException;
use Workspace\Agents\Support\Json;

/**
 * Host-local settings for backup.archive: the public encryption key and
 * the off-site store. They are never committed: they live in a settings
 * file outside the repository (agents/disk-janitor.json names its path),
 * and JANITOR_ARCHIVE_* environment variables override single keys.
 *
 * Settings never leave this class unredacted: describe() is what reports
 * and findings see. A settings file holding a secret must not be readable
 * by group or others.
 */
final class ArchiveSettings
{
    public const ENCRYPTION_TOOLS = ['gpg', 'age'];
    public const DRIVERS = ['s3', 'filesystem'];

    /** Environment variable => settings key path. */
    public const ENVIRONMENT = [
        'JANITOR_ARCHIVE_ENCRYPTION_TOOL' => 'encryption.tool',
        'JANITOR_ARCHIVE_RECIPIENTS' => 'encryption.recipients',
        'JANITOR_ARCHIVE_RECIPIENT_FILES' => 'encryption.recipient_files',
        'JANITOR_ARCHIVE_REMOTE_DRIVER' => 'remote.driver',
        'JANITOR_ARCHIVE_REMOTE_PATH' => 'remote.path',
        'JANITOR_ARCHIVE_S3_ENDPOINT' => 'remote.endpoint',
        'JANITOR_ARCHIVE_S3_REGION' => 'remote.region',
        'JANITOR_ARCHIVE_S3_BUCKET' => 'remote.bucket',
        'JANITOR_ARCHIVE_S3_PREFIX' => 'remote.prefix',
        'JANITOR_ARCHIVE_S3_ACCESS_KEY' => 'remote.access_key',
        'JANITOR_ARCHIVE_S3_SECRET_KEY' => 'remote.secret_key',
        'JANITOR_ARCHIVE_S3_PATH_STYLE' => 'remote.path_style',
        'JANITOR_ARCHIVE_S3_CHECKSUM_HEADER' => 'remote.checksum_header',
        'JANITOR_ARCHIVE_TAR_BINARY' => 'binaries.tar',
        'JANITOR_ARCHIVE_GPG_BINARY' => 'binaries.gpg',
        'JANITOR_ARCHIVE_AGE_BINARY' => 'binaries.age',
        'JANITOR_ARCHIVE_ZSTD_BINARY' => 'binaries.zstd',
    ];

    private const LIST_KEYS = ['encryption.recipients', 'encryption.recipient_files'];
    private const BOOL_KEYS = ['remote.path_style', 'remote.checksum_header'];

    /**
     * @param  list<string>  $problems  configuration errors; empty when usable
     * @param  list<string>  $environment  names of the variables that were applied
     */
    private function __construct(
        public readonly array $values,
        public readonly ?string $file,
        public readonly array $environment,
        public readonly array $problems,
    ) {
    }

    /** @param array<string, string>|null $env defaults to getenv() */
    public static function load(string $file, ?array $env = null): self
    {
        $env ??= getenv();
        $values = [];
        $problems = [];
        $loaded = null;
        if (is_file($file)) {
            try {
                $values = Json::decodeFile($file);
                $loaded = $file;
            } catch (RuntimeException $exception) {
                $problems[] = 'settings file is not valid JSON: '.$exception->getMessage();
            }
        }

        $applied = [];
        foreach (self::ENVIRONMENT as $name => $key) {
            $raw = $env[$name] ?? null;
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $value = match (true) {
                in_array($key, self::LIST_KEYS, true) => array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '')),
                in_array($key, self::BOOL_KEYS, true) => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                default => $raw,
            };
            [$section, $field] = explode('.', $key);
            $values[$section][$field] = $value;
            $applied[] = $name;
        }

        $settings = new self($values, $loaded, $applied, $problems);

        return new self($values, $loaded, $applied, array_merge($problems, $settings->validate()));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$section, $field] = explode('.', $key);

        return $this->values[$section][$field] ?? $default;
    }

    public function tool(): string
    {
        return (string) $this->get('encryption.tool', 'gpg');
    }

    /** @return array<string, mixed> settings with every credential removed */
    public function describe(): array
    {
        $remote = (array) ($this->values['remote'] ?? []);
        unset($remote['access_key'], $remote['secret_key']);

        return [
            'file' => $this->file,
            'environment' => $this->environment,
            'encryption' => ['tool' => $this->tool(), 'recipient_files' => (array) $this->get('encryption.recipient_files', []), 'recipients' => count((array) $this->get('encryption.recipients', []))],
            'remote' => $remote,
        ];
    }

    /** @return list<string> */
    private function validate(): array
    {
        $problems = [];
        $tool = $this->get('encryption.tool', 'gpg');
        if (! in_array($tool, self::ENCRYPTION_TOOLS, true)) {
            $problems[] = 'encryption.tool must be gpg or age';
        }
        $recipients = $this->get('encryption.recipients', []);
        $files = $this->get('encryption.recipient_files', []);
        if (! self::isStringList($recipients) || ! self::isStringList($files)) {
            $problems[] = 'encryption.recipients and encryption.recipient_files must be lists of strings';
        } elseif ($recipients === [] && $files === []) {
            $problems[] = 'no encryption recipient: set encryption.recipient_files (gpg/age public key files) or encryption.recipients (age public keys)';
        } elseif ($tool === 'gpg' && $recipients !== []) {
            $problems[] = 'gpg takes public key files only (encryption.recipient_files), so archives never depend on a server keyring';
        }

        $driver = $this->get('remote.driver');
        if (! in_array($driver, self::DRIVERS, true)) {
            $problems[] = 'remote.driver must be s3 or filesystem';
        } elseif ($driver === 'filesystem' && ! is_string($this->get('remote.path'))) {
            $problems[] = 'remote.path is required for the filesystem driver';
        } elseif ($driver === 's3') {
            foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $field) {
                if (! is_string($this->get("remote.{$field}")) || $this->get("remote.{$field}") === '') {
                    $problems[] = "remote.{$field} is required for the s3 driver";
                }
            }
            $endpoint = (string) $this->get('remote.endpoint', '');
            if ($endpoint !== '' && preg_match('#^https?://[^/\s]+/?$#', $endpoint) !== 1) {
                $problems[] = 'remote.endpoint must be a bare http(s)://host[:port] URL';
            }
        }

        // A file holding credentials must be private to its owner.
        if ($this->file !== null && PHP_OS_FAMILY !== 'Windows' && isset($this->values['remote']['secret_key'])) {
            clearstatcache(true, $this->file);
            $mode = @fileperms($this->file);
            if ($mode !== false && ($mode & 0o077) !== 0) {
                $problems[] = sprintf('settings file %s holds a secret but has mode %o; chmod 600 it', $this->file, $mode & 0o777);
            }
        }

        return $problems;
    }

    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, static fn ($v): bool => is_string($v) && $v !== '') === $value;
    }
}
