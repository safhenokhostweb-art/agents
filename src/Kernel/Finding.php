<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

final class Finding
{
    public function __construct(
        public readonly string $code,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?string $subject = null,
        public readonly array $evidence = [],
        public readonly ?string $remedy = null,
    ) {
    }

    public static function info(string $code, string $message, ?string $subject = null, array $evidence = [], ?string $remedy = null): self
    {
        return new self($code, Severity::Info, $message, $subject, $evidence, $remedy);
    }

    public static function warning(string $code, string $message, ?string $subject = null, array $evidence = [], ?string $remedy = null): self
    {
        return new self($code, Severity::Warning, $message, $subject, $evidence, $remedy);
    }

    public static function blocker(string $code, string $message, ?string $subject = null, array $evidence = [], ?string $remedy = null): self
    {
        return new self($code, Severity::Blocker, $message, $subject, $evidence, $remedy);
    }

    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'severity' => $this->severity->value,
            'subject' => $this->subject,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'remedy' => $this->remedy,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
