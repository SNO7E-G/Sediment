<?php
/**
 * Plugin Name: Meta Clean Plugin (fixture)
 * Creates metadata and a role, and removes all of it in uninstall.php.
 */

function mcp_save($post_id, $user_id)
{
    update_post_meta($post_id, 'mcp_ref', 'x');
    update_user_meta($user_id, 'mcp_pref', 'y');
}

add_role('mcp_role', 'MCP Role', array('read' => true));
