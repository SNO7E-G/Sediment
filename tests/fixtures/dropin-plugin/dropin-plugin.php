<?php
/*
 * Plugin Name: Dropin Plugin
 * Description: Installs a drop-in and a must-use file, and removes both.
 */

file_put_contents( WP_CONTENT_DIR . '/advanced-cache.php', "<?php\n// cache\n" );

function dp_stage_filesystem(): void {
	global $wp_filesystem;
	$wp_filesystem->put_contents( WPMU_PLUGIN_DIR . '/dp-loader.php', "<?php\n// loader\n" );
}
