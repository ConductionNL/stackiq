---
kind: feature
depends_on: []
---

# softwarecatalog — EOL feed integration (endoflife.date matching)

## Why

End-of-life tracking is a full Specter research domain (164) and a recurring
competitor table-stake (Snipe-IT, GLPI, i-doit, Device42, ServiceNow, Flexera
all ship it), but today `moduleVersie.datumEindeOndersteuning` is entered by
hand — it is only as fresh and complete as whoever last edited it.
endoflife.date is a canonical, free, public source (460+ products, JSON API)
already logged in Specter `external_sources`, and the
`module-vulnerability-tracking` change already established the safe pattern
for consuming an external feed: transport lives in openconnector, matching
and consumption live in the leaf app. This change applies that same pattern
to EOL data, closing the gap between what `application-lifecycle-tracking`'s
EOL indicators, EOL-approaching filter, roadmap, and `eol-approaching`
notification rule *can* show and what data actually populates them.

Specter canonical features: `eol-feed-integration` (softwarecatalog, should,
demand 7) + `endoflife-date-source` (openconnector leaf, should, demand 7).

## What Changes

1. **Mapping config.** `module` gains an optional `eolProductSlug` field —
   the endoflife.date product identifier a catalog product corresponds to.
2. **Matcher.** A new `EolMatcherService` reads `eolProduct`/`eolCycle`
   objects from a configurable OpenRegister register (defaults matching what
   the sibling openconnector `endoflife-date-source` change provisions) via
   `ObjectService` — no HTTP client in this app. For each mapped module's
   `moduleVersie` records, it matches `versie` against cycle values by
   version-prefix and **only stamps on an unambiguous single-candidate
   match**; ties and no-matches are left untouched, never guessed.
3. **Stamping preserves PUT semantics.** A match sets
   `datumEindeOndersteuning` plus two new provenance fields, `eolBron`
   (source) and `eolBijgewerktOp` (fetched-at), while re-saving the complete
   existing `moduleVersie` object so every other field survives (OR
   `saveObject` is PUT-semantic).
4. **Scheduled job + manual trigger.** `EolSyncJob` (background job, system
   context, `cronjob-context` pattern) re-runs the match on a configurable
   interval; `SettingsController` gains a manual "sync now" endpoint that
   invokes the same logic on demand, alongside get/update endpoints for the
   EOL sync config and a status endpoint.
5. **Graceful degradation.** When the configured register/schema can't be
   resolved (openconnector not installed, or sync disabled), the matcher
   no-ops silently, status reports "unavailable" with a reason, and manual
   `datumEindeOndersteuning` entry / the EOL-approaching filter / roadmap /
   notification rule keep working exactly as today — none of them require
   this feature.
6. **No direct HTTP.** Softwarecatalog performs no outbound call to
   endoflife.date or any EOL feed; all fetching lives in the openconnector
   `endoflife-date-source` change.

`application-lifecycle-tracking` is **not modified** — this change only
improves what populates the field it already reads.

## Impact

- **New**: `EolMatcherService`, `EolSyncService`, `EolSyncJob`; EOL sync
  config/status/manual-trigger endpoints on `SettingsController`/
  `SettingsService`; a settings panel + module-form field on the frontend.
- **Schema (additive only)**: `module.eolProductSlug`,
  `moduleVersie.eolBron`, `moduleVersie.eolBijgewerktOp` — all optional;
  existing objects load and save unchanged. No Nextcloud migration class
  (ADR-001, no custom tables; applied via the existing register-JSON +
  repair-step import path).
- **Unchanged**: `application-lifecycle-tracking`'s derivation, filters,
  roadmap, and notification rule; `module-vulnerability-tracking`; license
  data. No new outbound network dependency in softwarecatalog.
- **Risk**: low-medium — the only correctness risk is a wrong stamp from
  ambiguous matching, mitigated by the conservative single-candidate-only
  rule (fixture-tested); everything else is additive and degrades to the
  current manual-only behaviour when the feed is absent.

## Dependencies

Depends at runtime (optionally) on the **openconnector**
`endoflife-date-source` change (sibling repo) for the `eolProduct`/
`eolCycle` register — softwarecatalog's own capability (manual EOL entry,
filters, roadmap, notifications) is fully functional without it. ADR-001 (OR
storage), ADR-011 (check OR core / existing schema shape first), ADR-012
(Cn components), ADR-005 (i18n), ADR-009 (tests), ADR-010 (docs).
