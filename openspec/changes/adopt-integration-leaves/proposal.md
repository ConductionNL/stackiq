---
kind: code
depends_on: []
---

# softwarecatalog — adopt OpenRegister integration leaves (contacts, calendar, deck, bookmarks)

## Why

OpenRegister ships an app-agnostic integration-leaf registry
(`openregister/lib/Service/Integration/IntegrationRegistry.php` +
`Providers/`): a schema that declares a leaf id in
`configuration.linkedTypes` gets that Nextcloud app's link surface (sidebar
tab / widgets on the object detail page) with zero per-app glue —
`ContactsProvider` (id `contacts`), `CalendarProvider` (`calendar`),
`DeckProvider` (`deck`), `BookmarksProvider` (`bookmarks`) all exist today,
alongside the legacy allow-list ids in
`LinkedEntityService::legacyLinkedTypeIds()` (`files`, `mail`, `contacts`,
`notes`, `todos`, `calendar`, `talk`, `deck`).

Software Catalog consumes almost none of this. Verified against
`lib/Settings/softwarecatalogus_register.json` at HEAD:

- `allowFiles: true` on exactly 6 schemas (`suite`, `service`,
  `organization`, `usage`, `module`, `compliancy`) — the files leaf.
- `linkedTypes` on exactly one schema: `contract` →
  `["decidesk-decisions"]` (the ADR-066 approval projection).
- No schema declares `contacts`, `calendar`, `deck`, or `bookmarks`.

That leaves four gaps the domain data is already shaped for:

1. **Contacts** — `contactPerson.contactsUid` is literally "Verwijzing
   (UID) naar de Nextcloud-contactpersoon in het adresboek
   (`OCP\Contacts\IManager`)", and `organization.contactsUid` mirrors it.
   The identity IS a Nextcloud contact by design (the
   `ContactpersoonDetail` manifest note says communication happens
   "through the linked Nextcloud contact via contactsUid"), yet the detail
   page renders no contacts leaf — the vCard link exists only as a bare
   string property.
2. **Calendar** — `contract.endDate` ("De einddatum van het contract") and
   `moduleVersion.dateEndSupport` ("Startdatum einde ondersteuning",
   stamped by the EOL matcher per `eolSource`/`eolUpdatedOn`) are the two
   dates portfolio managers plan around, and neither is visible in any
   calendar. The `ContractDetail` manifest `_note` even hard-codes the
   current state: "The contract schema declares NO email/calendar
   linkedType".
3. **Deck** — `assessment` records (reviews, live since the
   `catalog-ratings` fragment added `auteur` + moderation `status`
   pending/approved/rejected, enforced by `ReviewService::submit()` and
   `ModerationService::approve()/reject()`) generate follow-up work
   (moderate a pending review, chase a vendor about a bad rating) that has
   no task surface.
4. **Bookmarks** — `module.website` ("Een URL naar uw applicatie") and
   `service.website` are single URL strings; vendors accumulate more than
   one relevant link (docs, changelog, status page, pricing) and today
   have nowhere structured to put them.

## What Changes

- Add a new ADR-037 register fragment
  `lib/Settings/register.d/catalog-integration-leaves.json` (never editing
  the `softwarecatalogus_register.json` monolith) that declares
  `configuration.linkedTypes`:
  - `contactPerson`: `["contacts"]`
  - `organization`: `["contacts"]`
  - `contract`: `["decidesk-decisions", "calendar"]` — restating the
    existing `decidesk-decisions` entry so the merged array is correct
    regardless of whether the ADR-037 deep-merge unions or replaces
    arrays (verified behaviour recorded in `design.md`).
  - `moduleVersion`: `["calendar"]`
  - `assessment`: `["deck"]`
  - `module`: `["bookmarks"]`
  - `service`: `["bookmarks"]`
- Add a lifecycle-date calendar sync (`lib/Service/LifecycleCalendarService.php`
  + an OR object-saved listener): when `contract.endDate` or
  `moduleVersion.dateEndSupport` is set or changed, upsert a linked
  all-day VEVENT through OpenRegister's calendar link path (the same
  `X-OPENREGISTER-*`-marked events `CalendarProvider::list()` renders),
  so the calendar leaf tab shows the end-of-contract / end-of-support
  event without manual linking; remove the event when the date is
  cleared. EOL-matcher re-stamps of `dateEndSupport`
  (`EolSyncService::run()` → `EolMatcherService`) move the event.
- Update the stale `src/manifest.json` detail-page `_note` prose on
  `ContractDetail` and `ContactpersoonDetail` (both currently assert "NO
  email/calendar linkedType" as the reason no comms widgets are placed)
  and verify the leaf tabs render on the `ContractDetail`,
  `ContactpersoonDetail`, `ModuleversieDetail`, `ReviewDetail`,
  `ModuleDetail`, and `Diensten` detail surfaces.
- No new leaf providers and no OpenRegister changes: everything consumed
  here (`contacts`, `calendar`, `deck`, `bookmarks`) is already a
  registered `IntegrationProvider` at openregister HEAD.

Not BREAKING: purely additive configuration plus one new sync service; no
existing route, response shape, or schema property changes. The
`connection.dateEndSupport` and `compliancy.url` fields are deliberately
out of scope (see `design.md` deferrals).
