<?php
// This `if` reads an option but neither bails out nor guards a removal — it is
// a migration branch, not a cleanup gate.
if (get_option('ugp_schema') === 'v2') {
    ugp_log_migration();
}

// Cleanup itself is unconditional.
delete_option('ugp_settings');
