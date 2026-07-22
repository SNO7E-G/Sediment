<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * The outcome of statically resolving an expression to an artifact key (§8).
 *
 * - verified / resolved: {@see $value} holds the full literal key.
 * - pattern: {@see $value} holds the stable leading prefix (the key is
 *   reported as `prefix*`).
 * - dynamic: nothing resolved; {@see $raw} holds the pretty-printed source so a
 *   human can still see and, later, correct it.
 */
final class Resolution
{
    public function __construct(
        public readonly string $confidence,
        public readonly ?string $value = null,
        public readonly ?string $raw = null,
    ) {
    }

    public static function verified(string $value): self
    {
        return new self(Finding::CONFIDENCE_VERIFIED, $value);
    }

    public static function resolved(string $value): self
    {
        return new self(Finding::CONFIDENCE_RESOLVED, $value);
    }

    public static function pattern(string $prefix, ?string $raw = null): self
    {
        return new self(Finding::CONFIDENCE_PATTERN, $prefix, $raw);
    }

    public static function dynamic(?string $raw = null): self
    {
        return new self(Finding::CONFIDENCE_DYNAMIC, null, $raw);
    }

    public function isResolved(): bool
    {
        return $this->confidence === Finding::CONFIDENCE_VERIFIED
            || $this->confidence === Finding::CONFIDENCE_RESOLVED;
    }

    /**
     * The key as it should appear in a finding: the literal for verified/resolved,
     * `prefix*` for a pattern, and null when nothing could be resolved.
     */
    public function key(): ?string
    {
        return match ($this->confidence) {
            Finding::CONFIDENCE_VERIFIED, Finding::CONFIDENCE_RESOLVED => $this->value,
            Finding::CONFIDENCE_PATTERN => ($this->value ?? '') . '*',
            default => null,
        };
    }
}
