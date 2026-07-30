<?php
/**
 * Plugin Name: Sediment Live Fixture
 * Description: A deliberately dirty plugin used by the live-WordPress
 *              integration check. It creates one of every artifact type
 *              Sediment can generate a removal for, and ships no uninstall
 *              routine of its own.
 *
 * This file is what Sediment reads. The identical writes are performed for real
 * against the database by create.php, so the two can be compared.
 */

function slf_activate()
{
    global $wpdb;

    add_option('slf_settings', array('a' => 1));
    add_option('slf_version', '1.0.0', '', 'no');
    update_option('slf_cache_flag', 1);

    dbDelta("CREATE TABLE {$wpdb->prefix}slf_logs (id BIGINT NOT NULL AUTO_INCREMENT, msg TEXT, PRIMARY KEY (id))");

    wp_schedule_event(time(), 'daily', 'slf_daily_sync');
    set_transient('slf_cache', 'value', 3600);
    update_post_meta(1, 'slf_meta_ref', 'x');
    add_role('slf_manager', 'SLF Manager', array('read' => true));
}
