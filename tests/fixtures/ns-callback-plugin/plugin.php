<?php

namespace Acme;

/**
 * Plugin Name: Namespaced Callback Plugin (fixture)
 * The registered uninstall callback (Acme\Cleaner::run) does nothing; a
 * same-short-named class in another namespace does the delete but is not
 * registered, so nothing is actually cleaned.
 */

class Cleaner
{
    public static function run()
    {
        // intentionally empty
    }
}

function activate()
{
    add_option('nc_settings', 1);
}

register_uninstall_hook(__FILE__, [Cleaner::class, 'run']);
