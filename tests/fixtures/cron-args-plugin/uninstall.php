<?php
// Both hooks are "cleared", but wp_clear_scheduled_hook only removes events
// registered without arguments, so cap_with_args survives.

wp_clear_scheduled_hook('cap_plain');
wp_clear_scheduled_hook('cap_with_args');
