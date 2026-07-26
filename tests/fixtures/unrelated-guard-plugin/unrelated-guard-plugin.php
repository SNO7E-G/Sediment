<?php
/**
 * Plugin Name: Unrelated Guard Plugin (fixture)
 * Cleans up unconditionally, but its uninstall routine also reads an option for
 * a migration branch that has nothing to do with gating cleanup. That must not
 * cost it its A.
 */

function ugp_activate()
{
    add_option('ugp_settings', array());
}
