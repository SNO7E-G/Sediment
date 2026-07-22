<?php

namespace Acme\Admin;

// A different class that happens to share the short name "Cleaner". Its run()
// deletes the option, but it is NOT the registered uninstall callback, so this
// must not credit cleanup.
class Cleaner
{
    public static function run()
    {
        delete_option('nc_settings');
    }
}
