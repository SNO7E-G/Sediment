<?php

declare(strict_types=1);

namespace Sediment\Manifest;

use Sediment\Analyzer\Finding;

/**
 * Compares a scan against a manifest saved earlier, so a plugin author can see
 * what a release changed about their database footprint (§11).
 *
 * What matters, in order: an artifact that is new, one that used to be cleaned
 * up and no longer is, an option that now loads on every request, the grade
 * moving, and the scan seeing less of the plugin than before. Everything else
 * is noise.
 */
final class ManifestDiff
{
    /**
     * @param array<string, mixed> $before a previously emitted manifest
     * @param array<string, mixed> $after
     * @return array{
     *     added: list<string>, removed: list<string>, no_longer_cleaned: list<string>,
     *     newly_cleaned: list<string>, autoload_raised: list<string>, coverage_dropped: list<string>,
     *     grade_before: string, grade_after: string, regressed: bool
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
        $autoloadRaised = [];
        foreach (array_intersect_key($new, $old) as $id => $item) {
            if ($old[$id]['cleaned'] && !$item['cleaned']) {
                $noLongerCleaned[] = $id;
            } elseif (!$old[$id]['cleaned'] && $item['cleaned']) {
                $newlyCleaned[] = $id;
            }

            // An option going from `no` to `yes` moves from the lightest weight
            // to the heaviest: it now loads on every request of every page.
            $autoload = $item['autoload'];
            if ($autoload !== null && $old[$id]['autoload'] !== null
                && Finding::worseAutoload($old[$id]['autoload'], $autoload) !== $old[$id]['autoload']) {
                $autoloadRaised[] = $id;
            }
        }

        sort($added);
        sort($removed);
        sort($noLongerCleaned);
        sort($newlyCleaned);
        sort($autoloadRaised);

        $gradeBefore = (string) ($before['grade'] ?? '?');
        $gradeAfter = (string) ($after['grade'] ?? '?');
        $coverageDropped = self::coverageDropped($before, $after);

        return [
            'added' => $added,
            'removed' => $removed,
            'no_longer_cleaned' => $noLongerCleaned,
            'newly_cleaned' => $newlyCleaned,
            'autoload_raised' => $autoloadRaised,
            'coverage_dropped' => $coverageDropped,
            'grade_before' => $gradeBefore,
            'grade_after' => $gradeAfter,
            // Anything new that is not cleaned up, anything that stopped being
            // cleaned up, an option left behind that now autoloads, a worse
            // grade, or a scan that saw less. Adding an artifact you also remove
            // on uninstall is not a regression, and neither is autoloading one.
            'regressed' => $noLongerCleaned !== []
                || self::worse($gradeBefore, $gradeAfter)
                || $coverageDropped !== []
                || array_filter($added, static fn (string $id): bool => !$new[$id]['cleaned']) !== []
                || array_filter($autoloadRaised, static fn (string $id): bool => !$new[$id]['cleaned']) !== [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, array{cleaned: bool, autoload: string|null}> "group:key" => state
     */
    private static function flatten(array $manifest): array
    {
        $flat = [];
        foreach ((array) ($manifest['creates'] ?? []) as $group => $items) {
            foreach ((array) $items as $item) {
                if (is_array($item) && isset($item['key']) && is_scalar($item['key'])) {
                    $flat[$group . ':' . $item['key']] = [
                        'cleaned' => ($item['cleaned'] ?? false) === true,
                        'autoload' => isset($item['autoload']) && is_string($item['autoload']) ? $item['autoload'] : null,
                    ];
                }
            }
        }

        return $flat;
    }

    /**
     * A grade only covers what the scan could see, so a release that resolves
     * fewer write calls or skips more files can look cleaner while hiding more.
     * A baseline that predates a field is not compared on it.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string> what fell, as "measure: before → after"
     */
    private static function coverageDropped(array $before, array $after): array
    {
        $old = is_array($before['coverage'] ?? null) ? $before['coverage'] : [];
        $new = is_array($after['coverage'] ?? null) ? $after['coverage'] : [];
        $dropped = [];

        $rate = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? round((float) $value, 3) : null;
        [$rateBefore, $rateAfter] = [$rate($old['resolution_rate'] ?? null), $rate($new['resolution_rate'] ?? null)];
        if ($rateBefore !== null && $rateAfter !== null && $rateAfter < $rateBefore) {
            $dropped[] = sprintf('resolution rate: %.1f%% → %.1f%%', $rateBefore * 100, $rateAfter * 100);
        }

        $skippedBefore = $old['files_skipped'] ?? null;
        $skippedAfter = $new['files_skipped'] ?? null;
        if (is_int($skippedBefore) && is_int($skippedAfter) && $skippedAfter > $skippedBefore) {
            $dropped[] = sprintf('files skipped: %d → %d', $skippedBefore, $skippedAfter);
        }

        return $dropped;
    }

    private static function worse(string $before, string $after): bool
    {
        $order = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'F' => 4];

        return isset($order[$before], $order[$after]) && $order[$after] > $order[$before];
    }
}
