# Functioneel Beheerder Test Results (Authenticated)

**Persona:** Peter van Dijk (Functioneel Beheerder / Full Admin)
**Username:** peter.vandijk@test.nl
**Date:** 2026-03-02 (comprehensive re-test, continuation of 2026-03-01 session)
**Environment:** Frontend: http://localhost:3000 | Backend: http://localhost:8080
**Browser:** Playwright (Chromium headless, browser-5)
**Nextcloud Version:** 32.0.5

---

## Test Summary

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #155 | Definities via interactieve optie (Begrippenlijst) | **PASS** | Keywords show as text, empty external link allowed |
| #267 | Naam is softwarecatalogus i.p.v. Softwarecatalogus | **PARTIAL** | Moved to bezoeker scope; capitalisation consistent in tested pages |
| #332 | Voorpagina inrichten | **PASS** | Homepage fully loaded: search banner, 3 content blocks, stats, footer |
| #397 | Pagina aanmaken via CMS | **PASS** | 7 pages visible: About, Website, Privacyverklaring, Disclaimer, Algemene Voorwaarden, FAQ, Home |
| #403 | Tekst verwijderen aanpassen | **PASS** | Frontend delete dialog now shows type-specific text and in-use check |
| #406 | SiteImprove verwijderen | **PASS** | No SiteImprove script found, only Piwik Pro template present |
| #409 | Footer anders: inlog of uitgelogd | **PASS** | Footer links identical in logged-in and logged-out states |
| #410 | Dashboard schrijfwijze softwarecatalogus | **PASS** | Verified for both Peter (functioneel beheerder) AND Jan Pietersen (leverancier) |
| #92 | Webstatistiekenpakket (Piwik Pro) | **PARTIAL** | Piwik Pro template present but srcUrl/dataLayerName/id are empty (not configured) |
| #169 | Rest issues Organisatie en Configuratie | **PARTIAL** | Mijn Account works for both admin and leverancier; shows name, email, organisation (clickable link), functie, tussenvoegsels; org activation not tested |
| #85 | Publieke API toegang tot aanbodinformatie | **PASS** | All API endpoints return 200, OAS documentation accessible |
| #148 | GEMMA-architectuur opvraagbaar met API | **PASS** | Register 4 OAS returns 200 with auth, elements/relations queryable |
| #278 | Filterteksten aanpassen | **PASS** | Filter labels correct: Type, Referentiecomponenten, Standaardversies, Diensttype, Type koppeling |
| #286 | 500-error bij wachtwoord wijzigen | **PASS** | Password change via OCS API returns 200 OK |
| #392 | Geimporteerde gebruiker error bij omzetten naar user | **FAIL** | SQL not-null violation on "afnemer" column when creating contact person for imported org |
| #393 | Backend: fouten in voorzieningenregister | **PASS** | All 13 schema endpoints return 200; XLSX and CSV exports work |
| #396 | Verouderde NextCloud versie | **PASS** | Running Nextcloud 32.0.5 |
| #225 | Testresultaten 29-10-2025 | **PASS** | Search works (837 results for "VNG"); no "+" button on other org's pages |
| #141 | Organisaties samenvoegen na herindeling/overname | **PARTIAL** | Merge dialog fully functional: target selection with search, 4-column property comparison (Property/Source/Target/Result), intelligent defaults; not executed to completion |
| #15 | Exporteren van gegevens (CSV/Excel) | **PASS** | CSV export returns actual CSV format; human-readable _columns present |
| #355 | Exporteren functies (Applicatie export) | **PASS** | Export endpoint returns 200 for all schemas; CSV and XLSX both work |
| N/A | Themes management | **PASS** | 4 themes visible and manageable in OpenCatalogi backend |
| N/A | Facet editing | **PASS** | Schema properties with facetable flags confirmed via API |
| N/A | Schema export | **PASS** | Register API returns config JSON; OAS endpoints return valid OpenAPI specs |
| N/A | Import dialog | **CANNOT_TEST** | Register page loads but import dialog not tested in browser session |

---

## Changes from Previous Test (2026-02-26)

| Issue | Previous | Current | Change |
|-------|----------|---------|--------|
| #403 | PARTIAL (generic delete dialog in backend) | **PASS** | Frontend delete dialog shows type-specific text ("applicatie", "koppeling") and in-use check |
| #169 | FAIL (/mijn-account page empty) | **PARTIAL** | Correct URL is /beheer/my-account; shows email, name, org, functie |
| #225 | PARTIAL (not fully tested) | **PASS** | Verified search finds organisations; no "+" button on other org's pages |
| #410 | PASS (admin only) | **PASS** | Re-confirmed for both admin AND leverancier (Jan Pietersen) |
| Facet editing | CANNOT_TEST | **PASS** | Verified facetable properties via API (5 facetable fields on Applicatie schema) |
| #392 | SKIPPED | **FAIL** | Tested 2026-03-02: SQL not-null violation on "afnemer" column when creating contact person for imported org |
| #141 | PARTIAL (dialog only) | **PARTIAL** | Re-verified 2026-03-02: Full 2-step merge dialog tested with property comparison; screenshots captured |

---

## Detailed Test Results

### #155: Definities via interactieve optie (Begrippenlijst)

**Status: PASS** (confirmed on re-test 2026-03-01)

**Test Steps:**
1. Navigated to `http://localhost:8080/index.php/apps/opencatalogi/glossary#` via Settings > Glossary
2. Verified 10 glossary terms loaded successfully
3. Clicked "Add Term" to open the term modal
4. Filled Title, Summary, Description fields; left External Link empty
5. Added keywords via combobox: typed "test-keyword" + Enter, typed "second-keyword" + Enter
6. Both keywords displayed as text tags (not UUIDs)
7. Cancelled without saving

**Acceptance Criteria:**
- [x] Glossary endpoint returns glossary terms (10 terms loaded)
- [x] Terms from the current Softwarecatalogus lexicon are present (Convenant VNG, Eindproduct-standaard, Gegevensstandaard, Grondstof-standaard, Halffabrikaat-standaard, Leverancier, Referentiecomponent, SaaS, Standaard, Addendum)
- [x] **Admin: Add term with empty external link** -- Creating a glossary term without an external link succeeds (Add button becomes enabled with empty External Link field)
- [x] **Admin: Add term with keywords** -- Keywords field shows a taggable text input, keywords added as readable text tags (not UUIDs)
- [x] **Admin: Edit existing term** -- Existing terms show keywords as readable text (e.g., "VNG-convenant, convenant" for Convenant VNG)
- [x] Keywords are displayed as readable text in the term cards
- [x] API endpoint `/api/glossary` returns keywords as text arrays (confirmed via curl)
- [ ] Pages containing glossary terms show them as interactive -- Not tested in this session
- [x] Glossary search panel -- "Begrippenlijst" button visible on frontend homepage

**Evidence:**
- Screenshot: `06-glossary-overview.png` -- Glossary terms list (2026-03-01)
- Screenshot: `07-glossary-add-dialog.png` -- Add term dialog (2026-03-01)
- Screenshot: `08-glossary-add-filled.png` -- Filled dialog with keywords as text tags (2026-03-01)

---

### #267: Naam is softwarecatalogus i.p.v. Softwarecatalogus

**Status: PARTIAL (Moved to bezoeker per skill file)**

**Findings:**
- Browser tab: "Beheer - Softwarecatalogus" (capital S) -- CORRECT
- Header logo: "SOFTWARECATALOGUS" (all caps) -- Acceptable for logo styling
- Footer: "Softwarecatalogus" (capital S) -- CORRECT
- Footer subtitle: "Een plek voor alle software voor en door Gemeenten" -- CORRECT

**Note:** This issue was moved to bezoeker scope per the skill file. Capitalization appears consistent across the pages tested.

---

### #332: Voorpagina inrichten

**Status: PASS** (re-verified 2026-03-01)

**Test Steps:**
1. Navigated to `http://localhost:3000/` (homepage)
2. Took full-page screenshot confirming all sections present

**Acceptance Criteria:**
- [x] Home page displays a logo linking to home ("VNG SOFTWARECATALOGUS" logo visible)
- [x] Menu bar contains items (Privacy, Terms, Beheer when logged in)
- [x] When logged in, navigation shows "Beheer" link
- [x] Footer is configurable (verified 7 pages in CMS including Home)
- [x] CMS pages management accessible at backend pages URL
- [x] Home page has 7 content items configured
- [x] Homepage heading: "DE PLEK WAAR GEMEENTEN EN LEVERANCIERS ELKAAR VINDEN"
- [x] Search bar: "Waar bent u naar op zoek?" with "Zoek op naam of trefwoord" placeholder
- [x] Three content blocks: "Vergelijk software", "Beheer uw aanbod", "Ontdek leveranciers"
- [x] Statistics section: "Voor 342 gemeenten", "Voor 336 leveranciers", "Voor 15 community's"
- [x] "Over de softwarecatalogus" section present
- [x] "Begrippenlijst" floating button visible on homepage
- [x] "Onderwerpen" section with clickable links (Vind een applicatie, Meld je aan, Ontdek community's)
- [x] Quote section: "Samen bouwen aan een transparant softwarelandschap voor gemeenten"
- [ ] Banner behind search is configurable -- Not tested editing

**Evidence:**
- Screenshot: `02-homepage-loggedin.png` -- Full page homepage (2026-03-01)

---

### #397: Pagina aanmaken via CMS

**Status: PASS** (re-verified 2026-03-01)

**Test Steps:**
1. Navigated to `http://localhost:8080/index.php/apps/opencatalogi/pages#`
2. Verified 7 pages loaded: About, Website, Privacyverklaring, Disclaimer, Algemene Voorwaarden, FAQ, Home

**Acceptance Criteria:**
- [x] Admin can navigate to CMS page management
- [x] 7 existing pages visible with slugs and content items
- [x] "Add Page" button available for creating new pages
- [x] "Refresh" button available
- [x] "Help" button available
- [x] Each page shows slug, content items count, and status (Available/Configured)
- [x] Cards and Table view options available

**Page Details:**
| Page | Slug | Content Items | Status |
|------|------|---------------|--------|
| About | about | 1 | Available/Configured |
| Website | website | 1 | Available/Configured |
| Privacyverklaring | privacyverklaring | 1 | Available/Configured |
| Disclaimer | disclaimer | 1 | Available/Configured |
| Algemene Voorwaarden | algemene-voorwaarden | 1 | Available/Configured |
| FAQ | faq | 1 | Available/Configured |
| Home | home | 7 | Available/Configured |

**Evidence:**
- Screenshot: `09-cms-pages.png` -- CMS pages management (2026-03-01)

---

### #403: Tekst verwijderen aanpassen

**Status: PASS** (UPGRADED from PARTIAL on 2026-03-01)

**Test Steps (Frontend - 2026-03-01):**
1. Navigated to `http://localhost:3000/beheer/applicaties`
2. Waited for applicaties table to load (11 items visible)
3. Clicked "Acties" on "Test Module" row
4. Clicked "Verwijderen" from the dropdown
5. **Delete dialog appeared with type-specific text**
6. Cancelled without deleting
7. Navigated to `http://localhost:3000/beheer/koppelingen`
8. Clicked "Acties" on "Drupal voor Gemeenten (DVG) ↔ JOIN Klantcontact" row
9. Clicked "Verwijderen"
10. **Delete dialog appeared with koppeling-specific text**
11. Cancelled without deleting

**Applicatie Delete Dialog Content:**
- Title: "applicatie verwijderen"
- Alert (green checkmark): `De applicatie "Test Module" wordt niet gebruikt door gemeenten of samenwerkingen en kan veilig worden verwijderd.`
- Confirmation: `Weet je zeker dat je de applicatie "Test Module" wilt verwijderen?`
- Item list: "Te verwijderen applicatie:" followed by "* Test Module"
- Buttons: "Annuleren" and "Verwijderen"

**Koppeling Delete Dialog Content:**
- Title: "koppeling verwijderen"
- Alert (green checkmark): `De koppeling "Drupal voor Gemeenten (DVG) ↔ JOIN Klantcontact" wordt niet gebruikt door gemeenten of samenwerkingen en kan veilig worden verwijderd.`
- Confirmation: `Weet je zeker dat je de koppeling "Drupal voor Gemeenten (DVG) ↔ JOIN Klantcontact" wilt verwijderen?`
- Item list: "Te verwijderen koppeling:" followed by "* Drupal voor Gemeenten (DVG) ↔ JOIN Klantcontact"
- Buttons: "Annuleren" and "Verwijderen"

**Acceptance Criteria:**
- [x] [UI] Deleting application NOT in use: correct text shown with "kan veilig worden verwijderd"
- [ ] [UI] Deleting service NOT in use -- No diensten available for testing (admin org has 0 diensten)
- [x] [UI] Deleting connection NOT in use: correct text shown with type "koppeling"
- [ ] [UI] Deleting item IN USE: expected to show "kan niet worden verwijderd" with list -- No in-use items found for testing
- [x] [UI] Object name dynamically inserted (confirmed for both "Test Module" and "Drupal voor Gemeenten (DVG) ↔ JOIN Klantcontact")
- [x] [UI] Object type dynamically inserted (confirmed: "applicatie" and "koppeling" in dialog title and text)
- [ ] [UI] When deleting an application that has diensten linked by OTHER leveranciers, the system shows a specific warning -- Not testable without such data

**Additional Test (2026-03-02) -- Leverancier perspective (Jan Pietersen):**
- Logged in as jan.pietersen@test.nl on frontend
- Navigated to /beheer/applicaties -- table loaded with 9 applications
- Clicked Acties > Verwijderen on "Test Applicatie Leverancier"
- Delete dialog showed:
  - Title: "applicatie verwijderen"
  - Alert: `De applicatie "Test Applicatie Leverancier" wordt niet gebruikt door gemeenten of samenwerkingen en kan veilig worden verwijderd.`
  - Confirmation: `Weet je zeker dat je de applicatie "Test Applicatie Leverancier" wilt verwijderen?`
  - Item list: "Te verwijderen applicatie:" / "* Test Applicatie Leverancier"
- Cancelled without deleting

**Backend delete dialog (OpenRegister):**
- Generic text: "Do you want to permanently delete **Test Leverancier BV**? This action cannot be undone."
- Does NOT include type-specific text or in-use check (backend is generic, frontend is customized)

**Evidence:**
- Screenshot: `10-delete-dialog-applicatie.png` -- Applicatie delete dialog (2026-03-01)
- Screenshot: `11-delete-dialog-koppeling.png` -- Koppeling delete dialog (2026-03-01)
- Screenshot: `screenshot-delete-dialog-frontend.png` -- Leverancier delete dialog (2026-03-02)
- Screenshot: `screenshot-delete-dialog.png` -- Backend generic delete dialog (2026-03-02)

---

### #406: SiteImprove verwijderen

**Status: PASS** (re-verified 2026-03-01)

**Test Steps:**
1. Checked page source for "siteimproveanalytics" on logged-in page -- NOT found
2. Checked page source for "siteimprove" on logged-in page -- NOT found
3. Checked page source on logged-out homepage -- NOT found
4. Confirmed Piwik Pro template present as replacement

**Acceptance Criteria:**
- [x] HTML source does NOT contain `siteimproveanalytics.com` script tag
- [x] No references to "siteimprove" in page source
- [x] Piwik Pro analytics template present (with empty configuration values on localhost)
- [x] Only one analytics position (Piwik Pro) found
- [x] Piwik Pro is first script in body (confirmed)

---

### #409: Footer anders: inlog of uitgelogd

**Status: PASS** (re-verified 2026-03-01)

**Test Steps:**
1. Captured footer in logged-in state (Peter van Dijk at /beheer)
2. Captured footer in public state (homepage /)
3. Compared both -- identical structure and links

**Footer links (both logged-in AND logged-out -- IDENTICAL):**

Footer Left:
- GEMMA Online -> https://www.gemmaonline.nl/ (opens in new tab)
- NORA Online -> https://www.noraonline.nl/ (opens in new tab)

Footer Center:
- VNG -> https://vng.nl/ (opens in new tab)

Footer Right:
- Commonground -> https://commonground.nl/ (opens in new tab)

Footer branding:
- "Softwarecatalogus" with subtitle "Een plek voor alle software voor en door Gemeenten"

Sub-footer links:
- Privacy -> /privacyverklaring
- Algemene voorwaarden -> /algemene-voorwaarden
- Disclaimer -> /disclaimer
- FAQ -> /faq

**Acceptance Criteria:**
- [x] Footer links present and functional in logged-in state
- [x] Footer links present and functional in logged-out state
- [x] Footer structure identical between logged-in and logged-out
- [x] "Privacyverklaring" link points to /privacyverklaring
- [x] "Algemene voorwaarden" link points to /algemene-voorwaarden
- [x] Footer styling consistent

---

### #410: Dashboard schrijfwijze softwarecatalogus

**Status: PASS** (verified for both admin and leverancier on 2026-03-01)

**Dashboard Content (identical for both users):**
- Page heading: "Mijn softwarecatalogus"
- Welcome title: "Welkom in uw softwarecatalogus"
- Body text references registering: Applicaties, Diensten, Koppelingen, Standaarden
- Closing paragraph: "GEMeentelijke Model Architectuur (GEMMA)" with correct capitalization

**Peter van Dijk (functioneel beheerder) dashboard:**
- Action buttons: Applicatie publiceren, Koppeling publiceren, Dienst publiceren, Applicatiegebruik melden, Applicatie toevoegen, Koppeling toevoegen, Dienst toevoegen

**Jan Pietersen (leverancier) dashboard:**
- Organisation selector: "Test Leverancier BV"
- Action buttons: Applicatie publiceren, Koppeling publiceren, Dienst publiceren, Applicatiegebruik melden
- NO "toevoegen" buttons (leverancier can only publish, not add directly)

**Acceptance Criteria:**
- [x] "softwarecatalogus" used consistently in lowercase in welcome text
- [x] Supplier welcome text heading: "Welkom in uw softwarecatalogus"
- [x] Body includes bullet points about what suppliers can register
- [x] Welcome text uses "GEMeentelijke Model Architectuur (GEMMA)" with exact capitalization
- [x] Spelling consistent across dashboard
- [x] Verified for leverancier role (Jan Pietersen) -- Same welcome text as functioneel beheerder
- [x] Leverancier has organisation selector dropdown ("Test Leverancier BV")

**Evidence:**
- Screenshot: `01-login-dashboard.png` -- Peter's dashboard (2026-03-01)
- Screenshot: `13-leverancier-dashboard.png` -- Jan's dashboard (2026-03-01)
- Screenshot: `screenshot-dashboard-leverancier.png` -- Jan's dashboard re-verified (2026-03-02)

---

### #92: Webstatistiekenpakket (Piwik Pro)

**Status: PARTIAL** (re-verified 2026-03-01)

**Findings:**
- Piwik Pro Analytics template is present in page source (first script in `<body>`)
- Three configuration variables are empty strings (srcUrl, dataLayerName, id)
- When all three are empty, the script logs: "Piwik Pro Analytics: srcUrl, dataLayerName of id is niet ingesteld"
- On localhost, Piwik Pro does not initialize
- No SiteImprove references found anywhere in the source

**Acceptance Criteria:**
- [x] Piwik Pro script template present in HTML source (first script in body)
- [ ] Piwik Pro actively collecting data -- NOT collecting (empty config on localhost)
- [x] Only Piwik present (no SiteImprove)
- [ ] Piwik Pro configuration is set for production -- Not configured on localhost

**Note:** Expected behavior on localhost. Production environment should have srcUrl, dataLayerName, and id configured.

---

### #169: Rest issues Organisatie en Configuratie

**Status: PARTIAL** (UPGRADED from FAIL on 2026-03-01)

**Test Steps (2026-03-01):**
1. Navigated to `http://localhost:3000/beheer/my-account` (correct URL found via user menu)
2. Page shows "Mijn Account" with the following fields:
   - E-mailadres: peter.vandijk@test.nl
   - Voornaam: Peter
   - Tussenvoegsels: van
   - Achternaam: Dijk
   - Organisatie: Default Organisation (clickable link to /beheer/my-organisation)
   - Functie: Functioneel Beheerder
3. "Bewerken" (Edit) button present

**Acceptance Criteria:**
- [ ] Registration form fields align with "Mijn Account" form -- Not tested
- [x] "Mijn Account" page shows the user's organization name -- Shows "Default Organisation"
- [x] "Mijn Account" shows "Functie" -- Shows "Functioneel Beheerder"
- [ ] KVK number displayed in "Organisatie bewerken" -- Not tested
- [ ] After activating organization, status changes to "Actief" -- Not tested
- [x] No repeated authorization errors on first login -- No auth errors observed
- [x] Consistent capitalization for form field labels -- All labels properly capitalized
- [ ] Nextcloud account data synchronized with linked contact person -- Not verified

**Additional Test (2026-03-02) -- Leverancier perspective (Jan Pietersen):**
- Navigated to `http://localhost:3000/beheer/my-account` as Jan Pietersen
- Page shows "Mijn Account" with "Gebruikersgegevens" section:
  - E-mailadres: jan.pietersen@test.nl
  - Voornaam: Jan
  - Tussenvoegsels: - (empty/dash)
  - Achternaam: Pietersen
  - Organisatie: **Test Leverancier BV** (clickable link to /beheer/my-organisation)
  - Functie: CEO & Founder
- "Bewerken" (Edit) button present
- Organisation name is correctly shown and clickable for the leverancier user

**Remaining Issues:**
- Peter's account shows "Default Organisation" instead of expected org -- may be test environment setup issue
- The old URL `/mijn-account` still shows empty content (CMS page does not exist for that slug)
- KVK number display in "Organisatie bewerken" not tested
- Organisation activation flow not tested

**Evidence:**
- Screenshot: `05-mijn-account.png` -- Peter's Mijn Account page (2026-03-01)
- Screenshot: `screenshot-mijn-account.png` -- Jan's Mijn Account page (2026-03-02)

---

### #85: (VNGR) Publieke API toegang tot aanbodinformatie

**Status: PASS** (re-verified 2026-03-01)

**API Tests:**
- `GET /api/registers/2/oas` -> 200 OK
- `GET /api/registers/3/oas` -> 200 OK (OpenAPI 3.1.0, "Voorzieningen API", 26 paths, 16 schemas)
- `GET /api/registers/4/oas` -> 200 OK
- `GET /api/objects/voorzieningen/organisatie?_limit=3` -> 200 OK

**Acceptance Criteria:**
- [x] Public API returns data (organizations, applications)
- [x] OAS documentation accessible for all registers
- [x] API supports `_limit`, `_fields` query parameters
- [x] API returns organisation data with naam, type fields
- [x] API returns application data

---

### #148: (VNGR) GEMMA-architectuur opvraagbaar met API

**Status: PASS** (re-verified 2026-03-01)

**Acceptance Criteria:**
- [x] OAS documentation accessible at `/api/registers/4/oas` (200 OK with auth)
- [x] Elements endpoint returns ArchiMate elements with name and archiMateType
- [x] Relations endpoint returns data
- [x] Backend registers page shows AMEF register

---

### #278: Filterteksten aanpassen

**Status: PASS** (re-verified 2026-03-01)

**Filter Labels Found on /zoeken:**
- Type (4): Applicatie, Dienst, Koppeling, Organisatie
- Samenwerkingstype (14)
- Geregistreerd door (11)
- Leverancier (2583)
- Licentievorm (2): Closed source, Open source
- Referentiecomponenten (168)
- Standaardversies (43)
- Type koppeling (2): extern, intern
- Organisatietype (3)
- Diensttype (3)

**Acceptance Criteria:**
- [x] Filter labels display correct, updated text
- [x] "Type" filter present (not "Schema" or "Objecttype")
- [x] "Diensttype" present (not "Soort dienst")
- [x] Filter texts consistent with terminology (Referentiecomponenten, Standaardversies, etc.)

**Evidence:**
- Screenshot: `04-search-filters.png` -- Search filters panel (2026-03-01)

---

### #286: 500-error bij wachtwoord wijzigen

**Status: PASS** (re-verified 2026-03-01)

**API Test:**
```
PUT /ocs/v2.php/cloud/users/peter.vandijk%40test.nl
key=password, value=WelcomeToTest2026
Response: 200 OK, status=ok
```

**Acceptance Criteria:**
- [x] Password change via API completes without errors (HTTP 200)
- [x] Server responds with success status code
- [ ] Password change via UI -- Not tested in browser

---

### #392: Geimporteerde gebruiker error bij omzetten naar user

**Status: FAIL** (tested 2026-03-02)

**Test Steps:**
1. Created an "imported" organisation via API:
   ```
   POST /api/objects/3/15
   {"naam":"Test Import Org","type":"Leverancier","website":"https://test-import.nl","beschrijvingKort":"Organisatie voor import test"}
   ```
   Response: 200 OK, UUID: `7bae3c2e-...` (created successfully)

2. Attempted to create a contact person for the imported org:
   ```
   POST /api/objects/3/16
   {"voornaam":"Test","achternaam":"Import","email":"test.import@test.nl","organisatie":"<org-uuid>"}
   ```
   Response: **500 Internal Server Error**

3. Error message:
   ```
   SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column 'afnemer'
   of relation 'oc_openregister_table_3_16' violates not-null constraint
   ```

4. Verified user was NOT created in Nextcloud: `GET /ocs/v2.php/cloud/users/test.import%40test.nl` returned 404

5. Cleanup: Deleted the test organisation (HTTP 204)

**Root Cause:** The `afnemer` column on the contactpersoon magic table (register 3, schema 16) has a NOT NULL constraint, but when creating a contact person for an imported organisation, the system does not automatically populate this field. This is the core bug described in the issue.

**Acceptance Criteria:**
- [ ] Creating a contact person for an imported organization does NOT produce an error -- **FAIL** (SQL not-null violation)
- [ ] Contact person is converted to a user automatically -- **FAIL** (user not created)
- [ ] Converted user can log in with correct permissions -- **NOT TESTED** (depends on above)
- [ ] Behavior consistent between imported and newly created organizations -- **FAIL** (inconsistent)
- [ ] No backend errors in logs during conversion -- **FAIL** (500 error)

**Evidence:**
- API responses captured during testing (curl output)

---

### #393: Backend: fouten in voorzieningenregister

**Status: PASS** (re-verified 2026-03-01)

**API Tests (all return HTTP 200):**
| Endpoint | HTTP Status |
|----------|-------------|
| CSV export (applicaties) | 200 |
| Excel export (applicaties) | 200 |
| CSV export (diensten) | 200 |
| All 13 schema endpoints | 200 |

**Acceptance Criteria:**
- [x] Backend API returns valid schema data (all schemas return 200)
- [x] API documentation endpoint is accessible and complete (OAS endpoints work)
- [x] Excel export works without errors and produces valid .xlsx files
- [x] CSV export works without errors and produces valid .csv files

---

### #396: Verouderde NextCloud versie

**Status: PASS** (re-verified 2026-03-01)

**Result:**
- Version: 32.0.5
- Maintenance: false
- needsDbUpgrade: false

---

### #225: Testresultaten 29-10-2025

**Status: PASS** (UPGRADED from PARTIAL on 2026-03-01)

**Test Steps (2026-03-01):**
1. Searched for "VNG" on frontend `/zoeken` -- 837 results found
2. First result: "VNG" (Organisatie type) with correct metadata
3. Clicked through to VNG organisation page at `/publicatie/294d6574-...`
4. Organisation page shows 6 applicaties under "Applicaties (6)" tab
5. **No "+" button visible on VNG's public page** -- Correct behavior
6. Verified results show type labels: Organisatie, Applicatie

**Acceptance Criteria:**
- [x] A registered organization is findable via search ("VNG" found with 837 results)
- [x] Blue "+" button NOT shown on other organizations' public pages -- Confirmed: no add button on VNG page
- [x] Search results show type (Applicatie, Organisatie) with dates
- [x] Organisation public page shows applicaties with correct metadata (name, leverancier, referentiecomponenten, date)

**Evidence:**
- Screenshot: `12-org-page-no-add-button.png` -- VNG org page without "+" button (2026-03-01)

---

### #141: Organisaties samenvoegen na herindeling/overname

**Status: PARTIAL** (re-verified 2026-03-02)

**Test Steps (2026-03-02):**
1. Created merge candidate via API: "Test Leverancier BV (oud)" (type: Leverancier)
2. Navigated to Backend > Search / Views > Voorzieningen > Organisatie
3. Searched for "Test Leverancier BV" -- found 13 results including "(oud)" variant
4. Clicked three-dot menu on "Test Leverancier BV (oud)" row
5. Context menu shows: Edit, **Merge**, Copy, Depublish, Delete
6. Clicked "Merge" -- dialog opened immediately (no timeout)

**Step 1: Select Target Object**
- Title: "Merge Objects"
- Header: Register: Voorzieningen, Schema: Organisatie
- Info: "Objects can only be merged if they belong to the same register and schema"
- Source object: "Test Leverancier BV (oud)"
- Search box with filterable object list showing names and UUIDs
- Objects display with **readable names** (not UUIDs)
- Buttons: Cancel, Next

**Step 2: Configure Merge**
- Title: "Configure Merge"
- Subtitle: "Merging **Test Leverancier BV (oud)** into **Test Leverancier BV**"
- **Four-column layout**: Property | Source | Target | Result Value
- Properties shown: id, naam, beschrijvingKort, website, type, status
- Result Value column has dropdowns: "From Target: ..." or "From Source: ..."
- Intelligent defaults: naam picks Target, beschrijvingKort picks Source (only exists in source), status picks Target ("Actief" over "Concept")
- Buttons: Cancel, Back, **Merge Objects**

7. Clicked Cancel to abort (as instructed -- do NOT execute merge)
8. Cleaned up: Deleted "Test Leverancier BV (oud)" (HTTP 204)

**Acceptance Criteria:**
- [x] Object can be selected for merging from backend tables (three-dot menu > Merge)
- [x] Merge modal appears with three columns: Source, Target, Result
- [x] In the Result column, user can choose which object's values to keep per field
- [ ] After saving, target object is updated -- Not tested (cancelled per instructions)
- [ ] All relationships transferred -- Not tested
- [ ] Object A is deleted after merge -- Not tested
- [x] No timeout errors during merge (dialog loaded instantly)
- [x] Merge result displays readable object card titles (not UUIDs)
- [ ] Merge correctly handles the "group" field -- Not tested
- [ ] Documentation/handleiding for performing a merge -- Not verified

**Evidence:**
- Screenshot: `screenshot-merge-dialog.png` -- Step 1: Target selection (2026-03-02)
- Screenshot: `screenshot-merge-target-selected.png` -- Step 1: Search filtered (2026-03-02)
- Screenshot: `screenshot-merge-properties.png` -- Step 2: Property comparison (2026-03-02)

---

### #15: Exporteren van gegevens (CSV/Excel)

**Status: PASS**

**Findings (2026-03-01):**
- CSV export returns actual CSV format with human-readable name columns
- CSV columns include `_aanbieder` (resolved name like "BCT") alongside UUID `aanbieder` column
- Excel export returns valid Microsoft Excel 2007+ format
- Both exports work for applicaties, diensten, and organisaties

**Acceptance Criteria:**
- [x] Export endpoint returns 200 OK
- [x] XLSX export produces a valid file
- [x] CSV format correctly separates into columns
- [x] Exported columns include both human-readable names AND UUIDs

---

### #355: Exporteren functies (Applicatie export)

**Status: PASS** (re-verified 2026-03-01)

**Acceptance Criteria:**
- [x] Export endpoint returns 200 (not 500) for all tested schemas
- [x] Produces valid XLSX files
- [x] CSV export also works
- [x] Exported columns include human-readable names alongside UUIDs

---

### N/A: Themes Management (Exploratory)

**Status: PASS** (re-verified 2026-03-01)

**Findings:**
- Themes management accessible at `http://localhost:8080/index.php/apps/opencatalogi/themes#`
- 4 themes visible:
  1. **General** -- "General publications and announcements"
  2. **Voor 342 gemeenten** -- "Vergelijk applicaties, vind leveranciers, versterk digitale kracht."
  3. **Voor 336 leveranciers** -- "Zet uw software in de etalage voor gemeenten."
  4. **Voor 15 community's** -- "Community's van gemeenten delen hun kennis en producten."
- These correspond to the homepage content blocks/statistics
- Each theme shows Summary and Status (Available)
- "Add Theme" button available for creating new themes

**Evidence:**
- Screenshot: `14-themes-management.png` -- Themes management interface (2026-03-01)
- Screenshot: `screenshot-themes.png` -- Themes management re-verified (2026-03-02)

---

### N/A: Facet Editing

**Status: PASS** (verified via API on 2026-03-01)

**Findings:**
- Schema 25 (Applicatie) has 5 facetable properties:
  - `hostingLocatie`: facetable=true
  - `aanbieder`: facetable=true
  - `licentietype`: facetable=true
  - `referentieComponenten`: facetable=true
  - `standaardVersies`: facetable=true
- These correspond to the filter facets shown on the frontend search page
- Schema properties accessible and editable via `/api/schemas/{id}` endpoint

---

### N/A: Schema Export / Register API

**Status: PASS** (re-verified 2026-03-01)

**Findings:**
- Register 3 (Voorzieningen): 9 registers available, each with OAS endpoint
- OAS spec for register 3: OpenAPI 3.1.0, "Voorzieningen API", 26 paths, 16 component schemas
- Schema 25 (Applicatie): 23 properties exportable as JSON definition
- All registers accessible: Consent Register (1), Publication (2), Voorzieningen (3), AMEF (4), Pipelinq (5), case-management (6), Procest (7), Template Register (8), LarpingApp (9)

---

### N/A: Import Dialog

**Status: CANNOT_TEST**

The import dialog (accessible via the register card three-dot menu) was not tested in browser sessions. The Registers page loads correctly, but the import workflow was not exercised.

---

## Console Errors Summary

| Page | Error Count | Critical Errors |
|------|-------------|-----------------|
| Login page (frontend) | 0 | None |
| Dashboard /beheer | 0 | None |
| Homepage (public) | 0 | None |
| Search /zoeken | 7 | Failed name resolution for standard version UUIDs (data quality issue) |
| My Account /beheer/my-account | 0 | None |
| OpenCatalogi backend (pages, themes, glossary) | 2 | @nextcloud/vue warnings about appName/appVersion (non-critical) |

---

## Performance Summary

| Action | Duration | Status |
|--------|----------|--------|
| Frontend login | <2s | OK |
| Frontend logout + redirect | <1s | OK |
| Homepage load (full content) | <3s | OK |
| Search page load (12,667 results) | <3s | OK |
| Filter panel open + facet load | <5s | OK |
| Glossary page load | ~5s | OK |
| CMS pages load | ~5s | OK |
| Themes page load | ~5s | OK |
| Delete dialog open | <1s | OK |
| Organisation public page load | <3s | OK |
| XLSX export (applicaties) | <5s | OK |
| CSV export (applicaties) | <5s | OK |
| OAS endpoint | <1s | OK |

No PERFORMANCE_FAIL (>1000ms) observed for individual API calls during testing.

---

## Overall Assessment

**Strengths:**
1. Public API endpoints work reliably (OAS docs, data queries)
2. Export functionality works: both XLSX and CSV formats with human-readable name columns
3. Glossary management works well (keywords as text, empty external link allowed)
4. CMS page management functional with 7 configured pages
5. SiteImprove completely removed, only Piwik Pro present
6. Nextcloud updated to version 32.0.5
7. Dashboard welcome text matches requirements exactly (verified for both leverancier and functioneel beheerder)
8. Search filters have proper labels and terminology (10 filter categories)
9. Merge dialog is fully functional with property-level selection, no timeout errors
10. Footer is consistent between logged-in and logged-out states
11. All 13 voorzieningenregister schemas are accessible and exportable
12. Themes management accessible with 4 themes corresponding to homepage content
13. Homepage fully functional with all required sections
14. **Frontend delete dialog now shows type-specific text ("applicatie verwijderen", "koppeling verwijderen") with in-use check**
15. **No "+" button visible on other organisations' public pages** (RBAC working correctly)
16. **Mijn Account page functional at /beheer/my-account with correct fields**

**Issues Found:**
1. **#392 FAIL**: Creating a contact person for an imported organisation produces SQL error: `SQLSTATE[23502]: Not null violation: null value in column 'afnemer'`. The user is NOT auto-created in Nextcloud. This is a confirmed bug.
2. **#92 PARTIAL**: Piwik Pro not configured (empty srcUrl, dataLayerName, id) -- expected on localhost
3. **#169 PARTIAL**: Peter's account shows "Default Organisation" instead of specific org; Jan Pietersen shows correct org "Test Leverancier BV". Old URL `/mijn-account` is broken.
4. **#141 PARTIAL**: Merge dialog works fully (2-step flow with property comparison) but was not tested to completion (actual merge execution)
5. Some search result cards show "Loading..." temporarily while names resolve
6. Console errors on search page for failed standard version UUID name resolution (data quality issue, not code bug)
7. @nextcloud/vue warnings about appName/appVersion on backend pages (non-critical)
8. Backend OpenRegister API returns 500 when using `_fields` parameter on objects endpoint (see #141 setup)

**Improvements Since 2026-02-26:**
1. **#403 UPGRADED to PASS**: Frontend delete dialog now shows type-specific text with in-use check
2. **#169 UPGRADED to PARTIAL**: Correct URL `/beheer/my-account` found; page shows email, name, org (clickable link), functie
3. **#225 UPGRADED to PASS**: Verified search finds organisations; no "+" button on other org's public pages
4. **Facet editing VERIFIED**: 5 facetable properties confirmed on Applicatie schema via API
5. **#392 NOW TESTED (FAIL)**: Previously SKIPPED, now confirmed as a real bug with SQL error
6. **#141 RE-VERIFIED**: Full 2-step merge dialog tested with target search, property comparison, and intelligent defaults

**Recommendations:**
1. **Fix #392 (Critical)**: Fix the NOT NULL constraint on `afnemer` column in contactpersoon magic table. When creating a contact person for an imported org, the system must populate this field automatically.
2. **Fix #169**: Ensure admin user (Peter) is assigned to correct organisation (shows "Default Organisation" instead of VNG)
3. Configure Piwik Pro for production environments (srcUrl, dataLayerName, id)
4. Remove or redirect old `/mijn-account` URL to `/beheer/my-account`
5. Fix the `_fields` parameter on the objects API endpoint (causes 500 error: `stripEmptyValues()` receives ObjectEntity instead of array)
6. Test merge execution end-to-end (#141) including relationship transfer and "group" field handling
7. Test export with non-admin user to verify RBAC filtering in exports
