<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\Error;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Generator\UninstallGenerator;

final class UninstallGeneratorTest extends TestCase
{
    private function assertValidPhp(string $code): void
    {
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
            self::assertNotNull($ast);
        } catch (Error $e) {
            self::fail('generated code is not valid PHP: ' . $e->getMessage() . "\n" . $code);
        }
    }

    private function finding(
        string $type,
        ?string $key,
        string $confidence = Finding::CONFIDENCE_VERIFIED,
        ?bool $cleaned = false,
        string $function = 'add_option',
    ): Finding {
        return new Finding(
            type: $type,
            function: $function,
            key: $key,
            confidence: $confidence,
            file: 'plugin.php',
            line: 1,
            cleaned: $cleaned,
        );
    }

    public function test_it_emits_removals_for_every_deletable_artifact(): void
    {
        $code = (new UninstallGenerator())->generate([
            $this->finding('option', 'foo'),
            $this->finding('option', 'bar', function: 'add_site_option'),
            $this->finding('table', '{prefix}logs', Finding::CONFIDENCE_RESOLVED, function: 'dbDelta'),
            $this->finding('cron', 'sync', function: 'wp_schedule_event'),
            $this->finding('transient', 'cache', function: 'set_transient'),
            $this->finding('transient', 'netcache', function: 'set_site_transient'),
        ], 'Example');

        $this->assertValidPhp($code);
        self::assertStringContainsString('WP_UNINSTALL_PLUGIN', $code);
        self::assertStringContainsString("delete_option('foo');", $code);
        self::assertStringContainsString("delete_site_option('bar');", $code);
        self::assertStringContainsString("\$wpdb->prefix . 'logs'", $code);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $code);
        self::assertStringContainsString("wp_clear_scheduled_hook('sync');", $code);
        self::assertStringContainsString("delete_transient('cache');", $code);
        self::assertStringContainsString("delete_site_transient('netcache');", $code);
    }

    public function test_it_never_emits_cleaned_dynamic_pattern_or_core_artifacts(): void
    {
        $code = (new UninstallGenerator())->generate([
            $this->finding('option', 'already_gone', cleaned: true),
            $this->finding('option', null, Finding::CONFIDENCE_DYNAMIC),
            $this->finding('option', 'pat_*', Finding::CONFIDENCE_PATTERN),
            $this->finding('option', 'siteurl'), // WordPress core
            $this->finding('option', '{prefix}user_roles', Finding::CONFIDENCE_RESOLVED), // core roles
            $this->finding('option', 'keep_me'),
        ], 'Example');

        $this->assertValidPhp($code);
        self::assertStringContainsString("delete_option('keep_me');", $code);
        self::assertStringNotContainsString('already_gone', $code);
        self::assertStringNotContainsString('pat_', $code);
        self::assertStringNotContainsString('siteurl', $code);
        self::assertStringNotContainsString('user_roles', $code);
    }

    public function test_it_rebuilds_the_prefix_token_for_non_table_artifacts(): void
    {
        $code = (new UninstallGenerator())->generate([
            $this->finding('option', '{prefix}po_settings', Finding::CONFIDENCE_RESOLVED),
        ], 'Example');

        $this->assertValidPhp($code);
        self::assertStringContainsString('global $wpdb;', $code);
        self::assertStringContainsString("delete_option(\$wpdb->prefix . 'po_settings');", $code);
        self::assertStringNotContainsString('{prefix}', $code);
    }

    public function test_it_unschedules_action_scheduler_jobs_and_notes_what_it_will_not_remove(): void
    {
        $code = (new UninstallGenerator())->generate([
            $this->finding('action', 'acme_sync', function: 'as_schedule_recurring_action'),
            $this->finding('directory', '{content_dir}/acme-logs', Finding::CONFIDENCE_RESOLVED, function: 'wp_mkdir_p'),
            $this->finding('rewrite_rule', '^acme/([^/]+)/?$', function: 'add_rewrite_rule'),
        ], 'Acme');

        $this->assertValidPhp($code);

        // Unscheduling a queued job is safe and belongs in an uninstall routine,
        // but the library may already be gone.
        self::assertStringContainsString("function_exists('as_unschedule_all_actions')", $code);
        self::assertStringContainsString("as_unschedule_all_actions('acme_sync');", $code);

        // Deleting a directory or flushing routing is not the plugin's call, so
        // both are reported rather than executed.
        self::assertStringNotContainsString('rmdir', $code);
        self::assertStringNotContainsString('flush_rewrite_rules', $code);
        self::assertMatchesRegularExpression('/\/\/\s+- directory "\{content_dir\}\/acme-logs"/', $code);
        self::assertMatchesRegularExpression('/\/\/\s+- rewrite rule "\^acme/', $code);
    }

    public function test_a_hook_scheduled_with_and_without_arguments_uses_the_broader_clear(): void
    {
        $with = new Finding(
            type: 'cron',
            function: 'wp_schedule_event',
            key: 'acme_cron',
            confidence: Finding::CONFIDENCE_VERIFIED,
            file: 'plugin.php',
            line: 1,
            cleaned: false,
            hasArgs: true,
        );

        $code = (new UninstallGenerator())->generate([
            $with,
            $this->finding('cron', 'acme_cron', function: 'wp_schedule_event'), // same hook, no args
        ], 'Acme');

        $this->assertValidPhp($code);
        self::assertStringContainsString("wp_unschedule_hook('acme_cron');", $code);
        self::assertStringNotContainsString('wp_clear_scheduled_hook', $code);
    }

    public function test_empty_input_produces_a_valid_guarded_file(): void
    {
        $code = (new UninstallGenerator())->generate([], 'Empty');

        $this->assertValidPhp($code);
        self::assertStringContainsString('WP_UNINSTALL_PLUGIN', $code);
        self::assertStringNotContainsString('delete_option', $code);
    }

    public function test_generated_uninstall_targets_a_real_plugins_leftovers(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/partial-plugin');
        $code = (new UninstallGenerator())->generate($result['findings'], 'partial-plugin');

        $this->assertValidPhp($code);
        // The table and cron are left behind -> the generated file removes them.
        self::assertStringContainsString("\$wpdb->prefix . 'pp_data'", $code);
        self::assertStringContainsString("wp_clear_scheduled_hook('pp_cron');", $code);
        // pp_settings is already cleaned by the plugin -> not re-emitted.
        self::assertStringNotContainsString('pp_settings', $code);
    }
}
