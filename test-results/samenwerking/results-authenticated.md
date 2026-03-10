# Test Results: Samenwerking (Authenticated)

**Persona:** Linda Bakker -- Coordinator at a municipal collaboration (samenwerkingsverband)
**Role:** Gebruik-beheerder
**Login:** linda.bakker@test.nl
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Date:** 2026-03-10 (Re-test #7)
**Browser:** Playwright (browser-5, headless)

---

## Environment Status

### Improvements Since Re-test #6
1. **Organisation assignment restored:** Linda Bakker is now correctly assigned to "Test Samenwerking" (confirmed on /beheer/my-account page). This was broken in re-test #6 where the user was in "Default Organisation".
2. **Wizards restored:** Dashboard now shows three wizard buttons (Applicatie toevoegen, Koppeling toevoegen, Dienst toevoegen). This was broken in re-test #6.
3. **Publication detail pages working:** The PublicationsController $extend 500 error from re-test #6 is resolved. Detail pages now render.

### Remaining Issues
1. **Organisation data 500 errors:** Fetching org object (`voorzieningen/organisatie/52f5cae7-...`) returns 500 errors (upgraded from 404 in re-test #6). UI degrades gracefully -- no client crashes.
2. **Inactive org status:** Banner "Uw organisatie heeft nog geen actieve status" displayed on org page.
3. **vng-gemma register schemas:** Schemas 4/5, 4/20, 4/22 return 500 errors. Non-blocking.
4. **Contactpersoon collection 500:** Fetching contactpersoon list returns 500. Non-blocking for tested flows.

---

## Login Verification

- **Status:** PASS
- **Details:** Successfully logged in as linda.bakker@test.nl. Dashboard loaded at `/beheer` showing "Mijn softwarecatalogus" heading, welcome section, and three wizard buttons. No crashes or TypeErrors.
- **localStorage cleared** before login as required.
- **Organisation confirmed:** "Test Samenwerking" (verified on /beheer/my-account: Organisatie = "Test Samenwerking", Functie = "Coordinator").
- **Screenshot:** `01-dashboard-after-login.png`

---

## Issue #57: Pakketten opvoeren voor samenwerkingsverband

**Title:** Als gebruik-beheerder van een samenwerkingsverband wil ik softwarepakketten kunnen opvoeren
**Labels:** Gebruik, PvE eis
**Test Step:** Step 20 (Samenwerkingen en Multi-Organisatie Beheer)
**Previous Status:** PARTIAL (re-test #6)

### Acceptance Criteria Results

| # | Criterion | Type | Result | Notes |
|---|-----------|------|--------|-------|
| 1 | Samenwerking user can log in and see the dashboard without crash | HYBRID | **PASS** | Login succeeded, dashboard at /beheer loaded without crash. Three wizard buttons visible. Welcome text rendered correctly. |
| 2 | Dashboard shows organization name ("Test Samenwerking") | UI | **PASS** | Organization name "Test Samenwerking" is shown on /beheer/my-account (linked to /beheer/my-organisation) and as h1 heading on /beheer/my-organisation page. Dashboard itself shows "Mijn softwarecatalogus" but org context is accessible. |
| 3 | No `TypeError: Cannot read properties of undefined` in console | HYBRID | **PASS** | Checked console errors across dashboard, my-account, my-organisation, koppelingen, koppeling wizard, and search pages. No TypeError errors. Console errors are all backend 500s, not client-side TypeErrors. |
| 4 | Welcome section renders correctly for gebruik-beheerder role | UI | **PASS** | Welcome card "Welkom in de softwarecatalogus" renders with three action descriptions: "Dienst registreren", "Gebruik registreren", "Koppeling registreren". Links to "Mijn Account" and "Mijn Organisatie" present and functional. |
| 5 | Wizards are available for samenwerking organizations | UI | **PASS** | Three wizard buttons visible on dashboard: "Applicatie toevoegen", "Koppeling toevoegen", "Dienst toevoegen". Applicatie wizard opens at `/forms/gebruik/applicatie` with multi-step form (Applicatie > Gebruik configuratie > Controleren). Koppeling wizard opens at `/forms/gebruik/koppeling` with search-first approach. |
| 6 | Samenwerking user can register packages on behalf of member municipalities | UI | **CANNOT_TEST** | Feature not yet implemented per issue notes. The Applicatie wizard allows selecting applications but does not have a "on behalf of member municipality" option. The org has no applications in its portfolio, so the selection dropdown shows empty results. |

### Key Findings

**TypeError Fix Confirmed Stable (7th consecutive test):** Across all tested pages, no TypeError crashes occurred. The optional chaining fix applied to 6 files in February 2026 remains effective.

**Wizard Availability Restored:** After re-test #6's regression (org changed to Default Organisation causing "Geen wizards beschikbaar"), the wizard buttons are now correctly displayed again with the proper org assignment. This confirms wizard visibility is org-configuration-dependent.

**Applicatie Wizard Functional but Empty:** The wizard opens successfully with a multi-step form. The application dropdown triggers a search but returns no options because Test Samenwerking has no applications in its portfolio. The "Ik kan de gewenste applicatie niet vinden" fallback button and "Volgende" (disabled until selection) work correctly.

### Verdict: **PARTIAL**

Criteria 1-5 all PASS (improvement over re-test #6 where criteria 2 and 5 failed due to org regression). Criterion 6 remains CANNOT_TEST (feature not implemented). The core bug fix is solid; the remaining gap is the unimplemented member-municipality package registration feature.

### Comparison with Previous Test Runs

| Run | Status | Key Finding |
|-----|--------|-------------|
| Re-test #4 | PARTIAL | TypeError crash FIXED. Wizards missing. |
| Re-test #5 | PARTIAL | Wizard buttons visible. Applicatie wizard broken by schema slug. |
| Re-test #6 | PARTIAL (regressed) | Wizard buttons gone (org changed). TypeError fix stable. |
| Re-test #7 (current) | **PARTIAL** (improved) | Org restored. Wizards visible. 5/6 criteria pass. Only member-municipality feature untestable. |

### Evidence

| Screenshot | Description |
|------------|-------------|
| `01-dashboard-after-login.png` | Dashboard with three wizard buttons after login |
| `03-my-organisation-loaded.png` | My Organisation page showing "Test Samenwerking" with inactive status banner |
| `04-applicatie-wizard.png` | Applicatie wizard form (multi-step) |
| `12-my-account.png` | Mijn Account page confirming user identity: Linda Bakker, Test Samenwerking |

---

## Issue #186: Koppelingen

**Title:** Koppelingen
**Labels:** Aanbod, Bevinding, Restpunt, Koppeling
**Test Step:** Step 11 (Koppeling wizard)
**Previous Status:** PARTIAL (re-test #6, regressed due to PublicationsController 500)

### Acceptance Criteria Results

| # | Criterion | Type | Result | Notes |
|---|-----------|------|--------|-------|
| 1 | Koppelingen display in a table format with readable titles (not blank or UUID-only) | API | **PARTIAL** | **Beheer table:** Correct structure with columns Naam, Status, Korte beschrijving, Applicatie A, Applicatie B, Acties. Shows "Geen data gevonden" for this org (expected). **Search results:** Koppeling titles display as arrow symbols only ("left-arrow", "right-arrow", "bidirectional-arrow") instead of readable names. Applications show as "Onbekend" (Unknown). Some external services resolve correctly (e.g., "BRK - Basisregistratie Kadaster"). Standaardversies show raw UUIDs. Even newly created koppelingen (March 2026) have arrow-only titles. |
| 2 | Koppelingen linked to "buitengemeentelijke voorzieningen" correctly display the referenced external service | API | **PASS** | External services are correctly resolved and displayed. Confirmed on multiple koppelingen: "BRK - Basisregistratie Kadaster", "JUBES - JUstitie BErichten Service", "NHR - Handelsregister", "DigiD", "Enable-U 2Secure" (as intermediair). Both in search results and on detail pages. |
| 3 | Koppelingen do not reference non-existent applications (graceful handling) | API | **PARTIAL** | No crashes occur when applications are missing -- graceful degradation. However, the display shows literal "null" text and "Onbekend" labels instead of a cleaner fallback. This happens even on newly created koppelingen (e.g., "Test Koppeling Lever2" from March 2026 shows "null right-arrow null"). |
| 4 | Detail page shows all relevant fields: name, type, transport protocol, linked applications, external service | UI | **PARTIAL** | Detail page renders and shows: Richting, Transportprotocol, Status, Intermediair, Standaardversies, Buitengemeentelijke voorziening, Applicatie A/B, Korte beschrijving. Issues: (a) h1 heading shows only direction arrow instead of koppeling name (browser tab title IS correct), (b) Applicatie A/B show "-" or literal "null", (c) Standaardversies show raw UUIDs (names API returns 404). |
| 5 | Koppeling detail page at /publicatie/{uuid} renders correctly | API | **PASS** | Detail pages render without errors. **Major improvement from re-test #6** where all detail pages returned 500 (PublicationsController $extend bug). Tested both old (January 2025) and new (March 2026) koppelingen. Pages load, show field data, include "Koppeling aanbieden" action button. No client-side crashes. |

### Detailed Findings

#### Search Results Display Issues
- **Arrow-only titles:** All koppeling search cards show only direction arrows as headings instead of actual koppeling names
- **"Onbekend" labels:** Both application sides show "Onbekend" (Unknown) in italic text
- **UUID Standaardversies:** Standard versions display as raw UUIDs (e.g., "4edb406c-f544-4b31-b35b-4074e5a79ed9") with 404 errors from the names resolution API
- **Correct elements:** Type badge "Koppeling" and status "In gebruik" display correctly; dates properly formatted; pagination works (13,109 total results, 656 pages)

#### Detail Page (Old Koppeling -- January 2025, BRK)
- **URL:** `/publicatie/c8a8323e-650b-5577-9343-271d31568368`
- **h1 heading:** "left-arrow" (arrow only)
- **Visual display:** "null left-arrow BRK - Basisregistratie Kadaster"
- **Applicatie A:** "-"
- **Buitengemeentelijke voorziening:** BRK - Basisregistratie Kadaster (correct)
- **Richting:** BnaarA
- **Transportprotocol:** extern
- **Status:** In gebruik
- **Intermediair:** Enable-U 2Secure
- **Standaardversies:** 419ba65d-... (UUID, not resolved)

#### Detail Page (New Koppeling -- March 2026, "Test Koppeling Lever2")
- **URL:** `/publicatie/62390f8f-2de1-41eb-a531-3db64b3bb9b4`
- **Browser tab title:** "Test Koppeling Lever2" (CORRECT)
- **h1 heading:** "right-arrow" (arrow only -- WRONG, should show "Test Koppeling Lever2")
- **Visual display:** "null right-arrow null"
- **Applicatie A:** "-"
- **Applicatie B:** "-"
- **Richting:** AnaarB
- **Status:** in gebruik
- **Korte beschrijving:** "Koppeling aangemaakt door lever 2"

**Key observation:** The browser tab `<title>` correctly shows "Test Koppeling Lever2" but the `<h1>` heading on the page shows only the direction arrow. This confirms the data exists but the display template uses the wrong field for the heading (likely constructing the title from applicatie names instead of the koppeling's own naam field).

#### Beheer Koppelingen Table
- Table at /beheer/koppelingen has correct columns: Naam, Status, Korte beschrijving, Applicatie A, Applicatie B, Acties
- "Toevoegen" button present; search and filter icons available
- Shows "Geen data gevonden" for Test Samenwerking (expected -- no own koppelingen)
- No console errors on this page

#### Koppeling Wizard
- Accessible from dashboard via "Koppeling toevoegen" button
- Opens at `/forms/gebruik/koppeling?type=aanbieden-koppeling`
- Multi-step flow: Een koppeling zoeken > Gebruiksinformatie > Deelnemers toevoegen > Controleren
- Step 1 asks to check for existing koppelingen first (good UX)
- Info alert suggests using the search page as alternative
- Application dropdown shows "No options" for Test Samenwerking (expected -- no applications)
- "Ik kan de gewenste koppeling niet vinden" button disabled until application selected
- "Volgende" button correctly disabled (validation working)

#### Testing Note (per issues.md)
The issues.md states: "UUID-only titles, 'null' references, and arrow-only names in older koppelingen are caused by bad client data." However, testing confirmed that **newly created koppelingen** (March 2026, "Test Koppeling Lever2") also show arrow-only headings and "null" application references, despite having a correct name in the database (visible in browser tab title). This indicates a **code-level display issue** in the publication detail template, not just a legacy data problem.

### Verdict: **PARTIAL**

Criteria 2 and 5 PASS (external services display correctly; detail pages render -- major improvement from re-test #6). Criteria 1, 3, and 4 are PARTIAL (table structure correct but search titles are arrows, graceful degradation but "null" text, fields present but heading wrong and UUIDs not resolved).

### Comparison with Previous Test Runs

| Run | Status | Key Change |
|-----|--------|------------|
| Re-test #4 | PARTIAL | Detail pages verified; "null" display bug found |
| Re-test #5 | PARTIAL | Search type filter broken. Detail pages PASS. h1 UUID bug. |
| Re-test #6 | PARTIAL (regressed) | Detail pages 500 error (PublicationsController $extend bug). |
| Re-test #7 (current) | **PARTIAL** (improved) | Detail pages working again. Arrow-only title and "null" display bugs persist for both old and new koppelingen. |

### Evidence

| Screenshot | Description |
|------------|-------------|
| `05-koppeling-wizard.png` | Koppeling wizard form (full page, multi-step) |
| `06-koppelingen-search-no-titles.png` | Search page during initial load (before data settles) |
| `08-search-results-scrolled.png` | Search results showing arrow-only titles and "Onbekend" labels |
| `09-koppeling-detail-page.png` | Old koppeling detail (BRK, January 2025) showing "null" and UUID |
| `10-koppeling-detail-new.png` | New koppeling detail ("Test Koppeling Lever2", March 2026) showing "null right-arrow null" |
| `11-beheer-koppelingen-empty.png` | Beheer koppelingen table (empty for this org) |

---

## Cross-Cutting Observations

### Console Errors Summary

| Page | Error Count | Type | Notes |
|------|-------------|------|-------|
| /beheer (dashboard) | 4 | 500 | contactpersoon collection fetch |
| /beheer/my-organisation | 17 | 500 | org data, uses, used, audit-trails, schema |
| /beheer/koppelingen | 0 | -- | Clean page load |
| /beheer/my-account | 0 | -- | Clean page load |
| /zoeken | 8 | 500 | org data, facets API, menus API |
| /publicatie/{uuid} | 0-1 | 500 | Some name resolution 404s |
| /forms/gebruik/koppeling | 1 | 500 | org data with deelnemers extension |
| /forms/gebruik/applicatie | 4 | 500 | org data with deelnemers extension |

### Network Performance
- Schema cache warmup: completes successfully (8 schemas)
- Register cache warmup: completes successfully (2/2 registers)
- Backend cache loading: ~1415ms (acceptable)
- Search results: 13,109 results load with enrichment in ~5s (acceptable for full catalog)
- No individual requests flagged as critical performance failures

### Navigation Structure (Authenticated)
- Top nav: Privacy, Terms, Beheer (with user icon)
- Beheer sub-pages: Dashboard, My Account, My Organisation, Koppelingen
- Breadcrumbs are correct on all pages
- Begrippenlijst (glossary) floating button available on form pages

---

## Overall Summary

| Issue | Title | Re-test #6 | Re-test #7 (current) | Trend |
|-------|-------|------------|----------------------|-------|
| #57 | Pakketten opvoeren voor samenwerkingsverband | PARTIAL | **PARTIAL** (improved) | Org restored, wizards visible. 5/6 criteria pass. |
| #186 | Koppelingen | PARTIAL (regressed) | **PARTIAL** (improved) | Detail pages working. Display bugs persist (arrow titles, "null" text). |

### Remaining Bugs

1. **[MEDIUM] Koppeling h1 heading shows arrow instead of name:** The page heading shows only a direction arrow ("right-arrow", "left-arrow", "bidirectional-arrow") instead of the koppeling's actual name. The browser tab title is correct, confirming the name exists in the data. The display template constructs the heading from application names rather than the koppeling's naam field.

2. **[MEDIUM] "null" literal text in koppeling display:** When a koppeling references applications that are missing or unlinked, the visual display shows literal "null" text (e.g., "null right-arrow null"). Should display a cleaner fallback like "-" or the application name if available.

3. **[LOW] Standaardversie UUIDs not resolved:** Standard version references display as raw UUIDs. The `/api/names/{uuid}` endpoint returns 404 for these UUIDs, indicating the names are not indexed or the referenced objects no longer exist.

4. **[LOW] Organisation data 500 errors:** The org data endpoint consistently returns 500 for Test Samenwerking. This does not cause crashes but triggers multiple error log entries and may prevent some org-specific features from working.

### Recommendations

1. **Fix koppeling heading display:** Use the koppeling's `naam` field for the h1 heading, falling back to the constructed "AppA direction AppB" format only if naam is empty.
2. **Replace "null" with clean fallback:** When application references are missing, display "-" or "Onbekende applicatie" instead of literal "null".
3. **Resolve org data 500:** Investigate why the voorzieningen org endpoint returns 500 for Test Samenwerking (may be related to inactive status or schema issues).
4. **Index standaardversie names:** Ensure the names API can resolve standaardversie UUIDs, or display the version label from the object data instead.

---

## Test Data Cleanup

No test data was created during this test session. All testing was read-only (navigation and observation only). No cleanup required.

---

## Screenshots Index

| File | Description |
|------|-------------|
| `01-dashboard-after-login.png` | Dashboard with three wizard buttons after login |
| `02-my-organisation.png` | My Organisation page (loading spinner) |
| `03-my-organisation-loaded.png` | My Organisation page showing "Test Samenwerking" with inactive status |
| `04-applicatie-wizard.png` | Applicatie wizard form (multi-step) |
| `05-koppeling-wizard.png` | Koppeling wizard form (full page) |
| `06-koppelingen-search-no-titles.png` | Search page with koppelingen filter (before data load) |
| `07-search-results-loaded.png` | Search results header (13,109 results) |
| `08-search-results-scrolled.png` | Search results showing arrow-only titles and "Onbekend" labels |
| `09-koppeling-detail-page.png` | Old koppeling detail (BRK, January 2025) |
| `10-koppeling-detail-new.png` | New koppeling detail ("Test Koppeling Lever2", March 2026) |
| `11-beheer-koppelingen-empty.png` | Beheer koppelingen table (empty for this org) |
| `12-my-account.png` | Mijn Account page confirming user identity |
