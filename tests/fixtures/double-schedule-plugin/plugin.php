<?php
/** Plugin Name: Double Schedule Plugin (fixture) */
function dsp_activate() {
    wp_schedule_event(time(), 'daily',  'dsp_sync');
    wp_schedule_event(time(), 'hourly', 'dsp_sync', array('fast'));
}
