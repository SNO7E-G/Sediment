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
        public readonly string $type,        // artifact type: 'option' | 'cron' | 'transient'
        public readonly string $function,    // the write call, e.g. 'add_option'
        public readonly ?string $key,        // resolved key (with '*' for pattern), or null when dynamic
        public readonly string $confidence,  // one of the CONFIDENCE_* levels (§8)
        public readonly string $file,        // plugin-relative path
        public readonly int $line,
        public readonly ?string $autoload = null,   // 'yes' | 'no' | 'unknown' for options; null when N/A
        public readonly ?string $expression = null, // pretty-printed source when unresolved
        public readonly ?string $recurrence = null, // cron recurrence, e.g. 'daily' | 'single'; null when N/A
    ) {
    }
}
