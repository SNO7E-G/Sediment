<?php
// The gate calls a cleanup function rather than deleting inline — the most
// common conditional-uninstall idiom.
function gwc_do_cleanup() { delete_option('gwc_settings'); }

if (get_option('gwc_remove_data')) {
    gwc_do_cleanup();
}
