<?php
/**
 * Plugin Name: Wrapper Guard Plugin (fixture)
 * The other conditional shape: no early return, the removals simply sit inside
 * the `if`.
 */

function wgp_activate()
{
    add_option('wgp_settings', array());
}
