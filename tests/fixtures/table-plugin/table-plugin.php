<?php
/**
 * Plugin Name: Table Plugin (fixture)
 * Creates tables the way real plugins do: SQL assigned to a local variable and
 * passed to dbDelta(), and a direct CREATE via $wpdb->query().
 */

function tp_install()
{
    global $wpdb;

    $sql = "CREATE TABLE {$wpdb->prefix}tp_logs (
        id BIGINT NOT NULL AUTO_INCREMENT,
        created DATETIME NOT NULL,
        PRIMARY KEY (id)
    );";
    dbDelta($sql);

    $wpdb->query('CREATE TABLE ' . $wpdb->prefix . 'tp_cache (id INT)');
}

function tp_install_dynamic($name)
{
    dbDelta($name); // table name unknown at parse time
}
