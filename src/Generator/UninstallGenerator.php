<?php

declare(strict_types=1);

namespace Sediment\Generator;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\WordPressCore;

/**
 * Generates a syntactically valid `uninstall.php` that removes what a plugin
 * leaves behind (M13).
 *
 * Safety first: only artifacts that are `verified` or `resolved`, not already
 * cleaned, and not WordPress core are emitted. `pattern` and `dynamic` keys are
 * never generated — deleting on a guess is the failure this project exists to
 * avoid. The `{prefix}` token is rebuilt from `$wpdb->prefix` wherever it
 * appears, for every artifact type, never a hardcoded prefix.
 */
final class UninstallGenerator
{
    private const PREFIX_TOKEN = '{prefix}';

    /**
     * @param list<Finding> $findings
     */
    public function generate(array $findings, string $pluginName = 'this plugin'): string
    {
        $options = [];
        $siteOptions = [];
        $tables = [];
        $cron = [];
        $transients = [];
        $siteTransients = [];
        $meta = [];         // key => meta object type (post|term|comment, or user for a prefixed key)
        $userMeta = [];     // the users table is shared by the whole network
        $roles = [];
        $actions = [];
        $capabilities = []; // reported as a comment: removal needs the role that holds it
        $dropins = [];      // deletable: the plugin wrote these files itself
        $muplugins = [];
        $content = [];      // reported as a comment, never deleted

        foreach ($findings as $finding) {
            if (!$this->isDeletable($finding)) {
                continue;
            }

            /** @var string $key */
            $key = $finding->key;

            match ($finding->type) {
                'option'    => $finding->isNetworkScoped() ? $siteOptions[$key] = true : $options[$key] = true,
                'table'     => $tables[$key] = true,
                // An event scheduled with arguments is not removed by
                // wp_clear_scheduled_hook(), so it needs the broader call. If the
                // same hook is scheduled both ways, the broader call wins.
                'cron'      => $cron[$key] = ($finding->hasArgs || ($cron[$key] ?? null) === 'wp_unschedule_hook')
                    ? 'wp_unschedule_hook'
                    : 'wp_clear_scheduled_hook',
                'transient' => $finding->isNetworkScoped() ? $siteTransients[$key] = true : $transients[$key] = true,
                // Users are shared by the network, but a key built on the
                // table prefix (`wp_2_acme_seen`) is one per site.
                'user_meta' => str_contains($key, self::PREFIX_TOKEN) ? $meta[$key] = 'user' : $userMeta[$key] = true,
                'post_meta', 'term_meta', 'comment_meta' => $meta[$key] = str_replace('_meta', '', $finding->type),
                'role'      => $roles[$key] = true,
                'action'    => $actions[$key] = true,
                'capability'=> $capabilities[$key] = true,
                // A drop-in or mu-plugin file is something the plugin wrote;
                // deleting it removes code that would otherwise run forever.
                // Unlike posts or directories there is no user data at stake.
                'dropin'    => $dropins[$key] = true,
                'muplugin'  => $muplugins[$key] = true,
                'post_type', 'taxonomy', 'directory', 'rewrite_rule' => $content[$key] = $finding->type,
                default     => null,
            };
        }

        foreach ([&$options, &$siteOptions, &$tables, &$cron, &$transients, &$siteTransients, &$meta, &$userMeta, &$roles, &$actions, &$capabilities, &$dropins, &$muplugins, &$content] as &$group) {
            ksort($group);
        }
        unset($group);

        return $this->render(
            $pluginName,
            $this->perSite($options, $tables, $cron, $transients, $meta, $roles, $actions),
            $this->networkWide($pluginName, $siteOptions, $siteTransients, $userMeta, $dropins, $muplugins, $capabilities, $content),
        );
    }

    private function isDeletable(Finding $finding): bool
    {
        return $finding->key !== null
            && $finding->isConfident()
            && $finding->cleaned !== true
            && !WordPressCore::isCore($finding);
    }

    /**
     * The removals for data every site keeps its own copy of: its options
     * table, its `{prefix}` tables, its cron array, its posts' and terms' meta,
     * its roles, its Action Scheduler queue.
     *
     * @param array<string, true> $options
     * @param array<string, true> $tables
     * @param array<string, string> $cron hook => clearing function
     * @param array<string, true> $transients
     * @param array<string, string> $meta key => object type (post|term|comment, or user for a prefixed key)
     * @param array<string, true> $roles
     * @param array<string, true> $actions Action Scheduler hooks
     * @return list<string>
     */
    private function perSite(array $options, array $tables, array $cron, array $transients, array $meta, array $roles, array $actions): array
    {
        $lines = [];

        if ($tables !== [] || $this->anyPrefixed([
            ...array_keys($options), ...array_keys($cron), ...array_keys($transients),
            ...array_keys($meta), ...array_keys($roles), ...array_keys($actions),
        ])) {
            $lines[] = '';
            $lines[] = 'global $wpdb;';
        }

        if ($options !== []) {
            $lines[] = '';
            $lines[] = '// Options';
            foreach (array_keys($options) as $key) {
                $lines[] = 'delete_option(' . $this->keyExpression($key) . ');';
            }
        }

        if ($tables !== []) {
            $lines[] = '';
            $lines[] = '// Custom tables';
            foreach (array_keys($tables) as $key) {
                // A backtick inside an identifier would close the quoted name
                // early and let the rest read as SQL. Doubling it at run time
                // keeps every byte — from the literal or the site's prefix —
                // inside the name MySQL sees.
                $lines[] = "\$table = str_replace('`', '``', " . $this->keyExpression($key) . ');';
                $lines[] = '$wpdb->query("DROP TABLE IF EXISTS `{$table}`");';
            }
        }

        if ($cron !== []) {
            $lines[] = '';
            $lines[] = '// Scheduled events';
            foreach ($cron as $hook => $function) {
                $lines[] = $function . '(' . $this->keyExpression($hook) . ');';
            }
        }

        if ($transients !== []) {
            $lines[] = '';
            $lines[] = '// Transients';
            foreach (array_keys($transients) as $key) {
                $lines[] = 'delete_transient(' . $this->keyExpression($key) . ');';
            }
        }

        if ($meta !== []) {
            $lines[] = '';
            $lines[] = '// Metadata (removed across every object)';
            foreach ($meta as $key => $objectType) {
                $lines[] = $objectType === 'post'
                    ? 'delete_post_meta_by_key(' . $this->keyExpression($key) . ');'
                    : sprintf("delete_metadata('%s', 0, %s, '', true);", $objectType, $this->keyExpression($key));
            }
        }

        if ($roles !== []) {
            $lines[] = '';
            $lines[] = '// Roles (this also drops the capabilities they were created with)';
            foreach (array_keys($roles) as $role) {
                $lines[] = 'remove_role(' . $this->keyExpression($role) . ');';
            }
        }

        if ($actions !== []) {
            $lines[] = '';
            $lines[] = '// Action Scheduler jobs (the library may already be gone, so guard the call)';
            $lines[] = "if (function_exists('as_unschedule_all_actions')) {";
            foreach (array_keys($actions) as $hook) {
                $lines[] = '    as_unschedule_all_actions(' . $this->keyExpression($hook) . ');';
            }
            $lines[] = '}';
        }

        return $lines;
    }

    /**
     * The removals for what exists once per installation, however many sites
     * it runs: network options and transients, user meta (every site shares
     * the users table), and the files the plugin wrote. Then the report of
     * what is deliberately left alone.
     *
     * @param array<string, true> $siteOptions
     * @param array<string, true> $siteTransients
     * @param array<string, true> $userMeta
     * @param array<string, true> $dropins files to delete with wp_delete_file()
     * @param array<string, true> $muplugins files to delete with wp_delete_file()
     * @param array<string, true> $capabilities capabilities reported as a comment only
     * @param array<string, string> $content artifacts reported as a comment only
     * @return list<string>
     */
    private function networkWide(string $pluginName, array $siteOptions, array $siteTransients, array $userMeta, array $dropins, array $muplugins, array $capabilities, array $content): array
    {
        $lines = [];

        if ($this->anyPrefixed([...array_keys($siteOptions), ...array_keys($siteTransients), ...array_keys($userMeta)])) {
            $lines[] = '';
            $lines[] = 'global $wpdb;';
        }

        if ($siteOptions !== []) {
            // Network options and site options are the same rows;
            // delete_site_option delegates to delete_network_option.
            $lines[] = '';
            $lines[] = '// Network options';
            foreach (array_keys($siteOptions) as $key) {
                $lines[] = 'delete_site_option(' . $this->keyExpression($key) . ');';
            }
        }

        if ($siteTransients !== []) {
            $lines[] = '';
            $lines[] = '// Network transients';
            foreach (array_keys($siteTransients) as $key) {
                $lines[] = 'delete_site_transient(' . $this->keyExpression($key) . ');';
            }
        }

        if ($userMeta !== []) {
            $lines[] = '';
            $lines[] = '// User metadata (removed across every user)';
            foreach (array_keys($userMeta) as $key) {
                $lines[] = sprintf("delete_metadata('user', 0, %s, '', true);", $this->keyExpression($key));
            }
        }

        if ($dropins !== [] || $muplugins !== []) {
            // These files are code the plugin installed, not user data, so
            // deleting them is exactly what an uninstall is for. wp_delete_file()
            // over a bare unlink() because it runs the filters other caching
            // layers listen to.
            if ($dropins !== []) {
                // A drop-in name is a slot several plugins compete for: the
                // object-cache.php here now may belong to whatever replaced
                // this plugin. Delete it only while it still mentions us.
                $lines[] = '';
                $lines[] = '// Drop-ins (WordPress loads these on every request). Another plugin may';
                $lines[] = '// own the file by now, so it is removed only if it still names this one —';
                $lines[] = '// change the marker if your drop-in does not contain it.';
                foreach (array_keys($dropins) as $path) {
                    $lines[] = '$dropin = ' . $this->keyExpression($path) . ';';
                    $lines[] = sprintf(
                        'if (is_file($dropin) && stripos((string) file_get_contents($dropin), %s) !== false) {',
                        var_export($pluginName, true),
                    );
                    $lines[] = '    wp_delete_file($dropin);';
                    $lines[] = '}';
                }
            }
            if ($muplugins !== []) {
                $lines[] = '';
                $lines[] = '// Must-use plugin files (they load before normal plugins)';
                foreach (array_keys($muplugins) as $path) {
                    $lines[] = 'wp_delete_file(' . $this->keyExpression($path) . ');';
                }
            }
        }

        if ($capabilities !== []) {
            // A capability is stored inside a role's array, and which role
            // received it is not something static analysis can attribute —
            // remove_cap on the wrong role would silently do nothing. Report,
            // with the exact call to make once a human has checked.
            $lines[] = '';
            $lines[] = '// Capabilities this plugin grants may be left behind. Find the role that';
            $lines[] = '// holds each one (wp cap list <role>), then remove it explicitly:';
            foreach (array_keys($capabilities) as $cap) {
                $lines[] = '//   remove_cap( \'<role>\', ' . $this->commentSafe(var_export($cap, true)) . ' );';
            }
        }

        if ($content !== []) {
            // Deleting posts, terms, or an upload directory destroys user data,
            // and flushing rewrite rules is the site's call. An uninstall routine
            // must not do any of it silently — report and let a human decide.
            $lines[] = '';
            $lines[] = '// This plugin also leaves the following behind. They are intentionally NOT';
            $lines[] = '// removed here, because doing so could destroy data you want to keep:';
            foreach ($content as $name => $type) {
                $lines[] = sprintf('//   - %s "%s"', str_replace('_', ' ', $type), $this->commentSafe($name));
            }
        }

        return $lines;
    }

    /**
     * On a multisite network every site has its own copy of the per-site
     * data, and uninstall.php runs once, on the main site. So the per-site
     * removals sit in a closure that runs for every site on a network, and
     * once otherwise. `'number' => 0` matters: get_sites() stops at 100.
     *
     * @param list<string> $perSite
     * @param list<string> $networkWide
     */
    private function render(string $pluginName, array $perSite, array $networkWide): string
    {
        $lines = [
            '<?php',
            '',
            '/**',
            ' * Uninstall routine generated by Sediment for ' . $this->safeName($pluginName) . '.',
            ' *',
            ' * Removes the data this plugin leaves behind. Review before shipping:',
            ' * Sediment only emits artifacts it attributed with high confidence, so',
            ' * check the scan\'s coverage report for anything it could not resolve.',
            ' */',
            '',
            "if (!defined('WP_UNINSTALL_PLUGIN')) {",
            '    exit;',
            '}',
        ];

        if ($perSite !== []) {
            $lines[] = '';
            $lines[] = '// Every site on a multisite network keeps its own copy of this data.';
            $lines[] = '$sediment_uninstall_site = static function (): void {';
            foreach (array_slice($perSite, 1) as $line) { // each section opens with a blank line
                $lines[] = $line === '' ? '' : '    ' . $line;
            }
            $lines[] = '};';
            $lines[] = '';
            $lines[] = 'if (is_multisite()) {';
            $lines[] = "    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as \$site_id) {";
            $lines[] = '        switch_to_blog((int) $site_id);';
            $lines[] = '        $sediment_uninstall_site();';
            $lines[] = '        restore_current_blog();';
            $lines[] = '    }';
            $lines[] = '} else {';
            $lines[] = '    $sediment_uninstall_site();';
            $lines[] = '}';
        }

        array_push($lines, ...$networkWide);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build a PHP expression for a key, rebuilding every portable token:
     * "{prefix}logs" -> $wpdb->prefix . 'logs',
     * "{content_dir}/db.php" -> WP_CONTENT_DIR . '/db.php'.
     */
    private function keyExpression(string $key): string
    {
        $pattern = '/(\{prefix\}|\{content_dir\}|\{mu_plugins\}|\{plugin_dir\}|\{abspath\})/';
        if (!preg_match($pattern, $key)) {
            return var_export($key, true);
        }

        $expression = [];
        foreach (preg_split($pattern, $key, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($part === '') {
                continue;
            }

            // The table prefix is only knowable at run time; the roots are
            // constants WordPress itself defines.
            $expression[] = match ($part) {
                '{prefix}' => '$wpdb->prefix',
                '{content_dir}' => 'WP_CONTENT_DIR',
                '{mu_plugins}' => 'WPMU_PLUGIN_DIR',
                '{plugin_dir}' => 'WP_PLUGIN_DIR',
                '{abspath}' => 'ABSPATH',
                default => var_export($part, true),
            };
        }

        return implode(' . ', $expression);
    }

    /**
     * @param list<string> $keys
     */
    private function anyPrefixed(array $keys): bool
    {
        foreach ($keys as $key) {
            if (str_contains($key, self::PREFIX_TOKEN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text from the scanned plugin's source, made safe for a `//` comment in a
     * file people run. A newline ends the comment early, and so does `?>` — it
     * leaves PHP mode, and a `<?php` after it would be live code.
     */
    private function commentSafe(string $text): string
    {
        return str_replace(["\r", "\n", '?>'], [' ', ' ', '? >'], $text);
    }

    private function safeName(string $name): string
    {
        // Keep the docblock intact and printable.
        return trim(str_replace(['*/', "\n", "\r"], ' ', $name)) ?: 'this plugin';
    }
}
