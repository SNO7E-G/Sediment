---
name: Mis-detection or detection gap
about: Sediment attributed a key incorrectly, or missed one it should have found
title: ''
labels: detection
assignees: ''
---

**Plugin and version**

Which plugin, and which version/commit of its source.

**The source line**

The exact `add_option` / `dbDelta` / `wp_schedule_event` / `set_transient` call
(with `file:line`) that was read incorrectly or missed.

```php
// paste the offending source here
```

**What Sediment reported**

The finding it produced (key, confidence level), or "nothing" if it was missed.

**What it should have been**

The correct key and the confidence level you'd expect (`verified`, `resolved`,
`pattern`, or `dynamic`).

**A failing fixture (optional, but the fastest path to a fix)**

If you can, reduce it to a small snippet under `tests/fixtures/` with the
expected finding.
