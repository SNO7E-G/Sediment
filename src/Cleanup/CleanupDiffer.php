<?php

declare(strict_types=1);

namespace Sediment\Cleanup;

use Sediment\Analyzer\Finding;

/**
 * Diffs what a plugin creates against what it removes on uninstall (M8), setting
 * a per-item `cleaned` flag on every created finding.
 *
 * A removal only counts if it actually runs on uninstall: it lives in
 * `uninstall.php`, or inside a function/method registered via
 * `register_uninstall_hook`. Matching is by exact key within the same artifact
 * type — a create is `cleaned` only when a scoped removal names the same key.
 * Under-crediting (marking something not-cleaned when it might be) is the safe
 * direction for a grade.
 */
final class CleanupDiffer
{
    /**
     * @param list<Finding> $findings created artifacts
     * @param list<array{type: string, key: string, function: string|null, file: string}> $removals
     * @param list<string> $callbacks uninstall callback identifiers
     * @return list<Finding> the same findings with `cleaned` set
     */
    public static function apply(array $findings, array $removals, array $callbacks): array
    {
        $uninstallFunctions = array_fill_keys($callbacks, true);

        /** @var array<string, array<string, true>> $removed type => set of keys */
        $removed = [];
        foreach ($removals as $removal) {
            $inScope = self::isUninstallFile($removal['file'])
                || ($removal['function'] !== null && isset($uninstallFunctions[$removal['function']]));

            if ($inScope) {
                $removed[$removal['type']][$removal['key']] = true;
            }
        }

        $result = [];
        foreach ($findings as $finding) {
            $cleaned = $finding->key !== null && isset($removed[$finding->type][$finding->key]);
            $result[] = $finding->withCleaned($cleaned);
        }

        return $result;
    }

    public static function isUninstallFile(string $file): bool
    {
        // WordPress runs only the plugin-root uninstall.php (relative path
        // "uninstall.php"), never a nested one — so only that credits cleanup.
        return strtolower(str_replace('\\', '/', $file)) === 'uninstall.php';
    }
}
