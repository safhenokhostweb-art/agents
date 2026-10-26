<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Blocker = 'blocker';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Blocker => 2,
        };
    }
}
