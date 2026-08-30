# Context Brief: eol-feed-integration

## What
Data-driven end-of-life dates: match catalog products/module versions to **endoflife.date** product cycles and stamp `eolDate` / `supportEndDate` on module versions automatically. Primary data path: an OpenRegister register populated by the **openconnector `endoflife-date-source`** (sibling change in the openconnector repo). Softwarecatalog owns: the product↔eol-product mapping config, the matcher service, a scheduled refresh job, and surfacing (approaching-EOL indicators become feed-driven instead of manual).

## Why (evidence)
- End-of-life-tracking is a full Specter research domain (164); today EOL dates are manually entered.
- endoflife.date: 460+ products, public JSON API + iCal — the canonical open EOL source (logged in Specter external_sources).
- VNG #54 portfolio statistics include EOL exposure; lifecycle tracking spec has approaching-EOL filters that are only as good as their data.
- Specter canonical features: `eol-feed-integration` (softwarecatalog, should, 7) + `endoflife-date-source` (openconnector leaf, should, 7).

## Current state (read these specs first)
- `openspec/specs/application-lifecycle-tracking` — EOL indicators + approaching-EOL filter + lifecycle notifications; this change feeds those fields.
- `openspec/specs/module-vulnerability-tracking` — "optional CVE feed via openconnector" is the established integration-leaf pattern; FOLLOW IT: integration transport lives in openconnector, consumption/matching lives here.
- `openspec/specs/settings-admin-controller` — sync config + cron patterns; `cronjob-context` spec for background jobs.

## Scope
IN: eolProduct mapping config (per product: endoflife.date product slug, stored on the product object or a mapping schema), matcher service (map cycle rows → module versions by version prefix match, conservative: only stamp when unambiguous), scheduled background job + manual trigger endpoint (admin settings), read path from the EOL register (register/schema names configurable in settings, defaulting to what the openconnector change provisions), fallback state when openconnector/register absent (feature degrades gracefully to manual entry — NO direct HTTP fetching from this app), provenance on stamped fields (source: endoflife.date + fetched-at), approaching-EOL views unchanged but now populated, i18n, tests (matcher unit tests with fixture cycles), docs.
OUT: direct HTTP calls to endoflife.date from softwarecatalog (that is openconnector's job), CVE data, license data.

## Design constraints
- ADR-001 OR storage; ADR-011 check OpenRegister core first; integrations belong in openconnector (do NOT embed an HTTP client for the feed here).
- OR saveObject PUT-semantic — stamping eolDate must carry all module-version fields forward.
- Background job: NC 34 background-job registration gotchas apply; follow cronjob-context spec.
- ADR-012 Cn components; ADR-005 i18n; ADR-009 tests; ADR-010 docs.
- OpenSpec delta headers MUST be `### Requirement: <name>`.
