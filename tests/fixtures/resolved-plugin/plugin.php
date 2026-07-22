<?php
/**
 * Plugin Name: Resolved Plugin (fixture)
 * Keys are built from a constant defined in constants.php and from a class
 * property — both must resolve, and a partly-dynamic key must degrade to a pattern.
 */

class RP_Settings
{
    private $prefix = 'rp_opt_';

    public function boot(): void
    {
        add_option(RP_PREFIX . 'version', '1.0.0');        // resolved (cross-file constant)
        add_option($this->prefix . 'settings', array());   // resolved (property)
        update_option('rp_' . $this->section(), 'x');       // pattern (rp_*)
    }

    private function section(): string
    {
        return 'general';
    }
}
