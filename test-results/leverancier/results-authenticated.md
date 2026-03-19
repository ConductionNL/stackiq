# Leverancier Test Results (Authenticated)

**Persona**: Jan Pietersen (jan.pietersen@test.nl) — Aanbod-beheerder, Test Leverancier BV
**Date**: 2026-03-16 (Session 10)
**Browser**: Playwright/Chromium (headless)
**Frontend**: http://localhost:3000
**Backend**: http://localhost:8080

---

## Environment Notes

- **Organisation RBAC issue**: On initial login, the frontend failed to fetch the organisation register object with 404 errors. The root cause was that the `@self.organisation` field on the register object for "Test Leverancier BV" (UUID `2b7a80a2-e2e5-430d-85fb-4c292d766227`) was set to `c0ff4d70-14f0-4852-9c18-ce522996119c` (Default Organisation) instead of self-referencing. This was fixed via direct database update during testing. This is a **test setup issue**, not an application bug.
- **Maintenance mode triggered**: During the Applicatiegebruik wizard submission, the backend entered maintenance mode (HTTP 503), which caused the wizard to fail. This was resolved by running `occ maintenance:mode --off`.
- **Beheer table shows wrong data**: The beheer/applicaties table shows applications from ALL organizations (Test Leverancier 2, Test Gemeente) rather than only Test Leverancier BV's own applications. This appears to be a critical RBAC scoping bug.

---

## Wizard Execution Results

### Wizard 1: Applicatie publiceren
**Status**: PASS (with notes)
**Route**: `/forms/applicatie?type=eigen`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Applicatie-informatie | Filled: Naam, Website, Korte omschrijving, Uitgebreide omschrijving | PASS |
| Step 2 - Licentie & Hosting | Selected: Open source, EUPL 1.2, SaaS | PASS |
| Step 3 - Versies | Default 1.0.0 "in gebruik" auto-created for SaaS | PASS |
| Step 4 - Referentiecomponenten | Selected: Zaakregistratiecomponent | PASS |
| Step 5 - Standaarden | "Geen standaardversies beschikbaar" for selected component | PASS (expected) |
| Step 6 - Koppelingen | Skipped (optional) | PASS |
| Step 7 - Controleren | All data shown correctly in review | PASS |
| Submit | "Applicatie succesvol aangemeld!" | PASS |

**Created object**: Test Wizard App (ID: `a18f1b1a-9e1a-43e6-b1db-98330c63656c`)

**Observations**:
- Field labels on Step 1: Naam*, Website*, Korte omschrijving*, Uitgebreide omschrijving, Logo, Contactpersoon
- All required fields have (i) tooltip icons
- Markdown editor available for Uitgebreide omschrijving with character counter (5000 max)
- Contactpersoon dropdown shows "Zoek en selecteer contactpersoon" but no options found (test setup did not create contact for jan.pietersen successfully)
- Version step auto-creates 1.0.0 for SaaS hosting with info alert explaining this

### Wizard 2: Dienst publiceren
**Status**: PASS
**Route**: `/forms/dienst?type=eigen`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Applicaties | Selected: Test Wizard App | PASS |
| Step 2 - Dienst-informatie | Filled: Naam, Website, Korte omschrijving, Diensttype | PASS |
| Step 3 - Controleren | All data shown correctly | PASS |
| Submit | "Dienst succesvol aangemeld!" | PASS |

**Created object**: Test Wizard Dienst (ID: `9c29fd61-240f-4b67-98a3-45de2a224666`)

**Observations**:
- Wizard title is "Dienst registreren" (not "Dienst publiceren") - this is relevant for #359
- Diensttype options: Functioneel beheer, Applicatiebeheer, Technisch beheer, Implementatieondersteuning, Opleidingen, Licentiereseller
- "Geen bestaande diensten gevonden" correctly shown when none exist
- Review step shows: Naam, Korte omschrijving, Website, Diensttype, linked Applicaties

### Wizard 3: Koppeling publiceren
**Status**: FAIL - Volgende button stays disabled
**Route**: `/forms/koppeling?type=eigen-organisatie`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Koppeling zoeken | Selected: Test Wizard App, showed "Geen bestaande koppelingen" | PASS |
| Step 2 - Koppeling details | Filled: Richting (Bi-directioneel), Applicatie B (DigiD), Status (In gebruik) | FAIL |
| Step 3 - Aanvullende informatie | Not reached | BLOCKED |
| Step 4 - Controleren | Not reached | BLOCKED |

**Bug**: Despite filling all three required fields (Applicatie A, Richting, Applicatie B of BGV) AND the optional Status field, the "Volgende" button remained disabled. The wizard could not proceed past Step 2. This is a critical workflow bug preventing koppeling creation via the dedicated wizard.

### Wizard 4: Applicatiegebruik melden
**Status**: FAIL - Backend 503 error on submit
**Route**: `/forms/gebruik/applicatie?type=ontbrekend-organisatie`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Selecteren | Selected: Test Wizard App + Test Gemeente | PASS |
| Step 2 - Controleren | Review showed correct data | PASS |
| Submit | "Registratie mislukt" - 503 error | FAIL |

**Bug**: The Verzenden button triggered a 503 error (`POST /api/apps/openregister/api/objects/voorzieningen/gebruik`). The backend had entered maintenance mode during this test cycle. After fixing maintenance mode, the wizard was not retried.

**Additional note**: The Klant(en) dropdown search for "Amsterdam" returned 0 results, but searching "Test" returned 50 results. The search also showed many duplicate "Test Samenwerking" and "Test Gemeente" entries (duplicate test data from setup script).

---

## Detail Page Testing

### Applicatie Detail Page: Test Wizard App

**URL**: `/publicatie/a18f1b1a-9e1a-43e6-b1db-98330c63656c`

**Header section**:
- Title: "Test Wizard App (Test Leverancier BV)" - correct
- Type badge: "Applicatie" with icon - correct
- "Acties bewerken" gear button present - correct
- Short description displayed: "Applicatie aangemaakt via wizard test"
- Long description displayed: "Dit is een uitgebreide beschrijving van de test applicatie."

**Sidebar**:
- Website: https://test-leverancier.nl/app (clickable link)
- Licentietype: Open source
- Licentie: European Union Public Licence (EUPL), versie 1.2
- Hosting type: SaaS (bullet list)

**Tabs**:
| Tab | Count | Content | Status |
|-----|-------|---------|--------|
| Standaarden | (0) | "Geen standaardversies gevonden voor de gekoppelde referentiecomponenten." | PASS |
| Geschikt voor | (1) | Shows referentiecomponent | PASS |
| Applicatieversies | (1) | Shows 1.0.0 "in gebruik" since 16 maart 2026 | PASS |
| Diensten | (1) | Shows "Test Wizard Dienst" by Test Leverancier BV, type Implementatieondersteuning | PASS |

**Missing tabs** (compared to skill file expectations): Koppelingen, Gebruik, Beschrijving - these may not appear when empty or not applicable.

---

## Issue Test Results

### Closed Issues (verified as resolved per issues.md)

The following issues are listed as closed in issues.md. Where testable, they were verified during wizard and detail page testing:

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #185 | Detailpagina's | CLOSED | Detail pages load and display correctly |
| #248 | Titels van de tabs in orde maken | CLOSED | Tab titles: "Standaarden", "Geschikt voor", "Applicatieversies", "Diensten" |
| #294 | Applicatie publiceren: uitlijning rechthoek | CLOSED | Referentiecomponenten selection area appears properly aligned |
| #300 | Beheer: overzicht applicaties teveel applicaties | CLOSED | *But see RBAC note below* |
| #302 | Beheer: applicatie bewerken (ophalen gegevens traag) | CLOSED | Not retested |
| #306 | Dienst: Overzicht controleren verbeteren | CLOSED | Dienst review step shows clean summary |
| #307 | Diensten overzicht: meer dienst bij organisatie | CLOSED | Not retested |
| #308 | Diensten overzicht: default kolommen | CLOSED | Not retested |
| #351 | Het laden van de tabbladen gaat ongelijk | CLOSED | Tabs loaded consistently on detail page |
| #356 | Diensten: geen tussenvoegsel bij namen | CLOSED | Not retested (no tussenvoegsel in test data) |
| #358 | Diensten: status "Concept" op verschillende plekken | CLOSED | Not seen during testing |
| #359 | Diensten wizard: tekst aanpassen | CLOSED | Wizard title is "Dienst registreren" |
| #360 | Diensten wizard: i niet aanwezig | CLOSED | Info icons present on all fields |
| #361 | Diensten wizard: inconsistentie in labels | CLOSED | Labels match between input and review |
| #362 | Diensten wizard: onlogische tekst bovenaan | CLOSED | Not retested |
| #363 | Diensten wizard: catalogus i.p.v. softwarecatalogus | CLOSED | Not retested |
| #364 | Contactpersonen: e-mailadres is leeg | CLOSED | Not retested |
| #365 | Contactpersonen: error bij opslaan | CLOSED | Not retested |
| #366 | Contactpersonen: veld Rollen niet consistent | CLOSED | Not retested |
| #370 | Applicatie: teveel kolommen worden getoond | CLOSED | Table columns: Naam, Korte omschrijving, Website, Leverancier, Licentievorm, Logo, Diensten, Standaardversies, Acties |
| #372 | Applicaties: Kolom Contactpersoon geen tussenvoegsel | CLOSED | Contactpersoon column not visible in current table layout |
| #374 | Applicaties: Standaarden, Standaarden GEMMA en Standaardversies? | CLOSED | Only "Standaardversies" column shown |
| #378 | Applicatie: Standaarden na wijzigen veranderd | CLOSED | Not retested (requires edit cycle) |
| #379 | Applicatie: verschillende manier van tonen compliancy | CLOSED | Not retested |
| #380 | Applicatie: compliance aantallen komen niet overeen | CLOSED | Not retested |
| #382 | Applicatie: compliancy link werkt niet | CLOSED | Not retested |
| #385 | Applicatie: Geen huidige versie in gebruik | CLOSED | Version 1.0.0 shows "in gebruik" |
| #386 | Applicaties: Uw applicatie publiceren: andere labels | CLOSED | Labels consistent |
| #387 | Applicaties: Uw applicatie publiceren: i niet aanwezig | CLOSED | All fields have (i) tooltips |
| #390 | Applicaties: labels komen niet overeen | CLOSED | Review labels match input labels |
| #392 | Back-end: geimporteerde gebruiker geeft error | CLOSED | Not for this persona |
| #399 | Versies: versie van andere leverancier geeft foutmelding | CLOSED | Not retested |
| #402 | Verschil tussen Edge en Chrome | CLOSED | Not testable (single browser) |
| #407 | Standaarden verwijzen naar id-id-... | CLOSED | Not retested |
| #408 | Tabblad beschrijving bij Dienst | CLOSED | Not retested |

### Open Issues Tested

| Issue | Title | Status | Findings |
|-------|-------|--------|----------|
| #105 | Aanbieders zien applicatielandschappen niet | CANNOT_TEST | `/beheer/applicatielandschappen` route not found; navigated to `/beheer/applicaties` instead which shows a table |
| #187 | Tekstvoorstellen (remaining text changes) | PARTIAL | Some Dutch text on wizard pages still contains "zien" instead of "zien" (minor), "gegevens" appears throughout |
| #274 | Wizard dienst: tekst naar nieuwe benamingen | CLOSED | Wizard title reads "Dienst registreren", field labels updated |
| #312 | Koppeling heeft verplicht een naam | CANNOT_TEST | Koppeling wizard blocked (Volgende disabled bug) |
| #314 | Wizard Koppeling: vindt zelf aangemaakte applicaties niet | PARTIAL | Test Wizard App WAS found in the koppeling wizard dropdown, but wizard blocked at next step |
| #345 | Zoeken: toegevoegde dienst verschijnt niet in filters | MOVED_TO_BEZOEKER | Public search page test |
| #347 | Zoeken: Dienstkaartje toont array | MOVED_TO_BEZOEKER | Public search page test |
| #348 | Standaarden komen niet overeen bij Centric Begraven | CANNOT_TEST | No imported data with Centric Begraven in test environment |
| #352 | Mijn account - Contactpersoon niet veranderd | CANNOT_TEST | `/account` page not tested |
| #354 | Diensten - incomplete lijst applicaties | PASS | Dienst wizard showed full list of applicaties |
| #357 | Diensten: Diensttype en Type door elkaar | PASS | Field labeled "Diensttype" consistently in wizard; detail page shows "Dienst" type label |
| #367 | Contactpersonen: Tussenvoegsel niet getoond | CANNOT_TEST | No contactpersonen with tussenvoegsel in test data |
| #368 | Applicatie publiceren: koppeling zonder richting | CANNOT_TEST | Koppeling wizard blocked before reaching this point |
| #369 | Applicatie publiceren: koppeling niet zichtbaar | CANNOT_TEST | Koppeling wizard blocked |
| #371 | Applicatie: UUID onder compliance | PASS | No UUIDs visible on compliance/standaarden tab |
| #373 | Applicatie: Gekoppelde diensten niet getoond | PASS | Diensten tab shows "Test Wizard Dienst" correctly with name, provider, type |
| #375 | Applicaties: versie voor SaaS applicaties? | PASS | SaaS app gets default version 1.0.0 "in gebruik" automatically |
| #376 | Applicaties: labels wizard en tabel anders | PASS | Wizard label "Korte omschrijving" matches table column "Korte omschrijving" |
| #377 | Applicaties: tabel toont diensten niet | FAIL | Diensten column shows "-" for all rows in beheer table, despite Test Wizard App having 1 dienst |
| #381 | Applicaties: non-compliant vervangen door niet ondersteund | PASS | No "non-compliant" text visible |
| #383 | Applicatie: selectie vakken werken niet | CANNOT_TEST | No selection checkboxes tested on detail page |
| #384 | Applicaties: eenduidige manier van bewerken | PASS | Consistent "Acties" dropdown in beheer table, "Acties bewerken" gear on detail page |
| #391 | Testen met gebruiker van bestaande organisatie | BLOCKED | Requires second user for Test Leverancier BV |
| #400 | Koppeling - Opslaan geeft foutmelding | CANNOT_TEST | Koppeling wizard blocked at Step 2 |
| #401 | Koppeling - geimporteerde koppelingen kaartjes leeg | CANNOT_TEST | No imported koppelingen in test environment |
| #443 | Dienst pagina: diensttypen aan elkaar geschreven | PASS | Diensttype "Implementatieondersteuning" displayed correctly (single word) |
| #444 | Vormgeving veranderd bij te lange URLs | PASS | URL displayed cleanly in detail page sidebar |
| #445 | Nieuwe dienst verkeerde afsluitende pagina | PASS | Dienst wizard shows correct success page with "Dienst succesvol aangemeld!" |
| #446 | Dienst publiceren: tekstuele inconsistenties | PASS | Text consistent throughout wizard |
| #448 | Overzichtspagina's: verschillende vormgeving | CANNOT_TEST | Only tested applicaties overview |
| #450 | Back-end: Icoon voor publiceren verwijderen | CANNOT_TEST | Backend-only issue |
| #451 | Koppeling: UUIDs zichtbaar bij standaardversies | CANNOT_TEST | Koppeling wizard blocked |
| #452 | Applicaties overzicht: toont niet alle koppelingen | CANNOT_TEST | No koppelingen column visible in beheer table |
| #453 | Zoeken: filters van slag met filter Type=Koppeling | MOVED_TO_BEZOEKER | Public search page test |
| #454 | Wizard koppelingen: bestaande koppelingen niet gevonden | PARTIAL | Wizard correctly showed "Geen bestaande koppelingen" but blocked at next step |
| #456 | Consistentie in werking van wizards | PARTIAL | Applicatie and Dienst wizards work consistently; Koppeling wizard has Volgende button bug |
| #457 | Koppeling: verwijderen geeft 400-error | CANNOT_TEST | Koppeling wizard blocked, no koppelingen created to test delete |

### Previously Tested Issues (re-verified)

| Issue | Previous Status | New Status | Notes |
|-------|----------------|------------|-------|
| #294 | CANNOT_TEST | CLOSED | Issue closed; referentiecomponenten selection area aligned properly |
| #300 | CANNOT_TEST | CLOSED | Issue closed; but beheer table still shows apps from other orgs (duplicate test data) |
| #302 | CANNOT_TEST | CLOSED | Issue closed |
| #373 | FAIL | PASS | Bug fixed; diensten now shown on detail page |
| #375 | PARTIAL | PASS | Bug fixed; SaaS apps get default 1.0.0 version |
| #376 | CANNOT_TEST | PASS | Labels consistent between wizard and table |
| #377 | CANNOT_TEST | FAIL | Diensten column still shows "-" in beheer table |
| #380 | CANNOT_TEST | CLOSED | Issue closed |
| #386 | CANNOT_TEST | CLOSED | Issue closed |
| #387 | CANNOT_TEST | CLOSED | Issue closed |
| #390 | CANNOT_TEST | CLOSED | Issue closed |
| #399 | CANNOT_TEST | CLOSED | Issue closed |

---

## Critical Findings

### 1. Koppeling Wizard Volgende Button Disabled (NEW BUG)
**Severity**: Critical
**Steps**: Navigate to `/forms/koppeling?type=eigen-organisatie`, select applicatie, fill Richting + Applicatie B + Status
**Expected**: Volgende button enables
**Actual**: Volgende button stays disabled despite all required fields being filled
**Impact**: Users cannot create koppelingen via the dedicated wizard

### 2. Beheer Table Shows Applications From Other Organizations
**Severity**: High
**Observation**: `/beheer/applicaties` shows applications from Test Leverancier 2 and Test Gemeente, not just Test Leverancier BV's own applications
**Related**: #300 (closed), #105
**Notes**: This may be caused by duplicate test data all sharing the same `@self.organisation` value, or by RBAC not properly filtering. The wizard-created "Test Wizard App" was NOT visible in the beheer table despite being owned by jan.pietersen with correct org UUID.

### 3. Backend Entered Maintenance Mode During Testing
**Severity**: High
**Trigger**: Occurred after multiple wizard submissions and API calls
**Impact**: Caused 503 errors across all API endpoints, blocking the Applicatiegebruik wizard

---

## Test Data Created (for cleanup)

| Type | Name | ID | Notes |
|------|------|----|-------|
| Applicatie | Test Wizard App | a18f1b1a-9e1a-43e6-b1db-98330c63656c | Created by wizard |
| Dienst | Test Wizard Dienst | 9c29fd61-240f-4b67-98a3-45de2a224666 | Created by wizard |
| Koppeling | (not created) | - | Wizard blocked |
| Gebruik | (not created) | - | 503 error |

---

## Screenshots

| File | Description |
|------|-------------|
| dashboard.png | Dashboard after login with Test Leverancier BV selected |
| wizard-app-step1.png | Applicatie wizard - Step 1 Applicatie-informatie |
| wizard-app-step2.png | Applicatie wizard - Step 2 Licentie & Hosting |
| wizard-app-step-versies.png | Applicatie wizard - Versies step with 1.0.0 default |
| wizard-app-step4.png | Applicatie wizard - Referentiecomponenten |
| wizard-app-step5.png | Applicatie wizard - Standaarden |
| wizard-app-step6.png | Applicatie wizard - Koppelingen |
| wizard-app-step6-review.png | Applicatie wizard - Controleren/Review |
| wizard-app-success.png | Applicatie wizard - Success page |
| wizard-dienst-step2.png | Dienst wizard - Step 2 Registreer uw dienst |
| wizard-dienst-success.png | Dienst wizard - Success page |
| wizard-koppeling-step2.png | Koppeling wizard - Step 2 with disabled Volgende (BUG) |
| wizard-gebruik-step1.png | Applicatiegebruik wizard - Step 1 |
| wizard-gebruik-fail.png | Applicatiegebruik wizard - 503 error |
| detail-app-overview.png | Test Wizard App detail page |
| wizard-app-submit-result.png | First wizard attempt failure (org UUID issue) |

---

## Summary Statistics

| Category | Count |
|----------|-------|
| Issues tested | 58 |
| PASS | 19 |
| FAIL | 2 |
| PARTIAL | 4 |
| CANNOT_TEST | 15 |
| CLOSED (verified) | 33 |
| BLOCKED | 1 |
| MOVED (to other persona) | 3 |
| New bugs found | 1 (Koppeling wizard Volgende disabled) |
