# Sediment — Project Specification

**A static analyzer that reads a WordPress plugin's source code and tells you exactly what it leaves behind in your database.**

Spec v2.0 · Status: pre-development · Supersedes the "Footprint" draft (name retired due to collision)

---

## Table of contents

1. [Problem](#1-problem)
2. [Name and identity](#2-name-and-identity)
3. [Competitive position](#3-competitive-position)
4. [Architecture overview](#4-architecture-overview)
5. [**MVP definition**](#5-mvp-definition) ← start here
6. [MVP build plan, day by day](#6-mvp-build-plan-day-by-day)
7. [Detection reference](#7-detection-reference)
8. [Confidence model](#8-confidence-model)
9. [Manifest schema](#9-manifest-schema)
10. [Grading rubric](#10-grading-rubric)
11. [**Full roadmap**](#11-full-roadmap)
12. [Technical decisions](#12-technical-decisions)
13. [Testing strategy](#13-testing-strategy)
14. [Repository layout](#14-repository-layout)
15. [Launch plan](#15-launch-plan)
16. [Risks](#16-risks)
17. [Success metrics and decision gates](#17-success-metrics-and-decision-gates)
18. [Immediate next actions](#18-immediate-next-actions)

---

## 1. Problem

Deleting a WordPress plugin removes its files. It does not remove:

| Artifact | Where it lives | Why it hurts |
|---|---|---|
| Options | `wp_options` | Autoloaded rows load on **every single request**, forever |
| Custom tables | Database | Bloat backups, slow migrations, waste space |
| Cron events | `wp_options` (`cron`) | Fire hooks whose callbacks no longer exist |
| Post/user/term/comment meta | `wp_*meta` | Millions of rows on large sites |
| Transients | `wp_options` | Expired ones never garbage-collect reliably |
| Roles & capabilities | `wp_options` | Every user carries dead caps |
| Custom post types | `wp_posts` | Content becomes invisible but stays forever |
| Files & directories | `wp-content/` | Logs, caches, exports nobody will ever read |

WordPress provides `uninstall.php` and `register_uninstall_hook()` as the clean exit. Published analysis of the repository found **over 40% of plugins leave orphaned data behind, and only 28.6% are clean by default.** This has been a known problem since at least 2008; WP Tavern has been publicly asking authors to fix it for seventeen years. It has not been fixed.

### Why it stays unsolved

The hard part is **attribution**, not deletion. Knowing that `smk_last_sync_ts` belonged to a plugin deleted in 2021 — and is therefore safe to remove — versus belonging to something still active.

Every existing tool guesses from name prefixes. Prefix guessing produces false positives. False positives in a destructive tool destroy sites. So tools either stay timid (report only) or dangerous (delete on a guess).

### The insight this project is built on

**You don't have to guess. You can read the source.**

Every plugin on wordpress.org is publicly downloadable. Its code contains literal calls to `add_option()`, `dbDelta()`, `wp_schedule_event()`. Parse them statically and attribution becomes *ground truth*: "this option is created at `includes/setup.php:412`."

That converts a heuristic into a lookup — and the resulting dataset can be **open**, which commoditizes the exact feature the market leader sells.

---

## 2. Name and identity

## **Sediment**

> Everything your plugins left behind, layer by layer.

Sediment is what settles at the bottom over years and never leaves on its own. It's the precise metaphor: not a footprint you walk away from, but accumulated material that has to be dug out. It's neutral toward plugin authors — sediment is a natural process, not an accusation — which matters, because you need authors as allies, not opponents.

### Identity assets

| Asset | Value | Status |
|---|---|---|
| CLI binary | `sediment` | verify |
| Composer package | `sediment/analyzer` | verify |
| GitHub org | `sediment-wp` | verify |
| Dataset repo | `sediment-wp/index` — "The Sediment Index" | verify |
| WordPress plugin slug | `sediment` | verify |
| Domain | `sediment.dev` (preferred) or `sedimentwp.com` | verify |

Command surface reads naturally, which matters more than it sounds:

```
sediment scan ./my-plugin
sediment grade ./my-plugin
sediment uninstall ./my-plugin > uninstall.php
sediment fetch woocommerce
sediment check . --fail-on=D
```

### Backup names, in order

1. **Residuum** — Latin for "what remains." Distinctive, almost certainly unclaimed, slightly academic.
2. **Deadweight** — "What your plugins leave behind." Punchy, memorable, but reads as an accusation.
3. **wp-uninstall-lint** — Zero brand, total clarity, excellent SEO. The safe fallback if all brandable names collide.

### Name verification checklist — do this before writing code

- [ ] `wordpress.org/plugins/sediment/` returns 404
- [ ] `packagist.org/packages/sediment/analyzer` is free
- [ ] GitHub org `sediment-wp` is available
- [ ] npm `sediment` (in case of future JS tooling)
- [ ] `sediment.dev` / `sedimentwp.com` available
- [ ] No active trademark in software/SaaS classes
- [ ] Google `"sediment" wordpress plugin` returns nothing relevant

**Lesson from the last round:** the previous name was killed by a plugin published weeks earlier with a matching .com and the same A–E grading gimmick. Twenty minutes of checking saves weeks of rebranding.

---

## 3. Competitive position

### Direct competitors — orphan cleanup

| Tool | Method | Weakness you exploit |
|---|---|---|
| Advanced Database Cleaner | Prefix heuristics + **proprietary cloud** ownership DB | Accurate attribution is premium; dataset closed |
| Plugins Garbage Collector | Table prefix matching | Abandoned since April 2022; tables only |
| Cleanup Removed Plugins | Prefix cross-reference | Locked inside a $249 bundle |
| WP-Optimize / WP-Sweep | General bloat removal | No attribution to deleted plugins at all |

### Adjacent, not competing

- **Footprint (wordpress.org)** — a *runtime performance* profiler using differential scans to measure per-plugin page load impact. Different axis entirely. Its existence validates that per-plugin cost measurement is a real market.
- **PHPStan / Psalm / Phan** — general PHP static analysis. Powerful, but domain-agnostic; none understand what `dbDelta()` means semantically.
- **WPMU DEV's uninstall teardown** — the same analysis, done entirely by hand, one plugin at a time. That's the current state of the art, and it's the gap.

### The moat, stated plainly

Three layers, in increasing order of defensibility:

1. **The analyzer** — replicable in a few weeks by anyone. Not a moat.
2. **The open dataset** — replicable, but a competitor who monetizes closed attribution *cannot* open theirs without destroying their premium tier. Structurally hard for them to match.
3. **Being the reference** — if other tools consume your index, you become infrastructure. That's the real prize.

---

## 4. Architecture overview

```
┌────────────────────────────────────────────────────────────┐
│  PHASE 1 — sediment/analyzer          CLI, PHP  ← THE MVP  │
│  plugin source ──parse──▶ manifest.json + grade + fix      │
└─────────────────────────┬──────────────────────────────────┘
                          │  batch run against wordpress.org
                          ▼
┌────────────────────────────────────────────────────────────┐
│  PHASE 2 — The Sediment Index          public JSON dataset │
│  5,000+ plugins mapped · reverse lookup · corrections PRs  │
└─────────────────────────┬──────────────────────────────────┘
                          │  consumed as attribution source
                          ▼
┌────────────────────────────────────────────────────────────┐
│  PHASE 3 — Sediment Inspector          WordPress plugin    │
│  live site scan ──attribute──▶ report ──snapshot──▶ clean  │
└────────────────────────────────────────────────────────────┘
```

Each phase ships publicly and stands alone. If you stop after Phase 2, you still have a complete, useful, finished project — which is a far better outcome than an abandoned Phase 3.

---

## 5. MVP definition

**The MVP is the analyzer CLI. Nothing else.**

Not the dataset. Not the WordPress plugin. One command-line tool that scans a plugin directory and reports what it leaves behind.

### Why this is the right MVP

| Property | Consequence |
|---|---|
| Read-only static analysis | Cannot break anyone's site. Zero liability while you learn |
| No WordPress runtime needed | No admin UI, no AJAX batching, no timeout handling, no nonces |
| Finishable in three weeks | Which means it will actually be finished |
| Output is the input to everything else | Phase 2 is just this MVP run 5,000 times |
| Useful to a real audience on day one | Plugin authors, without any dataset existing yet |
| Demos in a single terminal GIF | Which is the entire README |

### MVP scope — IN

| # | Requirement |
|---|---|
| M1 | Scan a plugin directory recursively, respecting `.distignore`-style exclusions and skipping `vendor/`, `node_modules/`, tests |
| M2 | Build a symbol table: `define()` constants, class constants, and properties assigned literals in constructors |
| M3 | Detect **options** — `add_option`, `update_option`, `add_site_option`, `update_site_option`, capturing key + autoload flag |
| M4 | Detect **tables** — `dbDelta()` and direct `CREATE TABLE`, resolving `$wpdb->prefix` to a `{prefix}` token |
| M5 | Detect **cron** — `wp_schedule_event`, `wp_schedule_single_event`, capturing hook + recurrence |
| M6 | Detect **transients** — `set_transient`, `set_site_transient`, including implied timeout twins |
| M7 | Detect the cleanup path — `uninstall.php` presence, `register_uninstall_hook`, and parse it with the same engine |
| M8 | Diff created-vs-cleaned per item, producing a `cleaned: true/false` flag on every artifact |
| M9 | Assign a confidence level to every finding (§8) and never present a guess as a fact |
| M10 | Emit a JSON manifest conforming to the schema in §9 |
| M11 | Emit a readable terminal report with colour, grouping, and a summary line |
| M12 | Assign a letter grade A–F per the published rubric (§10) |
| M13 | Generate a syntactically valid `uninstall.php` covering all `verified` and `resolved` items |
| M14 | Never crash. Malformed PHP degrades to `dynamic`, never a fatal error |
| M15 | Report coverage honestly — what fraction of write calls were resolvable |

### MVP scope — explicitly OUT

Everything below is deferred. Writing it down is what stops scope creep from eating the three weeks.

- ❌ Post/user/term/comment meta detection → v0.2
- ❌ Roles, capabilities, post types, taxonomies, directories, rewrite rules → v0.2
- ❌ The wordpress.org batch runner → Phase 2
- ❌ The Index, the reverse lookup, the leaderboard → Phase 2
- ❌ Any WordPress plugin, any admin UI → Phase 3
- ❌ Deleting anything, anywhere, ever → Phase 4
- ❌ Web interface, hosted service, API → not before v1.0
- ❌ Multisite-specific analysis → v0.3
- ❌ Following `require`/`include` beyond the plugin directory → v0.2
- ❌ Conditional-cleanup gate detection (nice-to-have, but not MVP) → v0.2

### MVP acceptance criteria

The MVP is done — and only done — when all of these are true:

1. `sediment scan <path>` produces a correct manifest for **10 hand-verified real plugins** spanning clean, partially clean, and dirty
2. Resolution rate exceeds **80%** across those 10 (i.e. under 20% of write calls fall to `dynamic`)
3. Every generated `uninstall.php` passes `php -l` and, when executed in a test WordPress install, removes exactly what it claims and nothing else
4. The fixture test suite is green, including the hostile-input fuzz cases
5. A stranger can install it and run their first scan from the README in under two minutes
6. **Zero** WordPress core options, core tables, or core cron hooks ever appear in output — asserted in CI

Criterion 6 is non-negotiable and gets its own CI job. It is the invariant the entire project's credibility rests on.

---

## 6. MVP build plan, day by day

Fifteen working days. Adjust the calendar, not the sequence.

### Week 1 — the engine

| Day | Work | Done when |
|---|---|---|
| 1 | Project skeleton, `composer require nikic/php-parser`, CLI entry point with `symfony/console`, file walker | `sediment scan .` lists every PHP file it would parse |
| 2 | AST traversal, `NodeVisitor` base class, function-call detection scaffold | It can find and count every `add_option()` call in a directory |
| 3 | Symbol table pass — `define()`, class constants, constructor property literals | `self::PREFIX . 'key'` resolves to `mp_key` |
| 4 | Options visitor, including autoload flag capture | Option findings emitted with keys, autoload, file/line |
| 5 | Confidence classifier — literal / resolved / pattern / dynamic | Every finding carries a level; buffer day for week 1 spillover |

### Week 2 — coverage and cleanup diffing

| Day | Work | Done when |
|---|---|---|
| 6 | Table visitor — `dbDelta` SQL extraction, `$wpdb->prefix` resolution, direct `CREATE TABLE` | `{prefix}my_logs` detected from real plugins |
| 7 | Cron visitor + transient visitor | Hooks with recurrence; transients with timeout twins |
| 8 | Cleanup path detection — locate `uninstall.php` / `register_uninstall_hook`, parse with the same visitors | Removal calls detected as a separate set |
| 9 | Created-vs-cleaned diff engine | Every artifact carries an accurate `cleaned` flag |
| 10 | Manifest assembly + JSON serialization + schema validation | `--json` emits schema-valid output |

### Week 3 — output, safety, ship

| Day | Work | Done when |
|---|---|---|
| 11 | Grading rubric implementation, damage weighting | `sediment grade` returns a defensible letter |
| 12 | Terminal reporter — colour, grouping, summary, coverage line | Output is screenshot-worthy |
| 13 | `uninstall.php` generator + `php -l` validation of its own output | Generated file is valid and correct |
| 14 | Fixture suite, golden-file tests, fuzz cases, the core-protection CI job | All green |
| 15 | README (GIF first), install instructions, limitations section, LICENSE, tag **v0.1.0**, publish | It's public |

### Day-15 non-negotiables

Ship even if the grading rubric feels rough and the colours are ugly. A published v0.1.0 with 80% resolution beats a perfect v0.9 that never leaves your laptop. The whole point of choosing this MVP was that it's finishable.

---

## 7. Detection reference

### v0.1 (MVP)

| Artifact | Functions |
|---|---|
| Options | `add_option`, `update_option`, `add_site_option`, `update_site_option`, `register_setting` |
| Tables | `dbDelta`, `$wpdb->query` with `CREATE TABLE`, `$wpdb->prefix`/`base_prefix` concatenation, `ALTER TABLE` on core tables |
| Cron | `wp_schedule_event`, `wp_schedule_single_event`, `cron_schedules` filter |
| Transients | `set_transient`, `set_site_transient` |
| Cleanup | `uninstall.php`, `register_uninstall_hook`, `delete_option`, `DROP TABLE`, `wp_clear_scheduled_hook`, `delete_transient` |

### v0.2

| Artifact | Functions |
|---|---|
| Post meta | `add_post_meta`, `update_post_meta`, `register_meta` |
| User meta | `add_user_meta`, `update_user_meta` |
| Term meta | `add_term_meta`, `update_term_meta` |
| Comment meta | `add_comment_meta`, `update_comment_meta` |
| Roles & caps | `add_role`, `WP_Role::add_cap`, `$role->add_cap` |
| Content structures | `register_post_type`, `register_taxonomy` |
| Filesystem | `wp_mkdir_p`, `wp_upload_dir`, `WP_CONTENT_DIR` concatenation |
| Structural | `add_rewrite_rule`, `add_rewrite_endpoint`, `register_sidebar`, `register_widget` |
| Action Scheduler | `as_schedule_recurring_action`, `as_schedule_single_action` |

**Why post types matter and nobody covers them:** uninstall an e-commerce plugin and the products remain as unreachable rows in `wp_posts` — often tens of thousands. Every competitor ignores this because prefix matching can't see it. Source parsing can.

### v0.3+

Deactivation-hook data destruction (an anti-pattern worth flagging) · options written via `$wpdb` direct SQL · third-party library detection so bundled vendor code isn't blamed on the host plugin · REST route and capability registration.

---

## 8. Confidence model

The heart of the tool. Four levels, always attached to every finding.

| Level | Meaning | Example | Deletable in Phase 4? |
|---|---|---|---|
| `verified` | Literal string argument | `add_option('mp_version', '1.0')` | Yes |
| `resolved` | Statically resolved from constant, class const, or literal-assigned property | `add_option(self::PREFIX . 'settings')` | Yes |
| `pattern` | Contains a variable, but a stable literal prefix is extractable | `update_post_meta($id, '_mp_' . $f, …)` → `_mp_*` | Only with explicit per-item confirmation |
| `dynamic` | Fully unresolvable at parse time | `update_option($key)` from a function argument | Never bulk-deletable |

Every `dynamic` finding is still recorded, with the raw expression and file/line, so a human can resolve it and contribute a correction.

**Coverage honesty is a feature.** The manifest reports the resolution rate. A tool that says "I understood 91% of this plugin" is more trustworthy than one that silently pretends to 100%. Put this number in the terminal output and in the README.

---

## 9. Manifest schema

```jsonc
{
  "schema_version": "1.0",
  "plugin": {
    "slug": "example-plugin",
    "name": "Example Plugin",
    "version": "2.4.1",
    "source": "local",
    "scanned_at": "2026-07-22T10:00:00Z",
    "analyzer_version": "0.1.0"
  },
  "grade": "D",
  "score": 42,
  "coverage": {
    "write_calls_found": 87,
    "verified": 61,
    "resolved": 18,
    "pattern": 4,
    "dynamic": 4,
    "resolution_rate": 0.908
  },
  "cleanup": {
    "has_uninstall_php": false,
    "has_uninstall_hook": true,
    "conditional": true,
    "condition_option": "example_delete_data_on_uninstall",
    "condition_default": false
  },
  "creates": {
    "options": [
      {
        "key": "example_settings",
        "autoload": "yes",
        "confidence": "verified",
        "cleaned": false,
        "sources": [{ "file": "includes/setup.php", "line": 88 }]
      },
      {
        "key": "example_pro_*",
        "autoload": "unknown",
        "confidence": "pattern",
        "cleaned": false,
        "sources": [{ "file": "includes/pro.php", "line": 141 }]
      }
    ],
    "tables": [
      {
        "name": "{prefix}example_logs",
        "confidence": "verified",
        "cleaned": false,
        "sources": [{ "file": "includes/install.php", "line": 23 }]
      }
    ],
    "cron": [
      {
        "hook": "example_daily_sync",
        "recurrence": "daily",
        "confidence": "verified",
        "cleaned": true,
        "sources": [{ "file": "includes/cron.php", "line": 12 }]
      }
    ],
    "transients": [],
    "post_meta": [],
    "user_meta": [],
    "term_meta": [],
    "comment_meta": [],
    "roles": [],
    "capabilities": [],
    "post_types": [],
    "taxonomies": [],
    "directories": [],
    "rewrite_rules": []
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

**Design decisions worth defending:**

- `{prefix}` is a literal placeholder token, never a hardcoded `wp_`. Consumers substitute their real prefix. Getting this wrong once means the Index is worthless on any site with a custom prefix.
- `cleaned` is **per-item**, not plugin-level. Partial cleanup is the common case, and a plugin-level boolean would throw away the most useful information in the file.
- `sources` is an array — the same key is often written from several places, and Phase 3 users will want to see all of them.
- `confidence` travels with every item so downstream consumers filter by it rather than trusting blindly.
- `unresolved` is first-class and visible, not hidden. It's the contribution surface for the community.
- All v0.2 artifact arrays exist in v0.1 output as empty arrays, so the schema never breaks when they're populated.

---

## 10. Grading rubric

A grade must be defensible in public or authors will dismiss it as noise.

| Grade | Criteria |
|---|---|
| **A** | Cleans 100% of what it creates, unconditionally, via `uninstall.php` |
| **B** | Cleans 100%, but gated behind a user setting (*conditionally clean*) |
| **C** | Cleans some artifacts; leaves fewer than 5 items, none autoloaded, no tables |
| **D** | Leaves tables **or** autoloaded options **or** cron events behind |
| **F** | No uninstall routine at all |

**Weight by damage, not by count.** One autoloaded 200KB option is worse than twenty small non-autoloaded rows. One orphaned cron hook firing every five minutes is worse than a table nobody queries. The score (0–100) reflects weighted damage; the letter is a bucket of the score.

Publish the rubric at `docs/grading.md` and link it from every grade output. A grade you can't explain is a grade nobody accepts.

**Conditional cleanup (grade B) deserves its own category** because in practice the "delete data on uninstall" option defaults to off and is buried where no user finds it before hitting Delete. The plugin is technically clean and practically dirty. Naming that honestly is more useful than folding it into A or C.

---

## 11. Full roadmap

### Phase 1 — Analyzer (weeks 1–3) → **v0.1.0** — THE MVP

Per §5 and §6. Options, tables, cron, transients, cleanup diff, grading, JSON, terminal report, uninstall generator.

**Gate to Phase 2:** all six acceptance criteria met and v0.1.0 tagged publicly.

---

### Phase 1.5 — Coverage expansion (weeks 4–5) → **v0.2.0**

- All metadata types, roles, capabilities, post types, taxonomies, directories, rewrite rules
- Follow `require`/`include` of literal paths within the plugin
- Conditional-cleanup gate detection
- Vendor/bundled-library exclusion so a plugin isn't blamed for its dependencies
- `sediment diff` — compare current source against a prior manifest, catching footprint added in a release
- `sediment check --fail-on=<grade>` for CI use

**Why this slots before the Index:** running 5,000 plugins through a scanner that only sees half the artifact types produces a dataset you'd have to regenerate. Expand coverage first, batch once.

---

### Phase 2 — The Sediment Index (weeks 6–8) → **v0.3.0**

| Week | Deliverable |
|---|---|
| 6 | wordpress.org API client, downloader, cache, polite rate limiting, resumable job state, per-plugin timeout and memory caps |
| 7 | Batch run against the top 5,000 plugins by install count. Manual spot-check of the top 100 |
| 8 | Publish the Index repo, `dist/` build pipeline, corrections workflow, stats summary, leaderboard site |

**Index structure**

```
index/
  a/akismet.json
  c/contact-form-7.json
corrections/
  contact-form-7.yml       # human overrides, merged at build
dist/
  index.min.json           # flat artifact-key → slug lookup
  index.sqlite             # optional shipped DB for Phase 3
stats/
  summary.json             # aggregate stats behind the leaderboard
```

**The reverse lookup** is the critical derived artifact — a flat map from artifact key to owning plugin(s). Target under 2MB gzipped for the top 5,000 so it can ship inside a WordPress plugin; shard by first character if it grows.

```jsonc
{
  "options": {
    "wpseo": ["wordpress-seo"],
    "mp_*": ["example-plugin", "another-plugin"]   // ambiguity returned honestly
  },
  "tables": { "{prefix}wc_orders": ["woocommerce"] },
  "cron": { "wp_mail_smtp_summary": ["wp-mail-smtp"] }
}
```

**Corrections workflow:** PR against `corrections/<slug>.yml` → CI validates schema and re-runs the analyzer to confirm the correction doesn't contradict verifiable source → merge rebuilds `dist/` → tagged releases so consumers can pin.

**The leaderboard** is the marketing engine: which popular plugins leave the most behind, ranked, with links to the exact source lines and a generated fix. Rule: **report, never editorialize.** Authors who feel attacked become opponents; authors who feel helped become contributors.

**Gate to Phase 3:** 5,000 plugins indexed, resolution rate above 85%, dataset published under an explicitly free licence.

---

### Phase 3 — Sediment Inspector, read-only (weeks 9–12) → **plugin v1.0**

A WordPress plugin that **deletes nothing.** Trust first.

- Scan live `wp_options`, custom tables, cron events, transients
- Attribute each finding against the shipped Index
- Cross-reference installed plugins, `active_plugins`, `recently_activated`
- Group findings by originating plugin, with reclaimable size and a confidence badge
- **Autoload impact panel** — orphaned autoloaded options with their measured cost per page load. This is the screenshot that sells the plugin
- Export findings as CSV/JSON
- WP-CLI: `wp sediment scan`
- Submit to the wordpress.org repository

**Gate to Phase 4:** 1,000 active installs, and zero reports of misattribution causing user harm.

---

### Phase 4 — Safe deletion (weeks 13–16) → **plugin v2.0**

- **Snapshot before delete** — export selected rows to a restore file in uploads, protected by `.htaccess` deny and a randomized directory name
- **One-click undo** from that snapshot. This is the headline feature; no competitor offers real rollback
- Deletion permitted on `verified`/`resolved`; `pattern` requires explicit per-item confirmation; `dynamic` never bulk-deletable
- Immutable audit log — who, when, what, which restore file
- Batched AJAX with progress and resume, so large sites never hit PHP timeouts (the exact failure that killed the abandoned competitor)
- Core protection allowlist in a separate, small, heavily reviewed file with its own CI assertion

**Gate:** the integration suite installs 30 plugins, deletes them, cleans, restores from snapshot, and asserts the database returns to a byte-identical prior state.

---

### Phase 5 — Extended surface (weeks 17–20) → **plugin v3.0**

Orphaned meta of all four types · orphaned custom post types and taxonomies (content nobody else touches) · orphaned upload directories · leftover roles and capabilities · widget instances, theme mods, nav menu items · `wp-config.php` and `.htaccess` leftovers (**detect only, never auto-edit**).

---

### Phase 6 — Ecosystem (ongoing)

- Multisite network-wide scan
- Scheduled scans with email digest
- Multi-site aggregate reporting for agencies
- REST endpoint for MainWP / ManageWP integration
- **Preventive mode** — watch a plugin during activation, record what it creates, so a later uninstall is exact rather than inferred
- A GitHub Action authors add to their own plugin CI
- A `Sediment: A` README badge
- Outreach: send generated `uninstall.php` files to authors as pull requests

### Timeline at a glance

| Phase | Weeks | Output | Cumulative |
|---|---|---|---|
| 1 — Analyzer MVP | 1–3 | v0.1.0 CLI | 3 weeks |
| 1.5 — Coverage | 4–5 | v0.2.0 | 5 weeks |
| 2 — Index | 6–8 | v0.3.0 + dataset | 8 weeks |
| 3 — Inspector | 9–12 | WP plugin v1.0 | 12 weeks |
| 4 — Deletion | 13–16 | WP plugin v2.0 | 16 weeks |
| 5 — Extended | 17–20 | WP plugin v3.0 | 20 weeks |

Roughly five months to the full vision at a steady part-time pace — but **three weeks to something real and public.** That gap is the whole reason for this phasing.

---

## 12. Technical decisions

| Decision | Choice | Reasoning |
|---|---|---|
| Language | PHP 8.1+ | The corpus is PHP; use the language's own AST tooling |
| Parser | `nikic/php-parser` | The standard. **Do not write a regex parser** — you will drown in edge cases within days |
| CLI framework | `symfony/console` | Arg parsing, colour, progress bars, free |
| Distribution | Composer package + PHAR | PHAR means casual users need no Composer |
| Config | `sediment.yml` | Per-plugin overrides, ignores, custom detection rules |
| Testing | PHPUnit + fixture plugins | §13 |
| CI | GitHub Actions | Matrix across PHP 8.1 / 8.2 / 8.3 / 8.4 |
| Code licence | GPL-2.0-or-later | Required for the WP plugin; keep the stack consistent |
| Dataset licence | CC0 | Must be *explicitly* free, or you haven't beaten the closed competitor |

### Parser design notes

- **Symbol table pass before detection pass.** Resolving `define()`, class constants, and constructor-assigned properties is the single biggest driver of resolution rate. Skipping it drops you from ~90% to ~60%.
- Handle concatenation of literals and resolved symbols; degrade to `pattern` when one operand is unresolvable but a literal prefix survives.
- For `dbDelta()`, resolve the SQL string first, then a lightweight regex on the *already-resolved string* to find the table name. Regex on a resolved string is fine; regex on raw PHP is not.
- Follow `require`/`include` of literal paths within the plugin to build the full file set (v0.2).
- **Timebox and memory-cap every scan.** A batch of 5,000 must not die on the one plugin with a 40MB bundled library.
- Exclude `vendor/`, `node_modules/`, `tests/`, and minified assets by default. Blaming a plugin for its dependencies' options is a false positive with a bad smell.

---

## 13. Testing strategy

Not garnish. A bug in Phase 4 destroys someone's site — and the suite is also your strongest trust signal, so put it in the README.

**Fixture plugins** (`tests/fixtures/`) — hand-written miniature plugins, each isolating one pattern: literal option, constant-prefixed option, dynamically-keyed option, `dbDelta` table, concatenated table name, conditional uninstall, fully clean uninstall, no uninstall at all. Each with a committed expected manifest. This is the regression net and it's what you write on day 14 — or better, incrementally from day 4.

**Golden-file tests** — pin specific versions of ten well-known real plugins, commit expected manifests, fail on drift.

**Fuzzing** — feed the parser deliberately malformed and hostile PHP. It must never fatal; it must degrade to `dynamic`. This is requirement M14 and it's the difference between a tool people trust with a batch run and one that dies at plugin 3,400.

**Integration matrix** (Phase 3+) — Docker or WordPress Playground, across WP and PHP versions: install → activate → configure → deactivate → delete → scan → clean → restore → assert database equality.

**The safety invariant, asserted as its own CI job:**
> No WordPress core option, core table, or core cron hook ever appears in a deletable result set, under any input.

---

## 14. Repository layout

```
sediment/
├── bin/sediment
├── src/
│   ├── Analyzer/
│   │   ├── Scanner.php
│   │   ├── FileWalker.php
│   │   ├── SymbolTable.php
│   │   ├── ConfidenceClassifier.php
│   │   └── Visitors/
│   │       ├── OptionVisitor.php
│   │       ├── TableVisitor.php
│   │       ├── CronVisitor.php
│   │       ├── TransientVisitor.php
│   │       ├── MetaVisitor.php        # v0.2
│   │       └── RoleVisitor.php        # v0.2
│   ├── Cleanup/
│   │   ├── UninstallLocator.php
│   │   └── CleanupDiffer.php
│   ├── Manifest/
│   │   ├── Manifest.php
│   │   ├── Schema.php
│   │   └── Grader.php
│   ├── Generator/UninstallGenerator.php
│   ├── Source/WordPressOrgClient.php  # Phase 2
│   ├── Report/
│   │   ├── TerminalReporter.php
│   │   └── JsonReporter.php
│   └── Command/
├── tests/
│   ├── fixtures/
│   ├── golden/
│   ├── fuzz/
│   └── Unit/
├── docs/
│   ├── detection-patterns.md
│   ├── manifest-schema.md
│   ├── grading.md
│   ├── limitations.md
│   └── contributing-corrections.md
├── .github/workflows/
│   ├── tests.yml
│   └── core-protection.yml
├── composer.json
├── LICENSE
└── README.md
```

### README requirements

The README *is* the product for most visitors. Non-negotiable:

1. **Terminal GIF in the first screenful** showing a scan producing a grade
2. **One paragraph on the problem**, with the 40% / 28.6% statistic
3. One-line install
4. The grading rubric, so the grade is defensible rather than mysterious
5. An explicit **Limitations** section — what the parser cannot resolve and why. Publishing your own weaknesses is the single most credibility-building thing an audit tool can do, and it pre-empts the first critical issue anyone would file

---

## 15. Launch plan

| Step | Channel | Angle |
|---|---|---|
| 1 | Write-up | "I analyzed 5,000 WordPress plugins. Here's what they leave in your database." Data-led, tool as footnote |
| 2 | r/Wordpress, r/ProWordPress | The findings, not the repo |
| 3 | Hacker News | Only if the findings genuinely surprise. Submit the analysis |
| 4 | Post Status, WP Tavern, WPBuilds | Trade press loves ecosystem-health data — and Tavern has been covering this exact problem since 2008 |
| 5 | Author outreach | Send generated `uninstall.php` as PRs, never complaints. Turn subjects into contributors |
| 6 | wordpress.org submission | Phase 3 only, once the Index gives it an accuracy advantage |

**Lead with the dataset, not the tool.** Tools get a shrug. Data about a shared ecosystem gets discussed — and the discussion is what drives adoption of the tool.

Note the sequencing consequence: the launch moment is **week 8**, not week 3. v0.1.0 ships quietly to establish the repo; the loud launch waits until you have findings worth talking about.

---

## 16. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Deletion bug destroys a site | Critical | Read-only through three phases; snapshot + undo before any delete; core allowlist asserted in CI; dry-run mandatory |
| Static analysis misses dynamic keys | High | Report coverage honestly; corrections workflow; never bulk-delete `pattern`/`dynamic` |
| Scope creep across six phases | High | Each phase ships publicly before the next starts. §5's OUT list is binding |
| Abandonment | High | MVP deliberately small enough to finish. A finished analyzer beats an unfinished ecosystem |
| **Another name collision** | Medium | Run §2's checklist *before* the first commit. This already happened once |
| Plugin authors react defensively | Medium | Report, don't mock. Always ship the fix alongside the finding |
| Competitor ships a free tier that matches | Medium | The moat is the *open* dataset and the undo feature, not the scanner. They can't open theirs without killing their premium tier |
| wordpress.org rate limits the batch | Medium | Aggressive caching, resumable jobs, polite backoff, run over days not hours |
| One giant plugin kills the batch run | Low | Per-plugin timeout and memory caps (§12) |

---

## 17. Success metrics and decision gates

| Milestone | Metric | If missed |
|---|---|---|
| v0.1.0 | 10 plugins hand-verified correct; resolution rate >80% | Fix resolution before adding artifact types — coverage without accuracy is worthless |
| v0.2.0 | Resolution rate >85% with all artifact types | Investigate the dynamic cases; they may reveal a whole missing pattern class |
| Index published | 5,000 plugins; dataset under 2MB gzipped | Shard the lookup by first character |
| Leaderboard | The write-up gets discussed in at least one WP publication | Reconsider the angle, not the project |
| Inspector v1.0 | 1,000 active installs; zero misattribution harm reports | **Do not proceed to deletion.** Attribution isn't ready |
| Inspector v2.0 | 20+ community corrections merged; 5+ authors adopt the CI check | The community layer isn't working; reassess the corrections UX |
| Long term | Other tools consume the Index | — |

That last row is the real ambition. **The tool is replaceable. The dataset, if it becomes canonical, is not.**

---

## 18. Immediate next actions

1. **Run the §2 name checklist.** Twenty minutes. Do this before anything else — you have already lost one name to a collision that a search would have caught.
2. `composer require nikic/php-parser` and spike a walker that finds `add_option()` calls in a single file. Target: working in an afternoon. If this feels good, the rest is mechanical.
3. Hand-write three fixture plugins — clean, conditionally clean, dirty — with expected manifests. These become your test suite and your development target simultaneously.
4. Draft the README *before* the rest of the code. If you can't explain it in one paragraph, the scope is still wrong.
5. Reach out to the author of the published 40% / 28.6% analysis. Prior art exists as a one-off research script, and its author publicly wanted exactly this built. Collaboration beats duplication.
6. Create the repo public and empty on day 1, with the README and the roadmap committed. Building in the open is mild but real pressure to finish.

---

## Appendix A — Name decision and availability (checked 2026-07-22)

**Final name: Sediment.** Verified clear on every asset that matters. Two secondary collisions are noted below; neither is a blocker.

### Availability results for "Sediment"

| Asset | Result | Status |
|---|---|---|
| wordpress.org slug `sediment` | "0 plugins", nothing matched the query | ✅ Free |
| Packagist `sediment/analyzer` | 404 on the package API | ✅ Free |
| GitHub org `sediment-wp` | 404 — no such account | ✅ Available |
| Domain `sedimentwp.com` | Unregistered (Verisign RDAP, validated against google.com) | ✅ Available |
| Google `"sediment" wordpress plugin` | Only unrelated hits (Seeder, SeedProd, a geoscience site) | ✅ Clear |
| Software / dev-tool trademark | Nothing surfaced — **not** a formal legal clearance | ⚠️ No red flag |
| npm `sediment` | **Taken** — unrelated JS sentiment-analysis lib (AFINN-111, Miles Zimmerman) | ❌ Collision (secondary) |
| Domain `sediment.dev` (was preferred) | **Registered** since 2020 (Porkbun), but dark — does not resolve | ❌ Taken (not a competitor) |

**Consequences of the two collisions:**

- Preferred domain shifts from `sediment.dev` → **`sedimentwp.com`** (available). `sediment.tools` is a reasonable alternative to check.
- The npm word-name is taken; if JS tooling is ever needed, use the scope **`@sediment-wp/…`**.
- Critically: **no competing WordPress plugin, no competing PHP package, and no dev-tool trademark** exists. This is *not* a repeat of the "Footprint" collision (a live competing plugin + matching `.com` + the same grading gimmick).

### Alternative names considered (kept for the record)

Design constraints held throughout: neutral toward plugin authors (not an accusation), reads naturally as a CLI verb surface (`NAME scan ./plugin`), brandable, and ideally free across wordpress.org / Packagist / GitHub / a domain.

**Geological — "settles and stays, dig it out" (same lane as Sediment):**
Silt · Strata (echoes the "layer by layer" tagline) · Residuum (Latin "what remains", spec backup) · Tailings (mining material left after extraction)

**Archaeology — "what past inhabitants left behind":**
Midden (an archaeological refuse heap — arguably the most on-the-nose meaning) · Vestige ("a trace of something that no longer exists") · Relic

**Action verbs — the tool *does* something:**
Dredge (pulls sediment up from the bottom) · Sift · Teardown (the literal WP uninstall concept)

**Developer-native / function-forward:**
Cruft (dev slang for accumulated junk) · Orphan ("orphaned data" is the domain term; strong SEO) · Detritus · wp-uninstall-lint (zero brand, total clarity, best SEO — the safe fallback, spec backup)

**Runner-up shortlist if Sediment ever needs replacing:** Midden, Residuum, Dredge — all distinctive/coined enough to sweep clean across every asset in one shot, unlike short common words (Silt, Cruft, Orphan, Sift), which tend to collide on a domain or npm.

**Rule reaffirmed:** rare/coined words sweep clean; common words collide. Run this full checklist again before adopting any replacement.
