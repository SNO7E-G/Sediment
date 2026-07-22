<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Analyzer\WordPressCore;

/**
 * The safety invariant (§13): no WordPress core option, table, or cron hook may
 * ever appear in a deletable result set, under any input. This is the assertion
 * the project's credibility rests on, so it runs as its own CI job.
 */
#[Group('core-protection')]
final class CoreProtectionTest extends TestCase
{
    public function test_allowlist_identifies_core_options(): void
    {
        self::assertTrue(WordPressCore::isCoreOption('siteurl'));
        self::assertTrue(WordPressCore::isCoreOption('active_plugins'));
        self::assertTrue(WordPressCore::isCoreOption('cron'));
        // Roles and plugin-lifecycle state — the most dangerous keys to delete.
        self::assertTrue(WordPressCore::isCoreOption('wp_user_roles'));
        self::assertTrue(WordPressCore::isCoreOption('{prefix}user_roles'));
        self::assertTrue(WordPressCore::isCoreOption('uninstall_plugins'));
        self::assertFalse(WordPressCore::isCoreOption('corewriter_settings'));
        self::assertFalse(WordPressCore::isCoreOption('woocommerce_db_version'));
    }

    public function test_allowlist_identifies_core_tables_case_insensitively(): void
    {
        self::assertTrue(WordPressCore::isCoreTable('{prefix}posts'));
        self::assertTrue(WordPressCore::isCoreTable('{PREFIX}Options'));
        self::assertFalse(WordPressCore::isCoreTable('{prefix}woocommerce_orders'));
    }

    public function test_allowlist_identifies_core_cron_hooks(): void
    {
        self::assertTrue(WordPressCore::isCoreCronHook('wp_version_check'));
        self::assertTrue(WordPressCore::isCoreCronHook('do_pings'));
        self::assertFalse(WordPressCore::isCoreCronHook('corewriter_sync'));
    }

    public function test_core_artifacts_never_enter_a_deletable_set(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/core-writer');

        // A deletable candidate is a confidently-attributed, non-core finding.
        $deletableKeys = [];
        foreach ($result['findings'] as $finding) {
            $confident = $finding->confidence === Finding::CONFIDENCE_VERIFIED
                || $finding->confidence === Finding::CONFIDENCE_RESOLVED;

            if ($confident && !WordPressCore::isCore($finding) && $finding->key !== null) {
                $deletableKeys[] = $finding->key;
            }
        }

        // The invariant: core artifacts are absent from the deletable set...
        self::assertNotContains('siteurl', $deletableKeys);
        self::assertNotContains('wp_user_roles', $deletableKeys);
        self::assertNotContains('wp_version_check', $deletableKeys);

        // ...while the plugin's own artifacts are present.
        self::assertContains('corewriter_settings', $deletableKeys);
        self::assertContains('corewriter_sync', $deletableKeys);
    }

    public function test_core_writes_are_still_detected_just_flagged_as_core(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/core-writer');

        $core = array_filter($result['findings'], static fn (Finding $f): bool => WordPressCore::isCore($f));
        $coreKeys = array_map(static fn (Finding $f): ?string => $f->key, $core);

        // Detection still sees them (useful signal); attribution just marks them core.
        self::assertContains('siteurl', $coreKeys);
        self::assertContains('wp_version_check', $coreKeys);
    }
}
