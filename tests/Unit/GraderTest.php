<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;

final class GraderTest extends TestCase
{
    private function finding(
        string $type,
        ?bool $cleaned,
        string $confidence = Finding::CONFIDENCE_VERIFIED,
        ?string $autoload = null,
        string $function = 'add_option',
        string $key = 'k',
    ): Finding {
        return new Finding(
            type: $type,
            function: $function,
            key: $key,
            confidence: $confidence,
            file: 'plugin.php',
            line: 1,
            autoload: $autoload,
            cleaned: $cleaned,
        );
    }

    /** @return array{has_uninstall_php: bool, has_uninstall_hook: bool} */
    private function path(bool $has = true): array
    {
        return ['has_uninstall_php' => $has, 'has_uninstall_hook' => false];
    }

    public function test_duplicate_writes_keep_the_worst_autoload_claim_regardless_of_order(): void
    {
        // 'unknown' is graded as autoloaded — the safe direction. When the same
        // key is written once with autoload 'no' and once with 'unknown', the
        // grade must not depend on which write happened to be seen first.
        foreach ([['no', 'unknown'], ['unknown', 'no']] as [$first, $second]) {
            $grade = (new Grader())->grade([
                $this->finding('option', false, autoload: $first),
                $this->finding('option', false, autoload: $second),
            ], $this->path());

            self::assertSame('D', $grade->letter, "order {$first},{$second} must still count as autoloaded");
        }
    }

    public function test_everything_cleaned_is_A(): void
    {
        $grade = (new Grader())->grade([$this->finding('option', true, autoload: 'yes')], $this->path());

        self::assertSame('A', $grade->letter);
        self::assertSame(100, $grade->score);
    }

    public function test_no_uninstall_routine_is_F(): void
    {
        $grade = (new Grader())->grade([$this->finding('option', false)], $this->path(false));

        self::assertSame('F', $grade->letter);
    }

    public function test_left_table_is_D(): void
    {
        $grade = (new Grader())->grade([$this->finding('table', false, function: 'dbDelta')], $this->path());

        self::assertSame('D', $grade->letter);
        self::assertSame(65, $grade->score, 'a D score is held inside the D band');
    }

    public function test_left_autoloaded_option_is_D(): void
    {
        $grade = (new Grader())->grade([$this->finding('option', false, autoload: 'yes')], $this->path());

        self::assertSame('D', $grade->letter);
        self::assertSame(65, $grade->score, 'a D score is held inside the D band');
    }

    public function test_left_cron_is_D(): void
    {
        $grade = (new Grader())->grade([$this->finding('cron', false, function: 'wp_schedule_event')], $this->path());

        self::assertSame('D', $grade->letter);
    }

    public function test_minor_non_autoloaded_leftover_is_C(): void
    {
        $grade = (new Grader())->grade([$this->finding('option', false, autoload: 'no')], $this->path());

        self::assertSame('C', $grade->letter);
    }

    public function test_five_minor_leftovers_drop_to_D(): void
    {
        $left = [];
        for ($i = 0; $i < 5; $i++) {
            $left[] = $this->finding('option', false, autoload: 'no', key: "opt_$i");
        }

        self::assertSame('D', (new Grader())->grade($left, $this->path())->letter);
    }

    public function test_a_plugin_that_creates_nothing_is_A(): void
    {
        self::assertSame('A', (new Grader())->grade([], $this->path(false))->letter);
    }

    public function test_core_artifacts_do_not_affect_the_grade(): void
    {
        // Only a core option is "left" — it must be excluded, leaving nothing to grade.
        $grade = (new Grader())->grade([$this->finding('option', false, key: 'siteurl')], $this->path(false));

        self::assertSame('A', $grade->letter);
    }

    public function test_dynamic_findings_are_excluded_from_the_grade(): void
    {
        $grade = (new Grader())->grade(
            [$this->finding('option', false, confidence: Finding::CONFIDENCE_DYNAMIC, key: 'k')],
            $this->path(false),
        );

        // Nothing confidently attributed -> nothing to hold against the plugin.
        self::assertSame('A', $grade->letter);
    }

    public function test_grades_real_fixtures(): void
    {
        $grader = new Grader();

        $clean = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/clean-plugin');
        self::assertSame('A', $grader->grade($clean['findings'], $clean['cleanup'])->letter);

        $partial = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/partial-plugin');
        self::assertSame('D', $grader->grade($partial['findings'], $partial['cleanup'])->letter);

        $dirty = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/dirty-plugin');
        self::assertSame('F', $grader->grade($dirty['findings'], $dirty['cleanup'])->letter);
    }

    public function test_the_same_key_written_from_many_call_sites_counts_once(): void
    {
        $left = [];
        for ($i = 0; $i < 5; $i++) {
            $left[] = $this->finding('option', false, autoload: 'no', key: 'same_key');
        }

        $grade = (new Grader())->grade($left, $this->path());

        self::assertSame('C', $grade->letter, 'five writes of one key are one minor leftover, not five');
        self::assertSame(1, $grade->leftBehind);
    }

    public function test_unknown_autoload_option_is_treated_as_autoloaded(): void
    {
        $grade = (new Grader())->grade([$this->finding('option', false, autoload: 'unknown')], $this->path());

        self::assertSame('D', $grade->letter);
    }

    public function test_the_F_score_is_capped_so_letter_and_number_agree(): void
    {
        $grade = (new Grader())->grade([$this->finding('transient', false, function: 'set_transient')], $this->path(false));

        self::assertSame('F', $grade->letter);
        self::assertLessThan(50, $grade->score);
    }

    public function test_an_all_dynamic_plugin_reports_low_coverage_not_cleanliness(): void
    {
        $grade = (new Grader())->grade(
            [$this->finding('option', false, confidence: Finding::CONFIDENCE_DYNAMIC, key: 'k')],
            $this->path(false),
        );

        self::assertStringContainsString('could not be resolved', $grade->summary);
    }
}
