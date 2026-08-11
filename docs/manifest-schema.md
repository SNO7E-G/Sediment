# Manifest schema

`sediment scan <path> --json` emits the manifest: the machine-readable result of
a scan. It is the contract other tools build on — CI checks, the forthcoming
Index, and the WordPress plugin all read the manifest rather than reaching into
the analyzer.

Current `schema_version`: **`2.0`**, and frozen: the machine-readable
definition is [`schema/manifest.schema.json`](../schema/manifest.schema.json),
every manifest the test suite produces is validated against it in CI, and what
a change to it costs is written down in [stability.md](stability.md). Build a
consumer from the schema file; this page is the commentary, not the authority.

Manifests published with `schema_version: "1.0"` predate the freeze — that
version was mutable alpha output and self-identifies as such.

## Shape

```jsonc
{
  "schema_version": "2.0",
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
    "files_scanned": 214,          // PHP files read
    "files_skipped": 2,            // of those, files that could not be parsed
    "write_calls_found": 25,
    "verified": 18,
    "resolved": 5,
    "pattern": 1,
    "dynamic": 1,
    "resolution_rate": 0.92       // (verified + resolved) / write_calls_found
  },
  "cleanup": {
    "has_uninstall_php": true,
    "has_uninstall_hook": false,
    "conditional": true,                        // cleanup runs only if a setting says so
    "condition_option": "example_delete_data",  // the gating option, null when unconditional
    "condition_default": false                  // what that option defaults to
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
    "directories": [], "rewrite_rules": [],
    "actions": []                 // Action Scheduler jobs
  },
  "modifies_core": [
    {
      "type": "option",
      "key": "active_plugins",     // WordPress's own data, not the plugin's
      "confidence": "verified",
      "sources": [{ "file": "api/api-plugin.php", "line": 224 }]
    }
  ],
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

- **Every group in `creates` is always present**, even when empty. Adding
  detection for a new artifact type never changes the shape of the document.
- **Placeholders are literal tokens, never expanded.** `{prefix}` stands for the
  table prefix, and `{content_dir}`, `{plugin_dir}`, and `{abspath}` stand for
  the corresponding filesystem roots. Substitute the real values at the point of
  use; treating them literally makes every finding wrong on a site whose prefix
  or layout differs from the default.
- **`cleanup.conditional` marks a plugin that only cleans up when a setting says
  so**, with `condition_option` naming that setting and `condition_default`
  giving the value it takes on a site where the user never touched it. This is
  what separates grade B from grade A.
- **`sources` is always an array.** The same key is often written from several
  places, and all of them are reported.
- **`cleaned` is per item**, not per plugin. Partial cleanup is the common case,
  and a plugin-level boolean would discard the most useful information here.
- **`confidence` travels with every item**, so filter on it rather than trusting
  the list wholesale. Only `verified` and `resolved` are safe to act on; `pattern`
  needs human confirmation and `dynamic` is never actionable.
- **`coverage` says how much source stood behind the document.** A parse
  failure never aborts a scan — the file is skipped and counted in
  `files_skipped` — and `resolution_rate` covers only the write calls that were
  found. Weigh a grade by both before trusting it.
- **`unresolved` is first-class, not hidden.** Writes Sediment could not resolve
  are listed with their raw expression and location, which is what makes the
  `resolution_rate` auditable rather than a claim.
- **WordPress core artifacts never appear under `creates`.** Plugins really do
  write `active_plugins`, `blogname`, and `rewrite_rules`; that is modifying
  WordPress's own data, not leaving something behind. Such writes are reported
  under **`modifies_core`** — useful to know, never removable, and never counted
  against a grade.

## Grouping

Findings are grouped by key within their type: one option written from three
places is a single entry with three `sources`. An entry is `cleaned` only when
**every** one of those writes is matched by a removal that actually runs on
uninstall — a cron hook scheduled both with and without arguments needs both
cleared, and crediting it on one would report a plugin as spotless while an
event kept firing. An option is reported as autoloaded if any write autoloads it.
