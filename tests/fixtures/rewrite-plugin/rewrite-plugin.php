<?php
/**
 * Plugin Name: Rewrite Plugin (fixture)
 * Adds routing rules and never flushes them on uninstall.
 */

define('RWP_SLUG', 'rwp-portal');

function rwp_routes($dynamic)
{
    add_rewrite_rule('^rwp/([^/]+)/?$', 'index.php?rwp_id=$matches[1]', 'top');
    add_rewrite_endpoint(RWP_SLUG, EP_ROOT);
    add_rewrite_tag($dynamic, '([^&]+)');
}
