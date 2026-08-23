# Grading

A grade has to be defensible in public, or plugin authors will dismiss it as
noise. This page is the reference every grade output links to.

## The letters

| Grade | Criteria |
| --- | --- |
| **A** | Removes 100% of what it creates, unconditionally, via `uninstall.php`. |
| **B** | Removes 100%, but only when a user setting is enabled (*conditionally clean*). |
| **C** | Removes some artifacts; leaves fewer than five items, none autoloaded, no tables or cron. |
| **D** | Leaves tables, autoloaded options, cron events, or a registered post type behind. |
| **F** | Ships no uninstall routine at all. |

## Weight by damage, not by count

The letter is a bucket of a 0–100 score, and the score weights findings by the
harm they actually do on a live site — not by how many rows there are:

- **Autoloaded options** are the heaviest. An autoloaded option loads on *every*
  request, forever, whether or not anything reads it. One orphaned autoloaded
  option outweighs twenty small non-autoloaded rows.
- **Custom tables** bloat backups and slow migrations, and never garbage-collect.
- **Cron events** keep firing hooks whose callbacks no longer exist.
- **Registered post types** orphan their content: the posts stay in `wp_posts`
  with nothing left to render them, often tens of thousands of rows. This weighs
  like a table and caps the grade at D.
- **Drop-ins and must-use plugins** are code that keeps *executing* after the
  plugin is gone — a leftover `object-cache.php` runs on every request, a
  leftover `mu-plugins` file loads before anything can manage it. Both weigh
  like tables and cap the grade at D. Unlike orphaned content, both are safe to
  delete, so generated uninstall routines remove them outright.
- **Metadata** multiplies per object, and **roles and capabilities** ride on every
  user, so both weigh above a plain option.
- **Action Scheduler jobs** behave like cron events — a queued job keeps firing a
  hook whose callback is gone — so they weigh the same.
- **Directories** sit on disk forever but cost nothing per request, and **rewrite
  rules** are single entries in one option that vanish on the next flush, so both
  weigh lightly.
- **Non-autoloaded options and transients** are the lightest — still worth
  removing, but cheap to leave.

`sediment grade` computes the score as 100 minus the summed weight of every
artifact left behind. Two things are deliberately excluded from the verdict:
**WordPress core artifacts**, and **`dynamic`/`pattern` findings** — a key
Sediment could not resolve is its own blind spot, not evidence against the
plugin, so it is reported as coverage rather than held against the grade.

## Why conditional cleanup is its own grade

Grade **B** exists because "delete data on uninstall" options almost always
default to *off* and sit somewhere a user never sees before clicking Delete. Such
a plugin is technically clean and practically dirty. Folding it into A would
overstate it; folding it into C would understate it. Naming it honestly is more
useful than either.

Sediment detects the gate by reading the uninstall path for an `if` that decides
whether cleanup runs — either bailing out early (`if (!get_option('x')) return;`)
or wrapping the removals. The gating option and the value it defaults to are
reported alongside the grade, so a B always comes with the specific setting that
caused it. An `if` that reads an option but gates nothing does not count.

## What does not count against a grade

Nothing that belongs to WordPress core, and nothing a plugin creates from a
bundled dependency it did not author. Core options, core tables, and core cron
hooks never appear in Sediment's output.
