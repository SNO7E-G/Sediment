<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * A single detected artifact write (an option created, a cron event scheduled,
 * a transient set, ...), with the confidence of its attribution.
 *
 * Maps toward the per-item structure in the manifest schema (§9). `cleaned` and
 * multi-source aggregation are added by the cleanup-diff stage; this is the
 * raw finding a visitor emits.
 */
final class Finding
{
    public const CONFIDENCE_VERIFIED = 'verified';
    public const CONFIDENCE_RESOLVED = 'resolved';
    public const CONFIDENCE_PATTERN  = 'pattern';
    public const CONFIDENCE_DYNAMIC  = 'dynamic';

    public function __construct(
        public readonly string $type,        // artifact type: 'option' | 'table' | 'cron' | 'transient'
        public readonly string $function,    // the write call, e.g. 'add_option'
        public readonly ?string $key,        // resolved key (with '*' for pattern), or null when dynamic
        public readonly string $confidence,  // one of the CONFIDENCE_* levels (§8)
        public readonly string $file,        // plugin-relative path
        public readonly int $line,
        public readonly ?string $autoload = null,   // 'yes' | 'no' | 'unknown' for options; null when N/A
        public readonly ?string $expression = null, // pretty-printed source when unresolved
        public readonly ?string $recurrence = null, // cron recurrence, e.g. 'daily' | 'single'; null when N/A
        public readonly ?bool $cleaned = null,      // set by the cleanup diff; null until then
        public readonly bool $hasArgs = false,      // cron scheduled with arguments (changes how it must be cleared)
    ) {
    }

    /** Whether the key is confidently attributed (safe to act on): verified or resolved. */
    public function isConfident(): bool
    {
        return $this->confidence === self::CONFIDENCE_VERIFIED
            || $this->confidence === self::CONFIDENCE_RESOLVED;
    }

    /** Whether this write lands in network-wide storage rather than the current site's. */
    public function isNetworkScoped(): bool
    {
        return self::isNetworkCall($this->function);
    }

    /**
     * Whether a write or removal call works on network-wide storage. On
     * multisite, `update_site_option()` and `set_site_transient()` write the
     * sitemeta table while `update_option()` writes each site's own options
     * table, so a removal clears a write only when both name the same scope.
     * (On a single site the two share a table, but a removal that is right
     * only there is not credited.)
     */
    public static function isNetworkCall(string $function): bool
    {
        $function = strtolower($function);

        return str_contains($function, 'site_option')
            || str_contains($function, 'network_option')
            || str_contains($function, 'site_transient');
    }

    /**
     * The worse of two autoload claims for the same option: 'yes' over
     * 'unknown' over 'no'. Null — a network option, which WordPress never
     * autoloads — ranks with 'no'. The manifest and the grader both merge
     * duplicate writes through this, so they cannot disagree about a key.
     */
    public static function worseAutoload(?string $a, ?string $b): ?string
    {
        $rank = static fn (?string $autoload): int => match ($autoload) {
            'yes' => 2,
            'unknown' => 1,
            default => 0,
        };

        return $rank($b) > $rank($a) ? $b : $a;
    }

    /**
     * Return a copy naming a key the write's callers supply — the same call
     * site, now attributed to one of the keys that actually reaches it.
     */
    public function withKey(string $key, string $confidence): self
    {
        return $this->copy($key, $confidence, $this->cleaned);
    }

    /** Return a copy with the cleanup verdict set (§8, M8). */
    public function withCleaned(?bool $cleaned): self
    {
        return $this->copy($this->key, $this->confidence, $cleaned);
    }

    /** Readonly properties cannot be set on a clone, so copies come through here. */
    private function copy(?string $key, string $confidence, ?bool $cleaned): self
    {
        return new self(
            $this->type,
            $this->function,
            $key,
            $confidence,
            $this->file,
            $this->line,
            $this->autoload,
            $this->expression,
            $this->recurrence,
            $cleaned,
            $this->hasArgs,
        );
    }
}
