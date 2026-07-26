<?php
/** Plugin Name: Action Clean Plugin (fixture) */
function acp_activate() { as_schedule_recurring_action(time(), 3600, 'acp_job'); }
