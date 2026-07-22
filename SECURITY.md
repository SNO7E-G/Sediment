# Security Policy

Sediment reads source code and, in later phases, will help remove data from a
live WordPress database. That makes two classes of issue security-relevant:

- A **false attribution** — Sediment reporting a key as `verified` or `resolved`
  when it does not belong to the plugin, or belongs to WordPress core — because
  it could lead a downstream tool or a person to delete data that should be kept.
- A traditional vulnerability in the analyzer itself (for example, a crafted
  plugin source that causes code execution or a denial of service during a scan).

Both are taken seriously.

## Reporting a vulnerability

Please report privately rather than opening a public issue. Use GitHub's
**private vulnerability reporting** on this repository
(Security → Report a vulnerability). Include:

- a description of the issue and its impact,
- a minimal plugin source snippet or steps that reproduce it,
- the Sediment version or commit and your PHP version.

You will get an acknowledgement, and we will work with you on a fix and, where
appropriate, a coordinated disclosure. Reports about false-attribution cases are
welcome even when they are not classic vulnerabilities — accuracy is the core
promise of this tool.

## Supported versions

Sediment is pre-release; fixes land on `main`. Once tagged releases exist, this
section will name the supported line.
