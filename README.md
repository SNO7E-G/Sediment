<h1 align="center">Sediment</h1>

<p align="center"><em>Everything your plugins left behind, layer by layer.</em></p>

<p align="center">A static analyzer that reads a WordPress plugin's source and reports exactly what it leaves behind in your database.</p>

<p align="center">
  <a href="https://github.com/SNO7E-G/Sediment/actions/workflows/tests.yml"><img src="https://github.com/SNO7E-G/Sediment/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/SNO7E-G/Sediment/releases"><img src="https://img.shields.io/github/v/release/SNO7E-G/Sediment?include_prereleases&label=release&color=blue" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/status-alpha-orange.svg" alt="Status: alpha">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg" alt="PHP 8.3+">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg" alt="License: GPL-2.0-or-later"></a>
</p>

---

Uninstalling a WordPress plugin deletes its files and almost nothing else. The options it registered keep autoloading on every request, its custom tables keep bloating your backups, and its cron events keep firing hooks whose code is long gone. Analysis of the plugin directory has found that more than 40% of plugins leave orphaned data behind, and only 28.6% remove it cleanly — a problem that has been documented since 2008 and remains unsolved.

The hard part was never deletion. It is attribution: knowing that a stray `smk_last_sync_ts` row belongs to a plugin you removed years ago, and is therefore safe to drop. Cleanup tools guess at this by matching name prefixes, and a wrong guess in a tool that deletes data will wreck a site.

Sediment takes a different route. It reads the source. A plugin's own code contains the literal calls that create its data — `add_option()`, `dbDelta()`, `wp_schedule_event()` — so parsing them statically turns attribution from a guess into something you can point at: `includes/setup.php:412`.

## What sets it apart

It reads code but never runs it. There is no WordPress install, no database, and nothing executed; a scan works anywhere PHP 8.3 or newer runs. Every finding carries a confidence level, and the report tells you what fraction of write calls it could resolve, so the tool is candid about its own coverage instead of pretending to certainty it doesn't have. And it is real AST analysis built on `nikic/php-parser` — not a pile of regular expressions.

## Installation

Add it to a plugin project as a development dependency:

```bash
composer require --dev sediment/analyzer
```

Or work from a clone:

```bash
git clone https://github.com/SNO7E-G/Sediment.git
cd Sediment
composer install
```

## Usage

Point `scan` at a plugin directory:

```bash
php bin/sediment scan path/to/plugin
```

```
Options (3)
 function        key             confidence   autoload   cleaned   source
 add_option      acme_version    verified     no         yes       acme.php:31
 add_option      acme_settings   verified     yes        no        acme.php:33
 update_option   acme_pro_*      pattern      unknown    no        pro.php:88

Tables (1)
 function   key                confidence   cleaned   source
 dbDelta    {prefix}acme_logs  resolved     no        install.php:12

 Resolution rate: 92.0% (23 of 25 write calls resolved to a key).
 Cleanup path: uninstall.php — 8 of 12 detected artifacts removed on uninstall.
```

Grade a plugin, generate the `uninstall.php` it should have shipped with, emit a machine-readable manifest, or gate your CI on the result:

```bash
php bin/sediment grade path/to/plugin
php bin/sediment uninstall path/to/plugin > uninstall.php
php bin/sediment scan path/to/plugin --json > manifest.json
php bin/sediment check path/to/plugin --fail-on=C   # exit 1 if worse than C
```

The manifest carries the grade, the coverage counts, and every artifact with its confidence, `cleaned` flag, and source lines — it is the contract other tools build on, documented in [`docs/manifest-schema.md`](docs/manifest-schema.md). `check` is the same analysis wired for CI, so a plugin author can fail a build on their database footprint the way they already fail it on tests.

## What it detects

Options (with the autoload flag), custom tables, cron events, transients, post/user/term/comment metadata, roles and capabilities, and custom post types and taxonomies.

That last group is the one prefix-matching tools structurally cannot reach. Uninstall an e-commerce plugin and its products stay behind as unreachable rows in `wp_posts` — often tens of thousands. No prefix reveals that. Reading the source does.

`grade` returns a letter and a weighted-damage score. `uninstall` writes a teardown that removes only the artifacts Sediment attributed with high confidence — never a WordPress core key, an already-cleaned key, or a guess. (A `check --fail-on=<grade>` command for CI is planned.)

## How confidence works

Sediment labels every finding by how certain the attribution is, and it never presents a guess as a fact:

| Level | Meaning |
| --- | --- |
| `verified` | The key is a literal string in the source. |
| `resolved` | The key resolves from a constant, class constant, or literal property. |
| `pattern` | The key is partly dynamic but exposes a stable prefix, such as `_mp_*`. |
| `dynamic` | The key is only knowable at runtime. It is recorded honestly and never acted on. |

## Grading

A grade reflects what a plugin leaves behind, weighted by real-world cost rather than a raw count — one orphaned autoloaded option matters more than twenty small rows nobody reads:

| Grade | Meaning |
| --- | --- |
| **A** | Removes everything it creates, unconditionally, on uninstall. |
| **B** | Removes everything, but only when the user opts in (conditionally clean). |
| **C** | Removes some data; leaves a few harmless rows — none autoloaded, no tables or cron. |
| **D** | Leaves tables, autoloaded options, cron events, or orphaned content behind. |
| **F** | Ships no uninstall routine at all. |

The rubric is published in full at [`docs/grading.md`](docs/grading.md) so a grade is always something you can explain, not a black box.

## Limitations

Static analysis cannot see a key that is assembled entirely at runtime, so those are reported as `dynamic` and never acted on — the resolution rate tells you how much of a plugin fell into that bucket. A handful of narrower edges (cron events scheduled with arguments, an aliased `$wpdb` handle) are documented plainly in [`docs/limitations.md`](docs/limitations.md). Publishing these is deliberate: an audit tool earns trust by being honest about its own blind spots.

## Roadmap

Sediment is built in stages, each useful on its own:

1. **Analyzer** (this repository) — detect options, tables, cron, and transients; diff them against the plugin's cleanup routine; grade the result and generate an `uninstall.php`.
2. **Inspector** — a read-only WordPress plugin that bundles the analyzer and scans the plugins installed on a site, so it can tell you what each one will leave behind *before* you click Delete. Because the source is on disk, attribution is ground truth and needs no dataset.
3. **The Index** — a public, openly licensed dataset mapping thousands of plugins to the data they create, which extends the Inspector to rows left by plugins whose files are already gone.

## Status

**Alpha.** Detection, static resolution, the cleanup diff, grading, the manifest, the CI check, and the `uninstall.php` generator all work today, backed by a broad test suite. Releases are cut when a meaningful body of work is ready rather than per change, so each one carries real features — see the [changelog](CHANGELOG.md) and the [releases](https://github.com/SNO7E-G/Sediment/releases). Public interfaces, including the manifest schema, may still change before 1.0.

## Development

```bash
composer install
composer test
php bin/sediment scan tests/fixtures/dirty-plugin
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for how a change should look — especially the rule that nothing may be reported with more confidence than the source justifies.

## License

Released under [GPL-2.0-or-later](LICENSE). The forthcoming Index dataset will be released under CC0, so the open data can never be walled off.
