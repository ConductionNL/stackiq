# Design: architecture-decision-register

Read at development 49e65cb4. Line numbers below are from that sha. The Architecture menu group comes from `architecture-views-editor` (its D8), and `GebruikDetail` from `landscape-usage-registration`.

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Register | `stackiq` register (`lib/Settings/softwarecatalogus_register.json:817`), new schema `architectureDecision` | through a new fragment `lib/Settings/register.d/architecture-decision-register.json` |
| Lifecycle guard | new `lib/Lifecycle/ArchitectureDecisionReviewGuard.php`, implementing `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (`openregister-ro/lib/Lifecycle/LifecycleGuardInterface.php:37`) | resolved by OpenRegister through the server container by its class name |
| Analysis stub | `tests/Stubs` gains the OpenRegister guard interface and `GuardResult` if psalm and phpstan cannot see them | |
| Pages | new `src/manifest.d/architecture-decision-register.json` with `Architectuurbesluiten` (index) and `ArchitectuurbesluitDetail` (detail) | |
| Pages | `GebruikDetail` in `src/manifest.d/usages.json` and `StandaardDetail` (`src/manifest.json:724`) each gain an `object-list` widget | |
| Menu | `src/menu-layout.json` relocates `Architectuurbesluiten` under the `Architecture` group | |
| Service, controller, routes | none | reads and writes go through OpenRegister's objects API; transitions through OpenRegister's lifecycle actions |

## Decisions

### D1. An architecture decision is a stackiq object, not a decidiq decision

An architecture decision is recorded as an `architectureDecision` object in the `stackiq` register with a lifecycle declared on the schema (ADR-031). When a board formally adopts it in decidiq, the object links to that decidiq decision, in the way `catalogContract.decisions` does (register.json:3450).

Rejected: every architecture decision as a decidiq decision, projected back as contracts are. An architecture decision record is an architecture artefact: context, options, consequences, links to applications and GEMMA elements, and a chain of decisions that supersede each other. Architects review it; most never reach a board. A decidiq decision is a governance act with meetings, voting and signing. Delegating would make recording any architecture decision depend on decidiq being installed, move architecture fields into decidiq, and cost the fail-closed cross-app event path the contracts carry, including the two event class spellings after the rename (`lib/Service/ContractApprovalService.php:64` to :104).

### D2. The schema

`architectureDecision`:

| Field | Type | Notes |
|---|---|---|
| title | string, required | |
| context | string, long text | why a decision is needed |
| decision | string, long text | what was decided |
| alternatives | list of objects `{option, reasonRejected}` | the options not chosen |
| consequences | string, long text | |
| category | enum application, data, integration, infrastructure, security, standards, facetable | |
| impact | enum low, medium, high, facetable | |
| status | enum draft, in review, accepted, rejected, superseded, deprecated, default draft, facetable | lifecycle in the Declarative section |
| reviewer | string, a Nextcloud user id | required to submit |
| decidedOn | date | set by the owner when accepted |
| applications | list of related `usage` | the applications in use it affects |
| elements | list of related `element` | the GEMMA reference components, standards or other elements it affects |
| supersedes | related `architectureDecision` | |
| supersededBy | related `architectureDecision` | |
| boardDecisions | list of decidiq decision uuids, `x-external-register` decidesk, `referenceType` decision | as `catalogContract.decisions` (register.json:3450) |

The read and write rules follow `usage` (register.json, `usage.authorization`): the catalogue groups create and update, and read is matched on `_organisation`. OpenRegister multitenancy scopes each decision to the organisation that recorded it.

### D3. Fixed classification lists

Category and impact are enums on the schema, so `CnIndexPage` facets and the forms render them without code.

Rejected: dropdown fields an administrator adds to a decision template, as the LeanIX changelog describes. That needs a field-definition schema and a renderer for its answers next to the schema-driven forms (ADR-012), and properties added to the schema in OpenRegister's editor vanish on the next register import with a version bump. Two fixed lists cover the classification the row asks for, and a list can grow in a later register version.

### D4. A second pair of eyes through one guard

`ArchitectureDecisionReviewGuard::check(object, action, userId)` returns:

| Action | Allowed when |
|---|---|
| submit | `reviewer` names a Nextcloud user and is not the caller |
| accept, reject | the caller is the `reviewer` |
| supersede | `supersededBy` points at a decision in status accepted |

Other actions pass. The guard reads only; it never writes (the interface contract, `LifecycleGuardInterface.php:31` to :35). OpenRegister runs it for a named transition and for a direct edit of `status` alike (`openregister-ro/openspec/specs/object-lifecycle/spec.md:126` to :134), and denies with a 403 and the guard's message.

Rejected: the review rule in a stackiq controller in front of the transition. A direct save of `status` through OpenRegister's objects API would pass around it. The guard sits in the save pipeline.

### D5. Pages

`Architectuurbesluiten` (`/architectuurbesluiten`) is a `CnIndexPage` over `architectureDecision` with columns title, status, category, impact and reviewer, the facets in the sidebar, and quick filters All, In review, Accepted and "To review by me" (`{"reviewer": "@me"}`, resolved by `@conduction/nextcloud-vue` `src/utils/resolveFilterTokens.js:119`).

`ArchitectuurbesluitDetail` (`/architectuurbesluiten/:id`) is a `type: detail` page on the ADR-062 grid with a `data` widget for the text fields, a second `data` widget for classification, reviewer and dates, `object-list` widgets for the linked applications and elements, `lifecycleActions` on, and the History tab.

`GebruikDetail` and `StandaardDetail` each get an `object-list` widget "Architecture decisions" over `architectureDecision` with filter `{"applications": "@objectId"}` or `{"elements": "@objectId"}`, which OpenRegister answers with a JSON containment test (`openregister-ro/lib/Db/MagicMapper/MagicSearchHandler.php:1601`).

## Declarative versus imperative

- The lifecycle is declared as `configuration.x-openregister-lifecycle` on `architectureDecision`: field `status`, initial draft, final rejected, superseded and deprecated, transitions submit (draft to in review, `requires` the guard), accept (in review to accepted, `requires` the guard), reject (in review to rejected, `requires` the guard), rework (in review or rejected to draft), supersede (accepted to superseded, `requires` the guard) and deprecate (accepted to deprecated). Every `from` and `to` value is an enum value exactly (register changelog 2.4.4, register.json:7).
- Two notifications are declared in `x-openregister-notifications` on the schema, in the shape `usage` uses (register.json:2662): `review-requested`, trigger `updated` with the condition status equals in review, recipient `{"kind": "field", "field": "reviewer"}` (a single user id, which the resolver checks is a real user, `NotificationRecipientResolver.php:187`); and `review-concluded`, trigger `updated` with status in accepted or rejected, recipient `{"kind": "object-acl", "permission": "manage"}`. Both on the channels `nc-notification` and `email`, with Dutch and English subjects.
- The links are `related-object` properties and manifest `object-list` widgets.
- The guard is the only PHP, and it is a read-only check the platform calls.

## Seed data

All objects live in the `stackiq` register.

### Schema: `architectureDecision`

| Field | Object 1 | Object 2 |
|---|---|---|
| slug | `seed-ab-zaakgericht-werken` | `seed-ab-api-first` |
| title | Eén zaaksysteem voor alle domeinen | Nieuwe koppelingen alleen via API's |
| context | Drie domeinen gebruiken elk een eigen zaaksysteem. | Bestandsuitwisseling via FTP is niet te volgen. |
| decision | We gaan naar één zaaksysteem, domein voor domein. | Een nieuwe koppeling gebruikt een gedocumenteerde API. |
| category | application | integration |
| impact | high | medium |
| status | accepted | in review |
| reviewer | admin | admin |
| applications | `gebruik-suite4-gem-delft-eigenaar` | |

The usage slug is one of the three the register seeds (register.json `components.objects`).

## Risks

- **Guard resolution.** OpenRegister resolves a guard by class name through the server container and fails closed when it cannot (`openregister-ro/openspec/specs/object-lifecycle/spec.md:593`). A typo in the `requires` value blocks the transition on every instance, so the register shape test asserts the value equals the guard's class name.
- **Payload shape.** The guard reads `reviewer`, `supersededBy` and the linked decision's status from the payload OpenRegister passes. Its unit test builds that payload from a saved object, not by hand.
- **One reviewer.** The notification recipient kind `field` takes one user id, so a decision has one reviewer. A review by a group can follow once the resolver takes a list.
