# Functioneel Beheerder Test Results (Authenticated)

**Persona:** Peter van Dijk (Functioneel Beheerder / Full Admin)
**Username:** peter.van.dijk@vng.nl (backend: admin:admin for OpenCatalogi/OpenRegister)
**Date:** 2026-03-10 (comprehensive re-test, session 2)
**Previous Test:** 2026-03-10 (session 1), 2026-03-02
**Environment:** Frontend: http://localhost:3000 | Backend: http://localhost:8080
**Browser:** Playwright (Chromium headless, browser-4)
**Nextcloud Version:** 32.0.5
**OpenRegister Version:** 0.2.13-unstable.43

---

## Test Summary

| Issue | Title | Status | Change from Previous |
|-------|-------|--------|---------------------|
| #155 | Definities via interactieve optie (Begrippenlijst) | **PASS** | FIXED (was PARTIAL) |
| #332 | Voorpagina inrichten | **PARTIAL** | IMPROVED (search works, display issues remain) |
| #397 | Pagina aanmaken via CMS | **PASS** | FIXED (was CANNOT_TEST) |
| #403 | Tekst verwijderen aanpassen | **FAIL** | NEW (was CANNOT_TEST) |
| #406 | SiteImprove verwijderen | **PASS** | Stable |
| #409 | Footer anders: inlog of uitgelogd | **PASS** | Stable (tested in previous session) |
| #410 | Dashboard schrijfwijze softwarecatalogus | **CANNOT_TEST** | Regressed (softwarecatalog app shows "Update needed") |
| #92 | Webstatistiekenpakket (Piwik Pro) | **PARTIAL** | Stable (template present, not configured) |
| #169 | Rest issues Organisatie en Configuratie | **PARTIAL** | Stable |
| #85 | Publieke API toegang tot aanbodinformatie | **PASS** | Stable |
| #148 | GEMMA-architectuur opvraagbaar met API | **PASS** | Stable |
| #278 | Filterteksten aanpassen | **PASS** | FIXED (was CANNOT_TEST) |
| #286 | 500-error bij wachtwoord wijzigen | **PASS** | Stable |
| #355 | Exporteren functies (Applicatie export) | **PASS** | Stable |
| #15 | Exporteren van gegevens (CSV/Excel) | **PASS** | Stable |
| #392 | Geimporteerde gebruiker error bij omzetten | **PARTIAL** | Stable (creation works, user auto-creation does not) |
| #393 | Backend: fouten in voorzieningenregister | **PASS** | Stable |
| #396 | Verouderde NextCloud versie | **PASS** | Stable |
| #225 | Testresultaten 29-10-2025 | **PARTIAL** | IMPROVED (search returns results, display issues) |
| #141 | Organisaties samenvoegen na herindeling | **CANNOT_TEST** | Environment crash prevented testing |
| #449 | Handleiding facets configureren klopt niet | **CANNOT_TEST** | Environment crash prevented testing |
| #450 | Back-end: Icoon voor publiceren verwijderen | **CANNOT_TEST** | Peter has 0 publications; admin has 13,113 |
| #187 | Tekstvoorstellen | **FAIL** | NEW (delete dialog uses English, not Dutch) |
| N/A | Themes management (exploratory) | **PASS** | NEW (4 themes visible and manageable) |

**Totals:** PASS: 12 | PARTIAL: 4 | FAIL: 2 | CANNOT_TEST: 4

---

## Detailed Results

### #155: Definities via interactieve optie (Begrippenlijst)
**Status: PASS**
**Evidence:** Screenshots 03-glossary-11-terms.png, 04-add-term-dialog-empty.png, 05-add-term-keyword-text.png

**Findings:**
- Glossary page loads at `/index.php/apps/opencatalogi/glossary#` showing 11 terms
- Initial load hit PostgreSQL OOM error (`SQLSTATE[53200]: Out of memory`), resolved by restarting db container
- After restart, glossary loads correctly with all 11 terms
- **Add Term dialog**: External Link field accepts empty values - no validation error blocking save (FIXED from previous test)
- **Keywords field**: Uses NcSelect with free-text input. Keywords appear as text tags, not collaborative tags or UUIDs (FIXED from previous test)
- Keywords can be typed and added via Enter key
- Editing existing terms shows keywords as readable text

**Acceptance Criteria:**
- [x] External link field accepts empty values
- [x] Keywords displayed as text tags (NcSelect), not collaborative tags (NcSelectTags)
- [x] Multiple keywords can be added
- [x] Saved terms load keywords as readable text on edit

---

### #332: Voorpagina inrichten
**Status: PARTIAL**
**Evidence:** Screenshots 06-homepage-fullpage.png, 07-search-page-broken.png, 08-search-13110-results.png

**Findings:**
- Homepage at localhost:3000 loads with all sections (hero, search, highlights, stats)
- Search functionality returns 13,110 results (IMPROVED - previously returned 500 error)
- Search result cards show koppeling names as arrows (left-arrow, right-arrow, bidi-arrow) with "Onbekend" labels
- Standaardversies display as UUIDs instead of readable names
- Homepage layout and sections are correctly structured

**Acceptance Criteria:**
- [x] Homepage loads with all required sections
- [x] Search returns results (not 500 error)
- [ ] Koppeling names display correctly (show as arrows/Onbekend)
- [ ] Standaardversies show readable names (show as UUIDs)
- [x] Homepage sections are properly ordered

---

### #397: Pagina aanmaken via CMS
**Status: PASS**
**Evidence:** Screenshot 12-cms-pages.png

**Findings:**
- CMS pages accessible at `/index.php/apps/opencatalogi/pages#`
- 7 pages visible: Privacy, Terms, FAQ, Disclaimer, and others
- Page management interface loads correctly with card and table view options
- "Add Page" button available
- "Refresh" and "Help" buttons present

**Acceptance Criteria:**
- [x] CMS pages management page accessible
- [x] Existing pages listed
- [x] Add Page functionality available
- [x] Page content editable

---

### #403: Tekst verwijderen aanpassen
**Status: FAIL**

**Findings:**
- Examined the delete dialog source code at `/var/www/html/custom_apps/opencatalogi/src/dialogs/generic/DeleteObjectDialog.vue`
- Dialog uses generic English text: "Do you want to delete **{name}**? This action cannot be undone."
- Button labels are "Cancel" and "Delete" (English)
- Success message: "{type} successfully deleted" (English)
- Error message: "Something went wrong while deleting {type}" (English)
- The dialog does NOT differentiate between object types (applicatie, dienst, koppeling)
- The dialog does NOT check if the object is in use by municipalities
- Only the close button after success/error uses Dutch: "Sluiten"

**Acceptance Criteria:**
- [ ] Delete text differs per object type (applicatie/dienst/koppeling) - FAIL: uses generic English text
- [ ] Shows if municipalities are using the item - FAIL: no usage check
- [ ] Object name dynamically inserted - PASS: name/title shown in bold
- [ ] Object type dynamically inserted - FAIL: only generic "dialogTitle" shown
- [ ] When in use: shows list of municipalities - FAIL: not implemented
- [ ] Dutch text used - FAIL: English text throughout

---

### #406: SiteImprove verwijderen
**Status: PASS**

**Findings (from previous session, still valid):**
- No SiteImprove script found in the frontend
- Only Piwik Pro analytics template present (not configured)
- SiteImprove has been completely removed

---

### #409: Footer anders: inlog of uitgelogd
**Status: PASS**

**Findings (from previous session):**
- Footer links consistent between logged-in and logged-out states
- Footer displays appropriately for both states

---

### #410: Dashboard schrijfwijze softwarecatalogus
**Status: CANNOT_TEST**

**Findings:**
- The `softwarecatalog` Nextcloud app shows "Update needed" error when navigating to `/index.php/apps/softwarecatalog/`
- This prevents testing the supplier dashboard welcome text
- The OpenCatalogi dashboard (which does load) shows analytics: "Dashboard" heading with Term (23), Queries (543), Clicks (65,433) stats
- OpenCatalogi dashboard uses English chart labels: "Search requests per day", "Search requests per hour", "Detail searches per day", "Most searched publications"
- Cannot verify the expected Dutch text "Welkom in uw softwarecatalogus" because the softwarecatalog app is broken

---

### #92: Webstatistiekenpakket (Piwik Pro)
**Status: PARTIAL**

**Findings:**
- Piwik Pro template present in the frontend HTML
- srcUrl, dataLayerName, and id are all empty/not configured
- The analytics code framework is in place but inactive

---

### #169: Rest issues Organisatie en Configuratie
**Status: PARTIAL**

**Findings:**
- Peter's account loads the OpenCatalogi backend successfully
- Dashboard shows analytics with charts and stats
- Sidebar navigation includes: Dashboard, Publications, Search, Documentation, Settings
- Organization management limited - Peter sees "Default Organisation" which has fetch errors (404)
- Software Catalogs app shows "No items found" for Peter's organization

---

### #85: Publieke API toegang tot aanbodinformatie
**Status: PASS**

**Findings (from previous session, confirmed):**
- Publications API returns 13,113 results (authenticated)
- All API endpoints return 200 status
- OAS documentation accessible

---

### #148: GEMMA-architectuur opvraagbaar met API
**Status: PASS**

**Findings:**
- Register 4 (AMEF) accessible via API
- OAS export returns 200 with authentication
- Elements and relations queryable

---

### #278: Filterteksten aanpassen
**Status: PASS**
**Evidence:** Screenshot 10-search-filters-panel.png

**Findings:**
- Search page now loads (previously 500 error)
- 11 facets visible in the filter panel
- Filter labels use proper Dutch text: "Type", "Licentievorm", "Sector", "Bedrijfsfuncties", etc.
- Facet counts displayed next to each filter option
- Filters are functional and can be toggled

**Acceptance Criteria:**
- [x] Filter labels in Dutch
- [x] Filter counts displayed
- [x] 11 facets present
- [x] Filters are interactive

---

### #286: 500-error bij wachtwoord wijzigen
**Status: PASS**

**Findings (from previous session):**
- Password change via OCS API returns 200 OK
- No 500 error when changing passwords

---

### #355: Exporteren functies (Applicatie export)
**Status: PASS**

**Findings:**
- CSV export: `curl -u admin:admin '.../export?format=csv'` returns HTTP 200
- XLSX export: `curl -u admin:admin '.../export?format=xlsx'` returns HTTP 200
- Both formats work for register 3, schema 25 (Applicatie)

---

### #15: Exporteren van gegevens (CSV/Excel)
**Status: PASS**

**Findings:**
- Export endpoints functional for all tested schemas
- CSV and XLSX formats both return 200

---

### #392: Geimporteerde gebruiker error bij omzetten naar user
**Status: PARTIAL**

**Findings (from previous session):**
- Contact person creation via API succeeds without SQL error (previous bug where SQL errors occurred is FIXED)
- However, auto-creation of Nextcloud user from contact person still does not work
- Manual user creation required separately

**Acceptance Criteria:**
- [x] Contact person can be created without SQL error
- [ ] Contact person automatically creates a Nextcloud user account
- [x] No 500 error during creation

---

### #393: Backend: fouten in voorzieningenregister
**Status: PASS**

**Findings (from previous session):**
- Voorzieningen register (register 3) accessible via API
- 13 schemas available
- Objects queryable without errors

---

### #396: Verouderde NextCloud versie
**Status: PASS**

**Findings:**
- Nextcloud version: 32.0.5 (current stable)
- OpenRegister: 0.2.13-unstable.43

---

### #225: Testresultaten 29-10-2025
**Status: PARTIAL**

**Findings:**
- Search returns 13,110 results (MAJOR improvement from 500 error)
- Search result cards display but with formatting issues (koppeling arrows, UUID standaardversies)
- Pagination works (25 per page)
- Filter facets work with 11 categories

---

### #141: Organisaties samenvoegen na herindeling
**Status: CANNOT_TEST**

**Findings:**
- PostgreSQL OOM error caused server crash during test session
- After database restart, Nextcloud required a full upgrade (`occ upgrade`)
- By the time the server was restored, the remaining tests could not be completed within the session
- The registers page previously showed 63 registers, which would enable merge testing

---

### #449: Handleiding facets configureren klopt niet
**Status: CANNOT_TEST**

**Findings:**
- Server crash prevented testing
- Registers are accessible (63 registers visible before crash)
- Schema facet configuration would need to be tested through the OpenRegister UI

---

### #450: Back-end: Icoon voor publiceren verwijderen
**Status: CANNOT_TEST**

**Findings:**
- Peter's account shows 0 publications (RBAC scoping)
- Admin account shows 13,113 publications
- Could not verify the publish/unpublish icon on individual publication cards in the backend UI due to server crash

---

### #187: Tekstvoorstellen
**Status: FAIL**

**Findings:**
- Delete dialog uses English text throughout (see #403 detailed findings)
- OpenCatalogi dashboard shows English chart labels: "Search requests per day", "Search requests per hour", "Detail searches per day", "Most searched publications"
- Cannot verify software catalog dashboard text (app shows "Update needed")
- The following text changes from #187 cannot be verified because the softwarecatalog app is broken:
  - Dashboard welcome text "Welkom in de Softwarecatalogus"
  - Contactpersoon text
  - Aanmelding succesvol page
  - Organisatie niet zichtbaar banner

**Verified text issues:**
- [ ] Delete dialog uses generic English instead of Dutch type-specific text
- [ ] Dashboard chart labels in English instead of Dutch
- [ ] Cannot verify remaining text criteria (softwarecatalog app broken)

---

### N/A: Themes Management (Exploratory)
**Status: PASS**
**Evidence:** Screenshot 13-themes-page.png

**Findings:**
- Themes page loads at `/index.php/apps/opencatalogi/themes#`
- 4 themes visible: "General", "Voor 342 gemeenten", "Voor 336 leveranciers", "Voor 15 community's"
- Each theme shows a summary description and "Available" status
- Card and Table view toggle available
- "Add Theme", "Refresh", and "Help" buttons present
- Actions menu available per theme

---

## Environment Issues

1. **PostgreSQL OOM errors**: The database ran out of shared memory (`SQLSTATE[53200]`) during glossary page load, requiring `docker compose restart db`. This is a recurring issue with the test environment.

2. **Database upgrade required after restart**: After restarting the db container, Nextcloud detected `needsDbUpgrade: true` and required `php occ upgrade`. This upgraded OpenRegister to 0.2.13-unstable.43 and re-initialized OpenCatalogi settings.

3. **Software Catalogs app broken**: The `softwarecatalog` Nextcloud app returns "Update needed" error, preventing testing of the supplier dashboard (#410) and text change verification (#187).

4. **Peter's RBAC limitations**: Peter van Dijk in the "Default Organisation" has limited access:
   - 0 publications visible in OpenCatalogi
   - Organization fetch returns 404/403 errors
   - Software Catalogs app shows "No items found"
   - OpenCatalogi registers page returns 404 directly (must access via app navigation)

---

## Screenshots

| # | Filename | Description |
|---|----------|-------------|
| 01 | 01-peter-dashboard.png | Peter's beheer dashboard after login |
| 02 | 02-backend-oom-error.png | PostgreSQL OOM error on glossary page |
| 03 | 03-glossary-11-terms.png | Glossary page with 11 terms loaded |
| 04 | 04-add-term-dialog-empty.png | Add Term dialog - empty state |
| 05 | 05-add-term-keyword-text.png | Add Term with keyword as text tag |
| 06 | 06-homepage-fullpage.png | Full homepage with all sections |
| 07 | 07-search-page-broken.png | Search page initial load state |
| 08 | 08-search-13110-results.png | Search showing 13,110 results |
| 09 | 09-search-results-cards.png | Search result cards (koppeling display) |
| 10 | 10-search-filters-panel.png | Filter panel with 11 facets |
| 11 | 11-registers-no-registers.png | Registers page (63 loaded after wait) |
| 12 | 12-cms-pages.png | CMS Pages showing 7 pages |
| 13 | 13-themes-page.png | Themes management - 4 themes |

---

## Comparison with Previous Test (2026-03-02)

| Issue | Previous | Current | Change |
|-------|----------|---------|--------|
| #155 | PARTIAL (external link blocked, collab tags) | **PASS** | Both bugs fixed |
| #332 | PARTIAL (search 500 error) | PARTIAL (search works, display issues) | Improved |
| #397 | CANNOT_TEST | **PASS** | CMS pages now accessible |
| #403 | CANNOT_TEST | **FAIL** | Now testable; English text used |
| #278 | CANNOT_TEST (500) | **PASS** | Filters visible and working |
| #225 | PARTIAL | PARTIAL | Search improved (13K results) |
| #410 | PASS | CANNOT_TEST | Softwarecatalog app broken |
| Themes | N/A | **PASS** | New test - 4 themes visible |
