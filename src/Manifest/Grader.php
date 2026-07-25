<?php

declare(strict_types=1);

namespace Sediment\Manifest;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\WordPressCore;

/**
 * Turns a scan into a grade (§10). The letter follows the published rubric; the
 * score weights what is left behind by real-world damage, not by count — one
 * orphaned autoloaded option outweighs many small non-autoloaded rows.
 *
 * Grading considers only *confidently* attributed creates (verified/resolved):
 * a `dynamic` key is Sediment's blind spot, not evidence against the plugin, so
 * it is excluded and reported separately as coverage. WordPress core artifacts
 * never count. Grade B (conditionally clean) awaits conditional-gate detection;
 * until then a fully-clean plugin is graded A.
 */
final class Grader
{
    private const WEIGHT_TABLE = 15;
    private const WEIGHT_CRON = 12;
    private const WEIGHT_OPTION_AUTOLOAD = 18;
    private const WEIGHT_OPTION_MAYBE_AUTOLOAD = 8;
    private const WEIGHT_OPTION = 4;
    private const WEIGHT_TRANSIENT = 3;

    /**
     * A registered post type left behind orphans its content as unreachable rows
     * in wp_posts — often tens of thousands — so it weighs like a table. Metadata
     * multiplies per object, roles and capabilities ride on every user.
     */
    private const WEIGHT_BY_TYPE = [
        'post_type'    => 15,
        'taxonomy'     => 9,
        'post_meta'    => 10,
        'user_meta'    => 8,
        'term_meta'    => 6,
        'comment_meta' => 6,
        'role'         => 8,
        'capability'   => 5,
    ];

    /** Artifact types heavy enough to cap a plugin at grade D (§10). */
    private const HEAVY_TYPES = ['table', 'cron', 'post_type'];

    /** Below this many leftover light items a plugin can still reach grade C. */
    private const MINOR_LEFTOVER_LIMIT = 5;

    /**
     * @param list<Finding> $findings
     * @param array{has_uninstall_php: bool, has_uninstall_hook: bool} $cleanup
     */
    public function grade(array $findings, array $cleanup): Grade
    {
        // Grade by unique artifact (type:key), not by call site, and only on
        // confidently-attributed, non-core creates.
        $confident = $this->uniqueConfidentCreates($findings);

        if ($confident === []) {
            $unresolved = $this->hasUnresolved($findings);

            return new Grade(
                'A',
                100,
                0,
                0,
                $unresolved
                    ? 'No high-confidence artifacts to grade; some writes could not be resolved — see coverage.'
                    : 'Creates no persistent data that needs cleaning up.',
            );
        }

        $left = array_values(array_filter($confident, static fn (Finding $f): bool => $f->cleaned !== true));
        $cleaned = count($confident) - count($left);
        $hasPath = $cleanup['has_uninstall_php'] || $cleanup['has_uninstall_hook'];

        if (!$hasPath) {
            // No teardown at all — cap the score so the letter and number agree.
            return new Grade('F', min($this->score($left), 49), $cleaned, count($left), 'No uninstall routine — everything it creates is left behind.');
        }

        if ($left === []) {
            return new Grade('A', 100, $cleaned, 0, 'Removes everything it creates on uninstall.');
        }

        $score = $this->score($left);
        $tables = $this->countType($left, 'table');
        $cron = $this->countType($left, 'cron');
        $postTypes = $this->countType($left, 'post_type');
        // 'unknown' autoload is treated as autoloaded for the grade — the safe direction.
        $autoloaded = count(array_filter(
            $left,
            static fn (Finding $f): bool => $f->type === 'option' && ($f->autoload === 'yes' || $f->autoload === 'unknown'),
        ));

        if ($tables > 0 || $autoloaded > 0 || $cron > 0 || $postTypes > 0) {
            return new Grade('D', $score, $cleaned, count($left), $this->describeHeavy($tables, $autoloaded, $cron, $postTypes));
        }

        if (count($left) < self::MINOR_LEFTOVER_LIMIT) {
            return new Grade(
                'C',
                $score,
                $cleaned,
                count($left),
                sprintf('Leaves %d minor item(s) behind — none autoloaded, no tables or cron.', count($left)),
            );
        }

        return new Grade('D', $score, $cleaned, count($left), sprintf('Leaves %d items behind.', count($left)));
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding> one representative per type:key (preferring the
     *         cleaned/most-damaging instance), core and unresolvable excluded
     */
    private function uniqueConfidentCreates(array $findings): array
    {
        $byKey = [];
        foreach ($findings as $finding) {
            if ($finding->key === null || !$finding->isConfident() || WordPressCore::isCore($finding)) {
                continue;
            }

            $id = $finding->type . ':' . $finding->key;
            $existing = $byKey[$id] ?? null;

            if ($existing === null) {
                $byKey[$id] = $finding;
                continue;
            }

            // Collapse duplicates: cleaned if any is cleaned; keep the heavier autoload.
            $cleaned = $existing->cleaned === true || $finding->cleaned === true;
            $keep = $finding->autoload === 'yes' ? $finding : $existing;
            $byKey[$id] = $keep->withCleaned($cleaned ? true : $keep->cleaned);
        }

        return array_values($byKey);
    }

    /**
     * @param list<Finding> $findings
     */
    private function hasUnresolved(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (!$finding->isConfident()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Finding> $left
     */
    private function score(array $left): int
    {
        $penalty = 0;
        foreach ($left as $finding) {
            $penalty += $this->weight($finding);
        }

        return max(0, 100 - $penalty);
    }

    private function weight(Finding $finding): int
    {
        return match ($finding->type) {
            'table'     => self::WEIGHT_TABLE,
            'cron'      => self::WEIGHT_CRON,
            'transient' => self::WEIGHT_TRANSIENT,
            'option'    => match ($finding->autoload) {
                'yes'     => self::WEIGHT_OPTION_AUTOLOAD,
                'unknown' => self::WEIGHT_OPTION_MAYBE_AUTOLOAD,
                default   => self::WEIGHT_OPTION,
            },
            default     => self::WEIGHT_BY_TYPE[$finding->type] ?? self::WEIGHT_OPTION,
        };
    }

    /**
     * @param list<Finding> $findings
     */
    private function countType(array $findings, string $type): int
    {
        return count(array_filter($findings, static fn (Finding $f): bool => $f->type === $type));
    }

    private function describeHeavy(int $tables, int $autoloaded, int $cron, int $postTypes = 0): string
    {
        $parts = [];
        if ($tables > 0) {
            $parts[] = sprintf('%d table(s)', $tables);
        }
        if ($autoloaded > 0) {
            $parts[] = sprintf('%d autoloaded option(s)', $autoloaded);
        }
        if ($cron > 0) {
            $parts[] = sprintf('%d cron event(s)', $cron);
        }
        if ($postTypes > 0) {
            $parts[] = sprintf('%d post type(s) with orphaned content', $postTypes);
        }

        return 'Leaves ' . implode(', ', $parts) . ' behind.';
    }
}
