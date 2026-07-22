# Grading

A grade has to be defensible in public, or plugin authors will dismiss it as
noise. This page is the reference every grade output links to.

## The letters

| Grade | Criteria |
| --- | --- |
| **A** | Removes 100% of what it creates, unconditionally, via `uninstall.php`. |
| **B** | Removes 100%, but only when a user setting is enabled (*conditionally clean*). |
| **C** | Removes some artifacts; leaves fewer than five items, none autoloaded, no tables. |
| **D** | Leaves tables, autoloaded options, or cron events behind. |
| **F** | Ships no uninstall routine at all. |

## Weight by damage, not by count

The letter is a bucket of a 0–100 score, and the score weights findings by the
harm they actually do on a live site — not by how many rows there are:

- **Autoloaded options** are the heaviest. An autoloaded option loads on *every*
  request, forever, whether or not anything reads it. One orphaned autoloaded
  option outweighs twenty small non-autoloaded rows.
- **Custom tables** bloat backups and slow migrations, and never garbage-collect.
- **Cron events** keep firing hooks whose callbacks no longer exist.
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

Detecting the gate requires conditional-cleanup analysis, which is on the
roadmap; until it lands, a fully-clean plugin is graded **A**, and grade **B**
is held in reserve.

## What does not count against a grade

Nothing that belongs to WordPress core, and nothing a plugin creates from a
bundled dependency it did not author. Core options, core tables, and core cron
hooks never appear in Sediment's output.
