<?php
/**
 * Plugin Name: Dirty Plugin (fixture)
 * Description: A hand-written fixture that writes options and never cleans up.
 *              Used to pin OptionVisitor behaviour. Not a real plugin.
 */

// Verified: literal string keys.
add_option('dirty_version', '1.0.0');
update_option('dirty_settings', ['enabled' => true]);
add_site_option('dirty_network_flag', 1);

// Dynamic: key comes from a function call, unresolvable at parse time.
$key = some_runtime_key();
update_option($key, 'value');
