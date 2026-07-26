<?php
/** Plugin Name: Dynamic Table Plugin (fixture) */
function dtp_install($suffix) {
    global $wpdb;
    $wpdb->query("CREATE TABLE {$wpdb->prefix}dtp_logs{$suffix} (id INT)");
}
