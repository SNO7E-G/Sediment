<?php
// Clears only events registered WITH these arguments, so the argument-less
// event this plugin schedules is untouched.
wp_clear_scheduled_hook('cwa_sync', array(42));
