<?php
/**
 * Plugin Name: Schedule Plugin (fixture)
 * Description: A hand-written fixture that schedules Action Scheduler jobs.
 *              Used to pin ScheduleVisitor behaviour. Not a real plugin.
 */

// Verified: literal hook; the interval is a plain int literal, which never
// resolves (Action Scheduler intervals are usually raw seconds, not a
// string ExpressionResolver can trace).
as_schedule_recurring_action(time(), 3600, 'sf_sync');

define('SF_INTERVAL', '3600');

class SF_Jobs
{
    const HOOK = 'sf_cleanup';

    // Resolved: hook and interval both traced through define()/class const.
    public function schedule(): void
    {
        as_schedule_recurring_action(time(), SF_INTERVAL, self::HOOK);
    }
}

// Verified: single one-off action, recurrence forced to 'single'.
as_schedule_single_action(time() + 60, 'sf_one_off');

// Verified: fire-and-forget async action, recurrence forced to 'async'.
as_enqueue_async_action('sf_async_task');

// Dynamic: hook comes from a variable, unresolvable at parse time.
$hook = get_dynamic_hook();
as_schedule_cron_action(time(), '*/5 * * * *', $hook);
