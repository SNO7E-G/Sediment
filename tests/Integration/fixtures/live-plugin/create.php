<?php

/**
 * Performs, for real, exactly the writes live-plugin.php declares.
 *
 * Kept beside the plugin file rather than inside it so the analyzer reads a
 * normal plugin while the integration check can execute the same writes against
 * a live database. Deliberately not named *.php-ignorable: the scanner reads
 * both files, which is harmless because they declare identical artifacts.
 */

declare(strict_types=1);

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

add_option('slf_settings', array('a' => 1));
add_option('slf_version', '1.0.0', '', 'no');
update_option('slf_cache_flag', 1);

dbDelta("CREATE TABLE {$wpdb->prefix}slf_logs (id BIGINT NOT NULL AUTO_INCREMENT, msg TEXT, PRIMARY KEY (id)) " . $wpdb->get_charset_collate());

wp_schedule_event(time(), 'daily', 'slf_daily_sync');
set_transient('slf_cache', 'value', 3600);
update_post_meta(1, 'slf_meta_ref', 'x');
add_role('slf_manager', 'SLF Manager', array('read' => true));
