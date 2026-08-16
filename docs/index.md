# The Index

What the 5,000 most popular WordPress plugins leave behind in your database,
plugin by plugin, with source lines behind every claim. This is the dataset
the project has been building toward since 0.1 — the thing that turns a stray
`smk_last_sync_ts` row into "that belongs to a plugin you removed years ago".

The dataset lives on the
[**`index-data`**](https://github.com/SNO7E-G/Sediment/tree/index-data)
branch, dedicated to the public domain under CC0: one manifest per plugin, a
reverse lookup from every artifact key to the plugins that create it,
aggregate statistics, the QA report the dataset had to pass, and the
provenance — pinned version and archive sha256 — of every plugin scanned.

## The run

On 2026-08-16, ten CI runners fetched and scanned the top 5,000 plugins by
popularity (pinned that day in `scripts/index-plugins.json`) in **13 minutes
42 seconds**, wall clock, end to end — including publishing the dataset.
Anyone can reproduce it: push the `index-run` branch, and the same pinned
list produces the same dataset.

| | |
|---|---|
| Plugins pinned | 4,999 distinct slugs |
| Manifests published | **4,995** |
| Could not be fetched | 1 (`geeky-bot` — its archive left wordpress.org between pin and fetch) |
| Lost to scan failures | 3 |
| QA violations — core artifacts attributed, schema strays, unreadable or unscanned manifests | **0 of 4,995** |
| Pooled resolution | 77.1% of 157,502 write calls |

The QA line is the release gate the roadmap demanded: **"zero WordPress core
artifacts" is asserted across every published manifest**, checked by
`sediment index` at build time rather than trusted from the analyzer, and a
violating dataset cannot be built at all.

## What 5,000 plugins leave behind

Static analysis, so every number is a floor: what could not be read can only
add to a footprint, and each manifest's `coverage` says how much of that
plugin's source stood behind its entry.

| | |
|---|---|
| Attributed artifacts | 74,583 |
| Removed on uninstall | 3,936 — **5.3%** |
| Left behind | **70,647** |
| — of which autoloaded options (loaded on every request, forever) | 4,165 |
| — cron events that keep firing a missing hook | 2,685 |
| — custom tables | 538 |
| — custom post types, orphaning their content | 1,375 |
| Plugins with any uninstall path at all | 1,951 of 4,995 (39%) |
| Plugins whose cleanup is gated on a setting | 191 |

| Grade | Plugins | Share |
|---|---|---|
| A | 1,185 | 23.7% |
| B | 11 | 0.2% |
| C | 160 | 3.2% |
| D | 1,446 | 28.9% |
| F | 2,193 | 43.9% |

Readings worth naming plainly:

- **Only 210 of 4,995 plugins — 4.2% — remove everything they create.** The
  other 975 A grades create no confidently-attributed persistent data in the
  first place: an honest A, but a different achievement.
- **Nearly 44% of the most popular plugins on wordpress.org ship no uninstall
  routine at all**, and the tail is worse than the top: 56% of the top 500
  have some uninstall path, against 39% of the top 5,000.
- **Grade B exists after all.** The 500-plugin pilot found nobody who removes
  everything conditionally; at ten times the scale, eleven plugins do. The
  rubric's rarest grade describes a real behaviour — just one in every 450
  plugins.
- The single heaviest footprint in the set is not a household name:
  `wp-compress-image-optimizer` leaves 669 attributed artifacts behind,
  ahead of WooCommerce's 455 and Jetpack's 373 — and those two at least ship
  cleanup for part of what they create.

## Reading the dataset

- `manifests/<slug>.json` — the frozen 2.0 schema
  ([`schema/manifest.schema.json`](../schema/manifest.schema.json),
  commentary in [manifest-schema.md](manifest-schema.md)). Filter on
  `confidence`; only `verified` and `resolved` are safe to act on.
- `reverse-lookup.json` — `"type:key"` to the sorted slugs that create it.
  Keys use the documented placeholder tokens (`{prefix}` and friends), never
  expanded values.
- `provenance.json` — version and archive sha256 per plugin;
  `fetch-failures.json` names what the run could not obtain, because a
  dataset that hides its holes overstates itself.

The three scan-time losses above are a known reporting gap of this first run:
the per-shard batch reports that record *why* a scan failed were not
preserved as artifacts. The workflow keeps them now, so the next run's holes
will each carry a reason.

## Against the smaller corpora

Pooled resolution: 78% on the ten-plugin golden corpus, 75.2% on the
top-500 pilot, **77.1%** here — three measurements, one story: the corpus is
representative, and the ">80% pooled" target from the MVP remains unmet for
the reasons [corpus.md](corpus.md) records. The [pilot](pilot.md) remains
the account of how this pipeline earned trust before it ran at full scale.
