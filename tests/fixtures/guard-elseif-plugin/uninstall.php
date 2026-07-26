<?php
// The gate is the second branch.
if (defined('GEL_SKIP')) {
    return;
} elseif (!get_option('gel_remove_data')) {
    return;
}

delete_option('gel_settings');
