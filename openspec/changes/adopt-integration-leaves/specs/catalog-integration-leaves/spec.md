## ADDED Requirements

### Requirement: Contact persons and organisations MUST expose the contacts leaf
The `contactPerson` and `organization` schemas SHALL declare `contacts` in
`configuration.linkedTypes`, so the detail pages render OpenRegister's
contacts leaf (vCard link rows with role, backed by
`openregister_contact_links` + `X-OPENREGISTER-*` vCard properties) for
the identity that today exists only as the bare `contactsUid` string.

#### Scenario: Contact role detail shows the linked Nextcloud contact
- GIVEN a `contactPerson` object whose `contactsUid` references an existing
  address-book contact
- WHEN a user opens `ContactpersoonDetail` (`/contactpersonen/:id`)
- THEN a contacts leaf tab MUST be present in the object sidebar
- AND it MUST list the linked contact with its display name (not the raw UID)
- @e2e Playwright: seed a contactPerson with a linked contact, open the
  detail page, assert the contacts tab and the contact's display name

#### Scenario: Organisation detail offers link-existing contact
- GIVEN an `organization` object with no linked contact
- WHEN a user opens the organisation detail page and uses the contacts leaf
- THEN the leaf MUST offer linking an existing address-book contact
- AND after linking, the contact MUST appear in the leaf list
- @e2e exclude Link-picker flow is owned and e2e-covered by OpenRegister's
  integration-contacts suite; this app only declares the linkedType

### Requirement: Contract end dates and version end-of-support dates MUST surface as calendar leaf events
The `contract` and `moduleVersion` schemas SHALL declare `calendar` in
`configuration.linkedTypes`, and the app SHALL maintain one linked all-day
calendar event per tracked date field — `contract.endDate` and
`moduleVersion.dateEndSupport` — created, moved, and removed by
`LifecycleCalendarService` when the field is set, changed (including EOL
re-stamps by `EolSyncService`/`EolMatcherService`), or cleared. Sync
failures MUST be logged and MUST NOT block the object save.

#### Scenario: Setting a contract end date creates the leaf event
- GIVEN a contract whose `endDate` is empty
- WHEN a user saves the contract with `endDate = 2027-03-31`
- THEN a linked all-day event on 2027-03-31 MUST exist for that contract
- AND it MUST be listed in the calendar leaf tab on `ContractDetail`
- @e2e Playwright: set an endDate through the contract modal, open the
  detail page, assert the calendar tab lists the end-date event

#### Scenario: EOL matcher re-stamp moves the end-of-support event
- GIVEN a `moduleVersion` with a synced end-of-support event on 2026-12-01
- WHEN the EOL sync (`EolSyncService::run()`) re-stamps `dateEndSupport`
  to 2027-06-01
- THEN the SAME linked event MUST now be on 2027-06-01
- AND no duplicate end-of-support event MUST exist for that version
- @e2e exclude Background-job path with an external-feed dependency;
  asserted by a PHPUnit test on `LifecycleCalendarService` upsert idempotency

#### Scenario: Clearing the date removes the event without failing the save
- GIVEN a contract with a synced end-date event
- WHEN the contract is saved with `endDate` cleared
- THEN the save MUST succeed
- AND the linked end-date event MUST be removed
- @e2e exclude Deletion side-effect; asserted by PHPUnit on the listener

#### Scenario: Calendar unavailable degrades gracefully
- GIVEN the Calendar app is disabled on the instance
- WHEN a contract with an `endDate` is saved
- THEN the save MUST succeed (HTTP 200 on the object write)
- AND the condition MUST be logged, not thrown
- @e2e exclude Requires disabling a server app mid-suite; asserted by
  PHPUnit with a throwing calendar-service double

### Requirement: Assessments MUST expose the deck leaf for follow-up work
The `assessment` schema SHALL declare `deck` in
`configuration.linkedTypes`, so review follow-ups (moderating a `pending`
review, acting on a low rating) can be tracked as Deck cards linked to the
assessment — supporting both `DeckProvider` create payload shapes
(`{cardId}` link-existing and `{boardId, stackId, title}` create-and-link).

#### Scenario: A pending review gets a follow-up card
- GIVEN an `assessment` with moderation `status = pending`
- WHEN a moderator opens `ReviewDetail` and creates a card from the deck leaf
- THEN a Deck card linked to that assessment MUST be created
- AND the deck leaf tab MUST list it with its board/stack context
- @e2e Playwright: open a seeded pending review, create a card via the
  deck leaf, assert it appears in the tab

### Requirement: Applications and services MUST expose the bookmarks leaf for vendor and documentation links
The `module` and `service` schemas SHALL declare `bookmarks` in
`configuration.linkedTypes`, complementing the single `website` property
each schema carries with a structured, multi-link surface backed by
`openregister_bookmark_links`.

#### Scenario: An application accumulates documentation links
- GIVEN a `module` object whose `website` property is set
- WHEN a user links two bookmarks (documentation, changelog) via the leaf
  on `ModuleDetail`
- THEN both bookmarks MUST be listed in the bookmarks leaf tab with their
  cached titles and URLs
- AND the `website` property MUST be unchanged
- @e2e Playwright: link a bookmark on a module detail page and assert the
  tab renders title + URL

#### Scenario: Bookmarks app uninstalled yields an empty leaf, not an error
- GIVEN the Bookmarks app is not installed
- WHEN a user opens `ModuleDetail`
- THEN the page MUST render without error
- AND the bookmarks leaf MUST present an empty/unavailable state
- @e2e exclude Requires uninstalling a server app; covered by
  `BookmarksProvider`'s own contract (returns empty list when uninstalled)

### Requirement: Leaf declarations MUST live in a register fragment and MUST preserve the contract's decidesk leaf
All `linkedTypes` additions SHALL be declared in a new
`lib/Settings/register.d/catalog-integration-leaves.json` fragment
(ADR-037); `lib/Settings/softwarecatalogus_register.json` MUST NOT be
modified. The fragment MUST declare `contract.configuration.linkedTypes`
as the full array `["decidesk-decisions", "calendar"]` so the existing
`decidesk-decisions` entry survives either array-merge semantic, and every
declared leaf id MUST be one that
`LinkedEntityService::validateType()` accepts at openregister HEAD.

#### Scenario: Merged contract linkedTypes contain both leaves exactly once
- GIVEN the monolith declaring `contract.linkedTypes = ["decidesk-decisions"]`
  and this change's fragment applied
- WHEN the merged settings are read (`GET /api/settings/load`)
- THEN `contract.configuration.linkedTypes` MUST contain
  `decidesk-decisions` and `calendar`, each exactly once
- @e2e exclude Config-merge assertion; asserted by a PHPUnit test on
  `SettingsService::loadSettings()` output

#### Scenario: No dangling linked type is introduced
- GIVEN the fragment applied on an instance at openregister HEAD
- WHEN the `LogDanglingLinkedTypes` repair step runs
- THEN it MUST report zero schemas whose `linkedTypes` reference an
  unregistered integration
- @e2e exclude Repair-step log assertion; verified via occ output in CI

### Requirement: Stale manifest notes MUST be corrected
The `src/manifest.json` `_note` strings on `ContractDetail` and
`ContactpersoonDetail` that assert the schemas declare "NO email/calendar
linkedType" SHALL be rewritten to describe the post-change state, keeping
the documented comms hard-rule (no email widgets) intact and accurate.

#### Scenario: Manifest notes no longer contradict the register
- GIVEN this change applied
- WHEN `src/manifest.json` is searched for "declares NO email/calendar linkedType"
- THEN no detail page whose schema now declares `calendar` or `contacts`
  MUST carry that assertion
- @e2e exclude Documentation-string assertion; checked by grep in review
