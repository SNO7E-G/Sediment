<?php
/**
 * Plugin Name: Dead Uninstall Plugin (fixture)
 * uninstall.php defines two cleanup functions but only calls one, so only one
 * option is actually removed.
 */

function du_activate()
{
    add_option('du_settings', 1);
    add_option('du_other', 1);
}
