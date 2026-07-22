<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * A single detected artifact write (an option created, a table built, ...).
 *
 * Spike-level shape. As the symbol table and confidence classifier land this
 * grows toward the per-item structure in the manifest schema (§9): autoload
 * flag, cleaned flag, multiple sources, resolved-vs-pattern keys.
 */
final class Finding
{
    public const CONFIDENCE_VERIFIED = 'verified';
    public const CONFIDENCE_RESOLVED = 'resolved';
    public const CONFIDENCE_PATTERN  = 'pattern';
    public const CONFIDENCE_DYNAMIC  = 'dynamic';

    public function __construct(
        public readonly string $type,        // artifact type, e.g. 'option'
        public readonly string $function,    // the write call, e.g. 'add_option'
        public readonly ?string $key,        // resolved key, or null when dynamic
        public readonly string $confidence,  // one of the CONFIDENCE_* levels (§8)
        public readonly string $file,        // plugin-relative path
        public readonly int $line,
        public readonly ?string $expression = null, // raw source when unresolved
    ) {
    }
}
