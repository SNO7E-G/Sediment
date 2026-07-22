<?php
/**
 * Plugin Name: Partial Plugin (fixture)
 * Removes only the option on uninstall; the table and cron event are left behind.
 * Also deletes an option at runtime, which must NOT count as cleanup.
 */

function pp_activate()
{
    global $wpdb;

    add_option('pp_settings', array());
    add_option('pp_temp', 1);
    dbDelta("CREATE TABLE {$wpdb->prefix}pp_data (id INT)");
    wp_schedule_event(time(), 'daily', 'pp_cron');
}

function pp_reset()
{
    // Runtime housekeeping — not an uninstall path, so it must not credit cleanup.
    delete_option('pp_temp');
}
