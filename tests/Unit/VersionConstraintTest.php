<?php

declare(strict_types=1);

namespace Workspace\Agents\Tests\Unit;

use Workspace\Agents\Support\VersionConstraint;
use Workspace\Agents\Tests\TestCase;

final class VersionConstraintTest extends TestCase
{
    public function testCaretFollowsComposerSemantics(): void
    {
        $this->check([
            ['12.62.0', '^12.0', true],
            ['13.0.0', '^12.0', false],
            ['11.9.9', '^12.0', false],
            ['0.3.5', '^0.3', true],
            ['0.4.0', '^0.3', false],
            ['0.0.3', '^0.0.3', true],
            ['0.0.4', '^0.0.3', false],
            ['0.9.0', '^0', true],
            ['1.0.0', '^0', false],
        ]);
    }

    public function testTildeWildcardAndOperators(): void
    {
        $this->check([
            ['1.4.9', '~1.2', true],
            ['2.0.0', '~1.2', false],
            ['1.2.9', '~1.2.3', true],
            ['1.3.0', '~1.2.3', false],
            ['10.5.0', '10.*', true],
            ['11.0.0', '10.*', false],
            ['10.2.7', '10.2.x', true],
            ['10.3.0', '10.2.x', false],
            ['8.3.0', '>=8.2', true],
            ['8.1.0', '>= 8.2', false],
            ['8.3.0', '>=8.1 <8.4', true],
            ['8.4.0', '>=8.1,<8.4', false],
            ['1.5.0', '1.0 - 2.0', true],
            ['2.0.1', '1.0 - 2.0', false],
            ['1.2.0', '!=1.2.0', false],
            ['3.0.0', '*', true],
        ]);
    }

    public function testOrAlternativesAsUsedByWorkspacePackages(): void
    {
        $this->check([
            ['v12.62.0', '^10.0|^11.0|^12.0', true],
            ['10.x-dev', '^10.0|^11.0|^12.0', true],
            ['v9.52.0', '^10.0 || ^11.0', false],
            ['v13.0.0', '^10.0|^11.0|^12.0', false],
            ['8.3.0', '^8.1', true],
        ]);
    }

    public function testStabilityFlagsAreIgnoredAndBranchAliasesResolve(): void
    {
        $this->check([
            ['1.2.0', '^1.0@dev', true],
            ['10.x-dev', '^10.10', true],
            ['10.x-dev', '^11.0', false],
        ]);
    }

    public function testUnparseableInputIsUnknownNotGuessed(): void
    {
        $this->assertNull(VersionConstraint::satisfies('dev-main', '^1.0'));
        $this->assertNull(VersionConstraint::satisfies('1.0.0', 'dev-feature'));
        $this->assertNull(VersionConstraint::satisfies('1.0.0', '^banana'));
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $cases */
    private function check(array $cases): void
    {
        foreach ($cases as [$version, $constraint, $expected]) {
            $this->assertSame($expected, VersionConstraint::satisfies($version, $constraint), "{$version} vs {$constraint}");
        }
    }
}
