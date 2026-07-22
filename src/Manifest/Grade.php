<?php

declare(strict_types=1);

namespace Sediment\Manifest;

/**
 * A plugin's grade: the letter from the published rubric (§10) and the 0–100
 * weighted-damage score, with a one-line, defensible explanation.
 */
final class Grade
{
    public function __construct(
        public readonly string $letter,     // A | B | C | D | F
        public readonly int $score,         // 0–100, weighted by damage
        public readonly int $cleaned,       // confidently-attributed creates that are removed
        public readonly int $leftBehind,    // confidently-attributed creates left behind
        public readonly string $summary,
    ) {
    }
}
