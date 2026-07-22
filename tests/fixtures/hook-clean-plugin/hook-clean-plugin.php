<?php
/**
 * Plugin Name: Hook Clean Plugin (fixture)
 * Cleans up via a register_uninstall_hook callback instead of uninstall.php.
 */

register_uninstall_hook(__FILE__, 'hcp_uninstall');

function hcp_install()
{
    add_option('hcp_opt', 1);
    wp_schedule_event(time(), 'daily', 'hcp_cron');
}

function hcp_uninstall()
{
    delete_option('hcp_opt');
    wp_clear_scheduled_hook('hcp_cron');
}
