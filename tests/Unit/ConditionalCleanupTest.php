<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

/**
 * Conditional cleanup (§10, grade B): a plugin whose uninstall routine is gated
 * behind a stored setting removes everything on paper and nothing in practice,
 * because that setting defaults to off.
 */
final class ConditionalCleanupTest extends TestCase
{
    /** @return array{findings: list<Finding>, cleanup: array<string, mixed>, files: list<string>, errors: list<array{file: string, message: string}>} */
    private function scan(string $fixture): array
    {
        return (new Scanner())->scan(dirname(__DIR__) . '/fixtures/' . $fixture);
    }

    public function test_it_detects_the_gating_option_and_its_default(): void
    {
        $cleanup = $this->scan('conditional-plugin')['cleanup'];

        self::assertTrue($cleanup['conditional']);
        self::assertSame('cnd_delete_data_on_uninstall', $cleanup['condition_option']);
        self::assertFalse($cleanup['condition_default']);
    }

    public function test_a_conditionally_clean_plugin_grades_B_not_A(): void
    {
        $scan = $this->scan('conditional-plugin');
        $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);

        self::assertSame('B', $grade->letter);
        self::assertSame(0, $grade->leftBehind, 'the code does remove everything — that is why it is B and not D');
        self::assertStringContainsString('cnd_delete_data_on_uninstall', $grade->summary);
        self::assertStringContainsString('off by default', $grade->summary);
    }

    public function test_unconditional_cleanup_still_grades_A(): void
    {
        $scan = $this->scan('clean-plugin');

        self::assertFalse($scan['cleanup']['conditional']);
        self::assertSame('A', (new Grader())->grade($scan['findings'], $scan['cleanup'])->letter);
    }

    public function test_a_guard_outside_the_uninstall_path_does_not_make_cleanup_conditional(): void
    {
        // partial-plugin reads no option in its uninstall path.
        self::assertFalse($this->scan('partial-plugin')['cleanup']['conditional']);
    }

    public function test_an_if_that_reads_an_option_but_gates_nothing_is_not_a_condition(): void
    {
        // The uninstall routine branches on an option for a migration, then
        // cleans up unconditionally. That is an A, not a B.
        $scan = $this->scan('unrelated-guard-plugin');

        self::assertFalse($scan['cleanup']['conditional']);
        self::assertNull($scan['cleanup']['condition_option']);
        self::assertSame('A', (new Grader())->grade($scan['findings'], $scan['cleanup'])->letter);
    }

    public function test_a_wrapper_style_gate_is_detected_too(): void
    {
        // if (get_option('x')) { delete_option(...); } — no early return, but the
        // removal sits inside the guard.
        $scan = $this->scan('wrapper-guard-plugin');

        self::assertTrue($scan['cleanup']['conditional']);
        self::assertSame('wgp_remove_data', $scan['cleanup']['condition_option']);
        self::assertSame('B', (new Grader())->grade($scan['findings'], $scan['cleanup'])->letter);
    }

    public function test_the_condition_is_reported_in_the_manifest(): void
    {
        $scan = $this->scan('conditional-plugin');
        $manifest = Manifest::build($scan, (new Grader())->grade($scan['findings'], $scan['cleanup']), 'x/conditional-plugin', '2026-07-26T00:00:00Z');

        self::assertTrue($manifest['cleanup']['conditional']);
        self::assertSame('cnd_delete_data_on_uninstall', $manifest['cleanup']['condition_option']);
        self::assertFalse($manifest['cleanup']['condition_default']);
        self::assertSame('B', $manifest['grade']);
    }
}
