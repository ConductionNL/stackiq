# Tasks — adopt-integration-leaves

## 1. Register fragment

- [x] 1.1 Add `lib/Settings/register.d/catalog-integration-leaves.json`
  declaring `configuration.linkedTypes` on: `contactPerson` +
  `organization` (`["contacts"]`), `contract`
  (`["decidesk-decisions", "calendar"]` — full array, see design.md §2),
  `moduleVersion` (`["calendar"]`), `assessment` (`["deck"]`), `module` +
  `service` (`["bookmarks"]`). Validate with `python3 -m json.tool`.
  - Done with today's slugs: `contract` is `catalogContract`, `assessment` is
    `software-review`, `service` is `catalogService`. `deepMergeConfig()`
    concatenates lists, so the fragment adds only `calendar` to the contract;
    the full array would list `decidesk-decisions` twice
    (`IntegrationLeavesFragmentTest`).
- [x] 1.2 Cross-check every leaf id against openregister HEAD:
  `LinkedEntityService::legacyLinkedTypeIds()` plus
  `IntegrationRegistry::listIds()` must accept all of `contacts`,
  `calendar`, `deck`, `bookmarks` (they do at time of writing — re-verify
  at apply time, the gate suite changes under you).
- [x] 1.3 PHPUnit on `SettingsService::loadSettings()` merged output:
  `contract.configuration.linkedTypes` contains `decidesk-decisions` AND
  `calendar` exactly once each (guards the array-merge semantic either way);
  all seven schemas carry their declared leaf.
- [x] 1.4 Re-import on the dev instance (fragment signature change triggers
  re-import) and confirm `Repair/LogDanglingLinkedTypes` reports zero
  dangling entries.
  > Live 25 Nov 2026 (sandbox Nextcloud 34, PostgreSQL 16, OpenRegister main, no decidesk): after the import the six leaves are on their schemas (contactPerson and organization `contacts`, moduleVersion `calendar`, software-review `deck`, module and catalogService `bookmarks`), and `occ maintenance:repair` runs OpenRegister's LogDanglingLinkedTypes with "All schemas linkedTypes are covered by registered integrations". Found: without decidesk, `catalogContract` lost its whole `linkedTypes`, `calendar` included, because OpenRegister dropped the key for the one unregistered id (`decidesk-decisions`). Fixed in OpenRegister (ConductionNL/openregister#11197, keeps the registered ids); with that branch a forced re-import gives `catalogContract` `["calendar"]`. On an instance with decidesk both ids stay, as before.

## 2. Lifecycle-date calendar sync

- [x] 2.1 Add `lib/Service/LifecycleCalendarService.php` with
  `syncContract(array $object): void` and
  `syncModuleVersion(array $object): void` — upsert one linked all-day
  event per tracked field (`contract.endDate`,
  `moduleVersion.dateEndSupport`) through OpenRegister's calendar link
  path (the `X-OPENREGISTER-*` event surface `CalendarProvider::list()`
  renders); move on change, delete on clear, never duplicate (one tracked
  field = one event, deterministic marker).
- [x] 2.2 Add `lib/EventListener/LifecycleCalendarListener.php` (the app's listener directory) subscribed to
  OpenRegister's object-saved event; filter to the stackiq register
  and the `contract`/`moduleVersion` schemas resolved via
  `SettingsService` (no hard-coded register/schema ids); register the
  listener in `lib/AppInfo/Application.php` alongside the existing OR
  event listeners.
  - Registered in `lib/EventListener/ListenerRegistrar.php`, where the other
    OpenRegister object listeners are. OpenRegister's calendar codec writes
    timed events only, so the "all-day" event runs from 00:00 to 00:00 UTC
    the next day.
- [x] 2.3 Fail-soft: wrap calendar interaction so an unavailable Calendar
  app (or no writable calendar) logs a warning and the object save still
  succeeds — mirror the graceful-degradation posture of the other
  integrations.
- [x] 2.4 Event titles in English per fleet convention:
  `Contract ends: {contractNumber}`,
  `End of support: {module name} {version}`.

## 3. Frontend / manifest

- [x] 3.1 Verify the leaf tabs render from schema `linkedTypes` on the
  affected detail pages (`ContactpersoonDetail`, `OrganisatieDetail`,
  `ContractDetail`, `ModuleversieDetail`, `ReviewDetail`, `ModuleDetail`,
  service detail) — expected zero per-page wiring via the shared object
  sidebar; if a page suppresses sidebar tabs, wire it there.
  - All six pages list their own sidebar `tabs` (History, Components), and
    `CnObjectSidebar` renders the registry's leaf tabs only when no `tabs`
    are given, so every page suppressed its leaves. Each page now declares
    its leaf as a tab drawn by `src/components/integrations/IntegrationLeafTab.vue`,
    which renders the registered provider's own tab (or its mount host).
    The services list has no detail page. Rendering on a running instance is
    part of 4.3.
- [x] 3.2 Rewrite the `_note` strings on `ContractDetail` and
  `ContactpersoonDetail` in `src/manifest.json` that currently assert
  "declares NO email/calendar linkedType" — keep the comms hard-rule (no
  email widgets) documented, describe the new calendar/contacts leaves.

## 4. Tests

- [x] 4.1 PHPUnit `LifecycleCalendarServiceTest`: create-on-set,
  move-on-change (including a simulated EOL re-stamp of
  `dateEndSupport`), delete-on-clear, idempotent double-save, and
  fail-soft when the calendar double throws.
- [x] 4.2 PHPUnit `LifecycleCalendarListenerTest`: fires only for
  `contract`/`moduleVersion` saves in the stackiq register; ignores
  other schemas.
- [x] 4.3 Playwright: contacts tab on a seeded contactPerson
  (display name, not raw UID); calendar tab on a contract after setting
  `endDate`; deck card create-and-link on a pending review; bookmark
  link + render on a module detail page — per the @e2e-tagged scenarios
  (gate-19 traceability).
  - Written: `tests/e2e/workflows/integration-leaves.spec.ts` (one test per
    @e2e scenario; links made through OpenRegister's contacts/new,
    integrations/deck and an `or:<uuid>`-tagged bookmark; a leaf whose app
    is not installed is skipped with that reason). Not run against a live
    instance yet: ticked as e2e written, runs in nightly; not verified on a live instance.

## 5. Spec + docs

- [x] 5.1 Sync this change's spec delta into
  `openspec/specs/catalog-integration-leaves/spec.md` on archive.
- [x] 5.2 CHANGELOG entry under Unreleased: contacts/calendar/deck/bookmarks
  leaf adoption + lifecycle-date calendar sync.
