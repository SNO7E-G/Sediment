<?php
// Removes both files this plugin installs. wp_delete_file for the drop-in,
// unlink for the must-use plugin — both paths are credited cleanup.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_delete_file( WP_CONTENT_DIR . '/advanced-cache.php' );
unlink( WPMU_PLUGIN_DIR . '/dp-loader.php' );
