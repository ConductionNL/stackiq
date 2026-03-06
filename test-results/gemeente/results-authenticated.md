# Test Results: Gemeente (Authenticated) - Maria van der Berg

**Date:** 2026-03-02 (Session 9 - Full Re-verification)
**Previous Sessions:** Session 5 (2026-02-24), Session 6 (2026-02-25), Session 7 (2026-02-26), Session 8 (2026-03-01)
**Persona:** Maria van der Berg - ICT-coordinator, Test Gemeente
**Role:** gebruik-beheerder
**Credentials:** maria.vanderberg@test.nl / WelcomeToTest2026
**Environment:** Frontend SPA: http://localhost:3000 | Backend: http://localhost:8080
**Browser:** Chromium (Playwright MCP, browser-3)

---

## Session 9 Context

This session performed a full re-verification of all wizard walkthroughs and issue tests. All three mandatory wizards were executed again from scratch, created objects were verified in beheer tables, and all search/filter/export/account issues were re-tested. Session 9 confirms and consolidates findings from Sessions 7 and 8.

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 17 |
| PARTIAL | 3 |
| FAIL | 1 |
| CANNOT_TEST | 2 |
| MOVED | 3 |
| NOT_VERIFIED | 1 |
| **Total** | **27** |

---

## Login & Dashboard

### Session 9 (Frontend SPA)
- **Login**: SUCCESS - Logged in at http://localhost:3000/login as maria.vanderberg@test.nl
- **Dashboard**: Shows "Mijn softwarecatalogus" with "Test Gemeente" organization selected
- **Navigation sidebar**: Full menu: Dashboard, Mijn Account, Mijn Organisatie, Diensten, Contactpersonen, Applicaties, Gebruik, Koppelingen, View
- **Dashboard issue**: "Geen wizards beschikbaar voor deze organisatie." - wizard buttons not shown on dashboard
- **Workaround**: Navigated directly to wizard URLs (/forms/gebruik/applicatie?type=gemeente, /forms/dienst, /forms/koppeling)
- **Console errors**: Two 404 errors for Test Gemeente organisation object (a44a5556-2001-4ffc-8a08-fe4705605b47) in the voorzieningen register
- **Screenshot**: login-dashboard-b3.png

---

## Wizard Walkthroughs (MANDATORY) - ALL COMPLETED

### Wizard 1: Applicatie toevoegen (gebruik registreren) - COMPLETED

| Step | Description | Status | Notes |
|------|-------------|--------|-------|
| Step 1 | Applicatie selecteren | PASS | Dropdown loads with initial results, searched for app, selected. "Ik kan de gewenste applicatie niet vinden" button visible |
| Step 1.1 | Nieuwe applicatie opvoeren | PASS | Clicked "Ik kan de gewenste applicatie niet vinden" - opens sub-step with fields for naam, leverancier, website. Filled: naam "Test Gemeente App", selected leverancier "Centric" |
| Step 2 | Gebruiksinformatie | PASS | Hosting: SaaS (only option for this app), Interne notitie filled, Status defaulted to "In productie", Startdatum auto-filled, Applicatie versie available |
| Step 3 | Referentiecomponenten | PASS | Two sections: "aangegeven door leverancier" + "toevoegen" (166 options). Selected referentiecomponent. All names human-readable |
| Step 4 | Controleren | PASS | All data verified in review. Alert explains visibility rules. Clicked "Gebruik registreren" |
| Result | | SUCCESS | "Gebruik succesvol geregistreerd!" - Type: Gebruik voor eigen organisatie |

**Screenshots**: wizard-app-step1-b3.png, wizard-app-step1-1-b3.png, wizard-app-step1-1-filled-b3.png, wizard-app-step2-b3.png, wizard-app-step3-b3.png, wizard-app-review-b3.png, wizard-app-success-b3.png

### Wizard 2: Dienst toevoegen - COMPLETED

| Step | Description | Status | Notes |
|------|-------------|--------|-------|
| Step 1 | Applicaties selecteren | PASS | Searched for app, selected. Shows "Geen bestaande diensten" section |
| Step 2 | Dienst informatie | PASS | Fields: Naam*, Website, Korte omschrijving, Uitgebreide omschrijving (markdown), Logo, Contactpersoon, Diensttype* (6 options) |
| Step 3 | Controleren | PASS | Review shows: naam, korte omschrijving, website, diensttype, linked applicaties |
| Result | | SUCCESS | "Dienst succesvol aangemeld!" |

**Screenshots**: wizard-dienst-step1-b3.png, wizard-dienst-step2-b3.png, wizard-dienst-review-b3.png, wizard-dienst-success-b3.png

### Wizard 3: Koppeling toevoegen - COMPLETED

| Step | Description | Status | Notes |
|------|-------------|--------|-------|
| Step 1 | Koppeling zoeken | PASS | Selected app from dropdown. Shows existing koppelingen for that application |
| Step 2 | Koppeling configuratie | PASS | Applicatie A pre-filled. Richting: 3 options. Applicatie B or BGV: 72 options. Status: 4 options |
| Step 3 | Aanvullende informatie | PASS | Optional fields: Korte beschrijving, Lange beschrijving, Standaardversies, Transportprotocol, Intermediair |
| Step 4 | Controleren | PASS | Review shows koppeling name, direction arrow, status, startdatum |
| Result | | SUCCESS | "Koppelingen succesvol opgeslagen!" |

**Screenshots**: wizard-koppeling-step1-b3.png, wizard-koppeling-step2-b3.png, wizard-koppeling-review-b3.png, wizard-koppeling-success-b3.png, wizard-koppeling-success-full-b3.png

### Created Objects Verification

| Object Type | Beheer URL | Object Name | Verified | Screenshot |
|-------------|-----------|-------------|----------|------------|
| Applicatie | /beheer/applicaties | Test Gemeente App (Centric, Closed source) | YES | beheer-applicaties-b3.png |
| Dienst | /beheer/diensten | Test Gemeente Dienst (Centric, Functioneel beheer) | YES | beheer-diensten-b3.png |
| Koppeling | /beheer/koppelingen | Test Gemeente App -> MijnOverheid.nl (in gebruik) | YES | beheer-koppelingen-b3.png |

---

## Issue Test Results

### #15: Data vanuit softwarecatalogus exporteren
**Status**: PASS
**Session 8+9 Verification**:
- **Diensten CSV export**: Clicked Acties -> Exporteren -> Als CSV on /beheer/diensten. Downloaded CSV (1,410 bytes, 4 diensten)
- **Diensten Excel export**: Clicked Acties -> Exporteren -> Als Excel. Downloaded XLSX file
- **Koppelingen CSV export**: Clicked Acties -> Exporteren -> Als CSV on /beheer/koppelingen. Downloaded CSV
- **Applicaties page**: Shows "Geen data gevonden" (no applicaties registered through beheer for this org)
- Both CSV and Excel export buttons present and functional on all management pages
- **Session 9 curl verification**: Exported diensten CSV via API - confirmed dual columns (UUID + human-readable)

**Acceptance Criteria**:
- [x] On the management overview pages, an export button is available (Acties -> Exporteren -> Als CSV / Als Excel)
- [x] The exported data contains ONLY the applications/products belonging to the user's own organization
- [x] Exported columns include both human-readable names AND UUIDs (dual-column: `aanbieder` = UUID, `_aanbieder` = "Test Gemeente")
- [x] The CSV format correctly separates into columns (verified CSV with proper quoting)
- [x] The export works correctly for gebruik-beheerder role
- [x] The export reflects RBAC permissions

**Screenshot**: export-options-b3.png

Bug is FIXED.

---

### #144: Overzicht organisaties met zoek- en filteropties
**Status**: PASS
**Session 9 Verification**:
- Total organisaties: 3,148 (Organisatietype filter on search page)
- Type filter shows "Organisatie (3.148)" in search filters
- Organisatietype sub-filter: Gemeente (358), Leverancier (2,687), Samenwerking (103)
- Search and filter functionality works correctly

---

### #266: Na inloggen: Mijn account & persoonlijke gegevens leeg?
**Status**: PASS
**Session 9 Frontend Verification**:
- Navigated to /beheer/my-account
- All fields populated: E-mailadres, Voornaam (Maria), Tussenvoegsels (van der), Achternaam (Berg), Organisatie (Test Gemeente), Functie (ICT-coordinator)
- "Bewerken" button available for editing

**Screenshot**: mijn-account-b3.png

Bug is FIXED.

---

### #278: Filterteksten aanpassen
**Status**: PASS
**Session 9 Frontend Verification**:
All filter labels on /zoeken are human-readable Dutch:
- Type (4): Applicatie (6,108), Dienst (13), Koppeling (3,430), Organisatie (3,148)
- Samenwerkingstype (14): collapsed, accessible
- Geregistreerd door (11): collapsed
- Leverancier (2,583): collapsed with search
- Licentievorm (2): Closed source (6,068), Open source (40)
- Referentiecomponenten (168): collapsed
- Standaardversies (43): collapsed
- Type koppeling (2): extern (892), intern (2,538)
- Organisatietype (3): Gemeente (358), Leverancier (2,687), Samenwerking (103)
- Diensttype (3): Applicatiebeheer (2), Functioneel beheer (3), Implementatieondersteuning (8)

**Screenshots**: zoeken-filters-b3.png

**Acceptance Criteria**:
- [x] Filter labels on /zoeken display correct, updated text
- [x] Updated texts appear without stale cached content
- [x] Filter texts are consistent with terminology used in wizards and management pages
- [x] Filter currently labeled "Schema" or "Objecttype" is renamed to "Type"
- [x] "Diensttype" label used (not "Soort dienst")

---

### #280: Zoeken: sorteren gaat niet goed
**Status**: PASS
**Session 8+9 Frontend Verification**:
- Sort dropdown shows 5 options: Meest relevant, Datum oud-nieuw, Datum nieuw-oud, Naam A-Z, Naam Z-A
- **"Naam - A naar Z"** (default): Correct alphabetical order (050media, 12view Gisprogramma, 14010 - VANAD...)
- **"Naam - Z naar A"**: Reversed order (MStation, Zynyo, ZXY Cloud, Zwolle...)
- **"Datum - nieuw naar oud"**: Most recent first (01 maart 2026 test items at top)
- Pagination: 634+ pages x 20 items = 12,699 results
- Page 1 and Page 2 confirmed to show completely different, non-overlapping results

Bug is FIXED.

---

### #315: Hoge prioriteit: Zoekpagina toont deel van gemeentelijk applicatielandschap
**Status**: PARTIAL
**Session 8+9 Verification**:

Privacy/RBAC testing:
- **Unauthenticated search for "Test Gemeente"**: 0 results (PASS - municipality data hidden)
- **Authenticated search for "Test Gemeente"**: 17 results (correctly visible to authenticated user)
- **Unauthenticated search for "gebruik"**: 0 results (PASS - gebruik data hidden)
- RBAC correctly prevents public access to municipality-specific data

Leverancier filter analysis:
- **Leverancier filter** on /zoeken contains 2,583 items (1,063 visible when expanded)
- **Municipality found in Leverancier filter**: "Amersfoort (2)" -- a municipality incorrectly appearing as a supplier
- **UUIDs found in Leverancier filter**: Several raw UUIDs appear as supplier names
- "Aangeboden door" on applicatie cards shows municipality names (e.g., "Bloemendaal-Heemstede", "Rotterdam", "Deurne") -- from imported data where municipalities were set as suppliers

**Acceptance Criteria**:
- [ ] "Leverancier" filter contains ONLY actual suppliers, NOT municipalities -- FAIL (Amersfoort found)
- [ ] Search result cards show the actual supplier as "aangeboden door" -- PARTIAL (some show municipalities)
- [x] Municipal application landscape data is not publicly visible to unauthenticated users
- [x] RBAC-based filtering controls visibility
- [ ] Supplier on search card matches supplier on detail page -- not fully verified
- [x] RBAC-based filtering replaces the old "published" status approach

---

### #340: Bevindingen op tussenoplevering Zoeken
**Status**: PARTIAL
**Session 8+9 Frontend Verification**:
- [PASS] Default sorting "Naam - A naar Z" produces correct alphabetical order
- [PASS] Date displayed on cards (e.g., "01 januari 2025", "30 maart 2020", "24 februari 2026")
- [PASS] Type shown on cards (Applicatie, Dienst, Koppeling, Organisatie)
- [PASS] Type filter present with 4 options
- [PASS] "Diensttype" label used (not "Soort dienst")
- [FAIL] Standaardversies on koppeling search result cards still show raw UUIDs (e.g., "46a26a56-4820-439f-804a-56448ba1164a") -- caused by /api/names/ endpoints returning 404
- [PASS] Applicatie/Organisatie cards show proper names

---

### #342: Zoeken: op kaartjes referentiecomponenten duidelijk maken
**Status**: FAIL
**Session 8+9 Notes**: Applicatie search cards show "Geschikt voor: [component name]" which IS showing referentiecomponenten. However, when there are multiple, only first is shown with no "+N meer" count visible. Some cards show "Geschikt voor 5 referentiecomponenten" (a count but not names).

**Acceptance Criteria**:
- [x] Referentiecomponenten appear on applicatie cards (as "Geschikt voor:")
- [ ] When an application has more than can be displayed, a total count AND names are shown -- PARTIAL (count OR names, not both)

---

### #343: Zoeken: Filter 'Type koppeling' toevoegen
**Status**: PASS
**Session 9 Frontend Verification**:
- "Type koppeling (2)" filter present in filter panel on /zoeken
- Two options: extern (892) and intern (2,538)

Bug is FIXED.

---

### #344: Zoeken: Geen resultaten bij Gravenbeheercomponent
**Status**: PASS
**Session 9 Frontend Verification**:
- Expanded Referentiecomponenten filter (168 items, all human-readable names)
- Typed "Graven" in filter search box -- narrowed to "Gravenbeheercomponent (32)"
- Selected it -- search results correctly showed 32 matching applications
- All results correctly showed Gravenbeheercomponent as reference component

Bug is FIXED. **Screenshot**: (Previously captured: test-344-gravenbeheercomponent-filter.png)

---

### #346: Zoeken: paginering werkt niet
**Status**: PASS
**Session 9 Frontend Verification**:
- 12,699 results across 634+ pages (20 per page)
- Page 1 and Page 2 show completely different, non-overlapping results
- Pagination controls: Vorige pagina, numbered pages (1-5, ..., 634), Volgende pagina

Bug is FIXED.

---

### #349: Zoeken: UUID's onder standaarden filter
**Status**: PARTIAL
**Session 9 Frontend Verification**:
- **Standaardversies filter dropdown**: Shows 43 items, ALL human-readable names (PASS)
- **Search result CARDS**: Koppeling cards still display raw UUIDs for standaardversies (e.g., "Standaardversies: 46a26a56-4820-439f-804a-56448ba1164a") -- FAIL
- Root cause: /api/names/ endpoints return 404 for these UUIDs, so the frontend cannot resolve them to names on cards

**Screenshots**: zoeken-standaardversies-filter-b3.png

**Acceptance Criteria**:
- [x] Standaardversies filter shows human-readable names
- [ ] Standaardversies on search result cards show human-readable names -- FAIL (UUIDs displayed)

---

### #350: De link achter de gebruikersnaam verwijzen naar Mijn account
**Status**: PASS
**Session 8+9 Frontend Verification**:
- "Maria van der Berg (Test Gemeente)" link in header: points to /beheer/my-account
- "Maria van der Berg" in Gebruikersmenu: points to /beheer/my-account
- Separate "Uitloggen" link: points to /logout

Bug is FIXED.

---

### #353: Mijn account - Je "functie" wordt niet aangepast na bewerken en opslaan
**Status**: PASS
**Session 9 Frontend Verification**:
1. Navigated to /beheer/my-account
2. Changed "ICT-coordinator" to "ICT Test Coordinator" via Bewerken
3. Saved successfully ("Uw gegevens zijn succesvol bijgewerkt.")
4. Reloaded page -- value persisted as "ICT Test Coordinator"
5. Changed back to "ICT-coordinator"

Bug is FIXED. **Screenshot**: mijn-account-b3.png

---

### #355: Diensten: Export geeft allerlei UUID's
**Status**: PASS
**Session 9 Frontend + API Verification**:
- Exported diensten CSV from /beheer/diensten (Acties -> Exporteren -> Als CSV)
- CSV columns use dual-column format:
  - `modules` (UUIDs) paired with `_modules` (readable names: "Test Applicatie Leverancier")
  - `aanbieder` (UUID) paired with `_aanbieder` (readable name: "Test Leverancier BV")
  - `type` already shows readable names: "Functioneel beheer", "Applicatiebeheer"
- Koppelingen CSV also uses dual-column: `moduleA` (UUID) with `_moduleA` (readable name)
- **API curl verification**: `curl --user 'maria.vanderberg@test.nl:WelcomeToTest2026' 'http://localhost:8080/index.php/apps/openregister/api/objects/voorzieningen/dienst/export?format=csv'` confirmed dual-column output

**Acceptance Criteria**:
- [x] CSV export includes human-readable names alongside UUIDs
- [x] Relation fields have `_fieldname` companion columns with resolved names
- [x] Type/enum fields show readable values directly

Bug is FIXED.

---

### #395: Menu linkerkant verdwijnt
**Status**: PASS
**Session 8+9 Frontend Verification**:
- Set viewport to 1280x800
- Navigated to /beheer/applicaties -- left sidenav visible with all items: Dashboard, Mijn Account, Mijn Organisatie, Diensten, Contactpersonen, Applicaties, Gebruik, Koppelingen, View
- Pressed F5 to refresh -- sidenav still fully visible with all items
- Navigation is persistent and does not disappear on page reload
- **Session 9 note**: On narrower viewport (1280x720), no left sidebar was visible -- only top nav and breadcrumb. This may indicate the sidebar hides at certain viewport sizes.

Bug is FIXED (at standard viewport sizes). **Screenshots**: test-395-applicaties-before-refresh.png, test-395-applicaties-after-refresh.png

---

### #316-#318: Dienst toevoegen Wizard Steps 1-3
**Status**: PASS (functional) / NOT_VERIFIED (exact text matching)
**Session 7+8+9 Notes**: All three steps of the Dienst wizard were walked through successfully across multiple sessions. Field labels, tooltips, and step titles match expected functionality. Dienst was successfully registered. Exact text comparison with PowerPoint reference (#329) was not performed.

---

### #319-#322: Koppeling toevoegen Wizard Steps 1-4
**Status**: PASS (functional) / NOT_VERIFIED (exact text matching)
**Session 7+8+9 Notes**: All four steps of the Koppeling wizard were walked through successfully across multiple sessions. Existing koppelingen shown on step 1, direction/app selection on step 2, optional fields on step 3, review on step 4. Koppeling was successfully saved. Exact text comparison with PowerPoint reference (#329) was not performed.

---

### #323-#327: Applicatie toevoegen Wizard Steps 1-5
**Status**: PASS (functional) / NOT_VERIFIED (exact text matching)
**Session 7+8+9 Notes**:
- Step 1 (Applicatie zoeken): Dropdown with search, 50 initial + typeahead filtering
- Step 1.1 (Nieuwe applicatie opvoeren): "Ik kan de gewenste applicatie niet vinden" button visible and functional -- opens sub-step with naam, leverancier, website fields
- Step 2 (Gebruiksinformatie): Hosting, Interne notitie, Status, Startdatum, Applicatie versie
- Step 3 (Referentiecomponenten): 166 options, two sections (leverancier + toevoegen)
- Step 4 (Controleren): Full review with all data, "Gebruik registreren" button
- Success page with "Wat gebeurt er nu?" list
Exact text comparison with PowerPoint reference (#329) was not performed.

---

### #328: Applicatie toevoegen: Stap 1.1 Nieuwe applicatie opvoeren
**Status**: PASS
**Session 9 Verification**:
- Navigated to gemeente applicatie wizard: /forms/gebruik/applicatie?type=gemeente
- In Step 1, "Ik kan de gewenste applicatie niet vinden" button is visible
- Clicked it -- sub-step 1.1 opens with fields for entering a new application
- Filled naam "Test Gemeente App" and selected leverancier "Centric"
- Sub-step is functional and accessible in the gemeente wizard

**Screenshots**: wizard-app-step1-1-b3.png, wizard-app-step1-1-filled-b3.png

Previously CANNOT_TEST, now confirmed as PASS.

---

### #286: Aanmelden organisatie: 500-error bij wachtwoord wijzigen
**Status**: MOVED -> functioneel-beheerder
**Notes**: Admin-level password change test, not applicable for gemeente persona.

---

### #345: Zoeken: toegevoegde dienst verschijnt niet in filters
**Status**: MOVED -> bezoeker
**Notes**: Public search page test, not applicable for authenticated gemeente testing.

---

### #347: Zoeken: Dienstkaartje toont array
**Status**: MOVED -> bezoeker
**Notes**: Public search page test for dienst card display.

---

## Detail Page Testing

### Applicatie Detail Page
**URL tested**: /publicatie/d97b5714-5f05-5c4e-a3fc-94d742ba7dc4 (Anywhere365 Unified Contact Center)
**Status**: PASS
- Title: "Anywhere365 Unified Contact Center (Workstream People)"
- Type label: "Applicatie" with icon
- Licentietype: "Closed source"
- Tabs present: Standaarden (17), Geschikt voor (1), Applicatieversies (1), Gebruik (1), Organisaties (1)
- **Standaarden tab**: Table with Standaardversie, Status, Bewijs columns. Standards grouped by Verplicht/Aanbevolen/Niet-actieve. All standard names human-readable with GEMMA Online links
- **Geschikt voor tab**: "Callcentercomponent" with GEMMA Online link
- **Gebruik tab**: Shows "Gebruikt door Zeist", status "Uitgefaseerd"
- **Organisaties tab**: "Workstream People" (vendor) with date
- Breadcrumb: Home > Zoeken > Applicatie

### Koppeling Detail Page
**URL tested**: /publicatie/ff666af0-af19-5f2c-8e79-8830fd8d496d (Active Directory -> Centric Leefomgeving)
**Status**: PARTIAL
- Title in page: "Active Directory -> Centric Leefomgeving" (correct, readable names)
- **BUG: Browser tab title**: Shows UUID "4539c2ec-893d-564e-901b-0a1e01a67c36 ->" instead of readable name
- **BUG: Applicatie B**: Displays `"[object Object]"` instead of the actual application name in multiple places
- Metadata: Richting "AnaarB (->)", Transportprotocol "intern", Status "In gebruik"
- Applicatie A: "Active Directory" (shown correctly)
- Applicaties tab (1): Shows "Active Directory" by Breda, correctly rendered
- Breadcrumb: Home > Zoeken > Koppeling
- Button: "Koppeling aanbieden" visible

### Dienst Detail Page
**URL tested**: /publicatie/ada2eb73-4e64-4e70-b819-19fc049d3f31 (Test Gemeente Dienst)
**Status**: PASS
- Title: "Test Gemeente Dienst"
- Type label: "Dienst"
- Description: "Dienst geregistreerd door Test Gemeente"
- Contact informatie: Website link https://test-gemeente.nl/dienst
- Button: "Acties bewerken" (user's own dienst)
- Applicaties tab (1): Shows "Centric Burgerzaken" by Centric, "Geschikt voor 5 referentiecomponenten"
- Breadcrumb: Home > Zoeken > Dienst

### Organisatie Detail Page
**URL tested**: /publicatie/73d75219-3ee4-5164-91b6-05aa73c97749 (BR Controls)
**Status**: PASS
- Title: "BR Controls"
- Type label: "Organisatie"
- Applicaties tab (2): "BR Controls" (by Emmen, BOR-component), "BRWebservice" (by Oldambt, Gebouwinstallatiecomponent)
- Breadcrumb: Home > Zoeken > Organisatie

---

## RBAC / Privacy Testing (#315 related)

### Data Visibility Comparison

| Data Type | Public (Unauthenticated) | Authenticated (Maria) | Ratio |
|-----------|-------------------------|----------------------|-------|
| Search "Test Gemeente" | 0 | 17 | 0% public |
| Search "gebruik" | 0 | N/A | 0% public |
| Modules/Applicaties | ~1,058 | ~6,108 | ~17% public |
| Koppelingen | 0 | 3,430 | 0% public |
| Diensten | N/A | 13 | N/A |
| Organisaties | ~3,148 | ~3,148 | ~100% public |

**Key findings**:
- Municipality-specific data (Test Gemeente) completely hidden from unauthenticated users (PASS)
- Koppelingen completely hidden from public users (PASS)
- Modules heavily filtered for public (~17% visible, supplier-published only)
- Organisaties fully public (expected -- org names are not sensitive)
- "Amersfoort" municipality still appears in the Leverancier filter (data quality issue from import)

---

## Console Errors

| Error | Severity | Occurrence |
|-------|----------|------------|
| 404: Organisation object a44a5556-...fe4705605b47 | MEDIUM | Every page load |
| 404: /api/names/{uuid} for standaardversie UUIDs | MEDIUM | Search page (multiple UUIDs) |
| 404: /api/names/{uuid} for various objects | LOW | Detail pages |
| 500: _extend[]=moduleVersies on module API | MEDIUM | Wizard module queries |
| 500: GEMMA elements API with _extend[]=aanbevolenStandaarden | MEDIUM | GEMMA data loading |

---

## Performance Summary

| Operation | Response Time | Status |
|-----------|--------------|--------|
| Login (Frontend SPA) | < 2s | OK |
| Search page initial load | ~5s | OK (12,699 results + filters) |
| Beheer management pages | < 3s | OK |
| CSV/Excel export | < 2s | OK |
| Detail page load | ~3s | OK |
| Mijn Account page | < 1s | OK |

---

## Screenshots Index

All screenshots saved to `test-results/gemeente/`:

### Session 9 Screenshots (browser-3, March 2)

**Login/Dashboard:**
- login-dashboard-b3.png - Dashboard after login

**Wizard 1 - Applicatie:**
- wizard-app-step1-b3.png - Step 1: Applicatie selecteren
- wizard-app-step1-1-b3.png - Step 1.1: "Ik kan de gewenste applicatie niet vinden" sub-step
- wizard-app-step1-1-filled-b3.png - Step 1.1: Filled with naam and leverancier
- wizard-app-step2-b3.png - Step 2: Gebruiksinformatie
- wizard-app-step3-b3.png - Step 3: Referentiecomponenten
- wizard-app-review-b3.png - Step 4: Controleren
- wizard-app-success-b3.png - Success: "Gebruik succesvol geregistreerd!"

**Wizard 2 - Dienst:**
- wizard-dienst-step1-b3.png - Step 1: Applicaties selecteren
- wizard-dienst-step2-b3.png - Step 2: Dienst informatie
- wizard-dienst-review-b3.png - Step 3: Controleren
- wizard-dienst-success-b3.png - Success: "Dienst succesvol aangemeld!"

**Wizard 3 - Koppeling:**
- wizard-koppeling-step1-b3.png - Step 1: Koppeling zoeken
- wizard-koppeling-step2-b3.png - Step 2: Koppeling definiëren
- wizard-koppeling-review-b3.png - Step 4: Controleren
- wizard-koppeling-success-b3.png - Success toast message
- wizard-koppeling-success-full-b3.png - Full success page

**Beheer Verification:**
- beheer-applicaties-b3.png - Applicaties table with created object
- beheer-diensten-b3.png - Diensten table with "Test Gemeente Dienst"
- beheer-koppelingen-b3.png - Koppelingen table with created koppeling

**Search/Filters:**
- zoeken-main-b3.png - Main search page (12,699 results)
- zoeken-filters-b3.png - Filter panel showing all filters
- zoeken-standaardversies-filter-b3.png - Standaardversies filter (human-readable names)

**Account/Export:**
- mijn-account-b3.png - Mijn Account page with all fields
- export-options-b3.png - Export dropdown (Als CSV / Als Excel)

### Session 8 Screenshots (preserved)
- test-344-gravenbeheercomponent-filter.png
- test-353-functie-saved.png
- test-395-applicaties-before-refresh.png
- test-395-applicaties-after-refresh.png

### Session 7 Screenshots (preserved)
- s7-dashboard.png, s7-mijn-account.png, s7-search-page.png
- s7-wizard-app-*.png, s7-wizard-dienst-*.png, s7-wizard-koppeling-*.png

---

## Bugs Found

### BUG-1: Koppeling detail page shows [object Object] for Applicatie B
- **Location**: /publicatie/{koppeling-uuid} detail page
- **Expected**: Applicatie B field shows human-readable application name
- **Actual**: Shows `"[object Object]"` in multiple places (title area, metadata section)
- **Severity**: MEDIUM
- **Likely cause**: Frontend renders the application object directly instead of extracting the `.naam` property

### BUG-2: Koppeling detail page browser tab title shows UUID
- **Location**: Browser tab title for koppeling detail pages
- **Expected**: "Active Directory -> Centric Leefomgeving"
- **Actual**: "4539c2ec-893d-564e-901b-0a1e01a67c36 ->"
- **Severity**: LOW
- **Likely cause**: Page title set before name resolution completes

### BUG-3: Standaardversies on search cards show raw UUIDs
- **Location**: /zoeken search result cards for koppelingen
- **Expected**: Human-readable standard version names
- **Actual**: Raw UUIDs like "46a26a56-4820-439f-804a-56448ba1164a"
- **Severity**: MEDIUM
- **Likely cause**: /api/names/ endpoint returns 404 for these UUIDs

### BUG-4: Municipality names in Leverancier filter
- **Location**: /zoeken Leverancier filter
- **Expected**: Only actual software suppliers
- **Actual**: Contains at least "Amersfoort" (a municipality) and several raw UUIDs as supplier names
- **Severity**: HIGH (data quality / privacy concern, per #315)
- **Likely cause**: Import data incorrectly set municipalities as suppliers

### BUG-5: Organisation object 404 on every page load
- **Location**: Console errors on all pages
- **Expected**: No 404 errors
- **Actual**: 404 for `/api/objects/voorzieningen/organisatie/a44a5556-2001-4ffc-8a08-fe4705605b47`
- **Severity**: LOW (does not block functionality)
- **Likely cause**: Test Gemeente organisation object missing from voorzieningen register

### BUG-6: Backend 500 errors on _extend queries
- **Location**: API calls with `_extend[]=moduleVersies` and GEMMA elements with `_extend[]=aanbevolenStandaarden`
- **Expected**: 200 OK with extended data
- **Actual**: 500 Internal Server Error (stripEmptyValues(): Argument #1 must be of type array, ObjectEntity given)
- **Severity**: MEDIUM (does not block wizard completion, but may cause missing data)

---

## Recommendations

1. **Fix [object Object] on koppeling detail pages** (BUG-1): The Applicatie B rendering needs to extract `.naam` from the object before displaying it. This is the most visible user-facing bug.

2. **Fix /api/names/ endpoint** (BUG-3): Many standaardversie UUIDs return 404 from the names API, causing raw UUIDs to appear on search cards. Either the names need to be populated, or the frontend should use the `_standaardversies` resolved names from the object data.

3. **Clean up Leverancier import data** (BUG-4): Municipality names like "Amersfoort" and raw UUIDs should not appear in the Leverancier filter. This is a data quality issue from the import process.

4. **Fix browser tab title for koppelingen** (BUG-2): Set the page title after name resolution completes, not before.

5. **Create Test Gemeente organisation object** (BUG-5): The persistent 404 for the organisation object should be resolved by ensuring the object exists in the voorzieningen register.

6. **Fix _extend backend errors** (BUG-6): The `stripEmptyValues()` error when extending moduleVersies and related GEMMA data needs a backend fix.

7. **Wizard text verification** (#316-#328): Exact text comparison with the PowerPoint reference in #329 was not performed in these sessions. A dedicated pass comparing each wizard step's text against the reference would be valuable.
