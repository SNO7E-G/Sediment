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
     * @param list<array{type: string, function: string|null, file: string}> $blankets removals that clear a whole type
     * @param array<string, true> $uninstallFiles every file whose top-level code runs on uninstall — see {@see reachableUninstallFiles()}
     * @return list<Finding> the same findings with `cleaned` set
     */
    public static function apply(array $findings, array $removals, array $callbacks, array $uninstallCalls = [], array $blankets = [], array $uninstallFiles = []): array
    {
        $scopedFunctions = self::scopedFunctions($callbacks, $uninstallCalls);

        /** @var array<string, array<string, list<string>>> $removed type => key => removal calls */
        $removed = [];
        foreach ($removals as $removal) {
            if (self::runsOnUninstall($removal['file'], $removal['function'], $scopedFunctions, $uninstallFiles)) {
                $removed[$removal['type']][$removal['key']][] = $removal['via'] ?? '';
            }
        }

        /** @var array<string, true> $clearedWholesale artifact types cleared in one call */
        $clearedWholesale = [];
        foreach ($blankets as $blanket) {
            if (self::runsOnUninstall($blanket['file'], $blanket['function'], $scopedFunctions, $uninstallFiles)) {
                $clearedWholesale[$blanket['type']] = true;
            }
        }

        $result = [];
        foreach ($findings as $finding) {
            if (isset($clearedWholesale[$finding->type])) {
                $result[] = $finding->withCleaned(true);
                continue;
            }

            $via = ($finding->key !== null ? $removed[$finding->type][$finding->key] ?? null : null);
            $result[] = $finding->withCleaned($via !== null && self::clears($finding, $via));
        }

        return $result;
    }

    /**
     * Whether the removal calls that name this key actually clear it.
     *
     * The one case where naming the key is not enough is an event scheduled
     * with arguments. `wp_clear_scheduled_hook($hook)` only removes cron events
     * registered with no arguments, and Action Scheduler's
     * `as_unschedule_action($hook)` matches pending actions by their arguments
     * the same way — so an event scheduled with them survives and keeps firing.
     * Clearing those needs the blanket calls, `wp_unschedule_hook()` and
     * `as_unschedule_all_actions()`, which remove every event for the hook
     * regardless of arguments.
     *
     * @param list<string> $via the removal functions naming this key
     */
    private static function clears(Finding $finding, array $via): bool
    {
        if (!$finding->hasArgs) {
            return true;
        }

        return match ($finding->type) {
            'cron'   => in_array('wp_unschedule_hook', $via, true),
            'action' => in_array('as_unschedule_all_actions', $via, true),
            default  => true,
        };
    }

    /**
     * The setting a plugin's cleanup is gated on, or null when cleanup is
     * unconditional.
     *
     * A guard counts only if it runs on uninstall AND actually decides whether
     * cleanup happens — it bails out early, wraps a removal, or calls a function
     * that is itself part of the uninstall path (`if (get_option('x')) {
     * my_cleanup(); }`). An `if` that merely reads an option without gating
     * anything is not a condition, and must not cost a clean plugin its A.
     *
     * @param list<array{option: string, default: bool|string|null, function: string|null, file: string, exits?: bool, removes?: bool, calls?: list<string>}> $guards
     * @param list<string> $callbacks
     * @param list<string> $uninstallCalls
     * @param list<array{type: string, key: string, via: string, function: string|null, file: string}> $removals
     * @param array<string, true> $uninstallFiles every file whose top-level code runs on uninstall — see {@see reachableUninstallFiles()}
     * @return array{option: string, default: bool|string|null}|null
     */
    public static function condition(array $guards, array $callbacks, array $uninstallCalls = [], array $removals = [], array $uninstallFiles = []): ?array
    {
        $scopedFunctions = self::scopedFunctions($callbacks, $uninstallCalls);
        $cleanupFunctions = self::functionsThatRemove($removals, $scopedFunctions);

        foreach ($guards as $guard) {
            if (!self::runsOnUninstall($guard['file'], $guard['function'], $scopedFunctions, $uninstallFiles)) {
                continue;
            }

            $gates = ($guard['exits'] ?? false)
                || ($guard['removes'] ?? false)
                || self::callsCleanup($guard['calls'] ?? [], $cleanupFunctions);

            if ($gates) {
                return ['option' => $guard['option'], 'default' => $guard['default']];
            }
        }

        return null;
    }

    /**
     * Functions that both run on uninstall and actually remove something. Calling
     * one from inside an `if` is what makes that `if` a gate — calling a function
     * that removes nothing (logging, a migration) is not.
     *
     * @param list<array{type: string, key: string, via: string, function: string|null, file: string}> $removals
     * @param array<string, true> $scopedFunctions
     * @return array<string, true>
     */
    private static function functionsThatRemove(array $removals, array $scopedFunctions): array
    {
        $functions = [];
        foreach ($removals as $removal) {
            if ($removal['function'] !== null && self::runsOnUninstall($removal['file'], $removal['function'], $scopedFunctions)) {
                $functions[strtolower($removal['function'])] = true;
            }
        }

        return $functions;
    }

    /**
     * @param list<string> $calls
     * @param array<string, true> $cleanupFunctions
     */
    private static function callsCleanup(array $calls, array $cleanupFunctions): bool
    {
        foreach ($calls as $call) {
            if (isset($cleanupFunctions[strtolower($call)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Code runs on uninstall when it is a top-level statement in the plugin-root
     * uninstall.php, in a file uninstall.php requires (transitively — a real
     * teardown is often split across files), in a registered uninstall callback,
     * or in a function that uninstall.php invokes at top level.
     *
     * @param array<string, true> $scopedFunctions
     * @param array<string, true> $uninstallFiles
     */
    private static function runsOnUninstall(string $file, ?string $function, array $scopedFunctions, array $uninstallFiles = []): bool
    {
        return $function === null
            ? self::isUninstallFile($file) || isset($uninstallFiles[self::normalizeFile($file)])
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

    /**
     * Every file whose top-level code runs on uninstall: the plugin-root
     * uninstall.php plus everything it pulls in with require/include,
     * transitively. Keys are normalized file paths; an edge naming a file that
     * does not exist simply matches nothing later.
     *
     * @param list<array{from: string, to: string}> $edges require edges as recorded by the cleanup visitor
     * @return array<string, true>
     */
    public static function reachableUninstallFiles(array $edges): array
    {
        $outgoing = [];
        foreach ($edges as $edge) {
            $outgoing[self::normalizeFile($edge['from'])][self::normalizeFile($edge['to'])] = true;
        }

        $reachable = ['uninstall.php' => true];
        $queue = ['uninstall.php'];
        while ($queue !== []) {
            foreach ($outgoing[array_pop($queue)] ?? [] as $next => $true) {
                if (!isset($reachable[$next])) {
                    $reachable[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $reachable;
    }

    /** Forward slashes, lowercased — the form every comparison here uses. */
    private static function normalizeFile(string $file): string
    {
        return strtolower(str_replace('\\', '/', $file));
    }

    public static function isUninstallFile(string $file): bool
    {
        // WordPress runs only the plugin-root uninstall.php (relative path
        // "uninstall.php"), never a nested one — so only that credits cleanup.
        return self::normalizeFile($file) === 'uninstall.php';
    }
}
