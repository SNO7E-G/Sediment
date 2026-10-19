<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * The WordPress core allowlist — options, tables, and cron hooks that belong to
 * WordPress itself and must never be attributed to a plugin or offered up for
 * deletion (§13, the safety invariant).
 *
 * Kept deliberately small, exact-match, and separate so it can be reviewed on
 * its own. Matching is conservative: exact names only, no prefix guessing, so a
 * plugin option is never mistaken for core and — far more importantly — a core
 * option is never mistaken for a plugin's.
 */
final class WordPressCore
{
    /** @var array<string, true> */
    private const OPTIONS = [
        'siteurl' => true, 'home' => true, 'blogname' => true, 'blogdescription' => true,
        'users_can_register' => true, 'admin_email' => true, 'start_of_week' => true,
        'use_balanceTags' => true, 'use_smilies' => true, 'require_name_email' => true,
        'comments_notify' => true, 'posts_per_rss' => true, 'rss_use_excerpt' => true,
        'mailserver_url' => true, 'mailserver_login' => true, 'mailserver_pass' => true,
        'mailserver_port' => true, 'default_category' => true, 'default_comment_status' => true,
        'default_ping_status' => true, 'default_pingback_flag' => true, 'posts_per_page' => true,
        'date_format' => true, 'time_format' => true, 'links_updated_date_format' => true,
        'comment_moderation' => true, 'moderation_notify' => true, 'permalink_structure' => true,
        'rewrite_rules' => true, 'hack_file' => true, 'blog_charset' => true, 'moderation_keys' => true,
        'active_plugins' => true, 'category_base' => true, 'ping_sites' => true, 'comment_max_links' => true,
        'gmt_offset' => true, 'default_email_category' => true, 'recently_edited' => true,
        'template' => true, 'stylesheet' => true, 'comment_registration' => true, 'html_type' => true,
        'use_trackback' => true, 'default_role' => true, 'db_version' => true, 'initial_db_version' => true,
        'uploads_use_yearmonth_folders' => true, 'upload_path' => true, 'blog_public' => true,
        'default_link_category' => true, 'show_on_front' => true, 'tag_base' => true,
        'sidebars_widgets' => true, 'cron' => true, 'sticky_posts' => true, 'WPLANG' => true,
        'timezone_string' => true, 'page_for_posts' => true, 'page_on_front' => true,
        'default_post_format' => true, 'link_manager_enabled' => true, 'site_icon' => true,
        'wp_page_for_privacy_policy' => true, 'admin_email_lifespan' => true, 'disallowed_keys' => true,
        'comment_previously_approved' => true, 'auto_update_core_dev' => true,
        'auto_update_core_minor' => true, 'auto_update_core_major' => true,
        'wp_force_deactivated_plugins' => true, 'fresh_site' => true, 'blogimage' => true,
        // Roles/capabilities and plugin-lifecycle state — deleting any of these
        // breaks the site or other plugins, so they are the most important to guard.
        '{prefix}user_roles' => true, 'wp_user_roles' => true, 'user_roles' => true,
        'uninstall_plugins' => true, 'recently_activated' => true, 'category_children' => true,
        'can_compress_scripts' => true, 'db_upgraded' => true, 'https_migration_required' => true,
        'thumbnail_size_w' => true, 'thumbnail_size_h' => true, 'medium_size_w' => true,
        'medium_size_h' => true, 'large_size_w' => true, 'large_size_h' => true,
        'medium_large_size_w' => true, 'medium_large_size_h' => true, 'avatar_default' => true,
        'avatar_rating' => true, 'nav_menu_options' => true, 'theme_switched' => true,
        'finished_splitting_shared_terms' => true, 'user_count' => true,
        // Discussion, media, and embed settings from the Settings screens.
        'comments_per_page' => true, 'page_comments' => true, 'thread_comments' => true,
        'thread_comments_depth' => true, 'show_avatars' => true, 'close_comments_for_old_posts' => true,
        'close_comments_days_old' => true, 'comment_order' => true, 'default_comments_page' => true,
        'show_comments_cookies_opt_in' => true, 'image_default_align' => true, 'image_default_size' => true,
        'image_default_link_type' => true, 'thumbnail_crop' => true, 'embed_autourls' => true,
        'embed_size_w' => true, 'embed_size_h' => true, 'current_theme' => true, 'upload_url_path' => true,
        'wp_attachment_pages_enabled' => true, 'active_sitewide_plugins' => true,
        // Core widgets' instance options; a plugin's own widget_<id> stays its own.
        'widget_archives' => true, 'widget_block' => true, 'widget_calendar' => true,
        'widget_categories' => true, 'widget_custom_html' => true, 'widget_media_audio' => true,
        'widget_media_gallery' => true, 'widget_media_image' => true, 'widget_media_video' => true,
        'widget_meta' => true, 'widget_nav_menu' => true, 'widget_pages' => true,
        'widget_recent-comments' => true, 'widget_recent-posts' => true, 'widget_rss' => true,
        'widget_search' => true, 'widget_tag_cloud' => true, 'widget_text' => true,
    ];

    /** @var array<string, array<string, true>> meta keys WordPress itself reads and writes, by finding type */
    private const META = [
        'post_meta' => [
            '_edit_lock' => true, '_edit_last' => true, '_thumbnail_id' => true, '_wp_page_template' => true,
            '_wp_attached_file' => true, '_wp_attachment_metadata' => true, '_wp_attachment_image_alt' => true,
            '_wp_old_slug' => true, '_wp_old_date' => true, '_wp_trash_meta_status' => true,
            '_wp_trash_meta_time' => true, '_wp_desired_post_slug' => true, '_wp_attachment_backup_sizes' => true,
            '_wp_attachment_context' => true, '_encloseme' => true, '_pingme' => true,
            '_menu_item_type' => true, '_menu_item_menu_item_parent' => true, '_menu_item_object_id' => true,
            '_menu_item_object' => true, '_menu_item_target' => true, '_menu_item_classes' => true,
            '_menu_item_xfn' => true, '_menu_item_url' => true,
        ],
        'user_meta' => [
            'first_name' => true, 'last_name' => true, 'nickname' => true, 'description' => true,
            'rich_editing' => true, 'syntax_highlighting' => true, 'comment_shortcuts' => true,
            'admin_color' => true, 'use_ssl' => true, 'show_admin_bar_front' => true, 'locale' => true,
            '{prefix}capabilities' => true, '{prefix}user_level' => true, 'dismissed_wp_pointers' => true,
            'show_welcome_panel' => true, 'session_tokens' => true, 'default_password_nag' => true,
            '{prefix}dashboard_quick_press_last_post_id' => true, 'community-events-location' => true,
        ],
    ];

    /** @var array<string, true> */
    private const ROLES = [
        'administrator' => true, 'editor' => true, 'author' => true, 'contributor' => true, 'subscriber' => true,
    ];

    /** @var array<string, true> capabilities WordPress grants its own roles */
    private const CAPABILITIES = [
        'switch_themes' => true, 'edit_themes' => true, 'activate_plugins' => true, 'edit_plugins' => true,
        'edit_users' => true, 'edit_files' => true, 'manage_options' => true, 'moderate_comments' => true,
        'manage_categories' => true, 'manage_links' => true, 'upload_files' => true, 'import' => true,
        'unfiltered_html' => true, 'edit_posts' => true, 'edit_others_posts' => true,
        'edit_published_posts' => true, 'publish_posts' => true, 'edit_pages' => true, 'read' => true,
        'edit_others_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true,
        'delete_pages' => true, 'delete_others_pages' => true, 'delete_published_pages' => true,
        'delete_posts' => true, 'delete_others_posts' => true, 'delete_published_posts' => true,
        'delete_private_posts' => true, 'edit_private_posts' => true, 'read_private_posts' => true,
        'delete_private_pages' => true, 'edit_private_pages' => true, 'read_private_pages' => true,
        'delete_users' => true, 'create_users' => true, 'unfiltered_upload' => true, 'edit_dashboard' => true,
        'update_plugins' => true, 'delete_plugins' => true, 'install_plugins' => true, 'update_themes' => true,
        'install_themes' => true, 'update_core' => true, 'list_users' => true, 'remove_users' => true,
        'promote_users' => true, 'edit_theme_options' => true, 'delete_themes' => true, 'export' => true,
        'customize' => true, 'delete_site' => true, 'manage_network' => true, 'manage_sites' => true,
        'manage_network_users' => true, 'manage_network_plugins' => true, 'manage_network_themes' => true,
        'manage_network_options' => true, 'upgrade_network' => true, 'setup_network' => true,
        'create_sites' => true, 'delete_sites' => true,
        'level_0' => true, 'level_1' => true, 'level_2' => true, 'level_3' => true, 'level_4' => true,
        'level_5' => true, 'level_6' => true, 'level_7' => true, 'level_8' => true, 'level_9' => true,
        'level_10' => true,
    ];

    /** @var array<string, true> */
    private const POST_TYPES = [
        'post' => true, 'page' => true, 'attachment' => true, 'revision' => true, 'nav_menu_item' => true,
        'custom_css' => true, 'customize_changeset' => true, 'oembed_cache' => true, 'user_request' => true,
        'wp_block' => true, 'wp_template' => true, 'wp_template_part' => true, 'wp_global_styles' => true,
        'wp_navigation' => true, 'wp_font_family' => true, 'wp_font_face' => true,
    ];

    /** @var array<string, true> */
    private const TAXONOMIES = [
        'category' => true, 'post_tag' => true, 'nav_menu' => true, 'link_category' => true,
        'post_format' => true, 'wp_theme' => true, 'wp_template_part_area' => true, 'wp_pattern_category' => true,
    ];

    /** @var array<string, true> core tables, keyed by {prefix} token (lowercased) */
    private const TABLES = [
        '{prefix}posts' => true, '{prefix}postmeta' => true, '{prefix}comments' => true,
        '{prefix}commentmeta' => true, '{prefix}terms' => true, '{prefix}termmeta' => true,
        '{prefix}term_relationships' => true, '{prefix}term_taxonomy' => true, '{prefix}links' => true,
        '{prefix}options' => true, '{prefix}users' => true, '{prefix}usermeta' => true,
        // Multisite
        '{prefix}blogs' => true, '{prefix}blogmeta' => true, '{prefix}site' => true,
        '{prefix}sitemeta' => true, '{prefix}signups' => true, '{prefix}registration_log' => true,
        '{prefix}blog_versions' => true,
    ];

    /** @var array<string, true> */
    private const CRON_HOOKS = [
        'wp_version_check' => true, 'wp_update_plugins' => true, 'wp_update_themes' => true,
        'wp_scheduled_delete' => true, 'wp_scheduled_auto_draft_delete' => true,
        'delete_expired_transients' => true, 'recovery_mode_clean_expired_keys' => true,
        'wp_privacy_delete_old_export_files' => true, 'wp_https_detection' => true,
        'wp_update_user_counts' => true, 'wp_site_health_scheduled_check' => true,
        'do_pings' => true, 'publish_future_post' => true, 'importer_scheduled_cleanup' => true,
        'wp_maybe_auto_update' => true, 'wp_split_shared_term_batch' => true,
        'wp_delete_temp_updater_backups' => true, 'wp_update_comment_type_batch' => true,
    ];

    /**
     * @var array<string, true> directories WordPress owns, in the placeholder
     *      form the filesystem detector emits
     */
    private const DIRECTORIES = [
        '{content_dir}' => true, '{content_dir}/uploads' => true, '{content_dir}/plugins' => true,
        '{content_dir}/themes' => true, '{content_dir}/mu-plugins' => true, '{content_dir}/upgrade' => true,
        '{content_dir}/languages' => true, '{content_dir}/upgrade-temp-backup' => true,
        '{plugin_dir}' => true, '{abspath}' => true, '{abspath}/wp-admin' => true, '{abspath}/wp-includes' => true,
    ];

    public static function isCoreDirectory(string $path): bool
    {
        return isset(self::DIRECTORIES[rtrim(strtolower($path), '/')]);
    }

    public static function isCoreOption(string $key): bool
    {
        // theme_mods_<theme> belongs to a theme, whichever plugin writes it.
        return isset(self::OPTIONS[$key]) || str_starts_with($key, 'theme_mods_');
    }

    public static function isCoreTable(string $name): bool
    {
        return isset(self::TABLES[strtolower($name)]);
    }

    public static function isCoreCronHook(string $hook): bool
    {
        return isset(self::CRON_HOOKS[$hook]);
    }

    /**
     * Whether a finding targets a WordPress core artifact and must therefore
     * never appear in a deletable result set.
     */
    public static function isCore(Finding $finding): bool
    {
        if ($finding->key === null) {
            return false;
        }

        return match ($finding->type) {
            'option'    => self::isCoreOption($finding->key),
            'cron'      => self::isCoreCronHook($finding->key),
            'table'     => self::isCoreTable($finding->key),
            'directory' => self::isCoreDirectory($finding->key),
            // A plugin granting `edit_posts` to its role, or registering a
            // taxonomy for `category`, touches core — it does not own them.
            'role'       => isset(self::ROLES[$finding->key]),
            'capability' => isset(self::CAPABILITIES[$finding->key]),
            'post_type'  => isset(self::POST_TYPES[$finding->key]),
            'taxonomy'   => isset(self::TAXONOMIES[$finding->key]),
            // Profile fields and attachment/menu bookkeeping belong to WordPress;
            // a plugin filling in a user's first_name does not own the field.
            'post_meta', 'user_meta' => isset(self::META[$finding->type][$finding->key]),
            default     => false,
        };
    }
}
