# Test Results: Samenwerking (Authenticated)

**Persona:** Linda Bakker -- Coordinator at a municipal collaboration (samenwerkingsverband)
**Role:** Gebruik-beheerder
**Login:** linda.bakker@test.nl
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Date:** 2026-03-16 (Re-test #8)
**Browser:** Playwright (browser-5, headless)

---

## Environment Status

### Changes Since Re-test #7
1. **Organisation 404 (downgraded from 500):** The org UUID `c0ff4d70-14f0-4852-9c18-ce522996119c` now returns 404 instead of 500. This means the org object was deleted or does not exist in the voorzieningen/organisatie register. UI degrades gracefully -- no crashes.
2. **Organization dropdown added:** Dashboard now shows an organization selector dropdown, allowing switching between "Default Organisation" and "Test Samenwerking". This was not present in re-test #7.
3. **Category search returns 0:** Searching with `categorie=koppeling` now returns 0 results (previously returned partial results).

### Remaining Issues
1. **Organisation data 404 errors:** Fetching org object returns 404. Org-specific features partially unavailable.
2. **Beheer menu warnings:** "Beheer menu (position 7) not found" and "No beheer types found in menu" -- side navigation is missing.
3. **AangebodenGebruik API:** Not available after switching to Test Samenwerking.

---

## Login Verification

- **Status:** PASS
- **Details:** Successfully logged in as linda.bakker@test.nl. Dashboard loaded at `/beheer` showing "Mijn softwarecatalogus" heading, welcome section, and three wizard buttons. No crashes or TypeErrors.
- **localStorage cleared** before login as required.
- **Organisation confirmed:** "Test Samenwerking" available in organization dropdown and confirmed on /beheer/my-organisation page (heading shows "Test Samenwerking" with "Acties" button).
- **Screenshot:** `screenshots/01-dashboard-after-login.png`

---

## Issue #57: Pakketten opvoeren voor samenwerkingsverband

**Title:** Als gebruik-beheerder van een samenwerkingsverband wil ik softwarepakketten kunnen opvoeren
**Labels:** Gebruik, PvE eis
**Test Step:** Step 20 (Samenwerkingen en Multi-Organisatie Beheer)
**Previous Status:** PARTIAL (re-test #7)

### Acceptance Criteria Results

| # | Criterion | Type | Result | Notes |
|---|-----------|------|--------|-------|
| 1 | Samenwerking user can log in and see the dashboard without crash | HYBRID | **PASS** | Login succeeded, dashboard at /beheer loaded without crash. Three wizard buttons visible. Welcome text rendered correctly. |
| 2 | Dashboard shows organization name ("Test Samenwerking") | UI | **PASS** | Organization dropdown on dashboard shows "Test Samenwerking" after selection. Confirmed on /beheer/my-organisation page as h1 heading. |
| 3 | No `TypeError: Cannot read properties of undefined` in console | HYBRID | **PASS** | Console errors are exclusively 404s for organization data fetch. No TypeError related to user.userGroups or user.isAuthenticated. The optional chaining fix is working. |
| 4 | Welcome section renders correctly for gebruik-beheerder role | UI | **PASS** | Welcome card "Welkom in de softwarecatalogus" renders with three action descriptions: "Dienst registreren", "Gebruik registreren", "Koppeling registreren". Links to "Mijn Account" and "Mijn Organisatie" present and functional. |
| 5 | Wizards are available for samenwerking organizations | UI | **PASS** | Three wizard buttons visible: "Applicatie toevoegen", "Koppeling toevoegen", "Dienst toevoegen". Applicatie wizard at `/forms/gebruik/applicatie` opens with multi-step form (Applicatie > Gebruik configuratie > Controleren). Koppeling wizard at `/forms/gebruik/koppeling` loads correctly with search-first approach. |
| 6 | Samenwerking user can register packages on behalf of member municipalities | UI | **CANNOT_TEST** | Feature not yet implemented. The Applicatie wizard allows selecting applications but does not have an "on behalf of member municipality" option. No member municipality selector exists in the wizard flow. |

### Key Findings

**TypeError Fix Confirmed Stable (8th consecutive test):** Across all tested pages, no TypeError crashes occurred. The optional chaining fix applied to 6 files in February 2026 remains effective.

**New: Organization Dropdown:** The dashboard now includes a "Selecteer organisatie" dropdown, allowing users to switch between their assigned organizations. This is a UI improvement for multi-org users like samenwerking coordinators.

**Applicatie Wizard Functional:** The wizard opens with a search-based application selector, step indicators (Applicatie > Gebruik configuratie > Referentiecomponenten > Controleren), an info alert suggesting the search page as an alternative, and a fallback button "Ik kan de gewenste applicatie niet vinden".

### Verdict: **PARTIAL**

Criteria 1-5 all PASS (consistent with re-test #7). Criterion 6 remains CANNOT_TEST (member-municipality delegation feature not implemented). The core bug fix is solid; the remaining gap is the unimplemented feature.

### Evidence

| Screenshot | Description |
|------------|-------------|
| `screenshots/01-dashboard-after-login.png` | Dashboard with three wizard buttons after login |
| `screenshots/02-applicatie-wizard.png` | Applicatie wizard form (multi-step) |
| `screenshots/03-dashboard-test-samenwerking-selected.png` | Dashboard with Test Samenwerking selected in dropdown |
| `screenshots/08-mijn-organisatie.png` | My Organisation page showing "Test Samenwerking" |

---

## Issue #186: Koppelingen

**Title:** Koppelingen
**Labels:** Aanbod, Bevinding, Restpunt, Koppeling
**Test Step:** Step 11 (Koppeling wizard)
**Previous Status:** PARTIAL (re-test #7)

### Acceptance Criteria Results

| # | Criterion | Type | Result | Notes |
|---|-----------|------|--------|-------|
| 1 | Koppelingen display in a table format with readable titles (not blank or UUID-only) | API | **PARTIAL** | **Search results:** Koppeling titles display as UUID-based names (e.g., "00f20897-dfd8-540f-af0a-06253457bf24 -> 2731313c-ec58-5fee-bd5b-8e18e05c97f2") or arrow-only titles ("->", "<-", "<->") with "Onbekend" labels. Module name resolution fails (404 from /api/names/ endpoint). External service names DO resolve correctly (e.g., "BRK-PB - Basisregistratie Kadaster Publiekrechtelijke Beperkingenbesluiten"). **Category filter:** Searching with `categorie=koppeling` returns 0 results. |
| 2 | Koppelingen linked to "buitengemeentelijke voorzieningen" correctly display the referenced external service | API | **PASS** | External services resolve correctly. Confirmed on detail page: "BRI - Basisregistratie Inkomen" displays as buitengemeentelijke voorziening. In search results: "LV-BAG - Basisregistratie Adressen en Gebouwen", "BRK-PB - Basisregistratie Kadaster Publiekrechtelijke Beperkingenbesluiten" also resolve. |
| 3 | Koppelingen do not reference non-existent applications (graceful handling) | API | **PASS** | When module UUIDs cannot be resolved, the UI gracefully shows "Onbekend" in search results or the raw UUID on detail pages. No crashes or unhandled errors. The name resolution failure is logged as info/error but handled without breaking the UI. |
| 4 | Detail page shows all relevant fields | UI | **PARTIAL** | Detail page renders and shows: Applicatie A, Applicatie B (or Buitengemeentelijke voorziening), Richting (with arrow symbol), Transportprotocol, Status, Intermediair (when applicable). **Issues:** (a) Page title/h1 contains raw UUID for Applicatie A when name cannot be resolved, (b) Intermediair shows raw UUID, (c) No tabs visible on detail page, (d) "Koppeling aanbieden" button is present. Fields that reference modules show raw UUIDs when name resolution fails (404). |
| 5 | Koppeling detail page at /publicatie/{uuid} renders correctly | API | **PASS** | Both tested detail pages rendered without errors: internal koppeling (`ee8a270b`) showing all fields with UUIDs, and external koppeling (`908e894e`) showing resolved external service name. Pages load within 5 seconds, show structured field data. |

### Detailed Findings

#### Search Results Display
- **25,059 total results** on general search (all types mixed, sorted A-Z)
- First 3 results show arrow-only titles ("left-arrow", "right-arrow", "bidirectional-arrow") with "Onbekend left-arrow Onbekend" descriptions
- Subsequent results show UUID-based titles
- Each koppeling card correctly shows: type badge "Koppeling", status "In gebruik", formatted date
- "Standaardversies" field shows raw UUIDs
- Category filter (`categorie=koppeling`) returns 0 results -- koppelingen are not published as a separate category

#### Detail Page: External Koppeling (908e894e)
- **Title:** "9ba4a796-9fa8-56bd-bb2c-27806c962985 left-arrow BRI - Basisregistratie Inkomen"
- **Applicatie A:** Raw UUID (9ba4a796...) -- name resolution 404
- **Buitengemeentelijke voorziening:** "BRI - Basisregistratie Inkomen" (CORRECT)
- **Richting:** BnaarA (left-arrow)
- **Transportprotocol:** extern
- **Status:** in gebruik
- **Intermediair:** Raw UUID (f69fd93a...) -- name resolution 404

#### Detail Page: Internal Koppeling (ee8a270b)
- **Title:** "a0597415-8288-5430-8d85-d1416e5bf28c left-arrow f0b3e480-c1b1-54cb-a1da-808af0e83ff6"
- **Applicatie A:** Raw UUID -- name resolution 404
- **Applicatie B:** Raw UUID -- name resolution 404
- **Richting:** BnaarA (left-arrow)
- **Transportprotocol:** intern
- **Status:** In gebruik

#### Koppeling Wizard
- Accessible from dashboard via "Koppeling toevoegen" button
- Opens at `/forms/gebruik/koppeling?type=aanbieden-koppeling`
- Multi-step flow: Een koppeling zoeken > Gebruiksinformatie > Deelnemers toevoegen > Controleren
- Step 1: "Controleren op bestaande koppeling" -- prompts user to check if koppeling exists first (good UX)
- Application dropdown with search functionality
- "Ik kan de gewenste koppeling niet vinden" button (disabled until application selected)
- "Volgende" button correctly disabled (validation working)

#### Testing Note (per issues.md)
The issues.md states UUID-only titles are caused by "bad client data." Testing confirms this is primarily a **data quality issue** -- the module UUIDs stored in koppeling objects reference modules that do not exist in the local register's names API. The frontend correctly attempts to resolve names but the backend returns 404 for most module UUIDs. External services (buitengemeentelijke voorzieningen) DO resolve because they exist in a different dataset.

### Verdict: **PARTIAL**

Criteria 2, 3, and 5 PASS. Criteria 1 and 4 are PARTIAL. The core rendering logic works correctly; the display issues are caused by unresolvable module references in the data.

### Evidence

| Screenshot | Description |
|------------|-------------|
| `screenshots/04-koppeling-wizard.png` | Koppeling wizard form |
| `screenshots/05-koppeling-search-0-results.png` | Category search returns 0 results |
| `screenshots/06-koppeling-detail-extern.png` | External koppeling detail (UUID + resolved BRI name) |
| `screenshots/07-koppeling-detail-intern-uuids.png` | Internal koppeling detail (all UUIDs) |
| `screenshots/09-search-results-koppelingen-uuids.png` | Search results top |
| `screenshots/10-search-results-scrolled.png` | Search results with "Onbekend" labels |

---

## Console Errors Summary

| Page | Error Count | Type | Key Errors |
|------|-------------|------|------------|
| /login | 0 | -- | Clean |
| /beheer (dashboard) | 8 | 404 | org data fetch (c0ff4d70...) x4 duplicate |
| /forms/gebruik/applicatie | 4 | 404 | org data with deelnemers extension |
| /forms/gebruik/koppeling | 1 | error | org data with deelnemers extension |
| /beheer/my-organisation | 3 | 404/500 | org files fetch |
| /zoeken | 26 | 404 | Name resolution failures for module UUIDs |
| /publicatie/{extern} | 4 | 404 | Name resolution + uses/used endpoints |
| /publicatie/{intern} | 4 | 404 | Name resolution + uses/used endpoints |

---

## Overall Summary

| Issue | Title | Re-test #7 | Re-test #8 (current) | Trend |
|-------|-------|------------|----------------------|-------|
| #57 | Pakketten opvoeren voor samenwerkingsverband | PARTIAL (5/6) | **PARTIAL** (5/6) | Stable. New org dropdown. TypeError fix solid. |
| #186 | Koppelingen | PARTIAL (improved) | **PARTIAL** (3/5 pass) | Stable. Detail pages work. Name resolution remains the core issue. |

### Remaining Issues

1. **[MEDIUM] Module name resolution failures:** The `/api/names/{uuid}` endpoint returns 404 for most module UUIDs referenced in koppelingen. This causes UUID-only titles, "Onbekend" labels in search results, and raw UUIDs on detail pages. Root cause: module objects are not in the local register or names index.

2. **[MEDIUM] Category search filter:** Searching with `categorie=koppeling` returns 0 results. Koppelingen are only findable through the general search (25,059 mixed results).

3. **[LOW] Organisation data 404:** Org UUID `c0ff4d70-14f0-4852-9c18-ce522996119c` returns 404 consistently. Multiple error log entries triggered per page load.

4. **[FEATURE GAP] Member municipality delegation:** Issue #57 criterion 6 -- registering packages on behalf of member municipalities -- remains unimplemented.

---

## Test Data Cleanup

No test data was created during this test session. All testing was read-only (navigation and observation). No cleanup required.

---

## Screenshots Index

| File | Description |
|------|-------------|
| `screenshots/01-dashboard-after-login.png` | Dashboard with three wizard buttons after login |
| `screenshots/02-applicatie-wizard.png` | Applicatie wizard form (multi-step) |
| `screenshots/03-dashboard-test-samenwerking-selected.png` | Dashboard with Test Samenwerking selected |
| `screenshots/04-koppeling-wizard.png` | Koppeling wizard form |
| `screenshots/05-koppeling-search-0-results.png` | Category search returns 0 results |
| `screenshots/06-koppeling-detail-extern.png` | External koppeling detail page |
| `screenshots/07-koppeling-detail-intern-uuids.png` | Internal koppeling detail page |
| `screenshots/08-mijn-organisatie.png` | My Organisation page |
| `screenshots/09-search-results-koppelingen-uuids.png` | Search results top |
| `screenshots/10-search-results-scrolled.png` | Search results with Onbekend labels |
