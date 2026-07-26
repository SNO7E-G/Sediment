<?php
/** Plugin Name: Clear With Args Plugin (fixture) */
function cwa_activate() { wp_schedule_event(time(), 'daily', 'cwa_sync'); }
