# Stability

What you can build on, what you cannot, and how things are removed. This is the
contract 0.8 ("Contract") exists to state. Sediment is still alpha — semantic
versioning applies in full at 1.0 — but everything named as covered here is
already treated as covered: breaking any of it is a defect, not a prerogative.

## Covered

These are the interfaces. A change that breaks one is a breaking change, lands
in a release whose changelog says so plainly, and — after 1.0 — requires a major
version.

### The manifest

The document `sediment scan --json` and `sediment batch` produce, defined by
[`schema/manifest.schema.json`](../schema/manifest.schema.json). It carries its
own version, `schema_version`, independent of the analyzer's:

- **`2.0` is frozen.** A field being removed, renamed, retyped, or changing
  meaning bumps the major (`3.0`) — and none of that is planned before 1.0.
- **Additions bump the minor** (`2.1`): a new field, a new group, a new enum
  value. The schema file is updated in the same commit, and CI validates every
  manifest the suite produces against it, so the file cannot drift from the
  output.
- Manifests published with `schema_version: "1.0"` predate the freeze: `1.0`
  was mutable alpha output and self-identifies as such.

Write a consumer against the schema file, tolerate fields you do not recognise,
and check `schema_version`'s major — that consumer will survive every release.

### Exit codes

`0` is success, and non-zero is failure — with two meanings kept distinct:

- **`1`** — the command could not do its job (bad path, unreadable input), or
  the gate it exists to enforce failed: `check` grading worse than `--fail-on`,
  `diff` finding a worse footprint.
- **`2`** — the invocation itself was invalid (e.g. a `--fail-on` value that is
  not a grade).

Gate CI on "non-zero", or on the specific code; both are stable.

### Error codes

A scan that degrades — a file it could not parse, read, or afford — records an
entry with a machine-readable `code`, so tooling can branch on *why* without
parsing prose. The vocabulary is frozen the same way: existing values never
change meaning, new values may be added.

| Code | Meaning |
| --- | --- |
| `E_PARSE` | The file exists but PHP cannot parse it. |
| `E_IO` | The file could not be read at all — permissions, a race. |
| `E_SIZE` | The file exceeds the per-file size limit and was skipped. |
| `E_INTERNAL` | An analyzer bug or unexpected node shape; the scan's never-fatal guarantee turned it into an entry instead of an exception. |

### Command-line interface

Command names, argument order, and existing flags with their defaults. A flag
is never removed or repurposed without going through the deprecation policy
below. New flags and commands may appear in any release.

## Not covered

- **Terminal output.** Wording, layout, tables, colours — the human report is
  for humans and improves without notice. Anything a machine needs is in the
  manifest or the exit code; parsing the terminal text is building on sand.
- **Any given plugin's grade or findings.** Better detection changes results —
  that is the tool working, not breaking. A grade moving between releases is
  data about the plugin (or about a fixed defect), never an API break.
- **The PHP classes under `src/`.** Sediment is a CLI, not a library; the
  package exposes a binary, and the classes behind it may be renamed or
  reshaped freely. If you find yourself constructing a `Scanner` from another
  codebase, pin the exact version and expect breakage.
- **The golden corpus and its recorded manifests.** They are test evidence,
  re-recorded whenever detection improves.

## Deprecation policy

When something covered has to go:

1. **It is announced in the changelog** of the release that deprecates it,
   naming the replacement.
2. **It keeps working for at least one further minor release.** A deprecated
   flag warns on stderr when used; a deprecated manifest field stays populated
   and is marked deprecated in the schema's description.
3. **Then it is removed** — before 1.0 in a minor release after that notice
   period, after 1.0 only in a major.

Nothing covered disappears without passing through all three steps.
