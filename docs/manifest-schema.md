# Manifest schema

`sediment scan <path> --json` emits the manifest: the machine-readable result of
a scan. It is the contract other tools build on — CI checks, the forthcoming
Index, and the WordPress plugin all read the manifest rather than reaching into
the analyzer.

Current `schema_version`: **`1.0`**. Sediment is in alpha, so the schema may
still change before 1.0; the field exists so a consumer can tell.

## Shape

```jsonc
{
  "schema_version": "1.0",
  "plugin": {
    "slug": "example-plugin",     // directory name
    "name": "Example Plugin",     // from the plugin header, null if absent
    "version": "2.4.1",           // from the plugin header, null if absent
    "source": "local",
    "scanned_at": "2026-07-26T10:00:00Z",
    "analyzer_version": "0.2.0"
  },
  "grade": "D",
  "score": 55,                    // 0–100, weighted by damage
  "coverage": {
    "write_calls_found": 25,
    "verified": 18,
    "resolved": 5,
    "pattern": 1,
    "dynamic": 1,
    "resolution_rate": 0.92       // (verified + resolved) / write_calls_found
  },
  "cleanup": {
    "has_uninstall_php": true,
    "has_uninstall_hook": false
  },
  "creates": {
    "options": [
      {
        "key": "example_settings",
        "autoload": "yes",        // "yes" | "no" | "unknown" (options only)
        "confidence": "verified",
        "cleaned": false,
        "sources": [{ "file": "includes/setup.php", "line": 88 }]
      }
    ],
    "tables": [],
    "cron": [],                   // entries carry "recurrence" (e.g. "daily", "single")
    "transients": [],
    "post_meta": [], "user_meta": [], "term_meta": [], "comment_meta": [],
    "roles": [], "capabilities": [],
    "post_types": [], "taxonomies": [],
    "directories": [], "rewrite_rules": []
  },
  "unresolved": [
    {
      "function": "update_option",
      "expression": "$this->build_key($section)",
      "file": "includes/settings.php",
      "line": 210
    }
  ]
}
```

## Rules a consumer can rely on

- **Every group in `creates` is always present**, even when empty — including
  types Sediment does not detect yet (`directories`, `rewrite_rules`). Adding
  detection for one never changes the shape of the document.
- **`{prefix}` is a literal placeholder**, never a hardcoded `wp_`. Substitute the
  real table prefix at the point of use. Getting this wrong makes every finding
  wrong on a site with a custom prefix.
- **`sources` is always an array.** The same key is often written from several
  places, and all of them are reported.
- **`cleaned` is per item**, not per plugin. Partial cleanup is the common case,
  and a plugin-level boolean would discard the most useful information here.
- **`confidence` travels with every item**, so filter on it rather than trusting
  the list wholesale. Only `verified` and `resolved` are safe to act on; `pattern`
  needs human confirmation and `dynamic` is never actionable.
- **`unresolved` is first-class, not hidden.** Writes Sediment could not resolve
  are listed with their raw expression and location, which is what makes the
  `resolution_rate` auditable rather than a claim.
- **WordPress core artifacts never appear** in a form that invites deletion.

## Grouping

Findings are grouped by key within their type: one option written from three
places is a single entry with three `sources`. An entry is `cleaned` if any of
those writes is matched by a removal that actually runs on uninstall, and an
option is reported as autoloaded if any write autoloads it.
