<?php
// Runs on uninstall. The actual teardown lives in required files.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/includes/tasks.php';
