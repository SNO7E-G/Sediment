# Detection patterns

What Sediment looks for, and how each finding is attributed. Detection is
organized by artifact type; every finding also carries a confidence level.

## Confidence levels

| Level | Meaning | Example |
| --- | --- | --- |
| `verified` | The key is a literal string. | `add_option('mp_version', …)` |
| `resolved` | The key resolves statically from a `define()` constant, a class constant, or a literal property. | `add_option(self::PREFIX . 'settings')` |
| `pattern` | The key is partly dynamic but has a stable leading prefix, reported as `prefix*`. | `update_post_meta($id, '_mp_' . $f, …)` → `_mp_*` |
| `dynamic` | The key is only knowable at runtime. Recorded with its raw source, never treated as certain. | `update_option($key)` |

Resolution runs in two passes: first a symbol-table pass harvests `define()`
constants, class constants, and literal properties across the whole plugin;
then detection resolves each key against that table. This is what lets
`self::PREFIX . 'key'` become `mp_key` instead of falling to `dynamic`.

## Artifacts

### Options

`add_option`, `update_option`, `add_site_option`, `update_site_option`. The
autoload flag is captured where it applies (`add_option` arg 4, `update_option`
arg 3); site/network options are not autoloaded.

`register_setting` is intentionally *not* treated as a create: it registers a
setting with the Settings API but does not itself write an option row, so
counting it would manufacture false positives.

### Tables

`dbDelta()` and direct `CREATE TABLE` via `$wpdb->query()`. The SQL string is
resolved first — `$wpdb->prefix` and `$wpdb->base_prefix` become the `{prefix}`
token, and SQL assigned to a local variable is followed — then the table name is
read from the resolved string with a single regex. The `{prefix}` token is kept
verbatim (never a hardcoded `wp_`) so the finding stays correct on custom-prefix
sites.

### Cron

`wp_schedule_event` (recurring, with a recurrence such as `daily`) and
`wp_schedule_single_event` (one-off). The finding is keyed by the hook name.

### Transients

`set_transient` and `set_site_transient`, keyed by the transient name. Each
transient is stored by WordPress as two option rows — `_transient_{key}` and
`_transient_timeout_{key}` (site variants use the `_site_transient_` prefix).
Sediment records the canonical transient name; the twin rows are materialized
later, by the uninstall generator.

## Cleanup

The cleanup path is parsed with the same engine. Removal calls — `delete_option`,
`delete_site_option`, `delete_transient`, `delete_site_transient`,
`wp_clear_scheduled_hook`, `wp_unschedule_hook`, `wp_unschedule_event`, and
`DROP TABLE` via `$wpdb->query()` — are matched against the creates they mirror,
and every created artifact carries a `cleaned` flag.

A removal only counts when it actually runs on uninstall: inside `uninstall.php`,
or inside a function or method registered via `register_uninstall_hook`. A
`delete_option()` called during normal operation does not credit cleanup.
Matching is by exact key within an artifact type; a partly-dynamic (`pattern`)
key is reported as not cleaned rather than guessed.

Naming the key is not always enough. A cron event scheduled *with arguments* is
not removed by an argument-less `wp_clear_scheduled_hook()` — that call only
clears events registered without arguments — so such an event is reported as not
cleaned unless `wp_unschedule_hook()` is used, which clears every event for the
hook. The mirror also holds: a `wp_clear_scheduled_hook($hook, $args)` clears
only events registered with those arguments, so it does not credit an
argument-less one.

Removals are also read for the newer types: `as_unschedule_all_actions` and
`as_unschedule_action` for Action Scheduler, `rmdir` for directories, and
`flush_rewrite_rules`, which rebuilds the routing table and so clears every rule
the plugin registered.

**Conditional cleanup.** When the uninstall path is gated on a stored setting,
the cleanup is recorded as conditional along with the gating option and its
default. Four shapes are recognised: an `if` that bails out early, one that wraps
the removals, one that calls a function which performs them, and one written as
an `elseif`. The option may be read in the condition itself or into a variable
the condition tests.

An `if` that reads an option without gating cleanup is not a condition, and a
`return` inside a closure is not a bail-out. Sediment reports *which* setting
decides, never which way it must be set — "keep my data" gates are as common as
"delete my data" ones, and the comparison itself is not evaluated.

### Metadata

`add_*_meta` and `update_*_meta` for posts, users, terms, and comments, keyed by
the meta key. `register_meta` is also read, but its object type comes from the
first argument rather than the function name: that argument is resolved and
mapped to one of `post`, `user`, `term`, or `comment`. If it does not resolve to
one of those four, nothing is emitted — guessing which meta table a call touches
would be worse than missing it.

### Roles, capabilities, and content types

`add_role` (including the capability names in its literal capabilities array),
`$role->add_cap()`, `register_post_type`, and `register_taxonomy`.

Content types matter more than their row count suggests. Uninstall a plugin that
registered one and its posts remain in `wp_posts` with no UI that renders them —
often tens of thousands of rows. Prefix matching cannot detect this at all, which
is why an orphaned post type caps a plugin's grade at D.

Sediment reports registered content but **never generates code to delete it**.
Removing posts or terms destroys user data, and that decision belongs to a human.

### Directories, rewrite rules, and Action Scheduler

`wp_mkdir_p` and `mkdir` for directories, with `WP_CONTENT_DIR`, `WP_PLUGIN_DIR`,
and `ABSPATH` rewritten to `{content_dir}`, `{plugin_dir}`, and `{abspath}` —
the same portability trick as `{prefix}`, so a finding is not tied to one
install's layout. A path that is only a root, with nothing under it, is skipped.

`add_rewrite_rule`, `add_rewrite_endpoint`, and `add_rewrite_tag` for routing.
`as_schedule_recurring_action`, `as_schedule_single_action`,
`as_schedule_cron_action`, and `as_enqueue_async_action` for Action Scheduler,
the queue library many larger plugins use instead of WP-Cron.

## Not yet detected

Options written through direct `$wpdb` SQL, widget instances, theme mods, and
`wp-config.php` / `.htaccess` edits. See [limitations](limitations.md).
