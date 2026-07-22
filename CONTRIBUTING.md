# Contributing to Sediment

Thanks for considering a contribution. Sediment is an analyzer people are meant
to trust with a batch run across thousands of plugins, so the bar for changes —
especially anything that affects how a key is attributed — is deliberately high.
This guide covers how to get set up and what a good change looks like.

## Getting started

You need PHP 8.1+ and Composer. No WordPress install is required; Sediment reads
source, it never runs it.

```bash
git clone https://github.com/SNO7E-G/Sediment.git
cd Sediment
composer install
composer test
```

Run the analyzer against any plugin directory while you work:

```bash
php bin/sediment scan path/to/plugin
```

## The one rule that matters most

**Never present a guess as a fact.** Every finding carries a confidence level
(`verified`, `resolved`, `pattern`, `dynamic`). If a change would report a key as
`verified` or `resolved` when it might not be that literal at runtime, it is a
bug, not a feature — a false "resolved" is the most dangerous thing this tool can
produce, because later phases may act on it. When in doubt, degrade to `pattern`
or `dynamic`. Under-claiming is safe; over-claiming is not.

## Adding a detection

Most contributions add or refine a detection. The shape is consistent:

1. Add a visitor under `src/Analyzer/Visitors/` that extends
   `AbstractDetectionVisitor`, or extend an existing one. Resolve keys with
   `$this->resolveKey()` — never re-implement resolution or reach for a regex on
   raw PHP.
2. If the Scanner needs to run it, register it (see `Scanner::OPTIONAL_VISITORS`).
3. Add a small **fixture** under `tests/fixtures/` that isolates the pattern —
   ideally one `verified`, one `resolved`, and one `dynamic` case — and a test
   that pins the expected findings. Fixtures are both the regression net and the
   spec for how a pattern should be read.

## Code style

- `declare(strict_types=1)` in every file; `final` classes by default.
- PSR-4 autoloading under the `Sediment\` namespace; PSR-12 formatting.
- Keep comments about *why*, and reference the spec sections (`§`) where a
  decision comes from one.
- No new runtime dependencies without discussion.

## Pull requests

- Keep each PR focused on one change.
- `composer test` must be green, including the malformed-input case.
- Update `CHANGELOG.md` under `## [Unreleased]`.
- Describe what the change detects (or fixes) and, if it touches resolution,
  which confidence level the affected keys land on and why.

## Reporting a mis-detection

If Sediment attributes a key incorrectly for a real plugin, that is a
high-value bug report. Open an issue with the plugin, the version, the offending
source line, and what the finding should have been. A failing fixture attached
to the issue is the fastest path to a fix.
