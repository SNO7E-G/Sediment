# Changelog

All notable changes to Sediment are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the project is
pre-1.0, minor versions may still change public interfaces.

## [0.1.2] — 2026-07-26

### Added

- **`sediment scan --json`** emits the machine-readable manifest (schema `1.0`):
  plugin metadata read from the plugin header, grade and score, coverage counts
  with a resolution rate, the cleanup path, and every artifact grouped by key
  with its confidence, `cleaned` flag, and all the `sources` that write it.
  Unresolvable writes are listed under `unresolved` rather than hidden, and the
  artifact types arriving in v0.2 are present as empty arrays so the schema never
  breaks for consumers.

  This is the contract every downstream consumer uses — CI checks, the Index, and
  the WordPress plugin all read the manifest rather than the analyzer's internals.

## [0.1.1] — 2026-07-22

A correctness and safety pass following a full adversarial review of the
analyzer. Every change here errs toward under-claiming rather than guessing: a
key is only reported as owned, cleaned, or safe to delete when the source
genuinely proves it.

### Fixed

- **Cross-namespace resolution.** Names are resolved to their fully-qualified
  form, so two classes or functions that share a short name in different
  namespaces no longer cross-resolve, and a same-named method in an unrelated
  class can no longer credit an uninstall callback.
- **Local-variable binding.** Variables bound by function parameters, `foreach`,
  `global`, `static`, `catch`, and list/array destructuring now resolve to
  `dynamic`. Previously a single literal assignment could claim a variable whose
  value actually varies at runtime, which could flow into a generated deletion.
- **Anonymous-class collisions.** Anonymous classes are keyed per file, closing a
  cross-file symbol leak.
- **Table SQL parsing.** Detection and cleanup share one anchored, statement-aware
  parser: `CREATE TABLE` appearing inside an INSERT value or a comment is no
  longer mistaken for a table, and every statement in a multi-statement `dbDelta`
  string is captured rather than only the first.
- **Cleanup scoping.** Credit is limited to code that actually runs on uninstall:
  only confident removals count, only inside the plugin-root `uninstall.php` (a
  top-level statement or a function it invokes) or a registered callback matched
  case-insensitively. A dead function defined in `uninstall.php` no longer credits
  cleanup, a `$wpdb` table drop now requires the `->query` method, and a
  `pattern`/`dynamic` removal never credits a specific create.
- **Never crash (M14).** A scan survives an unreadable subdirectory instead of
  aborting, and the first pass is guarded like the second.

### Added

- Roles and plugin-lifecycle options — `user_roles` / `wp_user_roles` /
  `{prefix}user_roles`, `uninstall_plugins`, `recently_activated`, and more — plus
  additional core cron hooks in the WordPress core allowlist, so a generated
  `uninstall.php` can never emit a delete for `wp_user_roles` or its peers.
- Case-insensitive uninstall-callback matching, and per-function argument names
  for removal detection.

### Changed

- The generated `uninstall.php` rebuilds the `{prefix}` token from `$wpdb->prefix`
  for options, cron, and transients (not only tables) and wherever it appears in
  a key; the plugin name in the header docblock is sanitized.
- The grader counts unique keys rather than call sites, treats unknown-autoload
  options as autoloaded, caps the score when a plugin has no uninstall routine,
  and reports low coverage instead of "creates no data" when a plugin's writes
  were merely unresolvable.
- Scanned source is escaped before it reaches the terminal report.

### Documentation

- Rewrote the README with an accurate sample of the scan output and a Limitations
  section, and documented the remaining `$wpdb`-aliasing and cron-with-arguments
  edges.

## [0.1.0] — 2026-07-22

The first public preview: a complete static analyzer for a WordPress plugin's
database footprint, from detection through grading and teardown generation. It
reads source only — no WordPress runtime, no database — and runs on PHP 8.3+.

### Added

- A two-pass analyzer with a symbol-table pass (`define()` and `const`, class
  constants, and literal properties) so a key built from a symbol in one file
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
- Three commands — `sediment scan` (a grouped report with a resolution rate and
  cleanup summary), `sediment grade` (an A–F letter and a 0–100 weighted-damage
  score), and `sediment uninstall` (a generated, `php -l`-valid teardown covering
  only the high-confidence, non-core artifacts a plugin leaves behind).
- A WordPress core allowlist and a dedicated `core-protection` CI job asserting
  that core artifacts can never enter a deletable set.
- A test suite spanning symbol resolution, autoload capture, interpolation and
  pattern extraction, cross-file constants, the cleanup diff, grading, generation,
  and hostile-input degradation, on a PHP 8.3 / 8.4 / 8.5 matrix.

### Security

- The resolver poisons ambiguous symbols to `dynamic` rather than guessing:
  conflicting or non-literal definitions, `static::`, and constructor-supplied
  properties never resolve to a stale literal. PHP 8 named arguments resolve by
  name, and first-class callables are ignored rather than crashing a scan.

[0.1.2]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.1.2
[0.1.1]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.1.1
[0.1.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.1.0
