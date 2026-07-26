<?php
/**
 * Plugin Name: Unschedule Hook Plugin (fixture)
 * Schedules an event with arguments and clears it correctly on uninstall with
 * wp_unschedule_hook(), which removes every event for the hook.
 */

function uhp_activate()
{
    wp_schedule_event(time(), 'daily', 'uhp_with_args', array('site' => 1));
}
