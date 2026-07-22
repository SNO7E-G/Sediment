# Changelog

All notable changes to Sediment are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed — correctness and safety hardening

Following a full adversarial review of the analyzer, a set of false-confidence,
unsafe-generation, and robustness issues were closed. Each fix errs toward
under-claiming, never toward guessing:

- Namespaces resolve to fully-qualified names, so classes or functions that share
  a short name across namespaces no longer cross-resolve or wrongly credit an
  uninstall callback. Anonymous classes are keyed per file.
- Variables bound by parameters, `foreach`, `global`, `static`, `catch`, and
  destructuring resolve to `dynamic` — a single literal assignment can no longer
  claim a variable whose value actually varies at runtime.
- Table SQL is read with anchored, statement-aware parsing shared by detection
  and cleanup: `CREATE TABLE` inside an INSERT value is ignored, and every
  statement in a multi-statement `dbDelta` is captured.
- Cleanup credit is scoped to what actually runs on uninstall — confident
  removals only, in the plugin-root `uninstall.php` (a top-level statement or a
  function it invokes) or a registered callback, matched case-insensitively. A
  `$wpdb` table drop requires the `->query` method.
- The WordPress core allowlist gained roles, `uninstall_plugins`, and more cron
  hooks, so a generated `uninstall.php` can never delete `wp_user_roles` or peers.
- The generator rebuilds the `{prefix}` token from `$wpdb->prefix` for every
  artifact type, wherever it appears in the key.
- The grader counts unique keys rather than call sites, treats unknown-autoload
  options as autoloaded, caps the score when there is no uninstall routine, and
  no longer reports "creates no data" for a plugin whose writes were merely
  unresolvable.
- Scans survive an unreadable directory, and scanned source is escaped before it
  reaches the terminal report.

## [0.1.0-beta] — 2026-07-22

The first public preview: a complete static analyzer for a WordPress plugin's
database footprint, from detection through grading and teardown generation. It
reads source only — no WordPress runtime, no database.

### Added

- A two-pass analyzer with a symbol-table pass — `define()` and `const`, class
  constants, and literal properties — so a key built from a symbol in one file
  resolves even when it is used in another.
- An expression resolver covering literals, constants, class constants (`self::`,
  `Foo::`), `$this->` properties, string concatenation, string interpolation, and
  straight-line local variables. When a key is only partly known it degrades to a
  `pattern` prefix; when it cannot be known, to an honest `dynamic`.
- Detection of **options** (with the autoload flag), **custom tables** (`dbDelta`
  and `CREATE TABLE` via `$wpdb->query`, with `$wpdb->prefix` resolved to a
  `{prefix}` token), **cron events** (with recurrence), and **transients**.
- A cleanup diff that parses `uninstall.php` and `register_uninstall_hook`
  callbacks with the same engine and marks every artifact cleaned or left behind.
- Three commands: `sediment scan` (a grouped report with a resolution rate and
  cleanup summary), `sediment grade` (an A–F letter and a 0–100 weighted-damage
  score), and `sediment uninstall` (a generated, `php -l`-valid teardown covering
  only the high-confidence, non-core artifacts a plugin leaves behind).
- A WordPress core allowlist and a dedicated `core-protection` CI job asserting
  that core artifacts can never enter a deletable set.
- A test suite spanning symbol resolution, autoload capture, interpolation and
  pattern extraction, cross-file constants, the cleanup diff, grading, generation,
  and hostile-input degradation.

### Security

- The resolver poisons ambiguous symbols to `dynamic` rather than guessing:
  conflicting or non-literal definitions, `static::`, and constructor-supplied
  properties never resolve to a stale literal. PHP 8 named arguments resolve by
  name, and first-class callables are ignored rather than crashing a scan.

[Unreleased]: https://github.com/SNO7E-G/Sediment/compare/Beta...main
[0.1.0-beta]: https://github.com/SNO7E-G/Sediment/releases/tag/Beta
