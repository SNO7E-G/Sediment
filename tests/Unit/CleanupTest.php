<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;

/**
 * The cleanup diff (M7, M8): every created artifact is marked cleaned/not-cleaned
 * by matching it against removals that actually run on uninstall — those in
 * uninstall.php or in a register_uninstall_hook callback.
 */
final class CleanupTest extends TestCase
{
    /**
     * @return array{findings: array<string, Finding>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool}}
     */
    private function scan(string $fixture): array
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/' . $fixture);

        $byKey = [];
        foreach ($result['findings'] as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->type . ':' . $finding->key] = $finding;
            }
        }

        return ['findings' => $byKey, 'cleanup' => $result['cleanup']];
    }

    public function test_a_fully_clean_plugin_marks_every_artifact_cleaned(): void
    {
        $scan = $this->scan('clean-plugin');

        self::assertTrue($scan['cleanup']['has_uninstall_php']);
        foreach (['option:cp_settings', 'table:{prefix}cp_logs', 'cron:cp_cron', 'transient:cp_cache'] as $id) {
            self::assertArrayHasKey($id, $scan['findings'], "missing $id");
            self::assertTrue($scan['findings'][$id]->cleaned, "$id should be cleaned");
        }
    }

    public function test_cleanup_via_register_uninstall_hook_callback_is_credited(): void
    {
        $scan = $this->scan('hook-clean-plugin');

        self::assertTrue($scan['cleanup']['has_uninstall_hook']);
        self::assertFalse($scan['cleanup']['has_uninstall_php']);
        self::assertTrue($scan['findings']['option:hcp_opt']->cleaned);
        self::assertTrue($scan['findings']['cron:hcp_cron']->cleaned);
    }

    public function test_partial_cleanup_marks_only_what_is_removed(): void
    {
        $scan = $this->scan('partial-plugin');

        self::assertTrue($scan['findings']['option:pp_settings']->cleaned);
        self::assertFalse($scan['findings']['table:{prefix}pp_data']->cleaned);
        self::assertFalse($scan['findings']['cron:pp_cron']->cleaned);
    }

    public function test_a_runtime_removal_outside_the_uninstall_path_does_not_credit_cleanup(): void
    {
        $scan = $this->scan('partial-plugin');

        // pp_temp is delete_option'd in pp_reset() — a normal method, not uninstall.
        self::assertArrayHasKey('option:pp_temp', $scan['findings']);
        self::assertFalse($scan['findings']['option:pp_temp']->cleaned);
    }

    public function test_a_dead_function_in_uninstall_php_does_not_credit_cleanup(): void
    {
        $scan = $this->scan('dead-uninstall-plugin');

        // du_dead is never called, so du_settings is not actually removed...
        self::assertFalse($scan['findings']['option:du_settings']->cleaned);
        // ...but du_run() is called at the top level, so du_other is.
        self::assertTrue($scan['findings']['option:du_other']->cleaned);
    }

    public function test_a_removal_in_a_same_short_named_class_in_another_namespace_is_not_credited(): void
    {
        $scan = $this->scan('ns-callback-plugin');

        // The delete lives in Acme\Admin\Cleaner::run, but Acme\Cleaner::run is
        // the registered (empty) callback — different classes, no credit.
        self::assertFalse($scan['findings']['option:nc_settings']->cleaned);
    }

    public function test_uninstall_callback_matching_is_case_insensitive(): void
    {
        $scan = $this->scan('case-hook-plugin');

        // Registered as 'CH_Uninstall', defined as ch_uninstall().
        self::assertTrue($scan['findings']['option:ch_opt']->cleaned);
    }

    public function test_top_level_code_in_files_required_by_uninstall_php_is_credited(): void
    {
        $scan = $this->scan('uninstall-require-plugin');

        // uninstall.php requires includes/tasks.php, which deletes urp_first at
        // top level and requires more-deletes.php one hop deeper for urp_second.
        self::assertTrue($scan['findings']['option:urp_first']->cleaned);
        self::assertTrue($scan['findings']['option:urp_second']->cleaned);

        // A dead function inside a required file is still dead.
        self::assertFalse($scan['findings']['option:urp_dead']->cleaned);
    }

    public function test_a_plugin_with_no_uninstall_path_cleans_nothing(): void
    {
        $scan = $this->scan('dirty-plugin');

        self::assertFalse($scan['cleanup']['has_uninstall_php']);
        self::assertFalse($scan['cleanup']['has_uninstall_hook']);
        foreach ($scan['findings'] as $finding) {
            self::assertFalse($finding->cleaned, "{$finding->type}:{$finding->key} should not be cleaned");
        }
    }
}
