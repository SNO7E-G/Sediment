<?php

declare(strict_types=1);

namespace Sediment\Manifest;

/**
 * Compares a scan against a manifest saved earlier, so a plugin author can see
 * what a release changed about their database footprint (§11).
 *
 * Three things matter, in order: an artifact that is new, one that used to be
 * cleaned up and no longer is, and the grade moving. Everything else is noise.
 */
final class ManifestDiff
{
    /**
     * @param array<string, mixed> $before a previously emitted manifest
     * @param array<string, mixed> $after
     * @return array{
     *     added: list<string>, removed: list<string>, no_longer_cleaned: list<string>,
     *     newly_cleaned: list<string>, grade_before: string, grade_after: string, regressed: bool
     * }
     */
    public static function between(array $before, array $after): array
    {
        $old = self::flatten($before);
        $new = self::flatten($after);

        $added = array_values(array_diff(array_keys($new), array_keys($old)));
        $removed = array_values(array_diff(array_keys($old), array_keys($new)));

        $noLongerCleaned = [];
        $newlyCleaned = [];
        foreach (array_intersect_key($new, $old) as $id => $cleaned) {
            if ($old[$id] === true && $cleaned === false) {
                $noLongerCleaned[] = $id;
            } elseif ($old[$id] === false && $cleaned === true) {
                $newlyCleaned[] = $id;
            }
        }

        sort($added);
        sort($removed);
        sort($noLongerCleaned);
        sort($newlyCleaned);

        $gradeBefore = (string) ($before['grade'] ?? '?');
        $gradeAfter = (string) ($after['grade'] ?? '?');

        return [
            'added' => $added,
            'removed' => $removed,
            'no_longer_cleaned' => $noLongerCleaned,
            'newly_cleaned' => $newlyCleaned,
            'grade_before' => $gradeBefore,
            'grade_after' => $gradeAfter,
            // Anything new that is not cleaned up, anything that stopped being
            // cleaned up, or a worse grade. Adding an artifact you also remove on
            // uninstall is not a regression.
            'regressed' => $noLongerCleaned !== []
                || self::worse($gradeBefore, $gradeAfter)
                || array_filter($added, static fn (string $id): bool => $new[$id] === false) !== [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, bool> "group:key" => cleaned
     */
    private static function flatten(array $manifest): array
    {
        $flat = [];
        foreach ((array) ($manifest['creates'] ?? []) as $group => $items) {
            foreach ((array) $items as $item) {
                if (is_array($item) && isset($item['key'])) {
                    $flat[$group . ':' . $item['key']] = ($item['cleaned'] ?? false) === true;
                }
            }
        }

        return $flat;
    }

    private static function worse(string $before, string $after): bool
    {
        $order = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'F' => 4];

        return isset($order[$before], $order[$after]) && $order[$after] > $order[$before];
    }
}
