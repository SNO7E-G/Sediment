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

    /** Below this many leftover light items a plugin can still reach grade C. */
    private const MINOR_LEFTOVER_LIMIT = 5;

    /**
     * @param list<Finding> $findings
     * @param array{has_uninstall_php: bool, has_uninstall_hook: bool} $cleanup
     */
    public function grade(array $findings, array $cleanup): Grade
    {
        $confident = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => self::isConfident($f) && !WordPressCore::isCore($f),
        ));

        if ($confident === []) {
            return new Grade('A', 100, 0, 0, 'Creates no persistent data that needs cleaning up.');
        }

        $left = array_values(array_filter($confident, static fn (Finding $f): bool => $f->cleaned !== true));
        $cleaned = count($confident) - count($left);
        $score = $this->score($left);
        $hasPath = $cleanup['has_uninstall_php'] || $cleanup['has_uninstall_hook'];

        if (!$hasPath) {
            return new Grade('F', $score, $cleaned, count($left), 'No uninstall routine — everything it creates is left behind.');
        }

        if ($left === []) {
            return new Grade('A', 100, $cleaned, 0, 'Removes everything it creates on uninstall.');
        }

        $tables = $this->countType($left, 'table');
        $cron = $this->countType($left, 'cron');
        $autoloaded = count(array_filter(
            $left,
            static fn (Finding $f): bool => $f->type === 'option' && $f->autoload === 'yes',
        ));

        if ($tables > 0 || $autoloaded > 0 || $cron > 0) {
            return new Grade('D', $score, $cleaned, count($left), $this->describeHeavy($tables, $autoloaded, $cron));
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
            default     => self::WEIGHT_OPTION,
        };
    }

    /**
     * @param list<Finding> $findings
     */
    private function countType(array $findings, string $type): int
    {
        return count(array_filter($findings, static fn (Finding $f): bool => $f->type === $type));
    }

    private function describeHeavy(int $tables, int $autoloaded, int $cron): string
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

        return 'Leaves ' . implode(', ', $parts) . ' behind.';
    }

    private static function isConfident(Finding $finding): bool
    {
        return $finding->confidence === Finding::CONFIDENCE_VERIFIED
            || $finding->confidence === Finding::CONFIDENCE_RESOLVED;
    }
}
