<?php
// Deliberately malformed PHP (M14 fixture). Sediment must record this as an
// error and keep going, never fatal. Not loaded by the test runner.

add_option('half_written',   // unterminated call, missing args and paren
class {{{ ->->-> $$$
