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

    public function test_a_reported_name_with_a_newline_cannot_escape_its_comment(): void
    {
        // The content report is the one place a scanned name lands in the file
        // outside a var_export'd literal. A newline in a registered post type
        // or directory name must not end the comment and become live code.
        $hostile = $this->finding(
            'directory',
            "evil\nfile_put_contents('shell.php', 'x');\n//",
            Finding::CONFIDENCE_RESOLVED,
            false,
            'wp_mkdir_p',
        );

        $code = (new UninstallGenerator())->generate([$hostile], 'Example');

        $this->assertValidPhp($code);
        self::assertStringNotContainsString("\nfile_put_contents", $code);
        self::assertStringContainsString('//   - directory "evil file_put_contents', $code);
    }

    public function test_empty_input_produces_a_valid_guarded_file(): void
    {
        $code = (new UninstallGenerator())->generate([], 'Empty');

        $this->assertValidPhp($code);
        self::assertStringContainsString('WP_UNINSTALL_PLUGIN', $code);
        self::assertStringNotContainsString('delete_option', $code);
    }

    public function test_dropins_and_mu_plugins_are_deleted_with_their_roots_rebuilt(): void
    {
        // These files are code the plugin installed, not user data — deleting
        // them is what an uninstall is for. The roots come back as constants,
        // never hardcoded paths.
        $code = (new UninstallGenerator())->generate([
            $this->finding('dropin', '{content_dir}/advanced-cache.php', Finding::CONFIDENCE_RESOLVED, function: 'file_put_contents'),
            $this->finding('muplugin', '{mu_plugins}/acme-loader.php', Finding::CONFIDENCE_RESOLVED, function: 'put_contents'),
        ], 'Example');

        $this->assertValidPhp($code);
        self::assertStringContainsString("wp_delete_file(WP_CONTENT_DIR . '/advanced-cache.php');", $code);
        self::assertStringContainsString("wp_delete_file(WPMU_PLUGIN_DIR . '/acme-loader.php');", $code);
        // No {prefix} tokens anywhere, so no $wpdb line either.
        self::assertStringNotContainsString('$wpdb', $code);
    }

    public function test_leftover_capabilities_are_reported_not_executed(): void
    {
        // A capability lives inside a role's array, and which role received it
        // is not attributable statically — so the generator reports it with the
        // exact call to make, and never executes a guess.
        $code = (new UninstallGenerator())->generate([
            $this->finding('capability', 'acme_manage_things', Finding::CONFIDENCE_RESOLVED, function: 'add_cap'),
        ], 'Example');

        $this->assertValidPhp($code);
        self::assertStringContainsString('acme_manage_things', $code);
        // Every mention sits in a comment: no executable remove_cap() call.
        self::assertDoesNotMatchRegularExpression('/^(?!\/\/).*remove_cap\(/m', $code);
        self::assertMatchesRegularExpression('/^\/\/   remove_cap\(/m', $code);
    }

    public function test_a_backtick_in_a_table_name_cannot_break_out_of_the_drop_statement(): void
    {
        // A hostile or merely odd table name containing a backtick would close
        // the quoted identifier early and let whatever follows read as SQL.
        // The emitted code doubles it at run time, so even the site's prefix
        // cannot carry one through.
        $code = (new UninstallGenerator())->generate([
            $this->finding('table', '{prefix}evil`table', Finding::CONFIDENCE_RESOLVED, function: 'dbDelta'),
        ], 'Example');

        $this->assertValidPhp($code);
        self::assertStringContainsString("str_replace('`', '``'", $code);
        self::assertStringContainsString("'evil`table'", $code);
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
