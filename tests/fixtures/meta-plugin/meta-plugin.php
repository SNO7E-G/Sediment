<?php
/**
 * Plugin Name: Meta Plugin (fixture)
 * Description: A hand-written fixture that writes post/user/term/comment meta
 *              and registers meta fields. Used to pin MetaVisitor behaviour.
 *              Not a real plugin.
 */

// Verified: literal meta key.
add_post_meta(1, 'mp_post_synced', true);

define('MP_PREFIX', 'mp_');

// Resolved: meta key built from a constant.
update_user_meta(1, MP_PREFIX . 'user_pref', 'value');

class MP_Store
{
    const KEY = 'mp_term_cache';

    // Resolved: meta key from a class constant.
    public function cache(int $termId): void
    {
        add_term_meta($termId, self::KEY, 'value');
    }
}

// Dynamic: meta key comes from a variable, unresolvable at parse time.
$field = get_dynamic_meta_field();
update_comment_meta(1, $field, 'value');

// register_meta with a literal, resolvable object type — key still resolves.
register_meta('post', 'mp_registered_field', ['single' => true]);

// register_meta with an unresolvable object type — must emit nothing at all.
$type = get_dynamic_object_type();
register_meta($type, 'mp_unattributed_field', ['single' => true]);
