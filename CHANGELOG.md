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
- `sediment scan` — a grouped terminal report with a per-scan resolution rate.
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

### Notes

This is pre-release work toward v0.1.0. Public interfaces and the manifest
schema may still change.

[Unreleased]: https://github.com/SNO7E-G/Sediment/commits/main
