<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Analyzer\WordPressCore;
use Sediment\Generator\UninstallGenerator;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

/**
 * Regressions for the ways Sediment could over-claim: reporting something as
 * cleaned, owned, or safe to delete when the source does not prove it.
 */
final class ReviewRegressionTest extends TestCase
{
    /** @return array{findings: list<Finding>, cleanup: array<string, mixed>, files: list<string>, errors: list<array{file: string, message: string}>} */
    private function scan(string $fixture): array
    {
        return (new Scanner())->scan(dirname(__DIR__) . '/fixtures/' . $fixture);
    }

    private function grade(string $fixture): \Sediment\Manifest\Grade
    {
        $scan = $this->scan($fixture);

        return (new Grader())->grade($scan['findings'], $scan['cleanup']);
    }

    public function test_a_hook_scheduled_twice_is_clean_only_when_both_are_cleared(): void
    {
        // dsp_sync is scheduled with and without arguments; the uninstall clears
        // only the argument-less form, so the plugin is NOT clean.
        $scan = $this->scan('double-schedule-plugin');
        $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);

        self::assertNotSame('A', $grade->letter, 'an event that keeps firing cannot be an A');
        self::assertSame(1, $grade->leftBehind);

        $manifest = Manifest::build($scan, $grade, 'x/double-schedule-plugin', '2026-07-26T00:00:00Z');
        self::assertFalse($manifest['creates']['cron'][0]['cleaned']);
        self::assertCount(2, $manifest['creates']['cron'][0]['sources'], 'both schedule calls are recorded');
    }

    public function test_a_clear_that_passes_arguments_does_not_credit_an_argless_event(): void
    {
        // wp_clear_scheduled_hook('h', array(42)) clears only events registered
        // with those arguments — not the one this plugin schedules.
        $scan = $this->scan('clear-with-args-plugin');

        foreach ($scan['findings'] as $finding) {
            if ($finding->type === 'cron') {
                self::assertFalse($finding->cleaned);
            }
        }

        self::assertNotSame('A', (new Grader())->grade($scan['findings'], $scan['cleanup'])->letter);
    }

    public function test_action_scheduler_jobs_can_be_cleaned(): void
    {
        // Without a matching removal these types could never be credited, capping
        // a correctly-behaving plugin at C forever.
        self::assertSame('A', $this->grade('action-clean-plugin')->letter);
    }

    public function test_flushing_rewrite_rules_cleans_them(): void
    {
        self::assertSame('A', $this->grade('rewrite-clean-plugin')->letter);
    }

    public function test_a_table_name_cut_short_by_a_dynamic_tail_is_not_invented(): void
    {
        // "CREATE TABLE {$wpdb->prefix}dtp_logs{$suffix}" resolves only as far as
        // {prefix}dtp_logs — which is NOT the table's name. Reporting it would
        // make the generator drop a table the plugin never created.
        $scan = $this->scan('dynamic-table-plugin');

        $tableKeys = array_map(
            static fn (Finding $f): ?string => $f->key,
            array_values(array_filter($scan['findings'], static fn (Finding $f): bool => $f->type === 'table')),
        );

        self::assertNotContains('{prefix}dtp_logs', $tableKeys, 'the name was cut mid-word and must not be claimed');

        $code = (new UninstallGenerator())->generate($scan['findings'], 'dtp');
        self::assertStringNotContainsString('dtp_logs', $code, 'nothing may be dropped on a truncated name');
    }

    public function test_wordpress_own_directories_are_not_blamed_on_the_plugin(): void
    {
        self::assertTrue(WordPressCore::isCoreDirectory('{content_dir}/uploads'));
        self::assertTrue(WordPressCore::isCoreDirectory('{content_dir}'));
        self::assertFalse(WordPressCore::isCoreDirectory('{content_dir}/acme-logs'));

        $core = new Finding(
            type: 'directory',
            function: 'wp_mkdir_p',
            key: '{content_dir}/uploads',
            confidence: Finding::CONFIDENCE_VERIFIED,
            file: 'plugin.php',
            line: 1,
            cleaned: false,
        );

        self::assertTrue(WordPressCore::isCore($core));
        self::assertSame('A', (new Grader())->grade([$core], ['has_uninstall_php' => false, 'has_uninstall_hook' => false])->letter);
        self::assertStringNotContainsString('uploads', (new UninstallGenerator())->generate([$core], 'Acme'));
    }

    public function test_scores_never_contradict_their_letter(): void
    {
        $ceilings = ['A' => 100, 'B' => 90, 'C' => 85, 'D' => 65, 'F' => 49];

        foreach (glob(dirname(__DIR__) . '/fixtures/*', GLOB_ONLYDIR) ?: [] as $path) {
            $scan = (new Scanner())->scan($path);
            $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);

            self::assertLessThanOrEqual(
                $ceilings[$grade->letter],
                $grade->score,
                sprintf('%s scored %d as a %s, which outranks a better letter', basename($path), $grade->score, $grade->letter),
            );
        }
    }

    public function test_an_unmapped_finding_type_fails_loudly_instead_of_vanishing(): void
    {
        $scan = [
            'files' => [],
            'errors' => [],
            'cleanup' => ['has_uninstall_php' => false, 'has_uninstall_hook' => false],
            'findings' => [new Finding(
                type: 'not_a_real_type',
                function: 'x',
                key: 'k',
                confidence: Finding::CONFIDENCE_VERIFIED,
                file: 'plugin.php',
                line: 1,
            )],
        ];

        $this->expectException(\LogicException::class);
        Manifest::build($scan, (new Grader())->grade([], $scan['cleanup']), 'x/p', '2026-07-26T00:00:00Z');
    }
}
