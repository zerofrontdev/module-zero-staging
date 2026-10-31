# Changelog: zerofront/module-zero-staging

## 1.0.4

- Conflicts between campaigns are judged by the store views a value lands in (global, website or store view scope).
- Start now, End now and edits wait for a cron run in progress, so a campaign is never started twice nor edited while it is applied.
- A change keeps the values it replaces before writing, so a write that stops halfway can still be undone.
- A preview parameter or cookie sent as an array no longer causes a 500 on storefront pages.

Earlier releases (1.0.3 and before): no release notes.
