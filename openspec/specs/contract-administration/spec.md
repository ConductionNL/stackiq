---
status: done
---

# contract-administration Specification

## Purpose
Manages software contracts against the existing contract schema through the OpenRegister object store, surfaced by manifest index and detail pages and visible in application context. Surfaces expiring contracts via a configurable window filter, transitions active contracts to expired automatically when their end date passes, enables contract-expiry notifications, derives and totals annualised cost, and links contract documents as Nextcloud Files references.
## Requirements
### Requirement: Contracts are managed on the existing schema via the manifest renderer

Contract CRUD SHALL run against the existing `contract` schema in the
softwarecatalogus register through the OpenRegister object store, surfaced by
the manifest `Contracten` (index) and `ContractDetail` (detail) pages — no
app-local contract controllers or services for CRUD (ADR-022). The index page
columns SHALL reference real schema fields: `contractNummer`, `contractType`,
`startDatum`, `eindDatum`, `kosten`, `status` (replacing the current drifted
columns `naam`/`leverancier`/`ingangsdatum`/`einddatum`).

#### Scenario: Create a contract linked to an application's gebruik

- **WHEN** a user creates a contract from the Contracten page with required fields (`contractNummer`, `contractType`, `startDatum`, `dienst`, `gebruik`)
- **THEN** the contract is stored as an OR object in the softwarecatalogus register
- **AND** it appears in the Contracten index with its number, type, dates, cost, and status

#### Scenario: Index columns render real data

- **WHEN** the Contracten index page lists existing contracts
- **THEN** every configured column shows the corresponding schema field value
- **AND** no column is permanently empty due to referencing a non-existent field

### Requirement: Contracts are visible in application context

The detail view of an application (gebruik/module) SHALL show the contracts
that reference it (via the contract's `gebruik`/`dienst` relations) in a
Contracts tab, including contract number, type, end date, annualised cost,
and status — so the portfolio question "what backs this application and when
does it expire" is answerable in place.

#### Scenario: Application detail lists its contracts

@e2e exclude Application-detail Contracts tab is a manifest-renderer follow-up (ContractDetail currently exposes Overview + Audit tabs only); the contract→gebruik/dienst relation and the listing query are covered by PHPUnit on the schema relations and by the contractCost vitest derivation. Tracked for the renderer relations-tab work.

- **WHEN** a user opens the detail view of an application that has linked contracts
- **THEN** a Contracts tab lists those contracts with number, type, end date, and status
- **AND** selecting one navigates to its ContractDetail page

### Requirement: Expiring contracts are surfaced

The Contracten index SHALL offer an "expiring soon" filter selecting active
contracts whose `eindDatum` falls within a configurable window (app-config
`stackiq/contract_expiry_window_days`, default 90). The filter SHALL
be a query over `eindDatum` — "expiring" is never a stored status.

#### Scenario: Expiring-soon filter shows only contracts in the window

- **WHEN** a user applies the expiring-soon filter and contracts exist with `eindDatum` inside and outside the window
- **THEN** only active contracts with `eindDatum` within the window are listed, ordered by `eindDatum` ascending
- **AND** expired and `In onderhandeling` contracts are not listed

### Requirement: Contract status is maintained automatically

A server-side scheduled mechanism SHALL transition contracts from `Actief` to
`Verlopen` once `eindDatum` has passed — declaratively via the OpenRegister
lifecycle engine if the date-driven transition is expressible, otherwise via
a daily app TimedJob. The mechanism SHALL only perform `Actief → Verlopen`;
it SHALL never modify `In onderhandeling` contracts, contracts without an
`eindDatum`, or manually set statuses in any other direction.

#### Scenario: Past end date flips status to Verlopen

@e2e exclude Scheduled background transition; covered by PHPUnit job/engine tests and verified via occ background-job:list in the deploy checklist.

- **WHEN** the scheduled status run processes an `Actief` contract whose `eindDatum` is in the past
- **THEN** the contract's `status` becomes `Verlopen`
- **AND** an `Actief` contract with a future or absent `eindDatum` is unchanged

#### Scenario: Negotiation status is never touched

@e2e exclude Backend invariant; covered by PHPUnit tests on the status mechanism.

- **WHEN** the scheduled status run processes an `In onderhandeling` contract whose `eindDatum` is in the past
- **THEN** the contract's `status` remains `In onderhandeling`

### Requirement: Contract expiry notifications are enabled

The `contract-expiry` notification rule SHALL be enabled once the OpenRegister
notification engine's `scheduled` date-window filtering ("`eindDatum` within N
days") is confirmed, with its window aligned to `contract_expiry_window_days`
defaults. If the engine
cannot express the window, the gap SHALL be filed against OpenRegister and
the rule SHALL stay disabled — no app-local notification dispatch (ADR-031).

#### Scenario: Approaching end date notifies admins and record managers

@e2e exclude Notification-engine dispatch; covered by PHPUnit/integration tests against the OR notification engine and a Newman check on the rule declaration.

- **WHEN** an `Actief` contract's `eindDatum` enters the notification window and the scheduled rule evaluates
- **THEN** members of the `stackiq-admins` group and users with manage ACL on the record receive the contract-expiry notification
- **AND** a contract outside the window or not `Actief` triggers nothing

### Requirement: Annualised cost is derived and totalled

The app SHALL derive an annualised cost per contract from `kosten` ×
`kostenPeriode` (`Maandelijks` ×12, `Jaarlijks` ×1; `Eenmalig` excluded from
the annual figure and shown separately as one-off) and SHALL total annualised
contract cost per application and per organisation in their detail views. The
derived figures SHALL never be persisted on the objects.

#### Scenario: Contract list shows annualised cost

@e2e exclude Annualised-cost derivation (Maandelijks ×12, Jaarlijks ×1, Eenmalig shown separately as one-off) is a pure client-side calculation in src/utils/contractCost.js, fully covered by tests/vitest/contractCost.spec.js (annualisesCost + isOneOff cases); never persisted, so there is no server round-trip to drive in a browser.

- **WHEN** a user views the Contracten index containing a monthly contract of 1000 and a yearly contract of 6000
- **THEN** their annualised costs display as 12000 and 6000 respectively
- **AND** a one-off (`Eenmalig`) contract shows its amount marked as one-off, not annualised

#### Scenario: Application detail totals its contract cost

@e2e exclude Per-application annualised total is the totalAnnualisedCost() reducer in src/utils/contractCost.js, covered by tests/vitest/contractCost.spec.js ("sums annual and one-off separately across contracts"); the application-detail Contracts tab that would surface it is the same manifest-renderer follow-up as "Application detail lists its contracts".

- **WHEN** a user opens an application whose gebruik has multiple active linked contracts
- **THEN** the Contracts tab shows the summed annualised cost of those contracts

### Requirement: Contract documents are NC Files references

Contract documents SHALL be linked via `documentReferentie` as Nextcloud
Files references — the register stores the link, never the document content
(link, don't store). Document full-text search remains a Nextcloud Files
capability.

#### Scenario: Linked document opens in Files

@e2e exclude documentReferentie stores only the NC Files link (link, don't store); the contract-detail document-link widget is a manifest-renderer follow-up (ContractDetail currently exposes Overview + Audit tabs only). The reference round-trip is covered by PHPUnit on the schema; opening the file is core Nextcloud Files behaviour, not app surface.

- **WHEN** a user opens a contract whose `documentReferentie` points to an NC Files file
- **THEN** the detail view shows the document link
- **AND** following it opens the file via Nextcloud Files

