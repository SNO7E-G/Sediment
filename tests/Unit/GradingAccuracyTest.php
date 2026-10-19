<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Analyzer\Sql\TableStatements;
use Sediment\Analyzer\WordPressCore;
use Sediment\Manifest\Grade;
use Sediment\Manifest\Grader;

/**
 * Regressions from the 0.11 grading review: cleanup real plugins perform that
 * went uncredited, gates that went unseen, and core data blamed on plugins.
 */
final class GradingAccuracyTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    /**
     * @param array<string, string> $files name => source
     * @return array{files: list<string>, findings: list<Finding>, errors: list<array{file: string, message: string, code: string}>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional: bool, condition_option: string|null, condition_default: bool|string|null}}
     */
    private function scan(array $files): array
    {
        $this->dir = sys_get_temp_dir() . '/sediment-grading-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0777, true);
        foreach ($files as $name => $source) {
            file_put_contents($this->dir . '/' . $name, $source);
        }

        return (new Scanner())->scan($this->dir);
    }

    /**
     * @param array{findings: list<Finding>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional?: bool, condition_option?: string|null, condition_default?: bool|string|null}} $scan
     */
    private static function grade(array $scan): Grade
    {
        return (new Grader())->grade($scan['findings'], $scan['cleanup']);
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, bool|null> "type:key" => cleaned
     */
    private static function cleaned(array $findings): array
    {
        $cleaned = [];
        foreach ($findings as $finding) {
            if ($finding->key !== null) {
                $cleaned[$finding->type . ':' . $finding->key] = $finding->cleaned;
            }
        }

        return $cleaned;
    }

    public function test_events_cleared_on_deactivation_are_cleaned(): void
    {
        // WordPress deletes only inactive plugins, so a deactivation hook always
        // runs first — and clearing cron there is the documented pattern.
        $scan = $this->scan(['plugin.php' => <<<'PHP'
            <?php
            register_activation_hook(__FILE__, function () { wp_schedule_event(time(), 'hourly', 'acme_sync'); });
            register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('acme_sync'); });
            register_deactivation_hook(__FILE__, array('Acme', 'deactivate'));
            class Acme { public static function deactivate() { flush_rewrite_rules(); wp_unschedule_hook('acme_hourly'); delete_option('acme_settings'); } }
            wp_schedule_event(time(), 'hourly', 'acme_hourly');
            add_rewrite_rule('^acme/?$', 'index.php?acme=1');
            add_option('acme_settings', [], '', 'no');
            PHP]);

        $cleaned = self::cleaned($scan['findings']);
        self::assertTrue($cleaned['cron:acme_sync'], 'a closure registered as the deactivation hook');
        self::assertTrue($cleaned['cron:acme_hourly'], 'a static method registered as the deactivation hook');
        self::assertTrue($cleaned['rewrite_rule:^acme/?$'], 'flush_rewrite_rules() on deactivation');
        self::assertFalse($cleaned['option:acme_settings'], 'data deleted on deactivation is not credited');
    }

    public function test_a_plugin_that_clears_everything_on_deactivation_is_not_an_F(): void
    {
        $scan = $this->scan(['plugin.php' => <<<'PHP'
            <?php
            wp_schedule_event(time(), 'daily', 'acme_daily');
            register_deactivation_hook(__FILE__, 'acme_off');
            function acme_off() { wp_clear_scheduled_hook('acme_daily'); }
            PHP]);

        self::assertSame('A', self::grade($scan)->letter);
    }

    public function test_cleanup_reached_through_methods_and_helpers_is_credited(): void
    {
        $scan = $this->scan([
            'plugin.php' => <<<'PHP'
                <?php
                add_option('un_a', 1, '', 'no'); add_option('un_b', 1, '', 'no'); add_option('un_c', 1, '', 'no');
                class Uninstaller {
                    public static function run() { delete_option('un_a'); self::more(); }
                    private static function more() { acme_clean(); }
                }
                function acme_clean() { acme_helper(); }
                function acme_helper() { delete_option('un_b'); }
                class Teardown { public function go() { $this->drop(); } private function drop() { delete_option('un_c'); } }
                function never_called() { delete_option('un_d'); }
                add_option('un_d', 1, '', 'no');
                PHP,
            'uninstall.php' => "<?php\nif (!defined('WP_UNINSTALL_PLUGIN')) { exit; }\nUninstaller::run();\n(new Teardown())->go();\n",
        ]);

        $cleaned = self::cleaned($scan['findings']);
        self::assertTrue($cleaned['option:un_a'], 'a static call from uninstall.php');
        self::assertTrue($cleaned['option:un_b'], 'two hops further, through plain functions');
        self::assertTrue($cleaned['option:un_c'], '(new X)->m() calling $this->m()');
        self::assertFalse($cleaned['option:un_d'], 'a function nothing on the uninstall path calls');
    }

    public function test_bulk_deletes_by_pattern_are_credited_and_narrowed_ones_are_not(): void
    {
        $scan = $this->scan([
            'plugin.php' => <<<'PHP'
                <?php
                add_option('rs_one', 1); set_transient('rs_cache', 1); update_post_meta(1, '_rs_m', 1);
                add_option('keep_me', 1); add_option('other', 1);
                global $wpdb;
                $wpdb->query("CREATE TABLE {$wpdb->prefix}t1 (id int)");
                $wpdb->query("CREATE TABLE {$wpdb->prefix}t2 (id int)");
                PHP,
            'uninstall.php' => <<<'PHP'
                <?php
                if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
                global $wpdb;
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rs\_%' OR option_name LIKE '\_transient\_rs\_%'");
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_rs_') . '%'));
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'keep%' AND autoload = 'no'");
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}t1, {$wpdb->prefix}t2");
                PHP,
        ]);

        $cleaned = self::cleaned($scan['findings']);
        self::assertTrue($cleaned['option:rs_one']);
        self::assertTrue($cleaned['transient:rs_cache'], 'its _transient_ row matches');
        self::assertTrue($cleaned['post_meta:_rs_m'], 'prepare() with esc_like()');
        self::assertFalse($cleaned['option:keep_me'], 'an AND can narrow the delete');
        self::assertFalse($cleaned['option:other']);
        self::assertTrue($cleaned['table:{prefix}t1']);
        self::assertTrue($cleaned['table:{prefix}t2'], 'every table in a DROP list');
    }

    public function test_a_truncated_drop_list_keeps_only_the_names_that_provably_ended(): void
    {
        self::assertSame(['{prefix}a'], TableStatements::dropped('DROP TABLE {prefix}a, {prefix}b', true));
        self::assertSame(['{prefix}a', '{prefix}b'], TableStatements::dropped('DROP TABLE {prefix}a, {prefix}b'));
    }

    public function test_a_loop_over_a_literal_array_resolves_every_key(): void
    {
        $scan = $this->scan([
            'plugin.php' => <<<'PHP'
                <?php
                foreach (['loop_a', 'loop_b'] as $o) { add_option($o, 1, '', 'no'); }
                $defaults = ['loop_c' => 1, 'loop_d' => 2];
                foreach ($defaults as $k => $v) { add_option('pre_' . $k, $v, '', 'no'); }
                foreach (['loop_e', 'loop_f'] as $last) { }
                add_option($last, 1);
                PHP,
            'uninstall.php' => "<?php\nif (!defined('WP_UNINSTALL_PLUGIN')) { exit; }\nforeach (['loop_a', 'loop_b', 'pre_loop_c', 'pre_loop_d'] as \$opt) { delete_option(\$opt); }\n",
        ]);

        $cleaned = self::cleaned($scan['findings']);
        foreach (['option:loop_a', 'option:loop_b', 'option:pre_loop_c', 'option:pre_loop_d'] as $id) {
            self::assertTrue($cleaned[$id] ?? null, $id);
        }
        self::assertArrayNotHasKey('option:loop_e', $cleaned, 'after the loop the variable holds only its last value');
        self::assertArrayNotHasKey('option:loop_f', $cleaned);
    }

    public function test_cleanup_gated_through_a_settings_layer_is_conditional(): void
    {
        $gated = $this->scan([
            'plugin.php' => "<?php\nadd_option('g_a', 1, '', 'no');\n",
            'uninstall.php' => "<?php\nif (!defined('WP_UNINSTALL_PLUGIN')) { exit; }\nif (!Settings::get('remove_data')) { return; }\ndelete_option('g_a');\n",
        ]);
        $grade = self::grade($gated);
        self::assertSame('B', $grade->letter);
        self::assertStringContainsString('remove_data', $grade->summary);
    }

    public function test_an_environment_check_is_not_a_cleanup_gate(): void
    {
        $scan = $this->scan([
            'plugin.php' => "<?php\nadd_option('g_a', 1, '', 'no');\n",
            'uninstall.php' => "<?php\nif (!defined('WP_UNINSTALL_PLUGIN')) { exit; }\nif (is_multisite()) { delete_site_option('g_a'); } else { delete_option('g_a'); }\nif (function_exists('acme_delete_data')) { acme_delete_data(); }\n",
        ]);

        self::assertSame('A', self::grade($scan)->letter);
    }

    public function test_core_roles_capabilities_and_taxonomies_are_not_the_plugins(): void
    {
        $scan = $this->scan(['plugin.php' => <<<'PHP'
            <?php
            wp_roles()->add_cap('administrator', 'manage_acme');
            get_role('editor')->add_cap('acme_edit');
            add_role('acme_manager', 'Acme', ['read' => true, 'acme_run' => true]);
            register_taxonomy('category', 'acme_cpt');
            add_option('comments_per_page', 5);
            update_user_meta(1, 'first_name', 'Ada');
            PHP]);

        $created = [];
        $core = [];
        foreach ($scan['findings'] as $finding) {
            if ($finding->key !== null) {
                WordPressCore::isCore($finding)
                    ? $core[] = $finding->type . ':' . $finding->key
                    : $created[] = $finding->type . ':' . $finding->key;
            }
        }
        sort($created);
        sort($core);

        self::assertSame(['capability:acme_edit', 'capability:acme_run', 'capability:manage_acme', 'role:acme_manager'], $created);
        self::assertSame(['capability:read', 'option:comments_per_page', 'taxonomy:category', 'user_meta:first_name'], $core);
    }
}
