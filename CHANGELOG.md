# Changelog

All notable changes to Sediment are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project aims to
follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) once it reaches
a tagged release.

## [Unreleased]

### Added

- Two-pass analyzer: a symbol-table pass — `define()` constants, class
  constants, and literal properties — runs before detection, so a key built
  from a symbol in one file resolves even when it is used in another.
- Expression resolver covering literals, constants, class constants (`self::`,
  `Foo::`), `$this->` properties, string concatenation, and string
  interpolation. When a key is only partly known it degrades to a `pattern`
  carrying the stable prefix; when it cannot be known it degrades to an honest
  `dynamic` with the raw source preserved.
- Option detection (`add_option`, `update_option`, `add_site_option`,
  `update_site_option`) with per-finding confidence and autoload capture.
- Cron detection (`wp_schedule_event`, `wp_schedule_single_event`) with recurrence.
- Transient detection (`set_transient`, `set_site_transient`).
- Table detection (`dbDelta`, and `CREATE TABLE` via `$wpdb->query`): the SQL is
  resolved first — `$wpdb->prefix` becomes the `{prefix}` token and SQL held in a
  local variable is followed — then the table name is read from the resolved string.
- Straight-line local-variable resolution within a function, poisoned on any
  conflicting or non-literal reassignment, so a key or SQL string assigned to a
  variable before use still resolves.
- Cleanup diff: `uninstall.php` and `register_uninstall_hook` callbacks are parsed
  with the same engine to detect removals (`delete_option`, `DROP TABLE`,
  `wp_clear_scheduled_hook`, `delete_transient`, …). Every created artifact gets a
  per-item `cleaned` flag, and a removal only counts when it actually runs on
  uninstall — inside `uninstall.php` or a registered callback — never at runtime.
- `sediment scan` — a grouped terminal report with a per-scan resolution rate and
  a cleanup summary.
- `sediment grade` — an A–F letter and a 0–100 weighted-damage score derived from
  the cleanup diff, weighting autoloaded options, tables, and cron the heaviest,
  and excluding core and unresolvable artifacts from the verdict.
- `sediment uninstall` — generates a syntactically valid `uninstall.php` covering
  the verified/resolved artifacts a plugin leaves behind. It never emits a core,
  already-cleaned, `pattern`, or `dynamic` key, and rebuilds `{prefix}` from
  `$wpdb->prefix`.
- WordPress core allowlist (options, tables, cron hooks) and the safety-invariant
  test — core artifacts never enter a deletable set — with its own
  `core-protection` CI job (§13).
- Test suite covering symbol resolution, autoload capture, interpolation and
  pattern extraction, cross-file constants, directory exclusion, the safety
  invariant, and a malformed-input case proving the parser degrades instead of
  fataling.

### Hardening — correctness and safety

- The resolver now defends against *false confidence*, the most dangerous
  failure. Conflicting or non-literal symbols poison to `dynamic` rather than
  trusting a stale literal; `static::` and constructor-parameter/promoted
  properties are treated as dynamic; properties overridden in a subclass are
  reconciled to dynamic; class names match case-insensitively; duplicate
  `define()`s and same-short-name class collisions no longer resolve to a guess.
- PHP 8 named arguments are resolved by name, and first-class callables
  (`add_option(...)`) are ignored instead of crashing the scan (M14).
- Detection is wrapped so an unexpected node can never abort a whole batch run.
- Namespaces are resolved to fully-qualified names, so classes/functions sharing
  a short name across namespaces no longer cross-resolve or falsely credit an
  uninstall callback. Anonymous classes are keyed per file.
- Local variables bound by parameters, `foreach`, `global`, `static`, `catch`,
  and destructuring poison to `dynamic` — one literal assignment can no longer
  claim a variable whose value actually varies.
- Table SQL is read with anchored, statement-aware parsing shared by detection
  and cleanup: `CREATE TABLE` inside an INSERT value is ignored, and every
  `CREATE`/`DROP` in a multi-statement `dbDelta` string is captured.
- Cleanup credit is scoped to what actually runs on uninstall: only confident
  removals, only the plugin-root `uninstall.php` (top-level or a function it
  invokes) or a registered callback (matched case-insensitively); a `$wpdb`
  table drop requires the `->query` method.
- Expanded the WordPress core allowlist (roles, `uninstall_plugins`, more cron
  hooks) so the generator can never emit a delete for `wp_user_roles` and peers.
- The grader counts unique keys (not call sites), treats unknown-autoload options
  as autoloaded, caps the F score, and stops claiming "creates no data" when a
  plugin's writes were merely unresolvable.

### Notes

This is pre-release work toward v0.1.0. Public interfaces and the manifest
schema may still change.

[Unreleased]: https://github.com/SNO7E-G/Sediment/commits/main
