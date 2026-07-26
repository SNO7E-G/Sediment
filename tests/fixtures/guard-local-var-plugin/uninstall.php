<?php
// The option is read into a variable that the condition then tests.
$keep = get_option('glv_keep_data');
if ($keep) {
    return;
}

delete_option('glv_settings');
