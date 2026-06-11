# application-lifecycle-tracking

## ADDED Requirements

### Requirement: Lifecycle phase is derived from existing gebruik dates

The app SHALL derive a lifecycle phase for every gebruik from its existing
phase-start dates (`startDatumVerwerving`, `startDatumGepland`,
`startDatumInProductie`, `startDatumUitTeFaseren`, `startDatumUitGefaseerd`):
the most advanced phase whose start date is in the past. A gebruik with no
phase dates SHALL derive as `Onbekend` (unknown) and SHALL remain visible in
all portfolio views. The derived phase SHALL NOT be persisted as a stored
field — it is computed at render/query time from the dates (single source of
truth).

#### Scenario: Phase follows the most recent past phase date

- **WHEN** a gebruik has `startDatumInProductie` in the past and `startDatumUitTeFaseren` in the future
- **THEN** its derived phase shows as `In productie` in list and detail views
- **AND** once `startDatumUitTeFaseren` passes, the same gebruik derives as `Uit te faseren` without any object write

#### Scenario: Undated gebruik shows as unknown, not hidden

- **WHEN** a gebruik has none of the five phase dates set
- **THEN** it is shown with phase `Onbekend` in listings and the roadmap

### Requirement: End-of-support is surfaced on applications in use

A gebruik whose linked `moduleVersie` has a passed `datumEindeOndersteuning`
or a set `datumTeruggetrokken` SHALL display an end-of-support indicator in
application listings and the detail view. Application listings SHALL offer an
"EOL approaching" filter selecting gebruiken whose linked version's
`datumEindeOndersteuning` falls within the app-config window
`softwarecatalog/eol_warning_window_days` (default 180). EOL facts SHALL stay
on `moduleVersie`; no end-of-support field SHALL be copied onto `gebruik`.

#### Scenario: Past end-of-support shows an EOL indicator

- **WHEN** a user views an application list containing a gebruik whose moduleVersie's `datumEindeOndersteuning` is in the past
- **THEN** that entry carries a visible end-of-support indicator
- **AND** the detail view states the end-of-support date and, when set, the withdrawn date

#### Scenario: EOL-approaching filter respects the window

- **WHEN** a user applies the EOL-approaching filter with versions ending support inside and outside the configured window
- **THEN** only gebruiken whose version ends support within the window are listed, ordered by end-of-support date ascending

### Requirement: Portfolio roadmap per organisation

The app SHALL provide a portfolio roadmap view that, for a selected
organisation, lists its gebruiken grouped by derived lifecycle phase, ordered
within each group by the nearest urgency date (end-of-support, phase-out, or
planned-replacement date). Entries with phase `Onbekend` SHALL render in
their own group rather than being omitted. When a planned replacement is set,
the roadmap SHALL show the successor module and planned date on the entry.

#### Scenario: Roadmap groups and orders the portfolio

- **WHEN** a user opens the roadmap for an organisation with gebruiken in multiple phases
- **THEN** the gebruiken appear grouped by derived phase with their EOL/phase-out dates
- **AND** within a group, entries are ordered by nearest urgency date

#### Scenario: Replacement is visible on the roadmap

- **WHEN** a gebruik in phase `Uit te faseren` has a planned replacement set
- **THEN** its roadmap entry shows the successor module and the planned replacement date
- **AND** the successor links to that module's detail view

### Requirement: Planned replacement is recorded on the gebruik

The `gebruik` schema SHALL gain two optional fields: `geplandeVervanging`
(related-object reference to the successor `module`) and
`geplandeVervangingsDatum` (date). Replacement SHALL be recorded per gebruik
(per organisation's usage), never on the module itself. Existing gebruik
objects SHALL remain valid without the new fields.

#### Scenario: User records a planned replacement

- **WHEN** a user edits a gebruik and selects a successor module with a planned date
- **THEN** the gebruik stores the `geplandeVervanging` reference and `geplandeVervangingsDatum`
- **AND** clearing the fields returns the gebruik to having no planned replacement

#### Scenario: Existing objects are unaffected by the schema addition

@e2e exclude Schema-migration invariant; covered by PHPUnit register-import tests.

- **WHEN** the updated register definition is imported over existing data
- **THEN** existing gebruik objects without the new fields load and save unchanged

### Requirement: Lifecycle events dispatch via the OR notification engine

The register SHALL declare two `x-openregister-notifications` rules (ADR-031
dialect): `eol-approaching` on `moduleVersie` (scheduled,
`datumEindeOndersteuning` within the EOL window) and `phaseout-approaching`
on `gebruik` (scheduled, `startDatumUitTeFaseren` within the window), each
with `nl` + `en` subjects and recipients `softwarecatalog-admins` group +
object-ACL manage. Both rules SHALL ship `enabled: false` until the engine's
scheduled date-window filtering is confirmed; if unsupported, the OR engine
gap SHALL be filed and referenced — the app SHALL NOT dispatch lifecycle
notifications imperatively.

#### Scenario: Approaching end-of-support notifies the catalogue admins

@e2e exclude Notification-engine dispatch; covered by integration tests against the OR notification engine once the date-window filter is confirmed.

- **WHEN** the rules are enabled and a moduleVersie's `datumEindeOndersteuning` enters the warning window
- **THEN** members of `softwarecatalog-admins` and manage-ACL holders receive the end-of-support notification
- **AND** versions outside the window trigger nothing

#### Scenario: Rules are declared in the canonical dialect

@e2e exclude Declarative register-file shape; covered by a Newman/PHPUnit assertion on the register JSON.

- **WHEN** the register definition is inspected
- **THEN** both lifecycle rules exist as `x-openregister-notifications` entries with scheduled triggers, bilingual subjects, and no imperative dispatch code in the app
