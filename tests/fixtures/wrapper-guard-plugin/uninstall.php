<?php
// Cleanup wrapped in the gate rather than guarded by an early return.

if (get_option('wgp_remove_data')) {
    delete_option('wgp_settings');
}
