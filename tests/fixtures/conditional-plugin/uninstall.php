<?php
// The classic "conditionally clean" shape: everything is removed, but only for
// the small fraction of users who found and enabled the setting first.

if (!get_option('cnd_delete_data_on_uninstall', false)) {
    return;
}

global $wpdb;

delete_option('cnd_settings');
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cnd_logs");
wp_clear_scheduled_hook('cnd_cron');
