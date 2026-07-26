<?php
/**
 * Plugin Name: Filesystem Plugin (fixture)
 * Description: A hand-written fixture that creates directories under
 *              WordPress roots via wp_mkdir_p()/mkdir(). Used to pin
 *              FilesystemVisitor behaviour. Not a real plugin.
 */

// Verified: literal absolute path, no WordPress root constant involved.
mkdir('/var/www/html/wp-content/uploads/fsf-static', 0755, true);

// Verified: WP_CONTENT_DIR root with a literal remainder — rewritten to the
// portable {content_dir} placeholder.
wp_mkdir_p(WP_CONTENT_DIR . '/fsf-logs');

define('FSF_CACHE_DIR', 'fsf-cache');

// Resolved: WP_CONTENT_DIR root with a remainder built from a define()
// constant.
wp_mkdir_p(WP_CONTENT_DIR . '/' . FSF_CACHE_DIR);

// Dynamic: fully dynamic target, unresolvable at parse time.
$target = get_dynamic_upload_dir();
mkdir($target);

// Skipped entirely: the placeholder alone would name wp-content itself.
wp_mkdir_p(WP_CONTENT_DIR);
