<?php
/**
 * Plugin Name: Case Hook Plugin (fixture)
 * The callback is registered with different letter-casing than the function is
 * defined — PHP callables are case-insensitive, so this cleans correctly.
 */

register_uninstall_hook(__FILE__, 'CH_Uninstall');

function ch_install()
{
    add_option('ch_opt', 1);
}

function ch_uninstall()
{
    delete_option('ch_opt');
}
