<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * Turns a write whose key is a wrapper's parameter into the keys its callers
 * actually pass.
 *
 * `update_option($key, $value)` inside a plugin's own settings helper is
 * unresolvable on its own, and Sediment reported it as `dynamic` — honest, but
 * it meant the largest plugins, which are exactly the ones that wrap, were the
 * ones it could say least about.
 *
 * Expanded findings are `resolved`, never `verified`: the key is a literal, but
 * it reached the write through a call rather than being written at it.
 */
final class WrapperExpander
{
    /**
     * @param array{literals: list<string>, complete: bool}|null $known keys the callers supply
     * @return list<Finding> the original finding, or one per key its callers pass
     */
    public static function expand(Finding $finding, ?array $known): array
    {
        if ($known === null || $finding->isConfident()) {
            return [$finding];
        }

        $expanded = [];
        foreach ($known['literals'] as $key) {
            $expanded[] = $finding->withKey($key, Finding::CONFIDENCE_RESOLVED);
        }

        // When some call site passed something unreadable, the unresolved write
        // stays in the report alongside the keys that were found. Dropping it
        // would let a wrapper called once with a literal and once with a runtime
        // value read as fully understood.
        if (!$known['complete']) {
            $expanded[] = $finding;
        }

        return $expanded;
    }
}
