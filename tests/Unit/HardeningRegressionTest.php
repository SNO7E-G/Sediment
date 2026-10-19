<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Generator\UninstallGenerator;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Symfony\Component\Process\Process;

/**
 * Regressions from the 0.12 hardening pass: scope, multisite teardown, and
 * the autoload rule the manifest and the grader share.
 */
final class HardeningRegressionTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            foreach (glob($path . '/*') ?: [] as $file) {
                @unlink($file);
            }
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    /**
     * @param array<string, string> $files name => source
     * @return array{files: list<string>, findings: list<Finding>, errors: list<array{file: string, message: string, code: string}>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional: bool, condition_option: string|null, condition_default: bool|string|null}}
     */
    private function scan(array $files): array
    {
        $dir = sys_get_temp_dir() . '/sediment-hardening-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0777, true);
        $this->paths[] = $dir;
        foreach ($files as $name => $source) {
            file_put_contents($dir . '/' . $name, $source);
        }

        return (new Scanner())->scan($dir);
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, bool|null> "function:key" => cleaned
     */
    private static function cleanedByCall(array $findings): array
    {
        $cleaned = [];
        foreach ($findings as $finding) {
            $cleaned[$finding->function . ':' . $finding->key] = $finding->cleaned;
        }

        return $cleaned;
    }

    public function test_a_removal_credits_only_a_write_in_the_same_scope(): void
    {
        // On multisite, delete_site_option() clears sitemeta and
        // delete_option() clears the site's options table: neither removes
        // the other's row.
        $scan = $this->scan([
            'plugin.php' => <<<'PHP'
                <?php
                update_option('acme_blog', 1);
                update_site_option('acme_net', 1);
                set_transient('acme_t', 1, 60);
                set_site_transient('acme_st', 1, 60);
                update_option('acme_ok', 1);
                update_network_option(null, 'acme_net_ok', 1);
                set_site_transient('acme_bulk', 1, 60);
                PHP,
            'uninstall.php' => <<<'PHP'
                <?php
                if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
                delete_site_option('acme_blog');
                delete_option('acme_net');
                delete_site_transient('acme_t');
                delete_transient('acme_st');
                delete_option('acme_ok');
                delete_site_option('acme_net_ok');
                global $wpdb;
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_site\_transient\_acme\_bulk'");
                PHP,
        ]);

        $expected = [
            'update_option:acme_blog' => false,
            'update_site_option:acme_net' => false,
            'set_transient:acme_t' => false,
            'set_site_transient:acme_st' => false,
            'update_option:acme_ok' => true,
            'update_network_option:acme_net_ok' => true,
            // A site transient lives in sitemeta on a network; an options-table
            // delete reaches it only on a single site.
            'set_site_transient:acme_bulk' => false,
        ];
        $actual = self::cleanedByCall($scan['findings']);
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    public function test_the_manifest_and_the_grader_merge_autoload_the_same_way(): void
    {
        // Written once with autoload off and once with it left as it was: the
        // manifest used to say "no" while the grader weighed "unknown".
        $scan = $this->scan(['plugin.php' => <<<'PHP'
            <?php
            add_option('acme_mixed', 1, '', 'no');
            update_option('acme_mixed', 2);
            update_site_option('acme_network', 1);
            PHP]);

        $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);
        /** @var array{creates: array{options: list<array{key: string, autoload: string}>}} $manifest */
        $manifest = Manifest::build($scan, $grade, 'plugin', '2026-01-01T00:00:00Z');
        $options = $manifest['creates']['options'];
        $autoload = array_column($options, 'autoload', 'key');

        self::assertSame('unknown', $autoload['acme_mixed']);
        self::assertSame('no', $autoload['acme_network'], 'WordPress never autoloads a network option');

        // The grader weighs the merged key exactly as it weighs a single
        // unknown-autoload write of it.
        $single = $this->scan(['plugin.php' => "<?php
update_option('acme_mixed', 2);
update_site_option('acme_network', 1);
"]);
        self::assertSame((new Grader())->grade($single['findings'], $single['cleanup'])->score, $grade->score);
    }

    public function test_generated_uninstall_tears_down_every_site_on_a_network(): void
    {
        $code = (new UninstallGenerator())->generate([
            new Finding(type: 'option', function: 'update_option', key: 'acme_opt', confidence: Finding::CONFIDENCE_VERIFIED, file: 'p.php', line: 1, autoload: 'yes'),
            new Finding(type: 'table', function: 'dbDelta', key: '{prefix}acme_log', confidence: Finding::CONFIDENCE_VERIFIED, file: 'p.php', line: 2),
            new Finding(type: 'option', function: 'update_site_option', key: 'acme_net', confidence: Finding::CONFIDENCE_VERIFIED, file: 'p.php', line: 3),
            new Finding(type: 'user_meta', function: 'update_user_meta', key: 'acme_um', confidence: Finding::CONFIDENCE_VERIFIED, file: 'p.php', line: 4),
        ], 'acme');

        $generated = sys_get_temp_dir() . '/sediment-multisite-' . bin2hex(random_bytes(3)) . '.php';
        $runner = $generated . '.runner.php';
        $this->paths[] = $generated;
        $this->paths[] = $runner;
        file_put_contents($generated, $code);
        file_put_contents($runner, <<<'PHP'
            <?php
            define('WP_UNINSTALL_PLUGIN', true);
            $GLOBALS['calls'] = [];
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public function query(string $sql): void { $GLOBALS['calls'][] = $sql; }
            };
            function is_multisite(): bool { return true; }
            function get_sites(array $args): array { $GLOBALS['calls'][] = 'get_sites ' . json_encode($args); return ['1', '2']; }
            function switch_to_blog(int $id): void { $GLOBALS['wpdb']->prefix = $id === 1 ? 'wp_' : "wp_{$id}_"; $GLOBALS['calls'][] = "switch {$id}"; }
            function restore_current_blog(): void { $GLOBALS['wpdb']->prefix = 'wp_'; $GLOBALS['calls'][] = 'restore'; }
            function delete_option(string $k): void { $GLOBALS['calls'][] = "delete_option {$k}"; }
            function delete_site_option(string $k): void { $GLOBALS['calls'][] = "delete_site_option {$k}"; }
            function delete_metadata(string $type, int $id, string $k): void { $GLOBALS['calls'][] = "delete_metadata {$type} {$k}"; }
            (static function () use ($argv): void { include $argv[1]; })();
            echo implode("\n", $GLOBALS['calls']);
            PHP);

        $process = new Process([\PHP_BINARY, $runner, $generated]);
        $process->run();
        self::assertSame('', $process->getErrorOutput());

        self::assertSame([
            'get_sites {"fields":"ids","number":0}',
            'switch 1',
            'delete_option acme_opt',
            'DROP TABLE IF EXISTS `wp_acme_log`',
            'restore',
            'switch 2',
            'delete_option acme_opt',
            'DROP TABLE IF EXISTS `wp_2_acme_log`',
            'restore',
            // Network-wide data, once.
            'delete_site_option acme_net',
            'delete_metadata user acme_um',
        ], explode("\n", $process->getOutput()));
    }
}
