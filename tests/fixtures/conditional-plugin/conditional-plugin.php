<?php
/**
 * Plugin Name: Conditional Plugin (fixture)
 * Removes everything it creates, but only when a setting the user has to find
 * and enable says so. Technically clean, practically dirty — grade B.
 */

function cnd_activate()
{
    global $wpdb;

    add_option('cnd_settings', array());
    dbDelta("CREATE TABLE {$wpdb->prefix}cnd_logs (id INT)");
    wp_schedule_event(time(), 'daily', 'cnd_cron');
}
