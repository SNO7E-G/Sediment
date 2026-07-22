<?php
/**
 * Plugin Name: Cron Plugin (fixture)
 * Description: A hand-written fixture that schedules cron events. Used to pin
 *              CronVisitor behaviour. Not a real plugin.
 */

// Verified: literal hook and recurrence.
wp_schedule_event(time(), 'daily', 'cronf_cleanup');

define('CRONF_PREFIX', 'cronf_');

class CF_Scheduler
{
    const RECURRENCE = 'hourly';

    // Resolved: hook built from a constant, recurrence resolved from a class const.
    public function boot(): void
    {
        wp_schedule_event(time(), self::RECURRENCE, CRONF_PREFIX . 'sync');
    }
}

// Dynamic: hook comes from a variable, unresolvable at parse time.
$hook = get_dynamic_hook();
wp_schedule_event(time(), 'twicedaily', $hook);

// Single event: verified hook, recurrence forced to 'single'.
wp_schedule_single_event(time() + 3600, 'cronf_one_off_task');
