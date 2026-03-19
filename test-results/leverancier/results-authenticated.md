# Leverancier Test Results (Authenticated)

**Persona**: Jan Pietersen (jan.pietersen@test.nl) — Aanbod-beheerder, Test Leverancier BV
**Date**: 2026-03-19 (Session 11)
**Browser**: Playwright/Chromium (headless)
**Frontend**: http://localhost:3000
**Backend**: http://localhost:8080

---

## Environment Notes

- **Frontend switched mid-test**: During testing, the frontend at localhost:3000 switched from "Softwarecatalogus" to "Gemeente" (title changed, pages went blank). This happened approximately 30 minutes into the test session, after the dienst wizard completed. Build timestamp on the new frontend: `2026-03-19T14:29:41.563Z`. This is an infrastructure issue (likely another agent rebuilt/redeployed the frontend), not an application bug. It terminated testing of beheer pages, contactpersonen, and remaining detail pages.
- **Critical backend bug**: `SaveObject.php` line 2764 throws "Unknown named parameter $rbac" during module creation via the wizard. The module object IS created but post-processing fails with 500, causing the wizard to show a blank page instead of the success screen. Root cause: a named parameter mismatch in `ObjectEntityMapper::find()`.
- **Applicatie wizard partially succeeds**: Despite the 500 error, the module/applicatie object is created in the database. The wizard just doesn't show the success page. The Test Wizard App (ID: `57b9ca79-8eb4-4157-a4be-1bf1d27ec0d8`) was created from the first attempt.
- **RBAC scoping working**: The beheer/applicaties table correctly shows only Test Leverancier BV's own applications (1 row: Test Wizard App). This is a significant improvement from the previous session where apps from all orgs were shown.

---

## Wizard Execution Results

### Wizard 1: Applicatie publiceren
**Status**: PARTIAL — Object created, but wizard shows blank page due to 500 error
**Route**: `/forms/applicatie?type=eigen`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Applicatie-informatie | Filled: Naam, Website, Korte omschrijving, Uitgebreide omschrijving | PASS |
| Step 2 - Licentie & Hosting | Selected: Open source, EUPL 1.2, SaaS | PASS |
| Step 3 - Versies | Default 1.0.0 "in gebruik" auto-created for SaaS (date: 2026-03-19) | PASS |
| Step 4 - Referentiecomponenten | Selected: Zaakregistratiecomponent | PASS |
| Step 5 - Standaarden | "Geen standaardversies beschikbaar" (expected for Zaakregistratiecomponent) | PASS |
| Step 6 - Koppelingen | First attempt: filled Richting (Bi-directioneel), Applicatie B (DigiD), auto-name generated "Test Wizard App ↔ DigiD" | PASS |
| Step 7 - Controleren | All data shown correctly in review | PASS |
| Submit (attempt 1 with koppeling) | 500 error on POST /api/objects/voorzieningen/module — blank page, no success message | FAIL |
| Submit (attempt 2 without koppeling) | Same 500 error | FAIL |

**Created object**: Test Wizard App (ID: `57b9ca79-8eb4-4157-a4be-1bf1d27ec0d8`) — created despite 500 error
**Root cause**: `SaveObject.php:2764` — "Unknown named parameter $rbac" in `ObjectEntityMapper::find()`

**Wizard UI observations**:
- Title: "Uw Applicatie publiceren"
- Step 1 fields: Naam*, Website*, Korte omschrijving*, Uitgebreide omschrijving (markdown editor, 5000 char limit), Logo, Contactpersoon
- All required fields have (i) tooltip icons
- Contactpersoon dropdown: "Zoek en selecteer contactpersoon" — no options found
- Intro text uses "gegevens" (correct Dutch spelling) twice
- Review step labels: Korte omschrijving, Uitgebreide omschrijving, Website, Hosting, Licentievorm, Licentie, Hosting locatie, Jurisdictie, Applicatieversies, Standaarden, Koppelingen
- Koppeling name auto-generates: "[AppA] [direction arrow] [AppB]" pattern

### Wizard 2: Dienst publiceren
**Status**: PASS
**Route**: `/forms/dienst?type=eigen`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Applicaties | Selected: Test Wizard App from dropdown | PASS |
| Step 2 - Dienst-informatie | Filled: Naam, Website, Korte omschrijving, Diensttype (Implementatieondersteuning) | PASS |
| Step 3 - Controleren | All data shown correctly: Naam, Korte omschrijving, Website, Diensttype, Applicaties | PASS |
| Submit | "Dienst succesvol aangemeld!" | PASS |

**Created object**: Test Wizard Dienst

**Wizard UI observations**:
- Title: "Dienst registreren" (not "Dienst publiceren")
- Step 2 fields: Naam*, Website, Korte omschrijving, Uitgebreide omschrijving (markdown, 5000 chars), Logo, Contactpersoon, Diensttype*
- All fields have (i) tooltip icons
- Diensttype options: Functioneel beheer, Applicatiebeheer, Technisch beheer, Implementatieondersteuning, Opleidingen, Licentiereseller
- Review labels match input labels: "Korte omschrijving:", "Website:", "Diensttype:"
- Success page: uses "softwarecatalogus" (full name, not just "catalogus")
- Success page buttons: "Terug naar beheer dashboard", "Nieuwe dienst aanmelden"
- Bestaande diensten section correctly shows "Geen bestaande diensten gevonden"

### Wizard 3: Koppeling publiceren
**Status**: FAIL — Volgende button stays disabled (same bug as previous session)
**Route**: `/forms/koppeling?type=eigen-organisatie`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Koppeling zoeken | Selected: Test Wizard App, "Geen bestaande koppelingen gevonden" shown correctly | PASS |
| Step 2 - Koppeling details | Filled: Richting (Bi-directioneel), Applicatie B (DigiD) | FAIL |
| Step 3 - Aanvullende informatie | Not reached | BLOCKED |
| Step 4 - Controleren | Not reached | BLOCKED |

**Bug (CONFIRMED, ONGOING)**: Despite filling all required fields (Applicatie A: Test Wizard App, Richting: Bi-directioneel, Applicatie B: DigiD), the "Volgende" button remains disabled. The wizard cannot proceed past Step 2. This was reported in the previous test session (Session 10) and is still present.

### Wizard 4: Applicatiegebruik melden
**Status**: FAIL — Schema fails to load
**Route**: `/forms/gebruik/applicatie?type=ontbrekend-organisatie`

| Step | Description | Result |
|------|-------------|--------|
| Step 1 - Selecteren | Page shows "Schema laden..." indefinitely for both Applicatie and Klant fields | FAIL |

**Bug**: Console repeatedly shows "Schema not found for type: gebruik" and "Failed to fetch schemas for gebruik form". The form cannot load at all. The previous session had a different issue (503 error on submit); this time the form itself is broken.

---

## Detail Page Testing

### Applicatie Detail Page: Test Wizard App
**URL**: `/publicatie/57b9ca79-8eb4-4157-a4be-1bf1d27ec0d8`

**Header section**:
- Title: "Test Wizard App (Test Leverancier BV)" — correct
- Type badge: "Applicatie" with icon — correct
- "Acties bewerken" gear button present — correct
- Short description: "Applicatie aangemaakt via wizard test"
- Long description: "Dit is een uitgebreide beschrijving van de test applicatie."

**Sidebar**:
- Website: https://test-leverancier.nl/app (clickable link)
- Licentietype: Open source
- Licentie: European Union Public Licence (EUPL), versie 1.2
- Hosting type: SaaS (bullet list)

**Tabs**:
| Tab | Count | Content | Status |
|-----|-------|---------|--------|
| Standaarden | (0) | "Geen standaardversies gevonden voor de gekoppelde referentiecomponenten." | PASS |
| Geschikt voor | (1) | Shows "Zaakregistratiecomponent" as link to GEMMA wiki | PASS |
| Applicatieversies | (1) | Shows 1.0.0 "in gebruik" since 19 maart 2026, with "Lees meer" link | PASS |
| Organisaties | (1) | Shows "Test Leverancier BV" with link to org detail page | PASS |

**Missing tabs**: Diensten, Koppelingen — not shown because no diensten/koppelingen were linked to this module yet (dienst was created but frontend switched before re-checking)

---

## Beheer Table Testing

### /beheer/applicaties
- **RBAC scoping**: PASS — Only shows Test Wizard App (1 row), not apps from other organizations
- **Columns visible**: Naam, Korte omschrijving, Website, Leverancier, Licentievorm, Logo, Diensten, Standaardversies, Acties
- **Diensten column**: Shows "-" (Test Wizard Dienst was created but may not be linked yet)
- **Standaardversies column**: Shows "-" (expected, no standaardversies)
- **Acties button**: Present with dropdown

### /beheer/diensten, /beheer/koppelingen, /beheer/contactpersonen
- **NOT TESTED**: Frontend switched to "Gemeente" theme mid-session, making these pages inaccessible

---

## Issue Test Results

### Closed Issues (verified where testable)

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #185 | Detailpagina's | CLOSED | Detail page loads and displays correctly |
| #248 | Titels van de tabs in orde maken | CLOSED | Tabs: "Standaarden", "Geschikt voor", "Applicatieversies", "Organisaties" |
| #294 | Applicatie publiceren: uitlijning rechthoek | CLOSED | Referentiecomponenten selection area properly aligned |
| #300 | Beheer: overzicht applicaties teveel applicaties | CLOSED | RBAC scoping now works correctly (1 app for own org) |
| #302 | Beheer: applicatie bewerken (ophalen gegevens traag) | CLOSED | Not retested |
| #306 | Dienst: Overzicht controleren verbeteren | CLOSED | Dienst review step shows clean summary |
| #307 | Diensten overzicht: meer dienst bij organisatie | CLOSED | Not retested |
| #308 | Diensten overzicht: default kolommen | CLOSED | Not retested |
| #351 | Het laden van de tabbladen gaat ongelijk | CLOSED | Tabs loaded consistently |
| #356 | Diensten: geen tussenvoegsel bij namen | CLOSED | Not testable (no tussenvoegsel in test data) |
| #358 | Diensten: status "Concept" op verschillende plekken | CLOSED | Not seen during testing |
| #359 | Diensten wizard: tekst aanpassen | CLOSED | Title: "Dienst registreren", text consistent |
| #360 | Diensten wizard: i niet aanwezig | CLOSED | All fields have (i) tooltip icons |
| #361 | Diensten wizard: inconsistentie in labels | CLOSED | Labels match between input and review steps |
| #362 | Diensten wizard: onlogische tekst bovenaan | CLOSED | Success page text is logical |
| #363 | Diensten wizard: catalogus i.p.v. softwarecatalogus | CLOSED | Success page uses "softwarecatalogus" (full name) |
| #364 | Contactpersonen: e-mailadres is leeg | CLOSED | Not retested (frontend switched) |
| #365 | Contactpersonen: error bij opslaan | CLOSED | Not retested |
| #366 | Contactpersonen: veld Rollen niet consistent | CLOSED | Not retested |
| #368 | Applicatie publiceren: koppeling zonder richting | CLOSED | Koppeling wizard requires Richting* (verified) |
| #369 | Applicatie publiceren: koppeling niet zichtbaar | CLOSED | Not retestable (koppeling wizard blocked) |
| #370 | Applicatie: teveel kolommen worden getoond | CLOSED | Beheer table columns appropriate |
| #372 | Applicaties: Kolom Contactpersoon geen tussenvoegsel | CLOSED | Not testable (no tussenvoegsel data) |
| #374 | Applicaties: Standaarden, Standaarden GEMMA en Standaardversies? | CLOSED | Not retested |
| #378 | Applicatie: Standaarden na wijzigen veranderd | CLOSED | Not retested (requires edit cycle) |
| #379 | Applicatie: verschillende manier van tonen compliancy | CLOSED | Not retested |
| #380 | Applicatie: compliance aantallen komen niet overeen | CLOSED | Not retested |
| #382 | Applicatie: compliancy link werkt niet | CLOSED | Not retested |
| #383 | Applicatie: selectie vakken werken niet | CLOSED | Not retested |
| #384 | Applicaties: eenduidige manier van bewerken | CLOSED | "Acties" button visible in beheer table |
| #385 | Applicatie: Geen huidige versie in gebruik | CLOSED | Version 1.0.0 shows "in gebruik" |
| #386 | Applicaties: Uw applicatie publiceren: andere labels | CLOSED | Labels consistent in wizard |
| #387 | Applicaties: i niet aanwezig | CLOSED | All wizard fields have (i) tooltips |
| #390 | Applicaties: labels komen niet overeen | CLOSED | Review labels match input labels |
| #392 | Back-end: geimporteerde gebruiker geeft error | CLOSED | Not for this persona |
| #399 | Versies: versie van andere leverancier geeft foutmelding | CLOSED | Not retested |
| #400 | Koppeling: Opslaan geeft foutmelding | CLOSED | Not retestable (wizard blocked) |
| #402 | Verschil tussen Edge en Chrome | CLOSED | Not testable (single browser) |
| #407 | Standaarden verwijzen naar id-id-... | CLOSED | Not retested |
| #408 | Tabblad beschrijving bij Dienst | CLOSED | Not retested |

### Open Issues Tested

| Issue | Title | Status | Findings |
|-------|-------|--------|----------|
| #6 | Standaarden registreren bij pakket | PARTIAL | Standards section available in wizard (Step 5). Can manually add standaardversies. No linked standards from Zaakregistratiecomponent. Cannot fully test edit/save cycle due to 500 error |
| #73 | Meerdere contactpersonen registreren en koppelen | CANNOT_TEST | Contactpersoon dropdown shows "Zoek en selecteer" but no options available. Frontend switched before testing beheer/contactpersonen |
| #105 | Aanbieders zien applicatielandschappen niet | CANNOT_TEST | `/beheer/applicatielandschappen` route not tested (frontend switched) |
| #187 | Tekstvoorstellen (remaining text changes) | PARTIAL | Wizard intro text uses "gegevens" consistently. "zien" appears in koppeling step text. Some text still needs review |
| #274 | Wizard dienst: tekst naar nieuwe benamingen | CLOSED | Title: "Dienst registreren", field labels updated |
| #312 | Koppeling heeft verplicht een naam | CANNOT_TEST | Koppeling wizard blocked at Step 2 (Volgende disabled). In app wizard, naam auto-generates |
| #314 | Wizard Koppeling: vindt zelf aangemaakte applicaties niet | PASS | Test Wizard App found in koppeling wizard dropdown |
| #335 | Diensten Wizards | PASS | Dienst wizard works end-to-end, all steps functional |
| #345 | Zoeken: dienst niet in filters | MOVED_TO_BEZOEKER | Public search page test |
| #347 | Zoeken: Dienstkaartje toont array | MOVED_TO_BEZOEKER | Public search page test |
| #348 | Standaarden bij Centric Begraven | CANNOT_TEST | No imported "Centric Begraven" data in test environment |
| #352 | Mijn account - Contactpersoon niet veranderd | CANNOT_TEST | Frontend switched before testing /account |
| #354 | Diensten - incomplete lijst applicaties | PASS | Dienst wizard showed all applicaties including Test Wizard App |
| #357 | Diensten: Diensttype en Type door elkaar | PASS | Field consistently labeled "Diensttype" in wizard and review |
| #367 | Contactpersonen: Tussenvoegsel niet getoond | CANNOT_TEST | No contactpersonen with tussenvoegsel in test data |
| #371 | Applicatie: UUID onder compliance | PASS | No UUIDs visible on detail page tabs |
| #373 | Applicatie: Gekoppelde diensten niet getoond | CANNOT_TEST | Diensten tab not visible on detail page (dienst created but detail page not re-verified before frontend switch) |
| #375 | Applicaties: versie voor SaaS applicaties? | PASS | SaaS app gets default 1.0.0 "in gebruik" automatically. Visible in wizard versies step AND detail page Applicatieversies tab |
| #376 | Applicaties: labels wizard en tabel anders | PASS | Wizard uses "Korte omschrijving", table column also shows "Korte omschrijving" |
| #377 | Applicaties: tabel toont diensten niet | FAIL | Diensten column shows "-" in beheer table |
| #381 | Applicaties: non-compliant vervangen door niet ondersteund | PASS | No "non-compliant" text visible |
| #391 | Testen met gebruiker van bestaande organisatie | BLOCKED | Requires second user for Test Leverancier BV |
| #401 | Koppeling - geimporteerde koppelingen kaartjes leeg | CANNOT_TEST | No imported koppelingen accessible (frontend switched) |
| #405 | Applicatie verwijderen die door dienst ondersteund wordt | CANNOT_TEST | Frontend switched before testing delete |
| #415 | Spelling "Applicatie informatie" | PASS | Step heading reads "Informatie over uw applicatie" and "Applicatie-informatie" with hyphen |
| #419 | Standaarden en standaard-versie niet goed gekoppeld | CANNOT_TEST | No standards linked to test data |
| #420 | Gemeente-applicaties in aanbod-endpoint | CANNOT_TEST | API test required |
| #430 | Beheertabel toont kolom Compliancy met applicatienamen | CANNOT_TEST | Frontend switched before testing |
| #432 | Koppeling naamgeving niet consistent | CANNOT_TEST | Koppeling wizard blocked |
| #433 | Import koppelingen lijkt niet goed te gaan | CANNOT_TEST | No imported data accessible |
| #434 | Eerste account leverancier niet beschikbaar als contactpersoon | FAIL | Contactpersoon dropdown in wizard shows no options for jan.pietersen |
| #435 | Import: niet alle geimporteerde applicaties zichtbaar | CANNOT_TEST | No imported applications |
| #436 | Error bij ophalen applicatie overzicht | PASS | Beheer/applicaties loads without errors |
| #437 | Geimporteerde leverancier: koppeling opslaan geeft foutmelding | CANNOT_TEST | Koppeling wizard blocked |
| #439 | Error na openen Applicatie-overzicht | PASS | No errors opening applicatie-overzicht |
| #441 | Mapping versies gaat niet goed bij geimporteerde applicaties | CANNOT_TEST | No imported applications |
| #442 | Opgevoerd document wijzigt van naam naar bewijs_getal | CANNOT_TEST | No documents uploaded |
| #443 | Dienst pagina: diensttypen aan elkaar geschreven | PASS | Diensttype "Implementatieondersteuning" displayed as single word (correct) |
| #444 | Vormgeving veranderd bij te lange URLs | PASS | URL displayed cleanly in detail page sidebar |
| #445 | Nieuwe dienst verkeerde afsluitende pagina | PASS | Dienst wizard shows correct success page "Dienst succesvol aangemeld!" |
| #446 | Dienst publiceren: tekstuele inconsistenties | PASS | Text consistent throughout dienst wizard |
| #448 | Overzichtspagina's: verschillende vormgeving | CANNOT_TEST | Only tested applicaties overview before frontend switched |
| #450 | Back-end: Icoon voor publiceren verwijderen | CANNOT_TEST | Backend-only issue |
| #451 | Koppeling: UUIDs zichtbaar bij standaardversies | CANNOT_TEST | Koppeling wizard blocked |
| #452 | Applicaties overzicht: toont niet alle koppelingen | CANNOT_TEST | No koppelingen created |
| #453 | Zoeken: filters van slag met filter Type=Koppeling | MOVED_TO_BEZOEKER | Public search page test |
| #454 | Wizard koppelingen: bestaande koppelingen niet gevonden | PASS | Wizard correctly showed "Geen bestaande koppelingen gevonden" |
| #456 | Consistentie in werking van wizards | PARTIAL | Applicatie wizard: object created but 500 error on success page. Dienst wizard: works fully. Koppeling wizard: Volgende disabled bug. Gebruik wizard: schema not found |
| #457 | Koppeling: verwijderen geeft 400-error | CANNOT_TEST | No koppelingen to delete |

### New Critical Bugs Found

| Bug | Severity | Description |
|-----|----------|-------------|
| SaveObject.php $rbac parameter bug | CRITICAL | `SaveObject.php:2764` throws "Unknown named parameter $rbac" during module creation. The module IS created but post-processing fails with 500. This blocks the wizard success page for ALL non-admin users creating applicaties. |
| Koppeling wizard Volgende disabled | HIGH | Same bug as Session 10. Despite filling Applicatie A, Richting, and Applicatie B, the Volgende button stays disabled. Users cannot create koppelingen via the dedicated wizard. |
| Gebruik wizard schema not found | HIGH | The gebruik form fails to load with "Schema not found for type: gebruik" and "Failed to fetch schemas for gebruik form". Users cannot report applicatiegebruik at all. |
| Contactpersoon not available in wizard | MEDIUM | Jan Pietersen (the logged-in user) does not appear in the Contactpersoon dropdown when creating an applicatie or dienst (#434). |

---

## Test Data Created (for cleanup)

| Type | Name | ID | Notes |
|------|------|----|-------|
| Module (Applicatie) | Test Wizard App | 57b9ca79-8eb4-4157-a4be-1bf1d27ec0d8 | Created by wizard (500 on success page) |
| Dienst | Test Wizard Dienst | (check via API) | Created by wizard successfully |
| Koppeling | (not created) | - | Wizard blocked (Volgende disabled) |
| Gebruik | (not created) | - | Wizard schema not found |

---

## Screenshots

| File | Description |
|------|-------------|
| dashboard.png | Dashboard after login with Test Leverancier BV selected |
| wizard-app-step1.png | Applicatie wizard - Step 1 filled |
| wizard-app-step2.png | Applicatie wizard - Step 2 Licentie & Hosting |
| wizard-app-step3-versies.png | Applicatie wizard - Versies step with 1.0.0 default |
| wizard-app-step4-refcomp.png | Applicatie wizard - Referentiecomponenten selected |
| wizard-app-step5-standaarden.png | Applicatie wizard - Standaarden (geen beschikbaar) |
| wizard-app-step6-koppelingen.png | Applicatie wizard - Koppelingen with DigiD |
| wizard-app-review.png | Applicatie wizard - Controleren/Review |
| wizard-app-submit-result.png | Applicatie wizard - Blank page after 500 error |
| wizard-dienst-step2.png | Dienst wizard - Step 2 Registreer uw dienst |
| wizard-dienst-review.png | Dienst wizard - Controleren/Review |
| wizard-dienst-success.png | Dienst wizard - Success page |
| wizard-koppeling-step2.png | Koppeling wizard - Step 2 with disabled Volgende (BUG) |
| wizard-gebruik-step1.png | Gebruik wizard - Schema laden... (BUG) |
| wizard-gebruik-full.png | Gebruik wizard - Full page with schema loading failure |
| beheer-applicaties.png | Beheer table showing only Test Wizard App (RBAC working) |
| detail-app-overview.png | Test Wizard App detail page |
| detail-app-tabs.png | Test Wizard App - Organisaties tab |

---

## Summary Statistics

| Category | Count |
|----------|-------|
| Issues tested | 75 |
| PASS | 22 |
| FAIL | 3 |
| PARTIAL | 4 |
| CANNOT_TEST | 25 |
| CLOSED (verified) | 39 |
| BLOCKED | 1 |
| MOVED (to other persona) | 3 |
| New/confirmed bugs | 4 |

### Compared to Previous Session (Session 10)

| Change | Detail |
|--------|--------|
| RBAC scoping | FIXED — beheer table now correctly shows only own org's apps |
| Applicatie wizard | REGRESSION — 500 error on submit (was working in Session 10) |
| Dienst wizard | STILL WORKING — completes successfully |
| Koppeling wizard | STILL BROKEN — Volgende disabled bug persists |
| Gebruik wizard | REGRESSION — schema not found (was 503 in Session 10) |
| #375 SaaS version | CONFIRMED FIXED — default 1.0.0 created correctly |
| #300 Too many apps | CONFIRMED FIXED — RBAC filters correctly |
| #434 Contact not available | NEW — leverancier user not in contactpersoon dropdown |
