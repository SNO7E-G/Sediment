# The golden corpus

Ten real, pinned plugins that Sediment's output is checked against on every
push. Hand-written fixtures prove the analyzer handles the shapes we thought of;
this corpus proves it handles the shapes other people actually wrote — and makes
any change in what we say about a real plugin show up as a diff rather than
silently.

The plugins are fetched from wordpress.org by exact version and cached. Their
source is never committed: it is GPL code belonging to other people, and pinning
`slug + version` with a recorded `sha256` is enough to reproduce a run. Only the
expected manifests live in the repository, under `tests/Golden/manifests/`.

## The plugins, and why each one is here

| Plugin | Why it earns a place |
| --- | --- |
| `classic-editor` | Small and tidy — short, mostly-literal code that should resolve almost completely |
| `health-check` | Written by the WordPress team itself; a check that ordinary code stays boring |
| `akismet` | Long-lived and conservative; proves nothing regresses on simple, stable code |
| `contact-form-7` | Mid-sized, registers a custom post type, cleans up only partially |
| `redirection` | Custom tables created through its own schema layer |
| `wp-super-cache` | Writes directories and files as its main footprint |
| `updraftplus` | Scheduling plus filesystem work, a combination nothing else here exercises |
| `wordfence` | Large and table-heavy, writing through its own storage layer |
| `wordpress-seo` | Very large and heavily indirect: the resolution-rate worst case |
| `woocommerce` | The richest single test — Action Scheduler, tables, roles and post types at once, across 3,500 scanned files |

## Measured results

Recorded at v0.6.0. Resolution is the share of write calls whose key Sediment
could resolve to a literal.

| Plugin | Grade | Write calls | Resolution | Artifacts | Core writes |
|---|---|---|---|---|---|
| `classic-editor` | C | 9 | 100% | 4 | 0 |
| `redirection` | D | 5 | 100% | 3 | 1 |
| `health-check` | D | 27 | 93% | 9 | 1 |
| `akismet` | F | 38 | 90% | 20 | 1 |
| `wp-super-cache` | D | 69 | 84% | 24 | 0 |
| `wordfence` | F | 81 | 79% | 46 | 1 |
| `woocommerce` | D | 789 | 78% | 448 | 3 |
| `updraftplus` | D | 67 | 73% | 34 | 6 |
| `wordpress-seo` | D | 123 | 62% | 54 | 1 |
| `contact-form-7` | D | 18 | 50% | 9 | 0 |

**Distribution: median 82%, mean 81%, and 77% pooled across all 1,226 write
calls.** Five of ten plugins resolve at 80% or better.

## What that says about the 80% target

The MVP set a target of ">80% resolution". The corpus mostly meets it — the
median plugin resolves 82% of its write calls — but the number falls to 77% when
every write call in the corpus is pooled, because **the largest plugins are the
least resolvable**. Yoast SEO and WooCommerce alone account for three quarters
of all write calls in the corpus, and both sit below the target.

That is not sloppiness; it is structural. Large plugins funnel writes through
their own settings layers (`Options_Helper::set()`), so the *call site* Sediment
can see is a wrapper rather than a literal key. It also means the metric itself
is unstable: one unresolvable wrapper can hide three hundred real options, and
three hundred unresolvable call sites can be a single wrapper.

**The target is therefore recorded as met on a per-plugin basis and missed when
pooled, and both numbers are published.** It is not being tuned to a number that
was a guess before any data existed. The follow-up is to measure what actually
matters — how much of a plugin's real footprint was found, checked against a
live database — rather than counting call sites.

## Hand verification

Reading the manifests against the plugins' own source immediately found a real
defect: six of the ten were credited with *creating* WordPress core options such
as `active_plugins`, `blogname`, and `rewrite_rules`. Those writes are real —
plugins genuinely call `update_option('active_plugins', ...)` — but modifying
WordPress's own data is not leaving something behind, and listing it under
`creates` put core keys into what a consumer would read as a removable set.

They are now reported under `modifies_core`, and a corpus-wide test asserts that
no plugin is ever credited with creating core data.

Known and accepted under-detection, all in the safe direction:

- `redirection` creates its tables through a schema-migration layer that builds
  SQL dynamically, so neither the creates nor the matching `DROP TABLE`s are
  detected. It is graded on what is visible, which under-reports its footprint
  rather than inventing one.
- No plugin in the corpus earns an A or a B. That is a finding about the
  ecosystem as much as about the tool, and it is consistent with the published
  research that most plugins leave data behind — but it also means the corpus
  does not yet exercise the top of the rubric, which the fixture suite covers
  instead.

## Updating the corpus

```bash
# Fetch a pinned plugin
php bin/sediment fetch contact-form-7 6.1.6 --cache=build/plugins

# Re-record expectations after an intentional change, then READ THE DIFF
SEDIMENT_UPDATE_GOLDEN=1 vendor/bin/phpunit --testsuite Golden
```

A changed manifest is not automatically a regression, but it is always a claim
about a real plugin that changed. The diff belongs in the changelog.
