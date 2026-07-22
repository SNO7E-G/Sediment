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

## Not yet detected

Metadata (post/user/term/comment), roles and capabilities, custom post types
and taxonomies, filesystem writes, and rewrite rules are on the roadmap. See
[limitations](limitations.md).
