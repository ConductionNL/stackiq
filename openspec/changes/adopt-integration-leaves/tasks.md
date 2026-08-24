# Tasks — adopt-integration-leaves

## 1. Register fragment

- [ ] 1.1 Add `lib/Settings/register.d/catalog-integration-leaves.json`
  declaring `configuration.linkedTypes` on: `contactPerson` +
  `organization` (`["contacts"]`), `contract`
  (`["decidesk-decisions", "calendar"]` — full array, see design.md §2),
  `moduleVersion` (`["calendar"]`), `assessment` (`["deck"]`), `module` +
  `service` (`["bookmarks"]`). Validate with `python3 -m json.tool`.
- [ ] 1.2 Cross-check every leaf id against openregister HEAD:
  `LinkedEntityService::legacyLinkedTypeIds()` plus
  `IntegrationRegistry::listIds()` must accept all of `contacts`,
  `calendar`, `deck`, `bookmarks` (they do at time of writing — re-verify
  at apply time, the gate suite changes under you).
- [ ] 1.3 PHPUnit on `SettingsService::loadSettings()` merged output:
  `contract.configuration.linkedTypes` contains `decidesk-decisions` AND
  `calendar` exactly once each (guards the array-merge semantic either way);
  all seven schemas carry their declared leaf.
- [ ] 1.4 Re-import on the dev instance (fragment signature change triggers
  re-import) and confirm `Repair/LogDanglingLinkedTypes` reports zero
  dangling entries.

## 2. Lifecycle-date calendar sync

- [ ] 2.1 Add `lib/Service/LifecycleCalendarService.php` with
  `syncContract(array $object): void` and
  `syncModuleVersion(array $object): void` — upsert one linked all-day
  event per tracked field (`contract.endDate`,
  `moduleVersion.dateEndSupport`) through OpenRegister's calendar link
  path (the `X-OPENREGISTER-*` event surface `CalendarProvider::list()`
  renders); move on change, delete on clear, never duplicate (one tracked
  field = one event, deterministic marker).
- [ ] 2.2 Add `lib/Listener/LifecycleCalendarListener.php` subscribed to
  OpenRegister's object-saved event; filter to the voorzieningen register
  and the `contract`/`moduleVersion` schemas resolved via
  `SettingsService` (no hard-coded register/schema ids); register the
  listener in `lib/AppInfo/Application.php` alongside the existing OR
  event listeners.
- [ ] 2.3 Fail-soft: wrap calendar interaction so an unavailable Calendar
  app (or no writable calendar) logs a warning and the object save still
  succeeds — mirror the graceful-degradation posture of the other
  integrations.
- [ ] 2.4 Event titles in English per fleet convention:
  `Contract ends: {contractNumber}`,
  `End of support: {module name} {version}`.

## 3. Frontend / manifest

- [ ] 3.1 Verify the leaf tabs render from schema `linkedTypes` on the
  affected detail pages (`ContactpersoonDetail`, `OrganisatieDetail`,
  `ContractDetail`, `ModuleversieDetail`, `ReviewDetail`, `ModuleDetail`,
  service detail) — expected zero per-page wiring via the shared object
  sidebar; if a page suppresses sidebar tabs, wire it there.
- [ ] 3.2 Rewrite the `_note` strings on `ContractDetail` and
  `ContactpersoonDetail` in `src/manifest.json` that currently assert
  "declares NO email/calendar linkedType" — keep the comms hard-rule (no
  email widgets) documented, describe the new calendar/contacts leaves.

## 4. Tests

- [ ] 4.1 PHPUnit `LifecycleCalendarServiceTest`: create-on-set,
  move-on-change (including a simulated EOL re-stamp of
  `dateEndSupport`), delete-on-clear, idempotent double-save, and
  fail-soft when the calendar double throws.
- [ ] 4.2 PHPUnit `LifecycleCalendarListenerTest`: fires only for
  `contract`/`moduleVersion` saves in the voorzieningen register; ignores
  other schemas.
- [ ] 4.3 Playwright: contacts tab on a seeded contactPerson
  (display name, not raw UID); calendar tab on a contract after setting
  `endDate`; deck card create-and-link on a pending review; bookmark
  link + render on a module detail page — per the @e2e-tagged scenarios
  (gate-19 traceability).

## 5. Spec + docs

- [ ] 5.1 Sync this change's spec delta into
  `openspec/specs/catalog-integration-leaves/spec.md` on archive.
- [ ] 5.2 CHANGELOG entry under Unreleased: contacts/calendar/deck/bookmarks
  leaf adoption + lifecycle-date calendar sync.
