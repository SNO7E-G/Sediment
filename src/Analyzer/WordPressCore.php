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
        return isset(self::OPTIONS[$key]);
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
            default     => false,
        };
    }
}
