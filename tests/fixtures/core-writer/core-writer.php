<?php
/**
 * Plugin Name: Core Writer (fixture)
 * A badly-behaved plugin that touches WordPress core artifacts alongside its
 * own. Sediment must be able to tell the core ones apart so they are never
 * offered up for deletion (§13).
 */

// Core — must never end up in a deletable set.
update_option('siteurl', 'https://example.test');
update_option('wp_user_roles', array()); // the most dangerous core option
wp_schedule_event(time(), 'daily', 'wp_version_check');

// The plugin's own artifacts — these are the ones a cleanup would target.
add_option('corewriter_settings', array());
wp_schedule_event(time(), 'daily', 'corewriter_sync');
