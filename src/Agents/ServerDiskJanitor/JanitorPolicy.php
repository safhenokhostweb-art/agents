<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\ServerDiskJanitor;

use RuntimeException;
use Workspace\Agents\Kernel\AgentContext;
use Workspace\Agents\Kernel\ProtocolException;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;

/**
 * The janitor's governance document (agents/disk-janitor.json): which files
 * are disposable (an allowlist of rules) and which never are (the
 * protection list). Nothing outside a rule is ever considered, and the
 * protection list wins over every rule.
 *
 * Paths are relative to the workspace root or absolute; a root may contain
 * `*` and is expanded with glob(). Host-specific roots that do not exist
 * (/var/log on a Windows checkout) are reported, not errors.
 */
final class JanitorPolicy
{
    public const SCHEMA = 'agents.disk-janitor/v1';

    public const KIND_FILES = 'files';
    public const KIND_EXPIRED_CACHE = 'expired-cache';
    public const KIND_ENTRIES = 'entries';
    public const KINDS = [self::KIND_FILES, self::KIND_EXPIRED_CACHE, self::KIND_ENTRIES];

    /**
     * System trees no rule may scan, whatever the policy says. A rule root
     * equal to or inside one of these makes the policy invalid.
     */
    public const FORBIDDEN_ROOTS = [
        '/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/libx32', '/proc', '/run',
        '/sbin', '/snap', '/sys', '/usr', '/var/lib', '/var/www/html',
        'C:/Windows', 'C:/Program Files', 'C:/Program Files (x86)', 'C:/ProgramData',
    ];

    public const DEFAULT_LIMITS = ['max_entries' => 500000, 'max_candidates' => 20000, 'max_depth' => 12];

    /**
     * @param  array{names: array<string, list<string>>, segments: list<string>, paths: array<string, list<string>>, roots: list<string>}  $protect
     * @param  array<string, list<string>>  $projects  absolute project root => disposable globs (relative to that root)
     * @param  list<array<string, mixed>>  $rules
     * @param  array{max_entries: int, max_candidates: int, max_depth: int}  $limits
     */
    private function __construct(
        public readonly string $file,
        public readonly string $sha256,
        public readonly string $reportsDir,
        public readonly array $protect,
        public readonly array $projects,
        public readonly array $rules,
        public readonly array $limits,
    ) {
    }

    public static function load(string $file, AgentContext $context): self
    {
        $path = Paths::join($context->workspaceRoot, $file);
        if (! is_file($path)) {
            throw new ProtocolException('POLICY_MISSING', "Janitor policy not found: {$path}");
        }
        $bytes = (string) file_get_contents($path);
        try {
            $config = Json::decode($bytes, $path);
        } catch (RuntimeException $exception) {
            throw new ProtocolException('POLICY_INVALID', $exception->getMessage());
        }
        if (($config['schema'] ?? null) !== self::SCHEMA) {
            throw self::invalid('schema must be '.self::SCHEMA);
        }

        $reports = $config['reports'] ?? null;
        if (! is_string($reports) || $reports === '') {
            throw self::invalid('reports must name the report directory');
        }
        $reportsDir = Paths::join($context->workspaceRoot, $reports);

        $limits = self::DEFAULT_LIMITS;
        foreach ((array) ($config['limits'] ?? []) as $key => $value) {
            if (! array_key_exists($key, $limits) || ! is_int($value) || $value < 1) {
                throw self::invalid("limits.{$key} must be one of ".implode(', ', array_keys($limits)).' with a positive integer value');
            }
            $limits[$key] = $value;
        }

        $protect = self::protection((array) ($config['protect'] ?? []), $context);
        // The janitor never touches its own evidence or the agent runtime's state.
        $protect['roots'][] = $reportsDir;
        $protect['roots'][] = $context->path((string) ($context->setting('runtime', [])['artifacts'] ?? 'agents/var/artifacts'));
        $protect['roots'][] = Paths::normalize(dirname($context->path((string) ($context->setting('runtime', [])['journal'] ?? 'agents/var/bus/journal.jsonl'))));
        $protect['roots'] = array_values(array_unique($protect['roots']));

        $projects = self::projects((array) ($config['projects'] ?? []), $context);

        $rules = [];
        foreach ((array) ($config['rules'] ?? []) as $index => $rule) {
            $normalized = self::rule(is_array($rule) ? $rule : [], "rules[{$index}]", $context);
            if (isset($rules[$normalized['id']])) {
                throw self::invalid("rule id '{$normalized['id']}' is declared twice");
            }
            $rules[$normalized['id']] = $normalized;
        }
        if ($rules === []) {
            throw self::invalid('at least one rule is required');
        }

        return new self($path, hash('sha256', $bytes), $reportsDir, $protect, $projects, array_values($rules), $limits);
    }

    /** @return list<string> */
    public function ruleIds(): array
    {
        return array_column($this->rules, 'id');
    }

    public static function isForbiddenRoot(string $path): bool
    {
        $path = Paths::normalize($path);
        if ($path === '/' || preg_match('#^[A-Za-z]:/?$#', $path) === 1) {
            return true;
        }
        foreach (self::FORBIDDEN_ROOTS as $forbidden) {
            if (Paths::relative($forbidden, $path) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function protection(array $config, AgentContext $context): array
    {
        $groups = static function (mixed $value, string $key): array {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                throw self::invalid("protect.{$key} must map a category to a list of patterns");
            }
            $out = [];
            foreach ($value as $category => $patterns) {
                if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $category) !== 1 || ! self::isStringList($patterns)) {
                    throw self::invalid("protect.{$key}.{$category} must be a kebab-case category with a list of patterns");
                }
                $out[(string) $category] = $patterns;
            }

            return $out;
        };
        $segments = $config['segments'] ?? [];
        $roots = $config['roots'] ?? [];
        if (! self::isStringList($segments) || ! self::isStringList($roots)) {
            throw self::invalid('protect.segments and protect.roots must be lists of strings');
        }
        $names = $groups($config['names'] ?? [], 'names');
        foreach ($names as $category => $patterns) {
            $names[$category] = array_map('strtolower', $patterns);
        }

        return [
            'names' => $names,
            'segments' => array_map('strtolower', $segments),
            'paths' => $groups($config['paths'] ?? [], 'paths'),
            'roots' => array_map(static fn (string $root): string => Paths::join($context->workspaceRoot, $root), $roots),
        ];
    }

    /** @return array<string, list<string>> */
    private static function projects(array $config, AgentContext $context): array
    {
        $extra = $config['extra_roots'] ?? [];
        $disposable = $config['disposable'] ?? [];
        if (! self::isStringList($extra) || ! self::isStringList($disposable)) {
            throw self::invalid('projects.extra_roots and projects.disposable must be lists of strings');
        }
        $roots = [];
        foreach (array_keys($context->projects()) as $name) {
            $roots[] = $context->projectPath((string) $name);
        }
        foreach ($extra as $root) {
            $roots[] = Paths::join($context->workspaceRoot, $root);
        }

        return array_fill_keys(array_values(array_unique($roots)), $disposable);
    }

    private static function rule(array $rule, string $label, AgentContext $context): array
    {
        $id = $rule['id'] ?? null;
        if (! is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            throw self::invalid("{$label}.id must be kebab-case");
        }
        $label = "rule '{$id}'";
        $kind = $rule['kind'] ?? null;
        if (! in_array($kind, self::KINDS, true)) {
            throw self::invalid("{$label} kind must be one of ".implode(', ', self::KINDS));
        }
        if (! is_string($rule['reason'] ?? null) || trim($rule['reason']) === '') {
            throw self::invalid("{$label} must state the reason its files are disposable");
        }
        foreach (['roots', 'match'] as $key) {
            if (! self::isStringList($rule[$key] ?? null) || $rule[$key] === []) {
                throw self::invalid("{$label} {$key} must be a non-empty list of strings");
            }
        }
        foreach (['exclude', 'skip_roots'] as $key) {
            if (! self::isStringList($rule[$key] ?? [])) {
                throw self::invalid("{$label} {$key} must be a list of strings");
            }
        }
        $age = $rule['min_age_days'] ?? null;
        if (! is_int($age) && ! is_float($age) || $age < 0) {
            throw self::invalid("{$label} min_age_days must be a number >= 0");
        }

        $retention = null;
        if (isset($rule['retention'])) {
            if ($kind !== self::KIND_ENTRIES) {
                throw self::invalid("{$label} retention applies to kind entries only");
            }
            $pattern = $rule['retention']['group_pattern'] ?? null;
            $keep = $rule['retention']['keep_newest'] ?? null;
            if (! is_string($pattern) || @preg_match($pattern, '') === false || ! str_contains($pattern, '(?<group>')) {
                throw self::invalid("{$label} retention.group_pattern must be a valid regex with a (?<group>…) capture");
            }
            if (! is_int($keep) || $keep < 1) {
                throw self::invalid("{$label} retention.keep_newest must be an integer >= 1: retention never deletes the newest copy");
            }
            $retention = ['group_pattern' => $pattern, 'keep_newest' => $keep];
        }

        $roots = [];
        foreach ($rule['roots'] as $root) {
            $absolute = Paths::isAbsolute($root) ? str_replace('\\', '/', $root) : rtrim(str_replace('\\', '/', $context->workspaceRoot), '/').'/'.$root;
            if (self::isForbiddenRoot($absolute)) {
                throw self::invalid("{$label} root '{$root}' is a protected system location");
            }
            $roots[] = $absolute;
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'reason' => trim($rule['reason']),
            'roots' => $roots,
            'skip_roots' => $rule['skip_roots'] ?? [],
            'match' => $rule['match'],
            'exclude' => $rule['exclude'] ?? [],
            'min_age_days' => (float) $age,
            'retention' => $retention,
        ];
    }

    private static function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                return false;
            }
        }

        return true;
    }

    private static function invalid(string $message): ProtocolException
    {
        return new ProtocolException('POLICY_INVALID', 'Janitor policy invalid: '.$message.'.');
    }
}
