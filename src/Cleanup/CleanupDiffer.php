<?php

declare(strict_types=1);

namespace Sediment\Cleanup;

use Sediment\Analyzer\Finding;

/**
 * Diffs what a plugin creates against what it removes on uninstall (M8), setting
 * a per-item `cleaned` flag on every created finding.
 *
 * A removal only counts if it actually runs on uninstall: it lives in
 * `uninstall.php` (or a file it requires), or in a function reachable from
 * there or from a `register_uninstall_hook` callback. Matching is by exact key
 * within the same artifact type — a create is `cleaned` only when a scoped
 * removal names the same key. Under-crediting (marking something not-cleaned
 * when it might be) is the safe direction for a grade.
 *
 * Deactivation counts too, for state that is routinely cleared there.
 * WordPress deletes only inactive plugins, so a deactivation hook always runs
 * before uninstall, and clearing scheduled events and rewrite rules on
 * deactivation is the documented pattern.
 */
final class CleanupDiffer
{
    /**
     * Types a deactivation hook is credited for. Options, tables and content
     * deleted on deactivation are gone too, but that is data loss on a plain
     * deactivate and is often gated on a setting this pass does not read for
     * deactivation, so it is not credited.
     */
    private const DEACTIVATION_TYPES = ['cron' => true, 'action' => true, 'transient' => true, 'rewrite_rule' => true];

    /**
     * The `via` of a removal whose key is a regex over the keys a bulk
     * `DELETE FROM ... LIKE` removes, rather than one exact key.
     */
    public const LIKE = 'wpdb::query like';

    /**
     * @param list<Finding> $findings created artifacts
     * @param list<array{type: string, key: string, via: string, function: string|null, file: string}> $removals
     * @param array<string, true> $scopedFunctions functions that run on uninstall — see {@see scopedFunctions()}
     * @param list<array{type: string, function: string|null, file: string}> $blankets removals that clear a whole type
     * @param array<string, true> $uninstallFiles every file whose top-level code runs on uninstall — see {@see reachableUninstallFiles()}
     * @param array<string, true> $deactivationFunctions functions that run on deactivation — see {@see reachableFunctions()}
     * @return list<Finding> the same findings with `cleaned` set
     */
    public static function apply(array $findings, array $removals, array $scopedFunctions, array $blankets = [], array $uninstallFiles = [], array $deactivationFunctions = []): array
    {
        $runs = static fn (string $type, string $file, ?string $function): bool
            => self::runsOnUninstall($file, $function, $scopedFunctions, $uninstallFiles)
                || (isset(self::DEACTIVATION_TYPES[$type]) && $function !== null && isset($deactivationFunctions[strtolower($function)]));

        /** @var array<string, array<string, list<string>>> $removed type => key => removal calls */
        $removed = [];
        /** @var array<string, list<string>> $likes type => regexes over the keys a bulk DELETE removes */
        $likes = [];
        foreach ($removals as $removal) {
            if (!$runs($removal['type'], $removal['file'], $removal['function'])) {
                continue;
            }

            if ($removal['via'] === self::LIKE) {
                $likes[$removal['type']][] = $removal['key'];
            } else {
                $removed[$removal['type']][$removal['key']][] = $removal['via'];
            }
        }

        /** @var array<string, true> $clearedWholesale artifact types cleared in one call */
        $clearedWholesale = [];
        foreach ($blankets as $blanket) {
            if ($runs($blanket['type'], $blanket['file'], $blanket['function'])) {
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
            $result[] = $finding->withCleaned(
                ($via !== null && self::clears($finding, $via)) || self::deletedInBulk($finding, $likes),
            );
        }

        return $result;
    }

    /**
     * Whether a bulk `DELETE FROM ... LIKE` removes this key's row. A transient
     * lives in the options table under `_transient_<key>` (`_site_transient_`
     * network-wide), so an options delete matching that row clears it.
     * Pattern and dynamic keys are never matched: their real names are unknown.
     *
     * @param array<string, list<string>> $likes
     */
    private static function deletedInBulk(Finding $finding, array $likes): bool
    {
        if ($finding->key === null || !$finding->isConfident()) {
            return false;
        }

        [$type, $rows] = $finding->type === 'transient'
            ? ['option', ['_transient_' . $finding->key, '_site_transient_' . $finding->key]]
            : [$finding->type, [$finding->key]];

        foreach ($likes[$type] ?? [] as $regex) {
            foreach ($rows as $row) {
                if (preg_match($regex, $row) === 1) {
                    return true;
                }
            }
        }

        return false;
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
     * @param list<array{option: string|null, default: bool|string|null, function: string|null, file: string, exits?: bool, removes?: bool, calls?: list<string>}> $guards
     * @param array<string, true> $scopedFunctions functions that run on uninstall
     * @param list<array{type: string, key: string, via: string, function: string|null, file: string}> $removals
     * @param array<string, true> $uninstallFiles every file whose top-level code runs on uninstall — see {@see reachableUninstallFiles()}
     * @return array{option: string|null, default: bool|string|null}|null
     */
    public static function condition(array $guards, array $scopedFunctions, array $removals = [], array $uninstallFiles = []): ?array
    {
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
     * Every function that runs on uninstall: the uninstall callbacks, whatever
     * top-level code in an uninstall file calls, and everything those call in
     * turn.
     *
     * @param list<string> $callbacks register_uninstall_hook callbacks
     * @param list<array{from: string|null, file: string, to: string}> $calls
     * @param array<string, true> $uninstallFiles
     * @return array<string, true> lowercased identifiers
     */
    public static function scopedFunctions(array $callbacks, array $calls, array $uninstallFiles = []): array
    {
        $roots = $callbacks;
        foreach ($calls as $call) {
            if ($call['from'] === null && (self::isUninstallFile($call['file']) || isset($uninstallFiles[self::normalizeFile($call['file'])]))) {
                $roots[] = $call['to'];
            }
        }

        return self::reachableFunctions($roots, $calls);
    }

    /**
     * The transitive closure of the call graph from a set of entry points.
     * PHP callables are case-insensitive, so identifiers are compared lowercased.
     *
     * @param list<string> $roots
     * @param list<array{from: string|null, file: string, to: string}> $calls
     * @return array<string, true>
     */
    public static function reachableFunctions(array $roots, array $calls): array
    {
        $outgoing = [];
        foreach ($calls as $call) {
            if ($call['from'] !== null) {
                $outgoing[strtolower($call['from'])][strtolower($call['to'])] = true;
            }
        }

        $reachable = [];
        $queue = array_map('strtolower', $roots);
        while ($queue !== []) {
            $function = array_pop($queue);
            if (isset($reachable[$function])) {
                continue;
            }

            $reachable[$function] = true;
            foreach ($outgoing[$function] ?? [] as $next => $true) {
                $queue[] = $next;
            }
        }

        return $reachable;
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
