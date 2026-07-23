# bio-compliance-assessment Specification

## Purpose
TBD - created by archiving change bio-compliance-assessment. Update Purpose after archive.
## Requirements
### Requirement: BIO measures form a seedable reference catalog

A `bioMaatregel` object SHALL represent one BIO 2.0 measure, carrying a
`code`, `naam`, `omschrijving`, `thema`, `bioVersie`, the applicable
`bbnNiveau`(s), and a `bron` reference to the published measure. The
catalog SHALL be seedable from the published BIO measure list, following
the same reference-catalog pattern as the GEMMA `element` catalog. The
catalog SHALL be publicly readable so it can be used as a selection
source for `compliancy` records and the BIO coverage report.

#### Scenario: Catalog is seeded with BIO measures

@e2e exclude Install/upgrade seed-data existence, not a UI event; covered by BioComplianceRegisterShapeTest::testBioMaatregelSeedDataExists (PHPUnit register-shape test).

- **WHEN** the app is installed or upgraded
- **THEN** the `bioMaatregel` catalog contains the seeded BIO 2.0 measure entries with code, title, theme, and applicable BBN level(s)

#### Scenario: Measure catalog entry is browsable

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); BioMaatregelen/BioMaatregelDetail are declarative manifest pages reusing the proven Standaarden/StandaardDetail index+detail pattern verbatim.

- **WHEN** a user opens the BIO measures catalog
- **THEN** each entry shows its code, title, theme, BIO version, and applicable BBN level(s)
- **AND** activating an entry shows the compliance claims (`compliancy` records) that reference it, mirroring how a GEMMA standard shows its compliance claims

### Requirement: Each application records a BBN level

The `module` schema SHALL gain an optional `bbnLevel` field with enum
values `BBN1`, `BBN2`, `BBN3` (the Baseline Informatiebeveiliging
Overheid basic security levels), facetable so it can drive catalog
filters and the BIO coverage report.

#### Scenario: Vendor sets the BBN level for an application

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); `bbnLevel` is a manifest-declared field on the existing ModuleDetail data widget's `include` list, no bespoke Vue code.

- **WHEN** a vendor edits an application and selects a BBN level
- **THEN** the module's `bbnLevel` is stored
- **AND** the application's detail view and catalog listing show the BBN level

### Requirement: Each application tracks DPIA status and review dates

The `module` schema SHALL gain `dpiaStatus` (enum: not required,
required, executed), `dpiaDate` (date the DPIA was executed),
`dpiaVolgendeBeoordeling` (date the DPIA is next due for review), and
`dpiaDocumentRef` (an NC Files reference to the DPIA document, following
the `compliancy.bewijsReferentie` link-don't-store pattern). All four
fields SHALL be optional and independently settable; `dpiaDate` and
`dpiaVolgendeBeoordeling` SHALL be meaningful only when `dpiaStatus` is
`executed` but the schema SHALL NOT hard-enforce that ordering (informational, not validated at write time).

#### Scenario: Vendor records a completed DPIA with document evidence

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); the four DPIA fields are manifest-declared on the existing ModuleDetail data widget's `include` list, no bespoke Vue code.

- **WHEN** a vendor sets `dpiaStatus` to executed, fills in `dpiaDate`, `dpiaVolgendeBeoordeling`, and links a DPIA document via NC Files
- **THEN** all four fields are stored on the module
- **AND** the application's detail view shows the DPIA status, dates, and a link to open the linked document via Nextcloud Files

#### Scenario: Application has a required but not yet executed DPIA

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); same manifest-declared field, no bespoke Vue code.

- **WHEN** a vendor sets `dpiaStatus` to required without filling in `dpiaDate`
- **THEN** the application's detail view and catalog listing show the DPIA as required-but-not-executed

### Requirement: Application references its register van verwerkingen entry

The `module` schema SHALL gain an optional `verwerkingsregisterRef`
field storing a reference (URL or identifier) to the entry for this
application in the organisation's register van verwerkingen. This
change stores only the reference; it does not model the register
itself.

#### Scenario: Vendor links the register van verwerkingen entry

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); `verwerkingsregisterRef` is a manifest-declared field on the existing ModuleDetail data widget's `include` list, no bespoke Vue code.

- **WHEN** a vendor sets `verwerkingsregisterRef` on an application
- **THEN** the reference is stored on the module
- **AND** the application's detail view shows the reference as a link when it resolves to a URL

### Requirement: Catalog can be filtered by BBN level and DPIA status

Module listings and catalog search SHALL offer filters on `bbnLevel`
and `dpiaStatus`, including a compound filter for "applications without
a DPIA at BBN2 or higher" (i.e. `bbnLevel` in `[BBN2, BBN3]` AND
`dpiaStatus` is not `executed`).

#### Scenario: Buyer filters modules lacking DPIA at BBN2 or higher

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); the quick filter is a manifest-declared `config.quickFilters` entry on the Modules index page, verified against OpenRegister's MagicSearchHandler operator dialect by static code review (see docs/features/bio-compliance-assessment.md's filter-shape note), not bespoke Vue code.

- **WHEN** a user applies the "without DPIA at BBN2+" filter to the catalog
- **THEN** only modules with `bbnLevel` of BBN2 or BBN3 and a `dpiaStatus` other than executed are listed

#### Scenario: Buyer filters modules by BBN level alone

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); same manifest-declared quick filter, no bespoke Vue code.

- **WHEN** a user filters the module catalog on a selected BBN level
- **THEN** only modules with that `bbnLevel` are listed

### Requirement: Organisation BIO coverage is reportable

For a selected organisation, the app SHALL report BIO coverage over the
organisation's in-use applications (gebruiken → modules), extending the
existing compliance matrix (see `module-compliance-assessment`): per
application, the report SHALL show the BBN level, the DPIA status, and
— for a selected set of BIO measures — whether the module's BIO
compliance is verified, claimed, or absent (reusing the `compliancy`
verified/claimed/none states with the `bioMaatregel` relation).
Applications with no BBN level, no DPIA data, or no BIO measure
compliance data SHALL be listed as such — never omitted.

#### Scenario: Organisation sees its BIO compliance posture

- **WHEN** a user selects their organisation and one or more BIO measures in the BIO coverage report
- **THEN** every in-use application is listed with its BBN level, DPIA status, and verified / claimed / none for each selected BIO measure
- **AND** applications without a BBN level, DPIA data, or BIO measure compliance data are visibly listed as having none, not omitted

### Requirement: Overdue DPIA reviews trigger a notification

The `module` schema SHALL declare a `dpia-review-overdue`
`x-openregister-notifications` rule using the canonical dialect (see
`softwarecatalog-notifications`): a `scheduled` trigger with a filter
matching modules whose `dpiaStatus` is `executed` and whose
`dpiaVolgendeBeoordeling` is on or before today (`withinNext` with a
zero-day window), dispatching to the `softwarecatalog-admins` group and
the module's manage-ACL holders on the `nc-notification` and `email`
channels, with `nl` and `en` subject strings naming the application and
the due date.

#### Scenario: Overdue DPIA review notifies admins and record managers

@e2e exclude Declarative background scheduled-sweep notification (x-openregister-notifications), not a UI event; the rule's dialect shape is covered by BioComplianceRegisterShapeTest::testModuleDeclaresDpiaOverdueRule (PHPUnit) and dispatch mechanics belong to OpenRegister's own notification engine.

- **WHEN** a module has `dpiaStatus` executed and `dpiaVolgendeBeoordeling` on or before today, and the scheduled sweep runs
- **THEN** the engine dispatches `nc-notification` + `email` to the `softwarecatalog-admins` group and the module's manage-ACL holders
- **AND** the subject includes the application name and the review-due date in the recipient's locale (nl/en)

#### Scenario: DPIA with a future review date does not notify

@e2e exclude Declarative background scheduled-sweep notification, not a UI event; the `withinNext P0D` filter shape is covered by BioComplianceRegisterShapeTest::testModuleDeclaresDpiaOverdueRule (PHPUnit) and the engine's own filter-evaluation tests own the negative case.

- **WHEN** a module has `dpiaStatus` executed and `dpiaVolgendeBeoordeling` set to a date after today
- **THEN** the scheduled sweep does not dispatch the `dpia-review-overdue` notification for that module

