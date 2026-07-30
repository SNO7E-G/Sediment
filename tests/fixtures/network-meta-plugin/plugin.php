<?php
/**
 * Plugin Name: Network Meta Plugin (fixture)
 * Uses the multisite network option API and the generic metadata API, both of
 * which take their key at a different argument position than the common twins.
 */

function nmp_activate($post_id) {
    update_network_option(null, 'nmp_network', array());
    add_metadata('post', $post_id, 'nmp_ref', 'v');
}
