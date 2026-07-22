# Limitations

Publishing its own blind spots is the most credibility-building thing an audit
tool can do, and it pre-empts the first issue anyone would file. Here is what
Sediment cannot do, and why.

## Static analysis cannot see runtime values

A key assembled entirely at runtime — `update_option($key)` where `$key` comes
from a function argument or user input — is genuinely unknowable from source.
Sediment reports these as `dynamic`, preserves the raw expression, and never
guesses. The scan's resolution rate tells you how much of a plugin fell into
this bucket, so the coverage is honest rather than hidden.

## What is not resolved yet (these fall to `dynamic`, which is always safe)

Sediment errs toward `dynamic` whenever it cannot be certain — under-claiming a
key can never cause a wrong deletion, while over-claiming can. The following are
known cases it does not yet resolve, and reports honestly instead of guessing:

- **`static::` and `parent::` members**, and any constant or property declared
  only on a parent class. The inheritance graph is walked just far enough to
  *poison* an overridden property (so it degrades to `dynamic`), not to resolve
  an inherited one.
- **Constants in traits, enums, and interfaces.**
- **`define()` / `const` values built from concatenation or other constants**, and
  keys built with `sprintf()` or other function calls.
- **Promoted constructor properties, and any property set from a constructor
  argument** — the value comes from the caller, so it is treated as dynamic.
- **Fully runtime keys** — anything assembled from a variable, request input, or a
  method's return value.

Where a key has a stable leading literal (`'mp_' . $x`), it is reported as a
`pattern` (`mp_*`) rather than lost entirely.

## What is not detected yet

Metadata (post/user/term/comment), roles and capabilities, custom post types and
taxonomies, filesystem writes, rewrite rules, options written via direct `$wpdb`
SQL, and Action Scheduler jobs. These are on the roadmap.

## Scope of a scan

- Analysis is per file within the plugin directory. Following `require`/`include`
  of literal paths to build the full file set is planned but not yet done.
- Bundled dependencies (`vendor/`, `node_modules/`) and test directories are
  excluded by design, so a plugin is never blamed for code it did not author.

## Database and cleanup edges

Names are resolved to their fully-qualified form, so two classes or functions
that share a short name across namespaces no longer collide. A few edges remain,
and all err toward *not* crediting cleanup or resolution:

- **`$wpdb` is recognized by variable name.** `$wpdb->prefix` and
  `$wpdb->query(...)` are understood, but an aliased handle (`$db = $wpdb;`) or a
  wrapped one (`$this->db->query(...)`) is not tracked. `base_prefix` is treated
  the same as `prefix`, which differs on a multisite subsite.
- **Cron events scheduled *with arguments*** are matched by hook name only. An
  args-less `wp_clear_scheduled_hook()` is credited as clearing the hook, though
  at runtime it clears only events scheduled without arguments.
- **`pattern` keys are never matched during the cleanup diff** — a partly-dynamic
  create is reported as *not cleaned* rather than guessed as removed.
