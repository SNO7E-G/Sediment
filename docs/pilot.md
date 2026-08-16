# The 500-plugin pilot

The roadmap gated 0.9 on a pilot run over the top 500 wordpress.org plugins —
unannounced, whose job was to break the batch pipeline and the manifest schema
while breaking was still cheap. It ran on 2026-08-16 against the 500 most
popular plugins, pinned to the versions current that day, on one ordinary
desktop. This page records what happened, because the run's purpose was
evidence, not a dataset: the published Index arrives with 0.9, built properly
and at ten times the size.

## The run

Fetched with `sediment fetch` semantics (pinned version, recorded sha256,
cached), scanned with `sediment batch --resume --report --timeout 600` — each
plugin in its own child process under the wall-clock timeout and the default
512M memory cap.

| | |
|---|---|
| Plugins scanned | **500 of 500** |
| Batch failures / hangs / timeouts | **0** |
| Wall clock | 1h 23m |
| Files that failed to parse | 1, in one plugin, out of the entire corpus |
| Manifests violating `schema/manifest.schema.json` | **0 of 500** |

The two numbers the run existed to produce are the last two lines: the batch
survived the real world unattended, and the schema frozen at 2.0 held against
five hundred plugins nobody hand-picked. No schema change is demanded.

Fetching, not scanning, is where the real world pushed back — twice, and both
fixes are in 0.8.1:

- About a tenth of the top 500 keep **no per-version archive** on
  wordpress.org; only the unversioned zip of the current release exists.
  `fetch` now falls back to it, strictly when the pinned version *is* the
  current one.
- On Windows, a virus scanner routinely still holds freshly-extracted files
  open, which failed the first `rename()` into the cache for 54 of 500
  plugins. A brief retry clears it; `fetch` now retries before giving up.

## What the top 500 leave behind

Static analysis, so all numbers are floors: 75.2% of the 28,028 write calls
resolved to a key; what could not be read can only add to a footprint.

| | |
|---|---|
| Attributed artifacts | 13,305 |
| Removed on uninstall | 731 — **5.5%** |
| Left behind | 12,574 |
| — of which autoloaded options | 764 |
| — cron events | 613 |
| — custom tables | 43 |
| — custom post types (orphaning their content) | 198 |
| Plugins with any uninstall path at all | 281 of 500 (56%) |
| Plugins whose cleanup is gated on a setting | 35 |

| Grade | Plugins | Share |
|---|---|---|
| A | 54 | 10.8% |
| B | 0 | 0% |
| C | 14 | 2.8% |
| D | 238 | 47.6% |
| F | 194 | 38.8% |

Two readings worth naming plainly:

- **Only 18 of 500 plugins remove everything they create.** The other 36 A
  grades create no confidently-attributed persistent data in the first place —
  an honest A, but a different achievement.
- **Nobody earned a B.** Thirty-five plugins gate their cleanup on a setting,
  but not one of them removes *everything* when the setting allows it — so
  conditional-and-complete, the B definition, is empty in the wild. The grade
  stays: it describes a behaviour plugins ought to reach for, and the rubric
  should not be resized to fit the data it measures.

## What the pilot demands before 0.9

Not a schema change — a pipeline rule. The manifest records `plugin.slug` as
the scanned directory's basename, which is right for a local scan and wrong
for a pinned cache whose directories carry versions: the pilot's manifests say
`woocommerce-11.0.1` where the Index needs `woocommerce`. The analyzer cannot
know a wordpress.org slug from a local path, so the Index builder must scan
from directories named exactly by slug (or carry the slug alongside), and the
golden corpus already does the equivalent normalisation. Recorded here so 0.9
builds it in from the start.

Pooled resolution at ecosystem scale is **75.2%** against the ten-plugin
corpus's 78% — the corpus is representative, slightly flattering. The ">80%
pooled" target stays open, and `docs/corpus.md` records why the remainder is
not reachable by one more static hop.
