# The Sediment Index

What the most popular WordPress plugins leave behind in your database,
plugin by plugin, with source lines behind every claim.

- `manifests/<slug>.json` — one manifest per plugin; the schema is
  frozen and published in the analyzer repository
  (`schema/manifest.schema.json`).
- `reverse-lookup.json` — artifact `type:key` to the plugins that
  create it: the file that turns a stray database row into the plugin
  that made it.
- `stats.json` — aggregates for the whole dataset.
- `qa.json` — the QA report the dataset had to pass to be published,
  including the assertion that no WordPress core artifact is ever
  attributed to a plugin.
- `provenance.json` — the pinned version and archive sha256 of every
  plugin scanned; `fetch-failures.json` lists any the run could not
  obtain.

Produced by [Sediment](https://github.com/SNO7E-G/Sediment). Static
analysis: every number is a floor, and each manifest's `coverage`
says how much of the plugin's source stood behind it.

## License

This dataset is dedicated to the public domain under
[CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/). The
plugins it describes are their authors' GPL work, fetched from
wordpress.org; only facts about them are recorded here.
