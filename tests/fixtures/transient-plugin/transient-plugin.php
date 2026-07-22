<?php
/**
 * Plugin Name: Transient Plugin (fixture)
 * Description: A hand-written fixture that sets transients. Used to pin
 *              TransientVisitor behaviour. Not a real plugin.
 */

// Verified: literal transient name.
set_transient('tf_cache', ['a' => 1], HOUR_IN_SECONDS);

define('TF_PREFIX', 'tf_');

// Resolved: transient name built from a constant.
set_site_transient(TF_PREFIX . 'network_cache', ['b' => 2], DAY_IN_SECONDS);

class TF_Store
{
    const KEY = 'tf_store_data';

    // Resolved: transient name from a class constant.
    public function cache(): void
    {
        set_transient(self::KEY, 'value', 3600);
    }
}

// Dynamic: transient name comes from a variable, unresolvable at parse time.
$name = get_dynamic_transient_name();
set_transient($name, 'value', 3600);
