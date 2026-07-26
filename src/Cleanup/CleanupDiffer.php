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
     * @param list<array{type: string, key: string, via: string, function: string|null, file: string}> $removals
     * @param list<string> $callbacks uninstall callback identifiers
     * @param list<string> $uninstallCalls functions called at the top level of uninstall.php
     * @return list<Finding> the same findings with `cleaned` set
     */
    public static function apply(array $findings, array $removals, array $callbacks, array $uninstallCalls = []): array
    {
        $scopedFunctions = self::scopedFunctions($callbacks, $uninstallCalls);

        /** @var array<string, array<string, list<string>>> $removed type => key => removal calls */
        $removed = [];
        foreach ($removals as $removal) {
            if (self::runsOnUninstall($removal['file'], $removal['function'], $scopedFunctions)) {
                $removed[$removal['type']][$removal['key']][] = $removal['via'] ?? '';
            }
        }

        $result = [];
        foreach ($findings as $finding) {
            $via = ($finding->key !== null ? $removed[$finding->type][$finding->key] ?? null : null);
            $result[] = $finding->withCleaned($via !== null && self::clears($finding, $via));
        }

        return $result;
    }

    /**
     * Whether the removal calls that name this key actually clear it.
     *
     * The one case where naming the key is not enough is a cron event scheduled
     * with arguments: `wp_clear_scheduled_hook($hook)` only removes events
     * registered with no arguments, so an event scheduled with them survives and
     * keeps firing. Clearing that needs `wp_unschedule_hook()`, which removes
     * every event for the hook regardless of arguments.
     *
     * @param list<string> $via the removal functions naming this key
     */
    private static function clears(Finding $finding, array $via): bool
    {
        if ($finding->type !== 'cron' || !$finding->hasArgs) {
            return true;
        }

        return in_array('wp_unschedule_hook', $via, true);
    }

    /**
     * The setting a plugin's cleanup is gated on, or null when cleanup is
     * unconditional. Only guards that themselves run on uninstall count.
     *
     * @param list<array{option: string, default: bool|string|null, function: string|null, file: string}> $guards
     * @param list<string> $callbacks
     * @param list<string> $uninstallCalls
     * @return array{option: string, default: bool|string|null}|null
     */
    public static function condition(array $guards, array $callbacks, array $uninstallCalls = []): ?array
    {
        $scopedFunctions = self::scopedFunctions($callbacks, $uninstallCalls);

        foreach ($guards as $guard) {
            if (self::runsOnUninstall($guard['file'], $guard['function'], $scopedFunctions)) {
                return ['option' => $guard['option'], 'default' => $guard['default']];
            }
        }

        return null;
    }

    /**
     * Code runs on uninstall when it is a top-level statement in the plugin-root
     * uninstall.php, or lives in a registered uninstall callback or a function
     * that uninstall.php invokes at top level.
     *
     * @param array<string, true> $scopedFunctions
     */
    private static function runsOnUninstall(string $file, ?string $function, array $scopedFunctions): bool
    {
        return $function === null
            ? self::isUninstallFile($file)
            : isset($scopedFunctions[strtolower($function)]);
    }

    /**
     * PHP callables are case-insensitive, so identifiers are compared lowercased.
     *
     * @param list<string> $callbacks
     * @param list<string> $uninstallCalls
     * @return array<string, true>
     */
    private static function scopedFunctions(array $callbacks, array $uninstallCalls): array
    {
        return array_fill_keys(array_map('strtolower', array_merge($callbacks, $uninstallCalls)), true);
    }

    public static function isUninstallFile(string $file): bool
    {
        // WordPress runs only the plugin-root uninstall.php (relative path
        // "uninstall.php"), never a nested one — so only that credits cleanup.
        return strtolower(str_replace('\\', '/', $file)) === 'uninstall.php';
    }
}
