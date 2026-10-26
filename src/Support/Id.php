<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

final class Id
{
    /** Sortable, collision-resistant id: <prefix>-<utc timestamp>-<random>. */
    public static function new(string $prefix): string
    {
        return $prefix.'-'.gmdate('Ymd\THis').'-'.bin2hex(random_bytes(4));
    }
}
