<?php
// A return inside a closure belongs to the closure, not the uninstall routine,
// so this `if` gates nothing and cleanup below is unconditional.
if (get_option('gcl_notify_on_uninstall')) {
    $payload = array_map(function ($v) { return trim($v); }, array('bye'));
    wp_remote_post('https://example.com', array('body' => $payload));
}

delete_option('gcl_settings');
