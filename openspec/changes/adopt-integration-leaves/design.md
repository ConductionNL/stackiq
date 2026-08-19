# Design — adopt-integration-leaves

## 1. Leaf-per-schema mapping and why each pairing is the right one

| Schema | Leaf | Grounding (real fields / services) |
|---|---|---|
| `contactPerson` | `contacts` | `contactsUid` — "Verwijzing (UID) naar de Nextcloud-contactpersoon in het adresboek (OCP\Contacts\IManager)". The catalogue record is explicitly only the ROLE; identity lives in NC Contacts. The leaf makes the vCard (display name, email, avatar) visible and linkable on `ContactpersoonDetail` instead of a bare UID string. |
| `organization` | `contacts` | `organization.contactsUid` — same convention, "contactpersoon van het type organisatie". |
| `contract` | `calendar` | `contract.startDate` / `contract.endDate` ("De einddatum van het contract (indien van toepassing)"). End dates drive renewal planning; the leaf gives every contract a Meetings/Events tab plus the synced end-date event (section 3). |
| `moduleVersion` | `calendar` | `dateEndSupport` ("Startdatum einde ondersteuning") and `dateWithdrawn`. `dateEndSupport` is machine-maintained by the EOL sync (`eolSource`, `eolUpdatedOn` — "Alleen gezet door de EOL-matcher", `EolSyncService::run()`), so the synced event tracks upstream endoflife.date data. |
| `assessment` | `deck` | Reviews carry moderation state (`status` pending/approved/rejected, forced to `pending` server-side by `ReviewService::submit()`, transitioned only by `ModerationService::approve()/reject()` — see `register.d/catalog-ratings.json`). Follow-ups ("moderate this review", "discuss rating 2/10 for module X with the vendor") are card-shaped work; `DeckProvider` supports both link-existing (`{cardId}`) and create-and-link (`{boardId, stackId, title}`). |
| `module` | `bookmarks` | `module.website` is one URL; real applications have docs, changelog, security advisories, pricing pages. `BookmarksProvider` stores links in OR's own `openregister_bookmark_links` table (survives Bookmarks tag edits, caches title/url for the sidebar). |
| `service` | `bookmarks` | `service.website` — same reasoning for supplier service offerings. |

Leaf ids verified against openregister at HEAD:
`LinkedEntityService::legacyLinkedTypeIds()` = `files`, `mail`, `contacts`,
`notes`, `todos`, `calendar`, `talk`, `deck`; registered
`IntegrationProvider::getId()` values include `contacts`, `calendar`,
`deck`, `bookmarks` (`openregister/lib/Service/Integration/Providers/`).
`LinkedEntityService::validateType()` throws on anything else, and
`Repair/LogDanglingLinkedTypes` logs schemas whose `linkedTypes` name an
unregistered integration — both act as loud guards against a typo in the
fragment.

## 2. Fragment mechanics — the `contract` array hazard

`contract` is the ONE schema that already carries `linkedTypes`
(`["decidesk-decisions"]`, in the monolith). ADR-037 fragments are
deep-merged by `SettingsService::loadSettings()` (`deepMergeConfig()`); for
scalar/object keys the merge is a union, but array-of-scalar semantics
(union vs replace) must not be assumed. The fragment therefore declares the
FULL intended array — `["decidesk-decisions", "calendar"]` — which is
correct under either semantic:

- replace → the merged value is exactly the full array;
- union → `decidesk-decisions` deduplicates, `calendar` is added.

Task 1.3 verifies the merged output (`/api/settings/load`) contains both
entries exactly once. Losing `decidesk-decisions` would silently break the
ContractApprovalPanel's decision leaf — this is the highest-risk line of the
whole change, hence its own task and scenario.

## 3. Lifecycle-date calendar sync

`CalendarProvider` is a read/render surface: it lists CalDAV VEVENTs that
carry `X-OPENREGISTER-*` properties identifying the owning object
(persistence is owned by the Calendar app; creation flows via OR's
`CalendarEventService`). Declaring `calendar` in `linkedTypes` gives the
tab and manual link/create, but nobody will hand-create "contract ends"
events for every contract — so this change adds a thin app-side sync:

- `lib/Service/LifecycleCalendarService.php` — `syncContract(objectData)`
  and `syncModuleVersion(objectData)`; upserts (creates, moves, or
  deletes) one all-day linked event per tracked date field via OR's
  calendar link path. Event titles are English per fleet convention
  (`feedback_english-code`): "Contract ends: {contractNumber}" /
  "End of support: {module name} {version}".
- `lib/Listener/LifecycleCalendarListener.php` — subscribes to
  OpenRegister's object-saved event for the voorzieningen register,
  filters on the `contract` / `moduleVersion` schema slugs resolved
  through `SettingsService` (never hard-coded register ids), and delegates
  to the service. Deletion of the object removes the linked event (OR's
  `ObjectCleanupListener` already unlinks leaf rows; the listener only
  needs to handle date-cleared-on-save).
- Idempotency: the event is looked up by its object link + a
  deterministic marker (one tracked field = one event), so re-saves and
  EOL re-stamps move the single event instead of accumulating duplicates.
- Fail-soft: calendar unavailable (app disabled, no writable calendar) is
  logged and never blocks the object save — same graceful-degradation
  posture the register's other integrations use.

**Which calendar?** The events are personal CalDAV objects; the sync runs
in the saving user's session and writes to that user's default calendar
(the same calendar OR's create-event leaf flow targets). A shared
"portfolio calendar" is a legitimate future improvement, deferred — it
needs an ownership/config decision (`declared-config-enforced-nowhere` is
the failure mode to avoid: no config key is introduced here until
something reads it).

## 4. Deferred (explicitly out of scope)

- `connection.dateEndSupport` / `dateWithdrawn` — same calendar shape as
  `moduleVersion`, deferred until the koppeling detail surface is
  reviewed; adding it later is one fragment line + one listener case.
- `compliancy.url` / `evidenceReference` as bookmarks — compliance
  evidence is file/reference-shaped and already has `allowFiles: true` +
  `evidenceReference`; forcing it into bookmarks would duplicate an
  existing surface.
- `usage` deck leaf (TIME-classification review follow-ups via
  `timeReviewDate`) — plausible, but the assessment leaf should prove the
  pattern first.
- NC Mail (`configuration.linkedTypes: ["mail"]` sidebar target +
  `mailObjectTemplate`) — a separate comms-rule discussion; the manifest
  `_note`s document a deliberate "comms hard-rule" that email widgets stay
  off these detail pages, and this change does not reopen it.

## 5. Manifest touch-points

`src/manifest.json` detail pages affected: `ContactpersoonDetail`,
`ContractDetail`, `ModuleDetail`, `Diensten`/`DienstDetail` equivalent,
`ModuleversieDetail`, `ReviewDetail`, `OrganisatieDetail`. The leaf tabs
render from schema `linkedTypes` via the shared detail-page sidebar
(`CnObjectSidebar` — "so the CnObjectSidebar and dashboard widgets can
render a … tab without per-app glue", per `CalendarProvider`'s own
docblock); no per-page widget wiring is expected, but the two `_note`
strings that assert "declares NO email/calendar linkedType" become false
for `contract`/`contactPerson` and MUST be rewritten to describe the new
state, so the next audit doesn't read a stale premise
(`reference_design-system-adoption-silent-failures`: notes that lie are
worse than no notes).
