<?php

declare(strict_types=1);

namespace Workspace\Agents\Agents\DependencyDeployment;

use Workspace\Agents\Kernel\Finding;
use Workspace\Agents\Support\Json;
use Workspace\Agents\Support\Paths;
use Workspace\Agents\Support\TreeHasher;
use Workspace\Agents\Support\VersionConstraint;

/**
 * Inspects every Composer `path` repository package each project installs.
 *
 * For packages one project serves to another (customer-site consuming
 * saas-platform/packages with `symlink: false`) it proves whether the
 * consumer's vendor copy is byte-identical to the source, and whether the
 * consumer's release silently depends on provider changes that production
 * does not have yet. For every package it checks the package's requirements
 * against the installing project's locked versions.
 */
final class SharedPackageAnalyzer
{
    private const SAMPLE = 20;

    /** @var list<Finding> */
    private array $findings = [];

    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @param  array<string, ProjectSnapshot>  $projects
     * @return array{packages: list<array>, findings: list<Finding>}
     */
    public function analyze(array $projects): array
    {
        $this->findings = [];
        $this->reported = [];
        $packages = [];

        foreach ($projects as $project) {
            $served = $this->servedPackages($project);
            $requirements = $project->composer->requirements();

            foreach ($this->providerIndex($projects) as $name => $owner) {
                if ($owner !== $project->name && isset($requirements[$name]) && ! isset($served[$name])) {
                    $this->unresolvable($project->name, $name, "{$project->name} requires {$name}, which {$owner} serves from a path repository, but {$project->name} declares no repository that can install it.");
                }
            }

            foreach ($served as $name => $repository) {
                if (! isset($requirements[$name]) && $project->composer->lockedVersion($name) === null) {
                    continue;
                }
                $packages[] = $this->inspect($project, $projects, $name, $repository, $served);
            }
        }

        return ['packages' => $packages, 'findings' => $this->findings];
    }

    /** @return array<string, array{url: string, directory: string, symlink: bool|null}> */
    private function servedPackages(ProjectSnapshot $project): array
    {
        $served = [];
        foreach ($project->composer->pathRepositories() as $repository) {
            if (! is_dir($repository['directory'])) {
                $this->add(Finding::blocker(
                    'PATH_REPOSITORY_MISSING',
                    "Path repository {$repository['url']} does not exist; composer install will fail.",
                    $project->name,
                    ['url' => $repository['url'], 'resolved' => $repository['directory']],
                ));
                continue;
            }
            $name = $this->packageName($repository['directory']);
            if ($name === null) {
                $this->add(Finding::warning('PATH_REPOSITORY_INVALID', "Path repository {$repository['url']} has no readable composer.json name.", $project->name));
                continue;
            }
            $served[$name] = $repository;
        }

        return $served;
    }

    /**
     * Packages each project serves from inside its own tree.
     *
     * @param  array<string, ProjectSnapshot>  $projects
     * @return array<string, string> package name => owning project
     */
    private function providerIndex(array $projects): array
    {
        $index = [];
        foreach ($projects as $project) {
            foreach ($project->composer->pathRepositories() as $repository) {
                $relative = Paths::relative($project->root, $repository['directory']);
                if ($relative !== null && $relative !== '' && ($name = $this->packageName($repository['directory'])) !== null) {
                    $index[$name] = $project->name;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, ProjectSnapshot>  $projects
     * @param  array{url: string, directory: string, symlink: bool|null}  $repository
     * @param  array<string, array>  $served
     */
    private function inspect(ProjectSnapshot $consumer, array $projects, string $name, array $repository, array $served): array
    {
        $provider = null;
        $sourcePath = null;
        foreach ($projects as $candidate) {
            $relative = Paths::relative($candidate->root, $repository['directory']);
            if ($relative !== null && $relative !== '') {
                $provider = $candidate;
                $sourcePath = $relative;
                break;
            }
        }
        $crossProject = $provider !== null && $provider->name !== $consumer->name;
        $subject = $consumer->name.':'.$name;

        $record = [
            'package' => $name,
            'consumer' => $consumer->name,
            'provider' => $provider?->name,
            'scope' => $crossProject ? 'cross-project' : ($provider === null ? 'external' : 'internal'),
            'source' => $sourcePath,
            'locked_version' => $consumer->composer->lockedVersion($name),
            'install' => $this->installState($consumer, $name, $repository, $subject),
            'requirements' => $this->checkRequirements($consumer, $name, $repository['directory'], $served, $subject),
        ];

        if ($crossProject && $sourcePath !== null) {
            $record['provider_state'] = $this->providerState($provider, $consumer, $name, $sourcePath, $subject);
        }

        return $record;
    }

    private function installState(ProjectSnapshot $consumer, string $name, array $repository, string $subject): array
    {
        $installed = $consumer->composer->root.'/vendor/'.$name;
        if (! file_exists($installed)) {
            $this->add(Finding::warning(
                'PACKAGE_NOT_INSTALLED',
                "{$name} is required but not installed in {$consumer->name}/vendor.",
                $subject,
                remedy: "Run composer install in {$consumer->name}.",
            ));

            return ['mode' => 'missing'];
        }
        if (is_link($installed) || Paths::normalize($installed) === Paths::normalize($repository['directory'])) {
            return ['mode' => 'linked'];
        }

        $source = TreeHasher::manifest($repository['directory']);
        $mirror = TreeHasher::manifest($installed);
        $diff = TreeHasher::compare($source, $mirror);
        $inSync = $diff['missing'] === [] && $diff['extra'] === [] && $diff['modified'] === [];
        $state = [
            'mode' => 'mirror',
            'in_sync' => $inSync,
            'source_files' => count($source),
            'source_digest' => TreeHasher::digest($source),
            'mirror_digest' => TreeHasher::digest($mirror),
            'missing' => count($diff['missing']),
            'extra' => count($diff['extra']),
            'modified' => count($diff['modified']),
        ];
        if (! $inSync) {
            $this->add(Finding::warning(
                'MIRROR_DRIFT',
                "{$consumer->name} runs a stale copy of {$name}: {$state['modified']} modified, {$state['missing']} missing, {$state['extra']} extra file(s) versus the source. Its tests do not exercise the current package code.",
                $subject,
                [
                    'modified' => array_slice($diff['modified'], 0, self::SAMPLE),
                    'missing' => array_slice($diff['missing'], 0, self::SAMPLE),
                    'extra' => array_slice($diff['extra'], 0, self::SAMPLE),
                ],
                "In {$consumer->name}: composer reinstall {$name} (re-mirrors the package; composer.lock is unchanged).",
            ));
        }

        return $state;
    }

    /** @return list<array{dependency: string, constraint: string, against: ?string, result: string}> */
    private function checkRequirements(ProjectSnapshot $consumer, string $name, string $directory, array $served, string $subject): array
    {
        $manifest = $this->manifest($directory);
        $checks = [];
        foreach ($manifest['require'] ?? [] as $dependency => $constraint) {
            $constraint = (string) $constraint;
            if (preg_match('/^(ext|lib)-|^composer(-plugin|-runtime)?-api$/', (string) $dependency) === 1) {
                continue;
            }
            if (isset($served[$dependency])) {
                $checks[] = ['dependency' => $dependency, 'constraint' => $constraint, 'against' => 'path repository', 'result' => 'satisfied'];
                continue;
            }
            if (str_starts_with($constraint, '@') || str_starts_with($constraint, 'dev-')) {
                // A dev-only reference to a sibling package no repository serves.
                $this->unresolvable($consumer->name, (string) $dependency, "{$name} requires {$dependency} ({$constraint}), but {$consumer->name} has no path repository serving it.");
                $checks[] = ['dependency' => $dependency, 'constraint' => $constraint, 'against' => null, 'result' => 'unresolvable'];
                continue;
            }

            $against = $dependency === 'php' ? $consumer->composer->platformPhp() : $consumer->composer->lockedVersion((string) $dependency);
            if ($against === null) {
                $checks[] = ['dependency' => $dependency, 'constraint' => $constraint, 'against' => null, 'result' => 'not-locked'];
                if ($dependency !== 'php' && $consumer->composer->lock !== null) {
                    $this->add(Finding::warning('DEPENDENCY_NOT_LOCKED', "{$name} requires {$dependency} {$constraint}, but {$consumer->name}'s composer.lock has no {$dependency}.", $subject));
                }
                continue;
            }

            $satisfied = VersionConstraint::satisfies($against, $constraint);
            $checks[] = ['dependency' => $dependency, 'constraint' => $constraint, 'against' => $against, 'result' => match ($satisfied) {
                true => 'satisfied',
                false => 'violated',
                null => 'unverified',
            }];
            if ($satisfied === false) {
                $this->add(Finding::blocker(
                    'CONSTRAINT_UNSATISFIED',
                    "{$name} requires {$dependency} {$constraint}, but {$consumer->name} provides {$against}.",
                    $subject,
                    ['dependency' => $dependency, 'constraint' => $constraint, 'provided' => $against],
                ));
            } elseif ($satisfied === null) {
                $this->add(Finding::info('CONSTRAINT_UNVERIFIED', "Could not evaluate {$dependency} {$constraint} against {$against}.", $subject));
            }
        }

        return $checks;
    }

    private function providerState(ProjectSnapshot $provider, ProjectSnapshot $consumer, string $name, string $sourcePath, string $subject): array
    {
        $uncommitted = array_column($provider->git->status($sourcePath), 'path');
        $base = $provider->mergeBase ?? $provider->baseSha;
        $unreleased = $base === null ? [] : $provider->git->changedSince($base, $sourcePath);

        if ($uncommitted !== []) {
            $this->add(Finding::warning(
                'SHARED_PACKAGE_UNCOMMITTED',
                count($uncommitted)." uncommitted change(s) in {$provider->name}/{$sourcePath}. {$consumer->name} may be validated against package code that exists in no commit.",
                $subject,
                ['files' => array_slice($uncommitted, 0, self::SAMPLE)],
            ));
        }
        if ($unreleased !== []) {
            $this->add(Finding::warning(
                'CROSS_REPO_COUPLING',
                count($unreleased)." file(s) of {$name} differ from {$provider->name} {$provider->baseRef}. On a server, {$consumer->name} installs this package from the {$provider->name} checkout, so {$provider->name} must be released first and its commit recorded in {$consumer->name}'s release.",
                $subject,
                ['files' => array_slice($unreleased, 0, self::SAMPLE)],
            ));
        }

        return [
            'base_ref' => $provider->baseRef,
            'uncommitted' => count($uncommitted),
            'unreleased' => count($unreleased),
        ];
    }

    private function unresolvable(string $project, string $dependency, string $message): void
    {
        $key = "{$project}|{$dependency}";
        if (! isset($this->reported[$key])) {
            $this->reported[$key] = true;
            $this->add(Finding::blocker('UNRESOLVABLE_SHARED_DEPENDENCY', $message, $project.':'.$dependency));
        }
    }

    private function packageName(string $directory): ?string
    {
        $name = $this->manifest($directory)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function manifest(string $directory): array
    {
        try {
            return Json::decodeFile($directory.'/composer.json');
        } catch (\RuntimeException) {
            return [];
        }
    }

    private function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }
}
