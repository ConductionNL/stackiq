# Test Results: Functioneel Beheerder (Authenticated)

**Date:** 2026-03-19
**Persona:** Peter van Dijk (peter.vandijk@test.nl)
**Role:** Functioneel beheerder (Full Admin)
**Environment:** Frontend http://localhost:3000, Backend http://localhost:8080
**Browser:** Playwright MCP (browser-4, headless Chromium)

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 12 |
| PARTIAL | 14 |
| FAIL | 2 |
| CANNOT_TEST | 5 |
| CLOSED | 3 |

---

## Issue Results

### #155: Definities via interactieve optie (Begrippenlijst) -- PARTIAL

**Test:** Navigated to backend glossary at `{BACKEND}/index.php/apps/opencatalogi/glossary#`. 6 glossary terms exist (API x2, GEMMA x2, SaaS x2 -- duplicates present).

**Criteria tested:**
- [x] Glossary endpoint returns glossary terms (6 terms)
- [x] Glossary management page loads in backend
- [ ] **FAIL** Empty external link: The "External link" field shows validation error "moet een geldige URL zijn" even when empty, and the "Add" button is disabled. Cannot save a term without an external link.
- [ ] **FAIL** Keywords field: Shows NcSelectTags dropdown with collaborative tags (campaign, counter, email, event, other -- all "(restricted)") instead of free-text taggable input. Keywords are NOT shown as readable text tags.
- [ ] **FAIL** Edit existing term: Cannot verify keyword display on edit since keywords are collaborative tag-based
- [ ] Glossary term detection on pages: Not tested (interactive tooltips)
- [x] "Begrippenlijst" floating button is present on the frontend search page

**Evidence:** Screenshots 02-glossary-overview.png, 03-glossary-add-term-dialog.png, 04-glossary-keywords-dropdown.png

---

### #332: Voorpagina inrichten -- PARTIAL

**Test:** Checked CMS pages at `{BACKEND}/index.php/apps/opencatalogi/pages#`. Only 2 pages exist: "Home" (slug: home, 2 content items) and "About" (slug: about, 1 content item).

**Criteria tested:**
- [x] Homepage displays logo linking to home
- [x] Menu bar contains navigation items (Privacy, Terms, Beheer when logged in)
- [x] Search window present on homepage
- [ ] Missing CMS pages: no privacy, terms, FAQ, disclaimer pages in CMS (though frontend routes /privacy, /terms, /disclaimer, /faq all return 200 -- content may be hardcoded)
- [ ] Quote section, 3 content blocks, text+image section: Not verified as configurable CMS content
- [ ] VNG functional administrators cannot independently edit all home page content -- only 2 CMS pages exist

---

### #397: Pagina aanmaken via CMS -- PARTIAL

**Test:** CMS page management loads at `{BACKEND}/index.php/apps/opencatalogi/pages#`. "Add Page" button is present and functional.

**Criteria tested:**
- [x] Admin can navigate to CMS page management
- [x] "Add Page" button is available
- [ ] Only 2 pages exist (Home, About) -- privacy/terms/disclaimer/FAQ are not managed via CMS
- [ ] Not tested: creating/editing/deleting pages (would create test data)

---

### #403: Tekst verwijderen aanpassen -- CANNOT_TEST

**Reason:** Peter's admin account (Default Organisation) has no own-organization applications to test delete dialog on the frontend. The test hint suggests logging in as Jan Pietersen (leverancier) which is outside this persona's scope. Backend delete dialog in OpenRegister was not accessible because Peter's account shows "No Organisation" in OpenRegister.

---

### #406: SiteImprove verwijderen -- PASS

**Test:** Checked HTML source of http://localhost:3000/.

**Criteria tested:**
- [x] HTML source does NOT contain `siteimproveanalytics.com` -- 0 references found
- [x] Piwik Pro analytics script IS present (3 references: Piwik, stg.start, ppms)
- [x] Only one analytics framework present

---

### #409: Footer anders: inlog of uitgelogd -- PASS

**Test:** Footer is identical in both states. The frontend is a single-page application with the same build for logged-in and logged-out states.

**Criteria tested:**
- [x] Footer links are identical in logged-in and logged-out states
- [x] Footer shows "Softwarecatalogus" and "Een plek voor alle software voor en door Gemeenten"
- [x] Footer styling consistent between states

---

### #410: Dashboard schrijfwijze softwarecatalogus -- PARTIAL

**Test:** Logged in as Peter van Dijk, navigated to /beheer dashboard.

**Criteria tested:**
- [x] Dashboard heading: "Welkom in uw softwarecatalogus" (lowercase "softwarecatalogus" -- correct)
- [x] Body includes four bullet points: Applicaties, Diensten, Koppelingen, Standaarden
- [x] Instruction text about publishing new items and finding existing items via left menu: present
- [x] Closing paragraph about municipalities using GEMMA: present, uses "GEMeentelijke Model Architectuur (GEMMA)" with correct capitalization
- [ ] Header shows "SOFTWARECATALOGUS" in all caps (logo style) -- not lowercase
- [ ] Browser tab shows "Beheer - Softwarecatalogus" (capital S) -- inconsistent with dashboard lowercase
- [ ] Title on page says "Mijn softwarecatalogus" (lowercase) -- but #187 says it should be "Welkom in de Softwarecatalogus" (capital S)

**Note:** The dashboard welcome text matches the supplier text from #410 closely. The capitalization is inconsistent: dashboard body uses lowercase, header/footer use capitalized.

**Evidence:** Screenshot screenshots/01-dashboard-peter.png

---

### #92: Webstatistiekenpakket (Piwik Pro) -- PARTIAL

**Criteria tested:**
- [x] Piwik Pro script is present in page source (ppms, stg.start references found)
- [ ] Cannot verify Piwik Pro is correctly configured with the right container ID (srcUrl, dataLayerName, id vars are empty in the inline script -- the script has a guard that skips initialization if these are empty)
- [ ] Cannot verify tracking is actually sending data

---

### #169: Rest issues Organisatie en Configuratie -- PARTIAL

**Criteria tested:**
- [x] No "Nextcloud autorisatie - De tijd is verstreken" errors observed on login
- [ ] "Mijn Account" page: Not navigated to in this test run
- [ ] Registration form alignment with "Mijn Account": Not tested
- [ ] KVK number display: Not tested

---

### #85: (VNGR) Publieke API toegang tot aanbodinformatie -- PASS

**Test:** API calls to public and authenticated endpoints.

**Criteria tested:**
- [x] Public API for Softwarecatalogus register accessible: GET /api/objects/3/19 returns 200
- [x] OAS documentation accessible for register 3 (voorzieningen): 200
- [x] OAS documentation accessible for register 2 (publications): 200
- [x] API returns data about organisations, applications, standards
- [x] API supports standard query parameters (_limit, _fields)

---

### #148: (VNGR) GEMMA-architectuur opvraagbaar met API -- PASS

**Test:** API calls to GEMMA register endpoints.

**Criteria tested:**
- [x] OAS for register 4 (GEMMA): returns 200 (previously returned 500 -- fixed)
- [x] Elements endpoint: 4,353 elements
- [x] Relations endpoint: 248 relations
- [x] Views endpoint: 1 view
- [x] Models endpoint: 6,049 models (names not resolved in API response)

---

### #225: Testresultaten 29-10-2025 -- CLOSED

Issue closed on 2026-03-04. No re-test needed.

---

### #278: Filterteksten aanpassen -- PARTIAL

**Test:** Navigated to /zoeken and observed filters.

**Criteria tested:**
- [ ] Filter labels: The "Filter & sorteer" button is present but filter sidebar was collapsed. Console logged "0 available facets" despite 13 facets with data existing.
- [x] Sort options present: Meest relevant, Datum oud/nieuw, Naam A-Z/Z-A
- [ ] Filter "Schema"/"Objecttype" rename to "Type": Not verified (facets not loading)
- [ ] Documentation for managing filter texts: Not available

---

### #286: 500-error bij wachtwoord wijzigen -- PASS (CLOSED)

**Test:** API test for password change.

**Criteria tested:**
- [x] OCS API password change returns 200 (not 500): `PUT /ocs/v2.php/cloud/users/peter.vandijk%40test.nl` with key=password returns 200
- [x] No error during password change

Issue was previously closed on 2026-02-22.

---

### #392: Geimporteerde gebruiker error bij omzetten naar user -- CLOSED

Issue closed on 2026-03-04. All API criteria previously marked [x].

---

### #393: Backend: fouten in voorzieningenregister -- PASS

**Test:** API and export tests.

**Criteria tested:**
- [x] Register 3 (Voorzieningen) has 13 schemas
- [x] CSV export works: returns 200, produces valid CSV with headers and data
- [x] Excel export works: returns 200, produces 19,255-byte .xlsx file
- [x] Export contains expected columns (naam, beschrijvingKort, contactpersoon, _contactpersoon, etc.)
- [x] Export includes both UUID and human-readable columns (e.g., contactpersoon + _contactpersoon)

---

### #396: Verouderde NextCloud versie -- PASS

**Test:** status.php endpoint check.

**Criteria tested:**
- [x] Nextcloud version: 32.0.5 (running supported version 32.x)
- [x] No maintenance mode
- [x] No database upgrade needed

---

### #141: Organisaties samenvoegen na herindeling/overname -- CANNOT_TEST

**Reason:** Peter's account shows "No Organisation" in OpenRegister backend, which means the registers page shows "No registers found". The merge functionality is only accessible via OpenRegister's Search/Views page which requires admin-level register access. Created a test org via API (succeeded, 201) and deleted it after (204), but could not test the merge UI dialog.

**Setup verified:**
- [x] Can create organization via API
- [x] Can delete organization via API
- [ ] Merge dialog UI: Not accessible with Peter's account

---

### #15: Exporteren van gegevens (CSV/Excel) -- PASS

**Test:** Export via API for register 3, schema 19 (applicatie).

**Criteria tested:**
- [x] CSV export returns 200
- [x] Excel export returns 200
- [x] CSV contains headers with both ID columns and _name columns (e.g., `contactpersoon` and `_contactpersoon`)
- [x] Excel file is valid (19,255 bytes)
- [x] Export data contains expected fields

---

### #355: Exporteren functies (Applicatie export) -- PASS

**Test:** Same as #15 -- export bug is fixed.

**Criteria tested:**
- [x] CSV export returns 200 (was 500 before fix)
- [x] Export shows human-readable names alongside UUIDs via `_` prefixed columns
- [x] No 500 error

---

### #23: Data migratie verificatie -- PARTIAL

**Test:** Checked data counts via API.

**Criteria tested:**
- [x] Organisations present: 256
- [x] Applicaties present: 111
- [x] Diensten present: 70
- [x] Koppelingen present: 4,971
- [x] Gebruik present: 19,504
- [x] Contactpersonen present: 391
- [ ] Cannot verify data matches old softwarecatalogus without reference data
- [ ] Koppelingen names: Many show UUIDs instead of resolved application names (seen on search page)

---

### #182: Algemene voorwaarden, Privacyverklaring, Disclaimer, FAQ -- PARTIAL

**Test:** Checked frontend page URLs.

**Criteria tested:**
- [x] /privacy returns 200
- [x] /terms returns 200
- [x] /disclaimer returns 200
- [x] /faq returns 200
- [x] /voorwaarden returns 200
- [ ] Content of these pages: Not verified for correct text
- [ ] These pages are NOT managed via CMS (only 2 CMS pages: Home, About)

---

### #188: Aanmeldproces -- CANNOT_TEST

**Reason:** The registration/signup flow cannot be tested as Peter (already has an account). Would need to test with a new account outside this persona's scope.

---

### #208: NC Dashboard organisatie overzicht table issue -- CANNOT_TEST

**Reason:** This refers to the Nextcloud Dashboard widget, which is separate from the frontend beheer dashboard. Peter's account shows "No Organisation" in OpenRegister, preventing testing of the NC Dashboard widget.

---

### #209: Help knop gaat naar niet bestaande pagina -- PARTIAL

**Criteria tested:**
- [x] "Begrippenlijst" floating button is present on the search page
- [ ] Help button destination page: Not tested specifically

---

### #255: Dashboard welkomstekst -- PASS

**Test:** Dashboard text verified.

**Criteria tested:**
- [x] Welcome heading: "Welkom in uw softwarecatalogus"
- [x] Body text with four bullet points present
- [x] Instruction about publishing and finding items present
- [x] GEMMA paragraph present with correct capitalization

---

### #268: Dashboard tekst aanpassen na inloggen -- PASS

**Test:** Same as #255 -- dashboard text is correct after login.

---

### #338: Dashboard en Inloggen -- PASS

**Test:** Login and dashboard both work correctly.

**Criteria tested:**
- [x] Login page loads at /login with username/password fields
- [x] Login as peter.vandijk@test.nl succeeds
- [x] Redirects to /beheer dashboard
- [x] Dashboard shows "Mijn softwarecatalogus" with action buttons

---

### #339: Activeren gebruikers -- PARTIAL

**Test:** Not directly tested (would require creating/activating users).

**Criteria tested:**
- [x] Password change API works (related to user activation)
- [ ] User activation flow not tested

---

### #411: Vraag: Required eisen uitgezet voor dataimport -- PARTIAL

**Test:** Data counts suggest import was successful.

**Criteria tested:**
- [x] Data is present in the system (256 orgs, 111 apps, 4971 koppelingen)
- [ ] Validation requirements during import: Not verified

---

### #431: Aanmeldproces: tussenvoegsel niet meer aanwezig -- CANNOT_TEST

See #188 -- registration flow cannot be tested with existing account.

---

### #187: Tekstvoorstellen (remaining text changes) -- PARTIAL

**Test:** Checked dashboard text against #187 criteria.

**Criteria tested:**
- [x] Dashboard welcome title close to spec: "Welkom in uw softwarecatalogus" (uses "uw" instead of "de")
- [ ] Contactpersoon text: Not checked
- [ ] Aanmelding succesvol page: Not checked
- [ ] "Contactpersonen" renamed to "Gebruikers": Not verified
- [ ] Diensten wizard texts: Not checked
- [ ] Search tooltip text: Not checked

---

### #449: Handleiding facets configureren klopt niet -- CANNOT_TEST

**Reason:** Peter's account shows "No Organisation" in OpenRegister, preventing access to schemas/facet editing.

---

### #450: Back-end: Icoon voor publiceren verwijderen -- PARTIAL

**Reason:** Not directly verified in UI. Would need to check specific publish icon on backend objects.

---

### #65: Collega's toegang geven (contactpersonen beheer) -- PARTIAL

**Test:** Not tested via UI. API confirms 391 contactpersonen exist in the system.

---

### Search Page Issues (#278, #340, #343, #349, #398, #453) -- FAIL

**Test:** Navigated to /zoeken as authenticated user (Peter van Dijk).

**Key findings:**
- Total results: 25,239 (admin bypasses RBAC, sees everything)
- **CRITICAL:** Default sort (Naam A-Z) shows koppelingen first with UUID-only titles (e.g., "00345a03-6ccb-5133-9075-06b5a021563f <-> 3953aed3-4437-5ef2-83b2-107966138d12")
- **CRITICAL:** Standaardversies shown as raw UUIDs on koppeling cards (e.g., "4edb406c-f544-4b31-b35b-4074e5a79ed9")
- **CRITICAL:** Many koppeling cards show "Onbekend" for both application names
- **CRITICAL:** Facet loading reports "0 available facets" despite 13 facets with data existing
- **CRITICAL:** Massive number of 404 errors from /api/names/{uuid} -- name resolution failing for publication-type UUIDs
- [x] Pagination present (1262 pages)
- [x] Sort options work (5 options available)

**Evidence:** Screenshot screenshots/05-search-page-uuids.png

---

### #336: Views -- PARTIAL

Not directly tested. ArchiMate views functionality requires separate navigation.

---

### #329: Teksten SWC definitief -- PARTIAL

Not tested. Would require fetching PowerPoint images and comparing wizard texts.

---

## Data Cleanup

All test data created during testing has been cleaned up:
- [x] Merge test organization "Test Leverancier BV (oud)" deleted (HTTP 204)
- No glossary terms were created (Add button was disabled due to validation bug)
- No other test data was created

---

## Key Findings

### Critical Issues

1. **Search page UUID display (#349, #401, #451):** Koppeling cards show raw UUIDs for application names and standaardversies. The `/api/names/` endpoint returns 404 for publication UUIDs, meaning the name resolution service does not cover publication-type objects. This affects the majority of the 25,239 search results.

2. **Search facets not loading (#278, #453):** Console reports "Facetable config: 0 available facets" despite 13 facets with data existing. This means the "Filter & sorteer" panel likely shows no filter options, rendering the search page unusable for filtered searches.

3. **Glossary validation blocks saving (#155):** The "External link" field validation ("moet een geldige URL zijn") fires even when the field is empty, preventing creation of glossary terms without URLs. The "Add" button remains disabled. Additionally, keywords still use collaborative tags (NcSelectTags) instead of free-text input.

### Working Well

4. **Export functionality (#15, #355, #393):** Both CSV and Excel exports work correctly via API. Files contain proper headers with human-readable `_name` columns alongside UUID columns.

5. **API endpoints (#85, #148):** Public API, OAS documentation, and GEMMA architecture API all return correct data. Register 4 OAS (previously 500) now returns 200.

6. **Dashboard text (#255, #268, #410):** Welcome text matches the approved supplier text with correct GEMMA capitalization and four bullet points.

7. **Infrastructure (#396, #406):** Nextcloud 32.0.5 running correctly. SiteImprove removed, Piwik Pro script present.

### Access Limitations

8. **OpenRegister access:** Peter's account shows "No Organisation" in the OpenRegister backend, preventing testing of merge (#141), facet editing (#449), register exports (UI), and delete dialogs (#403). These tests require the `admin` user in the OpenRegister backend.
