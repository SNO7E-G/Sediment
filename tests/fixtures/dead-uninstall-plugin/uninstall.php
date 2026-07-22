<?php
// du_dead is defined but never called — its removal must NOT credit cleanup.
function du_dead()
{
    delete_option('du_settings');
}

// du_run IS called at the top level — its removal counts.
function du_run()
{
    delete_option('du_other');
}

du_run();
