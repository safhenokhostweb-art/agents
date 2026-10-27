<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use RuntimeException;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;

/**
 * Read-only view of the workspace topology (workspace.json) handed to every
 * agent. Paths in workspace.json are relative to its `root`, which is itself
 * relative to the workspace.json file.
 */
final class AgentContext
{
    public const SCHEMA = 'agents.workspace/v1';

    private function __construct(public readonly string $workspaceRoot, private readonly array $config)
    {
    }

    public static function fromFile(string $workspaceFile): self
    {
        $config = Json::decodeFile($workspaceFile);
        if (($config['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException("Unsupported workspace schema in {$workspaceFile}.");
        }
        if (! is_array($config['projects'] ?? null) || $config['projects'] === []) {
            throw new RuntimeException("Workspace {$workspaceFile} declares no projects.");
        }

        return new self(Paths::join(dirname(Paths::normalize($workspaceFile)), (string) ($config['root'] ?? '.')), $config);
    }

    /** @return array<string, array{path: string, role?: string, composer?: string}> */
    public function projects(): array
    {
        return $this->config['projects'];
    }

    public function projectPath(string $name): string
    {
        $project = $this->config['projects'][$name] ?? null;
        if (! is_array($project) || ! isset($project['path'])) {
            throw new ProtocolException('PROJECT_UNKNOWN', "Project '{$name}' is not declared in workspace.json.");
        }

        return Paths::join($this->workspaceRoot, (string) $project['path']);
    }

    /**
     * Directory holding the project's composer.json: the git root unless the
     * project declares a `composer` subdirectory (deployment-system keeps its
     * only manifest in the Laravel app under service/).
     */
    public function composerPath(string $name): string
    {
        $root = $this->projectPath($name);
        $directory = $this->config['projects'][$name]['composer'] ?? null;
        if ($directory === null) {
            return $root;
        }
        if (! is_string($directory) || $directory === '' || Paths::isAbsolute($directory) || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $directory) === 1) {
            throw new ProtocolException('PROJECT_INVALID', "Project '{$name}' composer directory must be a relative path inside the project.");
        }

        return Paths::join($root, $directory);
    }

    public function path(string $relative): string
    {
        return Paths::join($this->workspaceRoot, $relative);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
