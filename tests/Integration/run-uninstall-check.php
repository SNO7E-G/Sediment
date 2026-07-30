<?php

/**
 * Proves the claim the whole project rests on, against a real WordPress:
 * a generated uninstall.php removes exactly what the plugin created, and
 * nothing else.
 *
 * Runs in its own process — it boots WordPress, which is far too much global
 * state to bring into the unit suite. Emits a single JSON line so the calling
 * test can assert on it.
 *
 * usage: php run-uninstall-check.php <path-to-wordpress> <fixture-dir>
 */

declare(strict_types=1);

$wordpress = $argv[1] ?? '';
$fixture = $argv[2] ?? '';

if (!is_file($wordpress . '/wp-load.php') || !is_dir($fixture)) {
    fwrite(STDERR, "usage: run-uninstall-check.php <path-to-wordpress> <fixture-dir>\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $wordpress . '/wp-load.php';

global $wpdb;

/**
 * Everything about the database this check cares about. Comparing whole sets
 * rather than looking for specific keys is what turns "it removed my rows" into
 * "it removed my rows and touched nothing else".
 *
 * @return array<string, list<string>>
 */
function sediment_snapshot(wpdb $wpdb): array
{
    return [
        'options' => (array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options} ORDER BY option_name"),
        'tables' => (array) $wpdb->get_col('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'),
        'postmeta' => (array) $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->postmeta} ORDER BY meta_key"),
        'usermeta' => (array) $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} ORDER BY meta_key"),
        'roles' => array_keys((array) get_option($wpdb->prefix . 'user_roles')),
    ];
}

$before = sediment_snapshot($wpdb);

// 1. The plugin creates its data for real.
require $fixture . '/create.php';
$afterCreate = sediment_snapshot($wpdb);

// 2. Sediment reads the plugin's source and writes the teardown it should ship.
$scan = (new Sediment\Analyzer\Scanner())->scan($fixture);
$grade = (new Sediment\Manifest\Grader())->grade($scan['findings'], $scan['cleanup']);
$generated = (new Sediment\Generator\UninstallGenerator())->generate($scan['findings'], 'live-fixture');

$uninstall = tempnam(sys_get_temp_dir(), 'sediment-uninstall') . '.php';
file_put_contents($uninstall, $generated);

// 3. Run it exactly as WordPress would.
define('WP_UNINSTALL_PLUGIN', 'live-plugin/live-plugin.php');
require $uninstall;
@unlink($uninstall);

$after = sediment_snapshot($wpdb);

$created = [];
$leftover = [];
$collateral = [];
foreach ($before as $kind => $itemsBefore) {
    $created[$kind] = array_values(array_diff($afterCreate[$kind], $itemsBefore));
    $leftover[$kind] = array_values(array_intersect($created[$kind], $after[$kind]));
    // Present before the plugin ran and gone now: taken, but never the plugin's.
    $collateral[$kind] = array_values(array_diff($itemsBefore, $after[$kind]));
}

echo json_encode([
    'grade' => $grade->letter,
    'created' => array_filter($created),
    'leftover' => array_filter($leftover),
    'collateral' => array_filter($collateral),
    'core_options_remaining' => count($after['options']),
    'core_tables_remaining' => count($after['tables']),
], JSON_UNESCAPED_SLASHES), "\n";
