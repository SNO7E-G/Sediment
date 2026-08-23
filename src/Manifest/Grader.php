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
 * never count. A plugin whose cleanup is gated behind a stored setting is graded
 * B rather than A, and the score is held inside the band its letter allows.
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
        // A queued Action Scheduler job behaves like a cron event: it keeps
        // firing a hook whose callback is gone.
        'action'       => 12,
        // A directory of logs or exports sits on disk forever, but costs nothing
        // per request. A rewrite rule is one entry in a single option.
        'directory'    => 7,
        'rewrite_rule' => 2,
    ];

    /** Below this many leftover light items a plugin can still reach grade C. */
    private const MINOR_LEFTOVER_LIMIT = 5;

    /**
     * Conditionally clean: the code removes everything, so this sits close to A,
     * but whether it runs depends on a setting — which is why it is not A.
     */
    private const CONDITIONAL_SCORE = 90;

    /**
     * The highest score each letter may carry, so the number never contradicts
     * the letter. Without this a C plugin with one stray transient scores 97 and
     * outranks a B, which reads as a bug on any leaderboard.
     */
    private const SCORE_CEILING = ['A' => 100, 'B' => 90, 'C' => 85, 'D' => 65, 'F' => 49];

    /**
     * @param list<Finding> $findings
     * @param array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional?: bool, condition_option?: string|null, condition_default?: bool|string|null} $cleanup
     */
    public function grade(array $findings, array $cleanup): Grade
    {
        // Grade by unique artifact (type:key), not by call site, and only on
        // confidently-attributed, non-core creates.
        $confident = $this->uniqueConfidentCreates($findings);

        if ($confident === []) {
            $unresolved = $this->hasUnresolved($findings);

            return self::make(
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
            return self::make('F', $this->score($left), $cleaned, count($left), 'No uninstall routine — everything it creates is left behind.');
        }

        if ($left === []) {
            // Conditionally clean (§10): the code removes everything, but only
            // when a stored setting allows it — so on a site where that setting
            // was never touched, nothing may be removed at all. Technically
            // clean, practically uncertain, and worth naming rather than
            // folding into A.
            if (($cleanup['conditional'] ?? false) === true) {
                return self::make(
                    'B',
                    self::CONDITIONAL_SCORE,
                    $cleaned,
                    0,
                    $this->describeConditional($cleanup),
                );
            }

            return self::make('A', 100, $cleaned, 0, 'Removes everything it creates on uninstall.');
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
            return self::make('D', $score, $cleaned, count($left), $this->describeHeavy($tables, $autoloaded, $cron, $postTypes));
        }

        if (count($left) < self::MINOR_LEFTOVER_LIMIT) {
            return self::make(
                'C',
                $score,
                $cleaned,
                count($left),
                sprintf('Leaves %d minor item(s) behind — none autoloaded, no tables or cron.', count($left)),
            );
        }

        return self::make('D', $score, $cleaned, count($left), sprintf('Leaves %d items behind.', count($left)));
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

            // Collapse duplicates conservatively: the key counts as cleaned only
            // when EVERY write of it is cleaned. The same hook scheduled with and
            // without arguments is one key with two fates — an args-less clear
            // removes only one of them, so "any cleaned" would report a plugin as
            // spotless while an event keeps firing.
            $cleaned = $existing->cleaned === true && $finding->cleaned === true;
            // Keep the worst autoload claim: 'yes' over 'unknown' over 'no', so
            // the grade never depends on which duplicate happened to sort first.
            $keep = match (true) {
                $finding->autoload === 'yes' => $finding,
                $existing->autoload === 'yes' => $existing,
                $finding->autoload === 'unknown' => $finding,
                default => $existing,
            };
            $byKey[$id] = $keep->withCleaned($cleaned);
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

    /**
     * @param array{conditional?: bool, condition_option?: string|null, condition_default?: bool|string|null} $cleanup
     */
    private function describeConditional(array $cleanup): string
    {
        $option = $cleanup['condition_option'] ?? null;
        if ($option === null) {
            return 'Removes everything it creates, but only when a stored setting allows it.';
        }

        // The polarity of the gate is not inspected — "delete my data" and "keep
        // my data" are both common — so the wording states which setting decides,
        // not which way it has to be set. Claiming a direction Sediment did not
        // check would be a confident falsehood in half the cases.
        return sprintf(
            'Removes everything it creates, but only when the "%s" setting allows it%s.',
            $option,
            array_key_exists('condition_default', $cleanup) && $cleanup['condition_default'] !== null
                ? sprintf(' (it defaults to %s)', self::describeDefault($cleanup['condition_default']))
                : '',
        );
    }

    /**
     * Build a grade, holding the score inside the band its letter allows so the
     * number and the letter can never tell different stories.
     */
    private static function make(string $letter, int $score, int $cleaned, int $leftBehind, string $summary): Grade
    {
        return new Grade($letter, min($score, self::SCORE_CEILING[$letter]), $cleaned, $leftBehind, $summary);
    }

    private static function describeDefault(bool|string $default): string
    {
        if (is_bool($default)) {
            return $default ? 'true' : 'false';
        }

        return $default === '' ? 'an empty value' : '"' . $default . '"';
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
