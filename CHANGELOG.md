# Changelog

All notable changes to Sediment are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Sediment is in alpha.** Releases are cut when a meaningful body of work is
ready rather than per change, so each one carries real features. Public
interfaces — including the manifest schema — may still change before 1.0.

## [0.7.0] — 2026-08-04

Reach. The corpus showed the largest plugins were the ones Sediment could say
least about, because they funnel every write through their own settings layer.
This release follows those writes one hop back to the callers — and reports
honestly that doing so bought less than the roadmap predicted.

### Added

- **One-hop wrapper resolution.** A write keyed on a function's parameter is
  resolved from the literals its callers actually pass, so `update_option($key,
  ...)` inside a settings helper now names the options it really writes. Handles
  the `self::$prefix . $key` shape the large plugins favour, follows plain
  functions, `$this->method()`, static calls, and calls through a *declared*
  type (a typed property or injected dependency) — reading types the author
  wrote rather than inferring them. Deliberately one hop, and never across two.
- **Removals resolve the same way.** A plugin that writes through a wrapper
  usually deletes through one too; expanding only the creates would have
  reported those keys as abandoned and cost plugins a grade for cleanup they do
  perform.
- **Coverage beside the grade.** Below 90% resolution, `grade` now says so and
  calls the letter a floor — what could not be read can only add to a footprint,
  never subtract from it.
- **`batch --resume`** skips plugins already scanned, so an interrupted run over
  thousands carries on instead of starting again, and **`batch --report`** writes
  the grade spread, resolution totals, and every failure with its reason to JSON.

### Measured

Pooled resolution moved **77% → 78%**, the corpus median **82% → 84%**, and six
of ten plugins now resolve at 80% or better rather than five. UpdraftPlus went
73% → 83%, Yoast SEO 62% → 64%; Akismet lost a point because an unresolved write
is kept alongside the keys found rather than replaced by them, which is the
honest accounting.

The ratio understates it. The feature surfaced **49 artifacts across the corpus
that were previously invisible**, 38 of them in UpdraftPlus, whose known
footprint more than doubled — a wrapper called with twenty literal keys was one
unresolved write before and is twenty named artifacts now.

It is still less than the roadmap predicted, and `docs/corpus.md` records why:
the unresolved remainder is mostly not wrapper parameters at all, but
runtime-assigned properties, keys arriving through interfaces and dependency
injection, and filesystem paths. The ">80% pooled" target remains open.

## [0.6.0] — 2026-07-31

Proof. Until now every correctness claim rested on hand-written fixtures and two
real plugins read by eye. This release checks Sediment against ten real, pinned
plugins on every push — and doing so immediately found two defects that fixtures
never would have.

### Added

- **`sediment fetch <slug> [version]`** downloads a plugin from wordpress.org
  into a local cache, recording a `sha256` so a pinned version is reproducible.
- **A golden corpus of ten pinned plugins**, spanning the grade range and the
  awkward shapes: from `classic-editor` (small and literal) through
  `wp-super-cache` (writes directories) and `redirection` (tables via its own
  schema layer) to `wordpress-seo` and `woocommerce` (3,500 scanned files, 789
  write calls). Their source is never committed — only the expected manifests,
  with the plugins fetched and cached in CI. Any change to what Sediment says
  about a real plugin now shows up as a diff instead of silently.
- `docs/corpus.md` — the corpus, the measured results, and the hand-verification
  notes, including what is knowingly under-detected.

### Fixed

- **Plugins were credited with creating WordPress core data.** Six of the ten
  corpus plugins listed core options — `active_plugins`, `blogname`,
  `admin_email`, `rewrite_rules` — under `creates`, and one listed a core cron
  hook. The writes are real, but modifying WordPress's own settings is not
  leaving something behind, and putting core keys in what a consumer reads as a
  removable set is precisely the misattribution this project exists to prevent.
  They are now reported under a new **`modifies_core`** section, and a
  corpus-wide test asserts no plugin is ever credited with creating core data.
- **A manifest was not the same document on every platform.** File paths were
  sorted with their native separators, and `\` and `/` sort differently against
  the characters between them, so `admin/x.php` and `admin\x.php` fall on
  opposite sides of `adminA.php`. The same plugin therefore scanned in a
  different order on Linux than on Windows, reordering every findings list.
  Paths are now normalised before sorting, and artifacts are ordered by key
  rather than by the order files happened to be read, so the document depends on
  its contents alone. Found by CI failing on a tree that passed locally.
- **A manifest did not survive a JSON round trip.** A resolution rate of exactly
  `1.0` encoded as `1` and decoded as an integer, so re-reading a manifest
  produced a different type than the scan that wrote it — enough to make `diff`
  report a change that never happened, and to give the Index two types for one
  field. All manifest encoding now goes through one method that cannot forget
  the flag. Found by the corpus, on exactly the two plugins that resolve
  completely.

### Measured

Resolution across the corpus: **median 82%, mean 81%, 77% pooled** across 1,226
write calls, with five of ten plugins at 80% or better.

The MVP target of ">80%" is therefore met per plugin and missed when pooled,
because the largest plugins are the least resolvable — Yoast SEO and WooCommerce
alone are three quarters of the corpus's write calls, and both funnel writes
through their own settings layers. Both numbers are published rather than the
target being tuned to whichever one flatters the tool. See `docs/corpus.md`.

## [0.5.1] — 2026-07-30

### Added

- **The generated `uninstall.php` is now proven against a real WordPress
  database, not just asserted.** A live check installs WordPress, lets a fixture
  plugin create one of every artifact type for real, generates the teardown
  Sediment would ship, runs it exactly as WordPress does, and compares full
  before/after snapshots of options, tables, metadata and roles.

  It removes every artifact the plugin created — including the timeout row a
  transient quietly writes alongside itself — and touches nothing else: a full
  core install is still standing afterwards. This was the project's central
  claim and the one acceptance criterion never actually tested.

  The check runs in CI on every push against a fresh WordPress and MySQL, and
  skips locally unless `SEDIMENT_WP_PATH` points at a configured install, so the
  unit suite stays runnable anywhere.

## [0.5.0] — 2026-07-30

Distribution. Sediment was finished but not obtainable: the README's first
instruction, `composer require --dev sediment/analyzer`, returned a 404, so
anyone following it failed at step one.

### Added

- **A self-contained `sediment.phar`, attached to every release.** One file, no
  Composer and no vendor directory — download it and run
  `php sediment.phar scan path/to/plugin`. That matters for an audit tool whose
  audience includes people who do not otherwise write PHP.
- **CI builds the PHAR and runs a real scan through it** on every push, checking
  the manifest it emits rather than only that the file exists. A binary that
  builds but cannot scan is worse than no binary.

### Fixed

- **The reported version was wrong in every manifest ever produced.** The
  application still identified itself as `0.1.0-dev` four releases on, and that
  string is stamped into each manifest as `analyzer_version` — so published data
  was mislabelled with a version that was never released. The version now tracks
  the changelog, and a test fails the build if the two ever disagree again.

## [0.4.1] — 2026-07-27

Stability and coverage, both driven by what real plugins actually do.

### Fixed

- **A scan no longer holds every syntax tree in memory.** Scanning Yoast SEO
  peaked at **242 MB** — past PHP's default 128 MB limit, so the scan died
  outright, and in a batch run one such plugin would have ended the whole job.
  Files are re-read for the second pass instead, which makes the cost of a scan
  proportional to the largest single file rather than the whole tree: the same
  scan now peaks at **36 MB** and finds exactly the same 123 artifacts. The
  trade is some time for a bound that holds, and a regression test pins it.

### Added

- **The multisite network option API** — `add_network_option`,
  `update_network_option`, and `delete_network_option`. Their key is the *second*
  argument, after the network id, which is precisely why they were invisible.
  Network options are never autoloaded, and a generated `uninstall.php` removes
  them with `delete_site_option`.
- **The generic metadata API** — `add_metadata` and `update_metadata`, which the
  typed `add_post_meta`-style helpers delegate to. `delete_metadata` was already
  credited as cleanup, so not detecting the create side left the two halves out
  of step. As with `register_meta`, the object type is resolved rather than
  guessed: an unknowable one emits nothing.

## [0.4.0] — 2026-07-27

The release where Sediment was first pointed at real plugins instead of
hand-written fixtures — and gained the commands for using it repeatedly and over
a whole collection.

### Added

- **`sediment diff <baseline.json> <path>`** compares a plugin against a manifest
  saved earlier and exits non-zero when the footprint got worse: a new artifact
  that is not cleaned up, one that stopped being cleaned up, or a worse grade.
  Adding data the plugin also removes on uninstall is not a regression. Commit
  the manifest and an accidental autoloaded option becomes a failing build rather
  than a discovery years later.
- **`sediment batch <dir>`** scans every plugin directory under a path, writing
  one manifest each plus a grade distribution and an overall resolution rate. A
  plugin that fails to scan is recorded and skipped, so one bad plugin cannot
  sink a run of thousands.

### Changed — resolution

Running against real plugins showed exactly where the resolution rate was going.
Members are now looked up the way PHP looks them up, which recovers the largest
missed buckets without loosening any safety rule:

- A constant or property declared on a base class resolves for subclasses that do
  not redefine it, and `parent::CONST` follows the recorded hierarchy.
- `static::CONST` resolves when no subclass in the plugin redefines it — exactly
  the case where late binding cannot change the value. When one does, it stays
  `dynamic` as before.
- `self::$prop` and `static::$prop` resolve.

Measured on Yoast SEO (1,680 files): **56.9% → 61.8%** of write calls resolved.

### Fixed

- **A write through `self::$prop` now poisons that symbol** just as `$this->prop`
  does. Previously a literal default survived a dynamic reassignment made through
  the static form, so a key could resolve to a value that never runs.

## [0.3.0] — 2026-07-26

Completes the grading rubric and the artifact surface. Grade B was published
from the start but could never be assigned, because nothing detected the
conditional cleanup it describes; that gap is now closed, and the last artifact
types the manifest reserved are detected.

### Added

- **Conditional cleanup detection, and with it grade B.** An uninstall routine
  gated on a stored setting — `if (!get_option('..._delete_data')) return;`, or
  the same removals wrapped inside the `if` — is now recognised, and the gating
  option and its default are reported in the cleanup block and the manifest.
  This is the plugin that is technically clean and practically dirty: the setting
  almost always defaults to off, so on a real site nothing is removed. Naming it
  B is more useful than folding it into A or C.

  A guard only counts when it actually gates cleanup — it bails out early, or a
  removal sits inside it — so an unrelated `if (get_option('schema') === 'v2')`
  in the same routine does not cost an otherwise clean plugin its A.
- **Directories** — `wp_mkdir_p` and `mkdir`, with `WP_CONTENT_DIR`,
  `WP_PLUGIN_DIR`, and `ABSPATH` rewritten to portable `{content_dir}`,
  `{plugin_dir}`, and `{abspath}` tokens, the same way `{prefix}` works for
  tables. A path that is only a root with nothing under it is skipped rather than
  reported as a directory the plugin created.
- **Rewrite rules** — `add_rewrite_rule`, `add_rewrite_endpoint`, and
  `add_rewrite_tag`. They weigh lightly: a rule is one entry in a single option
  and disappears on the next permalink flush.
- **Action Scheduler jobs** — `as_schedule_recurring_action`,
  `as_schedule_single_action`, `as_schedule_cron_action`, and
  `as_enqueue_async_action`. A queued job behaves like a cron event, so it weighs
  like one.

### Fixed

- **A cron event scheduled with arguments is no longer reported as cleaned by an
  argument-less `wp_clear_scheduled_hook()`.** That call only removes events
  registered without arguments, so the event actually survives and keeps firing —
  Sediment was crediting a cleanup that does not happen. Clearing it needs
  `wp_unschedule_hook()`, which is what the generated `uninstall.php` now emits
  for those events.
- **The `$wpdb` handle is recognised when held as a property** (`$this->wpdb`),
  not only as the global variable, so table creation and drops written that way
  are no longer missed. Recognition lives in one helper rather than three
  hand-rolled checks, and still requires the name `wpdb` — an alias under a
  different name is missed rather than guessed at.

- Cleanup detection for the new types — `as_unschedule_all_actions`,
  `as_unschedule_action`, `rmdir`, and `flush_rewrite_rules` — so a plugin that
  tidies up after itself is credited for it.

### Changed

- **A key written from several places counts as cleaned only when every write of
  it is cleaned.** A hook scheduled both with and without arguments is one key
  with two fates; the previous "any write cleaned" merge could report such a
  plugin as spotless while an event kept firing.
- **Scores are held inside the band their letter allows**, so a C with one stray
  transient can no longer outrank a B on a leaderboard.
- **Grade summaries no longer state which way a gating setting must be set.**
  Sediment sees which option decides, not the polarity of the comparison, and
  "keep my data" gates are as common as "delete my data" ones — so the wording
  names the setting and its default rather than asserting a direction.
- Every artifact type the manifest reserves is now populated by detection; the
  `creates` groups are unchanged, so consumers need no update. A finding type
  with no group now raises an error instead of vanishing from the document.
- WordPress's own directories (`uploads`, `plugins`, `themes`, …) are recognised
  as core, so they are never attributed to a plugin or offered for deletion.

### Security

- **A table name cut short by a dynamic tail is no longer claimed.** Resolving
  `"CREATE TABLE {$wpdb->prefix}logs{$suffix}"` stops mid-name, and treating the
  fragment as the table invented one the plugin never creates — which a generated
  `uninstall.php` would then drop. Such names now require proof that the name
  ended before the cut.
- `wp_clear_scheduled_hook($hook, $args)` no longer credits an argument-less
  event, since it clears only events registered with those exact arguments.
- The `$wpdb` handle is accepted only as `$wpdb`, `$this->wpdb`, or
  `self::$wpdb`, so an unrelated object's `->wpdb` cannot credit a table drop.

## [0.2.0] — 2026-07-26

Coverage expansion and the machine-readable output. The analyzer now sees the
artifact types that prefix-matching tools structurally cannot — metadata, roles,
and custom content types — and can be consumed by other tools and by CI.

### Added

- **Metadata detection** — `add_*_meta` / `update_*_meta` for posts, users, terms,
  and comments, plus `register_meta`. Because `register_meta`'s object type comes
  from its first argument rather than the function name, that argument is resolved
  and mapped; if it does not resolve to one of the four known literals, nothing is
  emitted rather than guessing which meta table is touched.
- **Roles and capabilities** — `add_role` (including the capability names in its
  literal capabilities array) and `$role->add_cap()`.
- **Custom content types** — `register_post_type` and `register_taxonomy`. This is
  the class of leftovers competitors miss entirely: uninstall an e-commerce plugin
  and its products remain as unreachable rows in `wp_posts`, often tens of
  thousands of them. Prefix matching cannot see this; source parsing can.
- **`sediment scan --json`** emits the manifest (schema `1.0`): plugin metadata
  read from the plugin header, grade and score, coverage counts with a resolution
  rate, the cleanup path, and every artifact grouped by key with its confidence,
  `cleaned` flag, and all the `sources` that write it. Unresolvable writes are
  listed under `unresolved` rather than hidden, and artifact types not yet
  detected ship as empty arrays so the schema never breaks for consumers. This is
  the contract every downstream consumer reads — CI, the Index, and the WordPress
  plugin — instead of reaching into the analyzer.
- **`sediment check --fail-on=<grade>`** exits non-zero when a plugin grades worse
  than a threshold, so a plugin author can gate their own CI on their database
  footprint the same way they gate on tests.
- `docs/manifest-schema.md` documents the manifest and the guarantees a consumer
  can rely on — every group always present, `{prefix}` never expanded, `sources`
  always an array, `cleaned` per item, and `unresolved` reported rather than
  hidden.
- Releases are now published straight from this changelog: when the top section
  carries a date instead of "unreleased", pushing it to `main` tags the commit and
  publishes the release with those notes.
- Cleanup detection for the new types: `delete_post_meta`,
  `delete_post_meta_by_key`, `delete_user_meta`, `delete_term_meta`,
  `delete_comment_meta`, `delete_metadata` (with its object type resolved), and
  `remove_role`.

### Changed

- **A post type left behind now caps the grade at D.** Orphaned content is rows in
  `wp_posts` that no longer render anywhere, which is as damaging as an orphaned
  table. The rubric in `docs/grading.md` and the README was updated to match.
- The grader weighs the new artifact types by damage: a post type like a table,
  metadata above a plain option because it multiplies per object, roles and
  capabilities because every user carries them.
- The generated `uninstall.php` removes metadata (`delete_post_meta_by_key` /
  `delete_metadata`) and roles (`remove_role`), and **deliberately never deletes
  posts or terms** — an uninstall routine must not destroy user content silently.
  Registered post types and taxonomies are listed as a comment for a human to
  decide on.

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

[0.7.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.7.0
[0.6.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.6.0
[0.5.1]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.5.1
[0.5.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.5.0
[0.4.1]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.4.1
[0.4.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.4.0
[0.3.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.3.0
[0.2.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.2.0
[0.1.1]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.1.1
[0.1.0]: https://github.com/SNO7E-G/Sediment/releases/tag/v0.1.0
