<?php
/**
 * Plugin Name: Clean Plugin (fixture)
 * Creates data and removes all of it in uninstall.php — a grade-A shape.
 */

function cp_activate()
{
    global $wpdb;

    add_option('cp_settings', array());
    dbDelta("CREATE TABLE {$wpdb->prefix}cp_logs (id INT)");
    wp_schedule_event(time(), 'daily', 'cp_cron');
    set_transient('cp_cache', 'x', 3600);
}
