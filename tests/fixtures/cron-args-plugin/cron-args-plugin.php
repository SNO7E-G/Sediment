<?php
/**
 * Plugin Name: Cron Args Plugin (fixture)
 * Schedules one event with arguments and one without. The uninstall routine
 * clears both with wp_clear_scheduled_hook(), which only actually removes the
 * argument-less one — the other keeps firing forever.
 *
 * Also uses a $this->wpdb handle rather than the global, to exercise handle
 * recognition beyond a bare $wpdb variable.
 */

class CAP_Installer
{
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function install()
    {
        $this->wpdb->query("CREATE TABLE {$this->wpdb->prefix}cap_logs (id INT)");

        wp_schedule_event(time(), 'daily', 'cap_plain');
        wp_schedule_event(time(), 'daily', 'cap_with_args', array(42));
    }
}
