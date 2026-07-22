<?php
// Runs only on uninstall (WordPress defines WP_UNINSTALL_PLUGIN before including
// this file). Every artifact the plugin creates is removed here.

global $wpdb;

delete_option('cp_settings');
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cp_logs");
wp_clear_scheduled_hook('cp_cron');
delete_transient('cp_cache');
