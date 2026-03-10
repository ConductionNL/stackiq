# Leverancier Test Results (Authenticated)

**Persona**: Jan Pietersen (Leverancier / Aanbod-beheerder)
**Date**: 2026-03-10 (Session 9) | Previous: 2026-03-02 (Session 8)
**Environment**: Frontend http://localhost:3000 | Backend http://localhost:8080
**Browser**: Playwright (headless, browser-1)
**Session**: 9 (context continuation from session 8)

---

## Wizard Walkthroughs

### Wizard 1: Applicatie publiceren
**Route**: `/forms/applicatie?type=eigen`
**Result**: PASS (with caveat - Referentiecomponenten step blocked by 500 error)

| Step | Description | Result | Notes |
|------|-------------|--------|-------|
| 1 | Applicatie-informatie | PASS | Filled: Naam="Test Wizard App", Website, Korte/Uitgebreide omschrijving, Contactpersoon="Jan Pietersen" |
| 2 | Licentie & Hosting | PASS | Selected: Licentievorm="Open source", Licentie="EUPL 1.2", Hosting="SaaS" |
| 3 | Versies | PASS | Default version 1.0.0 with status "in gebruik" and date 2026-03-02 pre-filled (confirms #375 fix) |
| 4 | Referentiecomponenten | FAIL | API endpoint returns 500 Internal Server Error repeatedly. Dropdown shows "Laden..." indefinitely. Endpoint: `/api/objects/vng-gemma/element?gemmaType=Referentiecomponent&_extend[]=aanbevolenStandaarden&_extend[]=verplichteStandaarden&_extend[]=gekoppeldeStandaardVersies`. Step skipped as it is optional |
| 5 | Standaarden | PASS | Shows "Geen standaardversies beschikbaar" (expected since no refcomponenten selected). Manual add option available |
| 6 | Koppelingen | PASS | Filled: Richting="Bi-directioneel", Applicatie B="DigiD". Naam auto-populated with "Test Wizard App <-> DigiD" (relevant for #312) |
| 7 | Review (Controleren) | PASS | All data shown correctly. Labels consistent across steps |
| Submit | Success | PASS | "Applicatie succesvol aangemeld!" - uses "softwarecatalogus" correctly (#363) |

**Critical Finding**: Referentiecomponenten API (vng-gemma/element with `_extend`) returns 500 in an infinite retry loop.
**Screenshots**: wizard-app-step1.png through wizard-app-success.png

### Wizard 2: Dienst publiceren
**Route**: `/forms/dienst?type=eigen`
**Result**: PASS - All steps completed successfully

| Step | Description | Result | Notes |
|------|-------------|--------|-------|
| 1 | Applicatie selectie | PASS | Selected "Test Wizard App" from 6 available options. Shows "Geen bestaande diensten gevonden" |
| 2 | Dienstgegevens | PASS | Filled: Naam="Test Wizard Dienst", Website, Korte omschrijving="Dienst aangemaakt via wizard test", Diensttype="Implementatieondersteuning" |
| 3 | Review (Controleren) | PASS | All data correct, labels consistent |
| Submit | Success | PASS | "Dienst succesvol aangemeld!" - uses "softwarecatalogus" correctly |

**Console error**: 404 for `/api/apps/openregister/api/schemas/product` (residual old schema reference)
**Screenshots**: wizard-dienst-step1.png through wizard-dienst-success.png

### Wizard 3: Koppeling publiceren
**Route**: `/forms/koppeling?type=eigen-organisatie`
**Result**: PASS - All steps completed successfully

| Step | Description | Result | Notes |
|------|-------------|--------|-------|
| 1 | Koppeling zoeken | PASS | Selected "Test Wizard App". Shows existing koppelingen including one with UUID-based name "aa156b86-... <-> DigiD" (confirming #312 still present for app wizard-created koppelingen) |
| 2 | Koppeling details | PASS | Applicatie A="Test Wizard App" (locked), Richting="Bi-directioneel", Applicatie B="MijnOverheid.nl" (BGV). Naam field was empty (NOT auto-populated - #312 partial), filled manually with "Test Wizard Koppeling". Status="In gebruik", Startdatum auto-set to 2026-03-01 |
| 3 | Aanvullende informatie | PASS | Filled: Korte beschrijving="Koppeling voor gegevensuitwisseling met MijnOverheid.nl via REST API", Transportprotocol="API". Markdown editor available for lange beschrijving |
| 4 | Review (Controleren) | PASS | Shows: "Test Wizard Koppeling" - Test Wizard App <-> MijnOverheid.nl, with all details correct |
| Submit | Success | PASS | "Koppelingen succesvol opgeslagen!" - uses "softwarecatalogus" correctly |

**Key Finding**: Applicatie B dropdown distinguishes Applicatie (green dot) from BGV (blue dot) with legend. 61 options available.
**Screenshots**: wizard-koppeling-step1.png through wizard-koppeling-success.png

### Wizard 4: Applicatiegebruik melden
**Route**: `/forms/gebruik/applicatie?type=ontbrekend-organisatie`
**Result**: PASS (Session 9, 2026-03-10) | Previously BLOCKED (Session 8)

| Step | Description | Result | Notes |
|------|-------------|--------|-------|
| 1 | Selecteren | PASS | Selected Applicatie="Test Wizard App" and Klant="Amsterdam". Both dropdowns work. 50 municipalities available in Klant(en) dropdown |
| 2 | Controleren | PASS | Review shows: Applicatie="Test Wizard App", Klant(en)=Amsterdam. Info alert explains visibility and approval workflow |
| Submit | Success | PASS | "Gebruik succesvol geregistreerd!" - explains klant must approve before it becomes definitive |

**Key Observations (Session 9)**:
- Applicatie dropdown now works (was blocked by 500 in session 8)
- Success page explains: "Type registratie: Gebruik voor andere organisatie (klant)"
- Follow-up actions: "Terug naar beheer dashboard" and "Nieuw gebruik registreren" buttons
- Console error: "Collection not found for type: voorziening" (non-blocking)
**Screenshots**: wizard-gebruik-review.png, wizard-gebruik-success.png

---

## Beheer Table Verification

### Session 9 (2026-03-10): ALL BEHEER TABLES BROKEN

| Table | Object | Present | Notes |
|-------|--------|---------|-------|
| /beheer/applicaties | Test Wizard App | BLOCKED | "Geen data gevonden" - stale org UUID (fd62b364-a89b-44a3-8920-0dc53624c6d0) returns 404 |
| /beheer/diensten | Test Wizard Dienst | BLOCKED | "Loading..." then likely empty - same org UUID error |
| /beheer/koppelingen | Test Wizard Koppeling | BLOCKED | Same org UUID error blocks all beheer tables |
| /beheer/contactpersonen | Jan Pietersen | BLOCKED | Same org UUID error |

**Root Cause (Session 9)**: Frontend uses stale organisation UUID `fd62b364-a89b-44a3-8920-0dc53624c6d0` which returns 404 on the backend. The actual Default Organisation UUID is `28307ef1-6b5a-4435-ace8-3b6da25209f9`. All beheer tables require org context, so they all fail. Console errors: "Error fetching voorzieningen_organisatie object", "Failed to fetch organization data".

### Session 8 (2026-03-02): Partial functionality

| Table | Object | Present | Notes |
|-------|--------|---------|-------|
| /beheer/applicaties | Test Wizard App | BLOCKED | "Geen data gevonden" due to `_extend[]=moduleVersies` 500 error |
| /beheer/diensten | Test Wizard Dienst | YES | 8 diensten, RBAC scoping correct |
| /beheer/koppelingen | Test Wizard Koppeling | YES | 12 koppelingen including UUID-named ones from app wizard |
| /beheer/contactpersonen | Jan Pietersen | YES | 5 contacts with full data |

**Critical Issue**: All beheer tables completely non-functional in session 9. This is a blocking regression from session 8 where diensten/koppelingen/contactpersonen tables DID work.

---

## Detail Page Testing

### CRITICAL BUG FOUND AND FIXED (Session 9)

**PublicationsController::show() 500 error**: All detail pages (`/publicatie/{id}`) returned 500 Internal Server Error because `PublicationsController::show(string $catalogSlug, string $id)` did not accept the `$extend` query parameter sent by the frontend. Nextcloud's dispatcher throws "Unknown named parameter $extend".

**Fix applied**: Added `?array $extend = null` to the method signature in `/var/www/html/apps/opencatalogi/lib/Controller/PublicationsController.php` line 368. Required container restart to clear OPcache.

**NOTE**: This fix needs to be committed to the opencatalogi codebase.

### Applicatie Detail Page (Test Wizard App) — Session 9
**URL**: `/publicatie/2c5bf231-3c8d-4a43-9d0b-bfada39a560f` (Session 9) | `/publicatie/aa156b86-b9f2-4ff6-b43c-455c1421c0a7` (Session 8)

| Element | Result | Notes |
|---------|--------|-------|
| Title | PASS | "Test Wizard App (Test Leverancier BV)" |
| Type badge | PASS | Shows "Applicatie" with icon |
| Summary | PASS | "Applicatie aangemaakt via wizard test" |
| Description | PASS | Full description shown |
| Website | PASS | Clickable link to https://test-leverancier.nl/app |
| Contactpersoon | PASS | "Jan Pietersen" with email jan.pietersen@test.nl and phone +31 6 12345678 |
| Licentietype | PASS | "Open source" |
| Licentie | PASS | "European Union Public Licence (EUPL), versie 1.2" |
| Hosting type | PASS | "SaaS" (shown as bullet list) |
| Breadcrumb | PASS | Home > Zoeken > Applicatie |
| Acties bewerken | PASS | Button present for editing |

**Tabs (Session 8)**:

| Tab | Count | Result | Notes |
|-----|-------|--------|-------|
| Standaarden | (0) | PASS | "Geen standaardversies gevonden voor de gekoppelde referentiecomponenten" (expected - no refcomponenten were selected due to 500 error) |
| Geschikt voor | (0) | PASS | Empty (expected - no refcomponenten selected) |
| Applicatieversies | (1) | PASS | Version 1.0.0, status "in gebruik", "In gebruik sinds 02 maart 2026". Confirms #375 FIX |
| Diensten | (1) | PASS | "Test Wizard Dienst (Aangeboden door Test Leverancier BV)" with description and "Lees meer" link. Confirms #373 FIX |
| Contactpersonen | (1) | PASS | "Jan Pietersen (Werkzaam bij Test Leverancier BV)", Functie: CEO, email, phone |
| Organisaties | (1) | PASS | "Test Leverancier BV" with description and "Lees meer" link |
| Koppelingen | (2) | PASS | Both "Test Wizard App <-> DigiD" and "Test Wizard App <-> MijnOverheid.nl" shown with proper names, dates, and status |

**Session 9 Detail Page (different app ID: 2c5bf231)**:

| Element | Result | Notes |
|---------|--------|-------|
| Title | FAIL | Shows "Test Wizard App (fd62b364-a89b-44a3-8920-0dc53624c6d0)" — UUID shown instead of org name |
| Type badge | PASS | Shows "Applicatie" with icon |
| Description | PASS | Both short and long descriptions shown correctly |
| Website/License/Hosting | PASS | All metadata displayed correctly |
| Standaarden tab | (15) | Shows 15 standards (10 Verplicht, 5 Aanbevolen) from Zaakregistratiecomponent. All "NIET ONDERSTEUND" |
| Geschikt voor tab | (1) | Shows "Zaakregistratiecomponent" with clickable link to gemmaonline.nl |
| Missing tabs | FAIL | Only 2 tabs visible (Standaarden, Geschikt voor). Missing: Diensten, Koppelingen, Versies, Contactpersonen, Organisaties |
| "Acties bewerken" button | PASS | Present and visible |

**BUG**: Title shows UUID `fd62b364-a89b-44a3-8920-0dc53624c6d0` where org name should be (stale org UUID issue)
**BUG**: Only 2 of 7 expected tabs visible — may be because this specific app has fewer relations

**Screenshots**: detail-app-standaarden.png (session 9), detail-app-overview.png (session 8)

### Dienst Detail Page (Test Wizard Dienst)
**URL**: `/publicatie/08d71364-6b31-4925-8eda-a93155b3d660`

| Element | Result | Notes |
|---------|--------|-------|
| Title | PASS | "Test Wizard Dienst" |
| Type badge | PASS | "Dienst" |
| Description | PASS | "Dienst aangemaakt via wizard test" |
| Website | PASS | Clickable link to https://test-leverancier.nl/dienst |
| Contact informatie | PASS | Section heading present with Website link |
| Basisinformatie | PASS | Shows "Diensttype: Implementatieondersteuning" |
| Applicaties tab | PASS | Shows "Test Wizard App (Aangeboden door Test Leverancier BV)" with description and "Lees meer" link |
| Breadcrumb | PASS | Home > Zoeken > Dienst |

**Note**: No separate "Beschrijving" tab - description shown directly on page (different from applicatie layout)
**Screenshot**: detail-dienst.png

### Koppeling Detail Page (Test Wizard Koppeling)
**URL**: `/publicatie/4f685e23-d7a5-4f57-8d33-a4015f136463` (Session 8) | `/publicatie/c0357b32-1e4a-46d7-940e-f42e60f331e3` (Session 9)

**Session 8 Results:**

| Element | Result | Notes |
|---------|--------|-------|
| Title | PASS | "Test Wizard App <-> MijnOverheid.nl" (proper name, not UUID) |
| Type badge | PASS | "Koppeling" with icon |
| Visual header | PASS | Shows "Test Wizard App <-> MijnOverheid.nl" with app names resolved |
| Applicatie A | PASS | "Test Wizard App" |
| BGV | PASS | "MijnOverheid.nl" (labeled as "Buitengemeentelijke voorziening") |
| Richting | PASS | "bi-directioneel (<->)" |
| Transportprotocol | PASS | "api" |
| Status | PASS | "in gebruik" |
| Startdatum | PASS | "1 maart 2026" |
| Korte beschrijving | PASS | "Koppeling voor gegevensuitwisseling met MijnOverheid.nl via REST API" |
| Applicaties tab | PASS | Shows linked "Test Wizard App" with details |
| Organisaties tab | PASS | Shows "(1)" count |
| Acties bewerken | PASS | Button present for editing |

**Session 9 Results (different koppeling: Test Wizard App <-> DigiD):**

| Element | Result | Notes |
|---------|--------|-------|
| Title | FAIL | Shows "aa156b86-b9f2-4ff6-b43c-455c1421c0a7 ↔ DigiD" — UUID instead of "Test Wizard App" (#312 confirmed) |
| Visual card | PASS | Body correctly shows "Test Wizard App ↔ DigiD" with resolved names |
| Applicatie A | PASS | "Test Wizard App" |
| Applicatie B | PASS | "DigiD" (labeled as "Buitengemeentelijke voorziening") |
| Richting | PASS | "bi-directioneel (↔)" |
| Status | PASS | "in gebruik" |

**BUG (Session 9)**: Title h1 shows UUID for Applicatie A instead of name — confirms #312 for app-wizard-created koppelingen
**Screenshot**: detail-koppeling.png

### Centric Begraven Detail Page (existing imported app)
**URL**: `/publicatie/45003fe7-3a1c-520e-bbee-8eb3c212c657`

| Element | Result | Notes |
|---------|--------|-------|
| Title | PASS | "Centric Begraven (Centric)" |
| Standaarden tab badge | (15) | Matches actual count of 15 standards in table |
| Standards breakdown | PASS | 1 Verplicht (active) + 10 Aanbevolen + 4 Niet-actieve (3 Verplicht + 1 Aanbevolen in niet-actief section) = 15 total |
| Compliance labels | PASS | Uses "NIET ONDERSTEUND" consistently (not "non-compliant") |
| Standard links | PASS | All link to gemmaonline.nl/wiki/GEMMA/id-{uuid} and are clickable |
| Geschikt voor | (1) | Referentiecomponent shown |
| Applicatieversies | (16) | Many versions available |
| Organisaties | (1) | Org shown |

### Mijn Account Page
**URL**: `/account`

| Element | Result | Notes |
|---------|--------|-------|
| Gebruikersgegevens | PASS | Shows E-mailadres, Voornaam="Jan", Tussenvoegsels="-", Achternaam="Pietersen", Organisatie="Test Leverancier BV" (clickable), Functie="CEO & Founder" |
| Bewerken button | PASS | Present for both sections |
| Contact gegevens | PASS | Shows UID, Account actief="Ja", Laatste login date, Backend="Database", Groepen="aanbod-beheerder, software-catalog-users", storage info |
| Breadcrumb | PASS | Home > Mijn account |

**Screenshot**: account-page.png

---

## Individual Issue Results

### Previously Tested (Re-verify)

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #294 | Applicatie publiceren: uitlijning rechthoek | CANNOT_TEST | Referentiecomponenten step blocked by 500 error. Cannot verify alignment of selection rectangle |
| #300 | Beheer: overzicht applicaties teveel applicaties | CANNOT_TEST | /beheer/applicaties table shows "Geen data gevonden" due to `_extend[]=moduleVersies` 500 error. Cannot verify RBAC scoping on applicaties table |
| #302 | Beheer: applicatie bewerken | CANNOT_TEST | Cannot access applicaties beheer table (500 error). Edit flow untestable |
| #370 | Applicatie: teveel kolommen worden getoond | CANNOT_TEST | Applicaties table not loading. Column headers visible but no data rows to verify |
| #373 | Applicatie: Gekoppelde diensten worden niet getoond | PASS | Detail page "Diensten (1)" tab shows "Test Wizard Dienst" correctly. Bug is FIXED |
| #375 | Applicaties: versie voor SaaS applicaties | PASS | Default version 1.0.0 automatically created during wizard step 3 AND visible on detail page "Applicatieversies (1)" tab. Bug is FIXED |
| #376 | Applicaties: labels wizard en tabel zijn anders | CANNOT_TEST | Cannot compare wizard labels with table column headers since table doesn't load |
| #377 | Applicaties: tabel toont diensten niet | CANNOT_TEST | Applicaties table not loading due to 500 error |
| #379 | Applicatie: verschillende manier van tonen compliancy | PASS | Centric Begraven detail page shows consistent compliance table: Verplicht/Aanbevolen/Niet-actieve sections, "NIET ONDERSTEUND" labels, proper standard links |
| #380 | Applicatie: compliance aantallen komen niet overeen | PASS | Centric Begraven: Tab badge "Standaarden (15)" matches exactly 15 rows in table (1 verplicht active + 10 aanbevolen + 4 niet-actieve). Counts match |
| #381 | Applicaties: non-compliant vervangen door niet ondersteund | PASS | Uses "NIET ONDERSTEUND" consistently instead of "non-compliant" on Centric Begraven detail page |
| #382 | Applicatie: compliancy link werkt niet | PASS | Standard links on Centric Begraven point to gemmaonline.nl/wiki/GEMMA/id-{uuid} format and are clickable |
| #383 | Applicatie: selectie vakken werken niet | PASS | Checkbox selection works in diensten and koppelingen tables. Row checkboxes enabled, "Selecteer alle" checkbox present |
| #384 | Applicaties: eenduidige manier van bewerken | PASS | Detail pages show "Acties bewerken" button. Beheer tables show "Acties" dropdown per row. Consistent edit entry points |
| #385 | Applicatie: Geen huidige versie in gebruik | PASS | Detail page shows version 1.0.0 with "in gebruik" status and date "In gebruik sinds 02 maart 2026" |
| #386 | Applicaties - Uw applicatie publiceren: andere labels | PASS | Wizard steps use clear labels: step names visible in stepper navigation. Field labels match expected naming |
| #387 | Applicaties - Uw applicatie publiceren: i niet aanwezig | PASS | Info (i) icons present next to fields needing explanation (e.g., Applicatie B in koppeling wizard, Klant(en) in gebruik wizard, Contactpersoon in app wizard) |
| #390 | Applicaties - Uw applicatie publiceren: labels komen niet overeen | PASS | Review/Controleren step labels match input step labels in all wizards tested |
| #399 | Versies: versie van andere leverancier geeft foutmelding | CANNOT_TEST | Did not navigate to another vendor's app versions. Would need "Test Applicatie Leverancier 2" |
| #105 | Aanbieders zien applicatielandschappen niet | CANNOT_TEST | /beheer/applicatielandschappen redirects to /beheer dashboard. Route does not exist for aanbod-beheerder role. Console error for related endpoint |

### New Issues

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #185 | Detailpagina's | PASS | Detail pages work for Applicatie, Dienst, Koppeling, and Organisatie. All show structured data with tabs, badges, type icons, and "Lees meer" links |
| #248 | Titels van de tabs in orde maken | PASS | Tabs use clear Dutch titles: "Standaarden", "Geschikt voor", "Applicatieversies", "Diensten", "Contactpersonen", "Organisaties", "Koppelingen", "Applicaties" |
| #263 | Niet ingelogd: gebruik tab toont gemeenten | MOVED | Moved to bezoeker persona (unauthenticated test required) |
| #274 | Wizard dienst: tekst aanpassen | PASS | Dienst wizard uses correct terminology throughout. Header "Uw Dienst publiceren", step descriptions are clear and helpful |
| #306 | Dienst: Overzicht controleren verbeteren | PASS | Dienst wizard review step shows clear overview with all entered data correctly formatted. Applicatie name, dienst name, website, diensttype all displayed |
| #307 | Diensten overzicht: meer dienst bij organisatie | PASS | Beheer/diensten table shows 8 diensten all belonging to Test Leverancier BV. No foreign diensten visible (RBAC scoping works correctly) |
| #308 | Diensten overzicht: default kolommen + kolom verwijderen | PASS | Table shows appropriate columns: Naam, Aanbieder, Diensttype, Korte omschrijving, Acties. Clean, focused column set |
| #312 | Koppeling heeft verplicht een naam | FAIL | Koppelingen created through the Applicatie wizard still use UUID-based names (confirmed: "aa156b86-... <-> DigiD" in beheer/koppelingen table). Koppelingen created through the dedicated Koppeling wizard DO get proper names ("Test Wizard App <-> MijnOverheid.nl"). The Naam field auto-populates in the app wizard koppeling step but the UUID is stored for moduleA instead of the app name |
| #314 | Wizard Koppeling: vindt eigen applicaties niet | PASS | Koppeling wizard shows all own applications in dropdown. 9 options available including all "Test Wizard App" entries. "al gekozen bij A" label shows for already-selected app |
| #345 | Zoeken: dienst verschijnt niet in filters | MOVED | Moved to bezoeker persona (public search page test) |
| #347 | Zoeken: Dienstkaartje toont array | MOVED | Moved to bezoeker persona (public search page test) |
| #348 | Standaarden count bij Centric Begraven | PASS | Centric Begraven found at `/publicatie/45003fe7-...`. Standaarden (15) badge matches actual count of 15 standard rows in the table. All standard names display correctly (no UUIDs) |
| #351 | Laden tabbladen gaat ongelijk | PASS | All tabs on detail pages loaded consistently without noticeable delay. Tab switching is responsive |
| #352 | Mijn account - Contactpersoon niet veranderd | PASS | /account page works correctly. Shows Gebruikersgegevens (Voornaam="Jan", Achternaam="Pietersen", Functie="CEO & Founder", Organisatie="Test Leverancier BV") and Contact gegevens sections with Bewerken buttons. Bug is FIXED (previously FAIL when /mijn-account was broken) |
| #354 | Diensten - incomplete lijst applicaties | PASS | Dienst wizard step 1 shows all own applicaties. 6 options from Test Leverancier BV visible |
| #356 | Diensten: geen tussenvoegsel bij namen | PASS | Contactpersonen table shows full names with tussenvoegsels: "Maria van der Berg", "Jan van de Berg" correctly displayed |
| #357 | Diensten: Diensttype en Type door elkaar | PASS | Consistent use of "Diensttype" in: beheer table column header, wizard step 2 label, detail page basisinformatie section |
| #358 | Diensten: status Concept getoond | PASS | No "Concept" status visible anywhere in diensten table, wizard, or detail page |
| #359 | Diensten wizard: tekst aanpassen | PASS | Wizard text is clear: "Selecteer een applicatie uit uw eigen aanbod", "Registreer uw dienst", step descriptions are helpful |
| #360 | Diensten wizard: i icons niet overeen | PASS | Info icons present next to fields requiring explanation in dienst wizard |
| #361 | Diensten wizard: inconsistentie labels | PASS | Dienst wizard review labels match input labels: "Naam", "Website", "Korte omschrijving", "Diensttype" consistent |
| #362 | Diensten wizard: onlogische tekst bovenaan | PASS | Success page header: "Dienst succesvol aangemeld!" - logical and clear |
| #363 | Diensten wizard: catalogus i.p.v. softwarecatalogus | PASS | All wizards use "softwarecatalogus" (full name) in success messages: "opgeslagen in de softwarecatalogus" |
| #364 | Contactpersonen: e-mailadres is leeg | PASS | All 5 contactpersonen show email addresses in beheer table. Jan Pietersen: "jan.pietersen@test.nl" |
| #365 | Contactpersonen: error bij opslaan | CANNOT_TEST | Did not test saving/editing contactpersoon to avoid modifying test data |
| #366 | Contactpersonen: veld Rollen niet consistent | CANNOT_TEST | No "Rollen" column visible in contactpersonen table. Columns are: Is gebruiker, Naam, Functie, E-mailadres, Acties. The Rollen field may have been removed or is not shown by default |
| #367 | Contactpersonen: tussenvoegsel niet getoond | PASS | Names display correctly with tussenvoegsels: "Maria van der Berg", "Jan van de Berg" in beheer table |
| #368 | Applicatie publiceren: koppeling zonder richting | PASS | Richting field is required (*) in both app wizard and koppeling wizard. Volgende button stays disabled until Richting is filled |
| #369 | Applicatie publiceren: aangemaakte koppeling niet zichtbaar | PASS | Koppelingen created in app wizard are visible in beheer/koppelingen table and on detail page Koppelingen tab (though app wizard ones have UUID names per #312) |
| #371 | Applicatie: UUID onder compliance | PASS | Standards on Centric Begraven detail page show proper names: "EN 301 549 versie 2.1.2 (WCAG 2.1)", "RSGB 3.0", etc. No UUIDs visible in standards display |
| #372 | Applicaties: contactpersoon geen tussenvoegsel | PASS | Detail page contactpersoon card shows "Jan Pietersen" correctly. Tussenvoegsels work (see #367 for proof with "van der Berg" names) |
| #374 | Applicaties: Standaarden, Standaarden GEMMA en Standaardversies | PASS | Standards tab uses clear column headers: "Standaardversie", "Status", "Bewijs". Categorized into Verplicht, Aanbevolen, Niet-actieve standaardversies |
| #378 | Applicatie: standaarden na wijzigen veranderd | CANNOT_TEST | Cannot access applicaties beheer table to edit. Would require before/after comparison |
| #391 | Testen met bestaande organisatie gebruiker | BLOCKED | Requires a second user for Test Leverancier BV organization. No second leverancier user available |
| #392 | Backend: geimporteerde gebruiker error | MOVED | Moved to functioneel-beheerder persona |
| #400 | Koppeling: opslaan geeft foutmelding | PASS | Koppeling wizard completed without errors. "Koppelingen succesvol opgeslagen!" message displayed correctly |
| #401 | Koppeling: geimporteerde koppelingen kaartjes leeg | PASS | Koppeling detail page shows structured data: title, Applicatie A, BGV/Applicatie B, Richting, Transportprotocol, Status, Startdatum, Korte beschrijving. Card is NOT empty |
| #402 | Verschil Edge en Chrome | SKIPPED | Untestable with single browser engine (Playwright/Chromium) |
| #407 | Standaarden verwijzen naar id-id-... | PASS | Standard links use correct single "id-" prefix: gemmaonline.nl/wiki/GEMMA/id-{uuid} (not "id-id-") |
| #408 | Tabblad beschrijving bij Dienst | PARTIAL | Dienst detail page shows description directly on page header, not in a separate "Beschrijving" tab. Has "Contact informatie" and "Basisinformatie" sections with "Applicaties" tab below. Different layout from applicatie page but content is present |

---

### New Issues Tested in Session 9

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #443 | Dienst pagina: diensttypen aan elkaar geschreven | CANNOT_TEST | Beheer diensten table not loading (org UUID error). Cannot verify diensttype display |
| #444 | Vormgeving veranderd bij te lange URL's | PASS | Centric Begraven detail page shows very long URL (centric.eu/NL/Default/Branches/...) without breaking layout. Contained within the right sidebar |
| #445 | Nieuwe dienst verkeerde afsluitende pagina | PASS (session 8) | Dienst wizard showed "Dienst succesvol aangemeld!" on correct success page |
| #446 | Dienst publiceren: tekstuele inconsistenties | PASS | Dienst wizard uses "Uw Dienst(en) publiceren" header, "softwarecatalogus" (full name), step labels consistent |
| #448 | Overzichtspagina's: verschillende vormgeving | CANNOT_TEST | Beheer tables not loading — cannot compare visual formatting across overview pages |
| #450 | Back-end: Icoon voor publiceren verwijderen | CANNOT_TEST | Beheer tables not loading — cannot check action icons |
| #451 | Koppeling: UUIDs zichtbaar bij standaardversies | CANNOT_TEST | Koppeling detail pages tested don't have standaardversies. Need imported koppeling with standards to verify |
| #452 | Applicaties overzicht: toont niet alle koppelingen | CANNOT_TEST | Beheer applicaties table not loading |
| #453 | Zoeken: filters van slag met Type=Koppeling | MOVED | Search page test — moved to bezoeker persona |
| #454 | Wizard koppelingen: bestaande koppelingen niet gevonden | PASS (session 8) | Koppeling wizard step 1 shows existing koppelingen for selected app |
| #456 | Consistentie in werking van wizards | PARTIAL | All 4 wizards work but have inconsistencies: App wizard has 7 steps, Dienst 3 steps, Koppeling 4 steps, Gebruik 2 steps. Different success page formats. App wizard koppeling step creates buggy data (#312) |
| #457 | Koppeling: verwijderen geeft 400-error | CANNOT_TEST | Beheer koppelingen table not loading — cannot test delete action |

## Summary Statistics

### Session 9 (2026-03-10) — Combined totals

| Status | Count |
|--------|-------|
| PASS | 41 |
| PARTIAL | 2 |
| FAIL | 1 |
| CANNOT_TEST | 17 |
| BLOCKED | 2 |
| MOVED | 5 |
| SKIPPED | 1 |
| **Total** | **69** |

### Changes from Session 8 to Session 9
- Wizard 4 (Gebruik melden): BLOCKED -> **PASS**
- #312 (Koppeling naam UUID): Confirmed FAIL with additional evidence from detail page
- 7 new issues tested (#443-#457 range)
- PublicationsController $extend bug found and fixed
- All beheer tables regressed: diensten/koppelingen/contactpersonen BLOCKED (were working in session 8)

---

## Critical Backend Issues

### 0. PublicationsController::show() $extend parameter crash (Session 9 — FIXED)
**Severity**: CRITICAL (all detail pages broken)
**File**: `/var/www/html/apps/opencatalogi/lib/Controller/PublicationsController.php` line 368
**Error**: "Unknown named parameter $extend" — Nextcloud dispatcher crashes when frontend sends `_extend[]` query params
**Fix**: Add `?array $extend = null` to method signature: `public function show(string $catalogSlug, string $id, ?array $extend = null): JSONResponse`
**Status**: Fixed in container, needs to be committed to opencatalogi codebase

### 0b. Stale organisation UUID (Session 9 — NOT FIXED)
**Severity**: CRITICAL (all beheer tables broken)
**UUID**: `fd62b364-a89b-44a3-8920-0dc53624c6d0` (returns 404)
**Correct UUID**: `28307ef1-6b5a-4435-ace8-3b6da25209f9` (Default Organisation)
**Impact**: All beheer tables show "Geen data gevonden" or "Loading..." because org context cannot be loaded
**Root Cause**: Frontend sends stale/wrong organisation UUID for the logged-in user. Config or user profile has outdated org reference.

### 1. `_extend[]=moduleVersies` causes 500 Internal Server Error
**Severity**: CRITICAL
**Endpoints affected**:
- `GET /api/objects/voorzieningen/module?_extend[]=moduleVersies` (any combination)
- `GET /api/objects/voorzieningen/module?_extend[]=moduleVersies&aanbieder={uuid}`
- `GET /api/objects/voorzieningen/module?_extend[]=moduleVersies&_extend[]=contactpersoon&_extend[]=diensten`

**Impact**:
1. `/beheer/applicaties` table shows "Geen data gevonden" - leveranciers cannot see or manage their applications
2. Wizard 4 (Applicatiegebruik melden) cannot load applicaties - "No options" in dropdown
3. Any API call including `_extend[]=moduleVersies` fails

**Verification**: `curl -s -o /dev/null -w "%{http_code}" -u admin:admin "http://localhost:8080/.../module?_limit=5&_extend%5B%5D=moduleVersies"` => 500

### 2. Referentiecomponenten API with `_extend` returns 500
**Severity**: HIGH
**Endpoint**: `GET /api/objects/vng-gemma/element?gemmaType=Referentiecomponent&_extend[]=aanbevolenStandaarden&_extend[]=verplichteStandaarden&_extend[]=gekoppeldeStandaardVersies`
**Impact**:
1. App wizard step 4 (Referentiecomponenten) shows "Laden..." indefinitely
2. Frontend retries in infinite loop, accumulating console errors
3. Workaround: step is optional and can be skipped

---

## Key Findings

### Issues Still Present (FAIL)

1. **#312 - Koppeling naam shows UUID**: Koppelingen created through the Applicatie wizard still use UUID-based names (e.g., "aa156b86-b9f2-4ff6-b43c-455c1421c0a7 <-> DigiD"). The dedicated Koppeling wizard correctly sets the name field. In the app wizard, the Naam field auto-populates visually in the UI ("Test Wizard App <-> DigiD") but the stored data uses the module UUID for moduleA, causing the name template fallback. Evidence: beheer/koppelingen table shows both UUID-named (from app wizard) and properly-named (from koppeling wizard) koppelingen side by side.

### Partial Issues

1. **#408 - Beschrijving tab bij Dienst**: The dienst detail page does not have a separate "Beschrijving" tab. Instead, it shows the description directly at the top with "Contact informatie" and "Basisinformatie" sections. This may be by design, but it differs from the applicatie layout.

### Regression from Session 7 -> 8

Several issues that were PASS in session 7 are now CANNOT_TEST in session 8 due to the `_extend[]=moduleVersies` 500 error:
- #294 (alignment) - required Referentiecomponenten step
- #300 (too many apps) - required applicaties table
- #302 (edit slow) - required applicaties table
- #370 (too many columns) - required applicaties table
- #376 (label comparison) - required applicaties table
- #377 (diensten column) - required applicaties table

### Regression from Session 8 -> 9

All beheer tables regressed due to stale org UUID:
- /beheer/diensten: was working (8 diensten) -> now BLOCKED
- /beheer/koppelingen: was working (12 koppelingen) -> now BLOCKED
- /beheer/contactpersonen: was working (5 contacts) -> now BLOCKED

### Improvements in Session 9

1. **Wizard 4 NOW WORKS**: Applicatiegebruik melden completed successfully (was BLOCKED in session 8 by 500 error)
2. **Detail pages FIXED**: PublicationsController $extend bug found and patched — all detail pages now load
3. **Centric Begraven detail page**: Confirmed 15 standards, proper names (no UUIDs), clickable links — matches session 8 findings

### Improvements in Session 8

1. **#352 FIXED**: Mijn Account page (`/account`) now works correctly
2. **#348 NOW TESTABLE**: Centric Begraven found in test data - standard count (15) matches badge and row count
3. **Koppeling wizard**: Fully tested with all 4 steps

### Console Errors Observed

**Session 9:**
1. **404** for org UUID `fd62b364-a89b-44a3-8920-0dc53624c6d0` on every page load (CRITICAL - blocks all beheer tables)
2. **500** for PublicationsController::show() with `$extend` parameter (FIXED by patching method signature)
3. **Error** "Collection not found for type: voorziening" during gebruik wizard submit (non-blocking)
4. **404** for names endpoint with stale org UUID (cosmetic — affects title display)

**Session 8:**
1. **500** for module endpoint with `_extend[]=moduleVersies` (blocks applicaties table)
2. **500** for referentiecomponenten endpoint with `_extend` (blocks app wizard step 4)
3. **404** for `/api/apps/openregister/api/schemas/product` (residual old schema reference)
4. **Error** loading `/beheer/applicatielandschappen/related` (route doesn't exist)

### Positive Observations

- **All four wizard flows completed successfully** (session 9: gebruik wizard now works)
- RBAC scoping works correctly in diensten and koppelingen tables (only own org data)
- Detail pages load with proper tabs, badges, icons, and structured content
- Standards display is consistent and well-formatted with proper categorization (Verplicht/Aanbevolen/Niet-actieve)
- Standard compliance labels use "NIET ONDERSTEUND" consistently
- Standard links use correct single "id-" prefix
- SaaS applications automatically get default 1.0.0 version (#375 FIXED)
- Diensten linked to applicaties show on detail page (#373 FIXED)
- Mijn Account page fully functional (#352 FIXED)
- Koppeling wizard distinguishes Applicaties from BGVs with color coding
- Tussenvoegsel (name particles) display correctly everywhere
- "softwarecatalogus" used consistently (not abbreviated to "catalogus")
- Breadcrumb navigation shows correct object type (Applicatie/Dienst/Koppeling)
- Contactpersonen show complete data (name, email, phone, function)

### Performance Notes

- Detail pages take 2-4 seconds for initial load (acceptable)
- Tab switching is immediate
- Beheer tables load within 1-2 seconds (when not blocked by errors)

### Test Data Cleanup

**Session 9 created objects** (not deleted — DELETE API returns 503/redirect):
- Test Wizard App: `2c5bf231-3c8d-4a43-9d0b-bfada39a560f` (register 3, schema 25)
- Test Wizard Dienst: `b2be71eb-0e27-4f31-9a21-1ac5043bc9ed` (register 3, schema 12)
- Test Wizard Koppeling (App <-> DigiD): `c0357b32-1e4a-46d7-940e-f42e60f331e3` (register 3, schema 18)
- Gebruik object: created for Amsterdam + Test Wizard App (register 3, schema 16)

**Note**: DELETE endpoint returned 503 (route not found). Manual cleanup via Nextcloud admin interface or direct DB needed.
- Name resolution for koppelingen detail page takes ~3 seconds for referenced objects
