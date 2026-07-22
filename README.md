<h1 align="center">Sediment</h1>

<p align="center"><em>Everything your plugins left behind, layer by layer.</em></p>

<p align="center">A static analyzer that reads a WordPress plugin's source code and reports exactly what it leaves behind in your database.</p>

<p align="center">
  <a href="https://github.com/SNO7E-G/Sediment/actions/workflows/tests.yml"><img src="https://github.com/SNO7E-G/Sediment/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg" alt="PHP 8.3+">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg" alt="License: GPL-2.0-or-later"></a>
</p>

---

Uninstalling a WordPress plugin deletes its files — and almost nothing else. Its options keep autoloading on every request, its custom tables keep bloating your backups, and its cron events keep firing hooks whose code is long gone. Published analysis of the plugin directory found that **more than 40% of plugins leave orphaned data behind, and only 28.6% remove it cleanly.** The problem has been documented since 2008 and is still unsolved.

What makes it hard is attribution, not deletion. Existing cleanup tools guess which leftover rows belong to which plugin by matching name prefixes — and a wrong guess in a tool that deletes data will wreck a site.

Sediment takes a different route: **it reads the source.** A plugin's own code contains the literal calls that create its data — `add_option()`, `dbDelta()`, `wp_schedule_event()`. Parse those statically and attribution stops being a guess and becomes something you can point at: `includes/setup.php:412`.

## Why it's different

- **Reads code, never runs it.** Pure static analysis — no WordPress install, no database, nothing executed. It runs anywhere PHP 8.3+ runs.
- **Honest about certainty.** Every finding carries a confidence level, and the report states what fraction of write calls it was able to resolve. It never presents a guess as a fact.
- **Real AST analysis.** Built on `nikic/php-parser`, not regular expressions.

## Installation

As a development dependency of a plugin project:

```bash
composer require --dev sediment/analyzer
```

Or from a clone:

```bash
git clone https://github.com/SNO7E-G/Sediment.git
cd Sediment
composer install
```

## Usage

```bash
php bin/sediment scan path/to/plugin
```

```
Options (4)
 function          key                confidence   source
 add_option        example_version    verified     includes/setup.php:88
 update_option     example_settings   verified     includes/setup.php:92
 add_site_option   example_network    verified     includes/network.php:14
 update_option     — (unresolved)     dynamic      includes/settings.php:210
```

Grade a plugin, or generate an `uninstall.php` for what it leaves behind:

```bash
php bin/sediment grade path/to/plugin
php bin/sediment uninstall path/to/plugin > uninstall.php
```

`grade` prints a letter (A–F) and a weighted-damage score; `uninstall` prints a syntactically valid teardown covering only the artifacts Sediment attributed with high confidence — never a core, cleaned, or guessed key. (A `check --fail-on=<grade>` command for CI is on the roadmap.)

## How confidence works

Sediment classifies every finding by how certain the attribution is:

| Level | Meaning |
| --- | --- |
| `verified` | The key is a literal string in the source. |
| `resolved` | The key resolves statically from a constant, class constant, or literal property. |
| `pattern` | The key is partly dynamic but exposes a stable prefix (e.g. `_mp_*`). |
| `dynamic` | The key is only knowable at runtime. Recorded honestly; never treated as certain. |

## Grading

A plugin's letter grade reflects what it leaves behind, weighted by real-world cost rather than a raw count (one autoloaded option outweighs twenty harmless rows):

| Grade | Meaning |
| --- | --- |
| **A** | Removes everything it creates, unconditionally, on uninstall. |
| **B** | Removes everything, but only if the user opts in (conditionally clean). |
| **C** | Removes some data; leaves a few harmless rows — none autoloaded, no tables or cron. |
| **D** | Leaves tables, autoloaded options, or cron events behind. |
| **F** | Ships no uninstall routine at all. |

## Roadmap

Sediment is built in stages, each useful on its own:

1. **Analyzer** (this repository) — the CLI: detect options, tables, cron, and transients; diff them against the plugin's cleanup routine; grade the result and generate an `uninstall.php`.
2. **The Index** — a public, openly licensed dataset mapping thousands of plugins to the data they create.
3. **Inspector** — a read-only WordPress plugin that attributes leftover data on a live site against the Index.

Design notes and the detection reference live in [`docs/`](docs/).

## Status

Early development. The analyzer detects **options, tables, cron events, and transients** — each with a confidence level and honest coverage — resolving keys built from constants, class constants, `$this->` properties, string interpolation, local variables, and `$wpdb->prefix`. It then **diffs them against the plugin's `uninstall.php` / `register_uninstall_hook`** to mark every artifact *cleaned* or *left behind*, **grades the result A–F**, and **generates an `uninstall.php`** for what's missing. That completes the v0.1 analyzer. Public interfaces may still change before the first tagged release.

## Development

```bash
composer install
composer test
php bin/sediment scan tests/fixtures/dirty-plugin
```

## License

Released under [GPL-2.0-or-later](LICENSE). The forthcoming Index dataset will be released under CC0, so the open data can never be walled off.
