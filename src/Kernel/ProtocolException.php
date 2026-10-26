<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

use RuntimeException;

/** An invalid contract or an unmet precondition. Agents surface it as a BLOCKED result. */
final class ProtocolException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
