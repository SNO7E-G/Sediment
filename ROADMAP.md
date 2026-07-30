# Roadmap

Where Sediment is going, and what each release has to prove before the next one
starts. Every release ships publicly on its own, so a pause between any two
still leaves something finished.

Current: **v0.5.1**. The analyzer is feature-complete; what remains before 1.0
is evidence and commitment, not features.

## What is already proven

- Detection across options, tables, cron, transients, all four metadata types,
  roles, capabilities, post types, taxonomies, directories, rewrite rules, and
  Action Scheduler.
- A generated `uninstall.php` executed against a real WordPress and MySQL in CI,
  removing exactly the plugin's own data — including the timeout row a transient
  quietly writes — and leaving every core option and table standing.
- Memory bounded to the largest single file rather than the whole tree, so a
  scan of a 1,670-file plugin peaks around 36 MB.

## What is not

- Only two real plugins have been hand-verified against a target of ten.
- The original ">80% resolution" target does not survive contact with large
  plugins (~62% measured on Yoast SEO), and the metric itself is questionable —
  see 0.6.

---

## 0.6 — Proof

Stop arguing from two data points.

- `sediment fetch` — download a pinned plugin version from wordpress.org.
- A ten-plugin golden corpus spanning the grade range and the awkward shapes,
  fetched by pinned version in CI, with expected manifests committed and any
  drift failing the build.
- **Artifact recall measured against runtime ground truth**: activate a real
  plugin, snapshot everything it writes, compare against the manifest. A
  call-site percentage can be moved by a single settings wrapper; what actually
  matters is how much of a plugin's real footprint was found.
- An evidence-based resolution target replacing the original guess.
- Published on Packagist, making `composer require` true.

**Done when:** ten of ten golden manifests are green, and both the call-site
distribution and runtime recall are measured and published.

## 0.7 — Reach

Close the measured gaps; make batch survive the real world.

- Bounded one-hop wrapper resolution — a plugin function whose parameter flows
  into a detection sink, resolved per literal call site. Anything needing more
  than one hop stays `dynamic`; under-claiming is the product.
- Grades annotated with their coverage, so a letter is never awarded on evidence
  that was mostly missing.
- Batch hardening: a child process per plugin, wall-clock timeout, memory cap,
  resumable state, and a machine-readable error log.
- A pilot index over the top 500 plugins — unannounced, whose job is to break
  the schema while breaking is still cheap.

**Done when:** a 500-plugin run completes unattended with no hangs, and the
schema changes the pilot demands are written up.

## 0.8 — Contract

The manifest becomes an API. Deliberately boring.

- A JSON Schema file, with every manifest CI produces validated against it.
- Every breaking schema change lands here, at once, and the schema freezes at
  `schema_version: "2.0"` — so the manifests already published under a mutable
  `"1.0"` remain self-identifying as alpha output.
- A stability document: what semantic versioning covers (the manifest, exit
  codes, flags) and what it does not (terminal output, any given plugin's grade).
- A deprecation policy.

**Done when:** someone could build a consumer from the schema and the docs alone.

## 0.9 — Public

The Index ships. This is the release candidate.

- A full run over the top 5,000 plugins, cached and resumable.
- The published Index: per-plugin manifests, a reverse lookup from artifact key
  to owning plugin, aggregate stats, and a QA report covering anomalies and
  sampled runtime checks. CC0, covering wordpress.org's public GPL corpus only.
- The write-up of what 5,000 plugins actually leave behind.

**Done when:** the Index is published, and "zero WordPress core artifacts" is
asserted across all 5,000 manifests rather than only across fixtures.

## 1.0 — Stable

Nothing new. That is the feature.

Thirty days after 0.9 with no schema-breaking defect reported. Anything that
surfaces is a 0.9.x patch and resets the clock. Then the alpha notice comes off
and semantic versioning applies.

1.0 means *proven and safe to depend on* — not feature-complete, which the
analyzer has been since 0.3.0.

---

## After 1.0

**Inspector** — a read-only WordPress plugin that scans installed plugins on
disk and shows what each will leave behind *before* you click Delete. It needs
no Index, because an installed plugin's source is right there. It waits until
after 1.0 because it needs a frozen schema, a settled PHP-support decision (the
analyzer requires PHP 8.3, which a large share of WordPress hosts do not run),
namespace-prefixed dependencies, and a batched job queue — a scan takes longer
than a shared host allows a request to run.

Safe deletion, with snapshots and undo, comes later still, and only once
attribution has a clean record with real users.

## Deliberately not on this roadmap

A public leaderboard ranking named plugins, a corrections-submission pipeline
before anyone has submitted a correction, a configuration file no feature needs,
and a hosted service. Each is recorded in the project spec with the reasoning.
