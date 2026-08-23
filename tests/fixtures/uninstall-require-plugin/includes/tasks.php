<?php
// Required by uninstall.php. Top-level statements here run on uninstall.

delete_option( 'urp_first' );

require __DIR__ . '/more-deletes.php';

function urp_never_called(): void {
	delete_option( 'urp_dead' );
}
