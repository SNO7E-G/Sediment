<?php
/**
 * Plugin Name: Structure Plugin (fixture)
 * Description: A hand-written fixture that registers roles, capabilities,
 *              post types, and taxonomies. Used to pin StructureVisitor
 *              behaviour. Not a real plugin.
 */

// Verified: literal role name, plus one verified capability per literal key.
add_role('sp_editor', 'SP Editor', [
    'sp_manage_widgets' => true,
    'read'              => true,
]);

define('SP_PREFIX', 'sp_');

// Resolved: post type built from a constant.
register_post_type(SP_PREFIX . 'listing', ['public' => true]);

class SP_Taxonomy
{
    const NAME = 'sp_genre';

    // Resolved: taxonomy name from a class constant.
    public function register(): void
    {
        register_taxonomy(self::NAME, 'post', []);
    }
}

// Dynamic: post type comes from a variable, unresolvable at parse time.
$type = get_dynamic_post_type();
register_post_type($type, []);

// Capability grant via method call — only the capability name is attributed.
$role = get_role('sp_editor');
$role->add_cap('sp_publish_listings');
