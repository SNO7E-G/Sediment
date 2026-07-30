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

- **Members reached through a subclass that redefines them.** A constant or
  property declared on a base class resolves for subclasses that do not redefine
  it, and `static::` resolves when no subclass redefines it — but when one does,
  the value depends on the object at runtime, so it stays `dynamic`.
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

Options written via direct `$wpdb` SQL, widget instances, theme mods
(`set_theme_mod`), site meta on multisite, and `wp-config.php` / `.htaccess`
edits. These are on the roadmap.

Some things are detected but deliberately never written into a generated
`uninstall.php`: **post types and taxonomies**, because deleting posts or terms
destroys user content; **directories**, because removing one can take user
uploads with it; **rewrite rules**, because flushing routing is the site's call;
and **capabilities granted via `$role->add_cap()`**, because Sediment does not
track which role received them. All are reported so a human can decide.

## Scope of a scan

- Analysis is per file within the plugin directory. Following `require`/`include`
  of literal paths to build the full file set is planned but not yet done.
- Bundled dependencies (`vendor/`, `node_modules/`) and test directories are
  excluded by design, so a plugin is never blamed for code it did not author.

## Database and cleanup edges

Names are resolved to their fully-qualified form, so two classes or functions
that share a short name across namespaces no longer collide. A few edges remain,
and all err toward *not* crediting cleanup or resolution:

- **`$wpdb` is recognized by name, in three shapes only:** `$wpdb`,
  `$this->wpdb`, and `self::$wpdb`. An alias under a different name
  (`$db = $wpdb;`) or reached through another object is missed rather than
  assumed, because accepting it would let an unrelated `->query()` credit a table
  drop that never happens. `base_prefix` is treated the same as `prefix`, which
  differs on a multisite subsite.
- **A table name cut short by a dynamic tail is not reported at all.**
  `"CREATE TABLE {$wpdb->prefix}logs{$suffix}"` resolves only as far as
  `{prefix}logs`, which is not the real name, so nothing is claimed.
- **`pattern` keys are never matched during the cleanup diff** — a partly-dynamic
  create is reported as *not cleaned* rather than guessed as removed.
- **Conditional cleanup is detected structurally, not semantically.** Sediment
  sees that an `if` gates the uninstall path and which option it reads; it does
  not evaluate the comparison, so it reports which setting decides but never
  which way it must be set. A gate that can never actually be false is still
  reported as conditional.
- **Directories and rewrite rules are reported but never removed** by the
  generated `uninstall.php`. Deleting a directory can destroy user uploads, and
  flushing rewrite rules on uninstall is the site's decision, not a plugin's.
