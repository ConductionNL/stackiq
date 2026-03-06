# Test Results: Samenwerking (Authenticated)

**Persona:** Linda Bakker -- Coordinator at a municipal collaboration (samenwerkingsverband)
**Role:** Gebruik-beheerder
**Login:** linda.bakker@test.nl
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Date:** 2026-03-02 (Re-test #5)
**Browser:** Playwright (browser-7, headless)

---

## Login Verification

- **Status:** PASS
- **Details:** Successfully logged in as linda.bakker@test.nl with password WelcomeToTest2026. Dashboard loaded at `/beheer` showing "Test Samenwerking" in the organization selector dropdown. No crashes or TypeErrors on login.
- **localStorage cleared** before login as required
- **Console errors on login:** 2x 404 for organisation register object (persistent known issue -- Nextcloud org UUID vs register object UUID mismatch)
- **Screenshot:** `01-login-dashboard.png`

---

## Issue #57: Pakketten opvoeren voor samenwerkingsverband

**Title:** Als gebruik-beheerder van een samenwerkingsverband wil ik softwarepakketten kunnen opvoeren voor de gemeenten waarvoor we werken
**GitHub:** https://github.com/VNG-Realisatie/Softwarecatalogus/issues/57
**Labels:** Gebruik, PvE eis
**Test Step:** Step 20 (Samenwerkingen en Multi-Organisatie Beheer)
**Previous Status:** PARTIAL (re-test #4), FAIL (re-test #3), PARTIAL (re-test #1)

### Acceptance Criteria Results

| # | Criterion | Type | Result | Notes |
|---|-----------|------|--------|-------|
| 1 | Samenwerking user can log in and see the dashboard without crash | HYBRID | **PASS** | Login succeeded, dashboard at /beheer loaded without crash. Organisation "Test Samenwerking" displayed in dropdown. |
| 2 | Dashboard shows organization name ("Test Samenwerking") | UI | **PASS** | "Test Samenwerking" visible in the organisation selector dropdown on the dashboard. |
| 3 | No `TypeError: Cannot read properties of undefined` in console | HYBRID | **PASS** | Console errors are only 404s for the organisation register object (UUID mismatch). No TypeError present anywhere. The previously reported crash is confirmed FIXED. |
| 4 | Welcome section renders correctly for gebruik-beheerder role | UI | **PASS** | Welcome card "Welkom in de softwarecatalogus" renders with three action descriptions: "Dienst registreren", "Gebruik registreren", "Koppeling registreren". Links to "Mijn Account" and "Mijn Organisatie" are present and functional. |
| 5 | Wizards are available for samenwerking organizations | UI | **PASS** | **IMPROVEMENT since re-test #4.** Dashboard now shows three wizard buttons: "Applicatie toevoegen", "Koppeling toevoegen", "Dienst toevoegen". All three wizards load correctly: Applicatie wizard at /forms/gebruik/applicatie, Koppeling wizard at /forms/gebruik/koppeling, Dienst wizard at /forms/gebruik/dienst. The "Geen wizards beschikbaar" message from re-test #4 is gone. |
| 6 | Samenwerking user can register packages on behalf of member municipalities | UI | **FAIL** | Not implemented. The wizard can add applications to the samenwerking's own landscape, but there is no mechanism to register packages **on behalf of member municipalities**. No member municipality selection, delegation, or "namens" (on behalf of) functionality exists. Additionally, the Applicatie wizard search returns "No options" with a server error: the frontend searches schema slug "applicatie" which does not exist in the voorzieningen register (the correct slug is "module"). |

### Key Findings

**Wizard Availability (Fixed):** The three wizard buttons (Applicatie toevoegen, Koppeling toevoegen, Dienst toevoegen) are now visible on the dashboard for the samenwerking organisation. This is a significant improvement over re-test #4 where the dashboard showed "Geen wizards beschikbaar."

**Applicatie Wizard Schema Bug (NEW):** The Applicatie wizard at /forms/gebruik/applicatie attempts to query schema slug "applicatie" in the voorzieningen register, but this schema does not exist. The correct slug is "module" (which contains 6,106 entries). The server returns "Schema not found" (ValidationException at ObjectService.php:501), causing the application dropdown to show "No options" regardless of search term. This means the samenwerking user **cannot complete the application registration wizard**.

**Koppeling and Dienst Wizards:** Both load correctly with proper multi-step forms. The Koppeling wizard shows: 1) Een koppeling zoeken, 2) Gebruiksinformatie, 3) Controleren. The Dienst wizard shows: 1) Dienst zoeken, 2) Gebruiksinformatie, 3) Controleren. Both have application selection dropdowns (which may also be affected by the "applicatie" vs "module" schema slug mismatch).

**Organisation Page:** The "Mijn Organisatie" page shows "Test Samenwerking" with "Geen korte beschrijving" but all action buttons (Bewerk contactgegevens, Bewerk korte beschrijving, Bewerk lange beschrijving, Deelnames) are **disabled**. The "Deelnames" button is particularly relevant for samenwerking member management.

**Mijn Account:** Shows correct user data: E-mailadres: linda.bakker@test.nl, Voornaam: Linda, Achternaam: Bakker, Organisatie: Test Samenwerking, Functie: Coordinator. "Bewerken" button is available.

### Organisation UUID Mismatch (Persistent)

- Nextcloud org UUID: `5ba08c6a-5fd8-48f0-ba14-99d9f974159e`
- Register object UUID: `5b7c5db6-be83-4727-845b-785f69f9ad09`
- The frontend tries to fetch `/api/objects/voorzieningen/organisatie/{nextcloud-org-uuid}` which returns 404 because the register object has a different UUID
- This causes 2-3 console 404 errors on every page load
- This likely causes the "Mijn Organisatie" action buttons to be disabled (no organisation data loaded)

### Verdict: **PARTIAL**

Criteria 1-5 pass (wizards are now available -- major improvement), but criterion 6 fails because the core samenwerking feature (registering packages on behalf of member municipalities) is not implemented, and the applicatie wizard itself is broken due to a schema slug mismatch ("applicatie" vs "module").

### Comparison with Previous Test Runs

| Run | Status | Key Finding |
|-----|--------|-------------|
| Re-test #1 | PARTIAL | Initial findings |
| Re-test #2 | FAIL | Org switch crash, no wizards, 404 errors |
| Re-test #3 | FAIL | Same issues persist: org switch crash, no wizards, 404 errors |
| Re-test #4 | PARTIAL | TypeError crash FIXED. Dashboard loads. Wizards still missing from dashboard. |
| Re-test #5 (current) | **PARTIAL** | Wizard buttons now visible on dashboard (5/6 criteria pass). Applicatie wizard broken by schema slug mismatch. Member delegation not implemented. |

### Evidence

| Screenshot | Description |
|------------|-------------|
| `01-login-dashboard.png` | Dashboard after login showing "Test Samenwerking" and three wizard buttons |
| `02-applicatie-wizard.png` | Applicatie wizard step 1 (multi-step form loaded) |
| `03-koppeling-wizard.png` | Koppeling wizard step 1 |
| `04-dashboard-full.png` | Full dashboard page showing wizard buttons and welcome section |
| `05-dienst-wizard.png` | Dienst wizard step 1 |
| `06-my-organisation.png` | My Organisation page with disabled action buttons |
| `07-org-actions-disabled.png` | Organisation actions dropdown showing all options disabled |
| `08-my-account.png` | Mijn Account page with correct user data |
| `09-applicatie-wizard-no-results.png` | Applicatie wizard showing "No options" due to schema slug mismatch |

---

## Issue #186: Koppelingen

**Title:** Koppelingen
**GitHub:** https://github.com/VNG-Realisatie/Softwarecatalogus/issues/186
**Labels:** Aanbod, Bevinding, Restpunt, Koppeling
**Test Step:** Step 11 (Koppeling wizard)
**Previous Status:** PARTIAL (re-test #4), PARTIAL (re-test #3, wizard-only testing)

### Acceptance Criteria Results

| # | Criterion | Type | Result | Notes |
|---|-----------|------|--------|-------|
| 1 | Koppelingen display in a table format with readable titles (not blank or UUID-only) | API | **FAIL** | On the search page (/zoeken), koppelingen display with arrow-only titles ("←", "→", "↔") instead of readable names. Legacy koppelingen with orphaned references show "Onbekend" for missing modules. The title rendering uses a template like "ModuleA → ModuleB" but when modules don't exist, the title degrades to just the arrow character. Per testing note: this is caused by bad client data (orphaned references), but newly created koppelingen (e.g., "Test Wizard App → DigiD") do display correct readable titles. The search filtered by `type=koppeling` returns 0 results, while general search shows koppelingen mixed with other types. |
| 2 | Koppelingen linked to "buitengemeentelijke voorzieningen" correctly display the referenced external service | API | **PASS** | The koppeling "Test Wizard App → DigiD" correctly displays "Buitengemeentelijke voorziening: DigiD" on its detail page. The legacy koppeling "← BRK - Basisregistratie Kadaster" also correctly resolves the external service name. |
| 3 | Koppelingen do not reference non-existent applications (graceful handling) | API | **PARTIAL** | The application does not crash when referencing non-existent applications -- pages render without JavaScript errors. However: (a) The koppeling visual display shows literal "null" text for missing Applicatie A. (b) The koppeling title h1 shows a UUID instead of the application name (e.g., "1e041054-4a21-47b9-94ca-a36c363ed49b → DigiD" instead of "Test Wizard App → DigiD"). (c) Standaardversies show UUIDs instead of names because the `/api/names/{uuid}` endpoint returns 404 for those references. |
| 4 | Detail page shows all relevant fields: name, type, transport protocol, linked applications, external service | UI | **PASS** | Detail page at /publicatie/{uuid} shows all fields: koppeling heading, visual connection display (A → B), Applicatie A, Buitengemeentelijke voorziening, Richting, Transportprotocol, Status, Intermediair, Standaardversies. The "Applicaties" tab shows linked application cards with description, supplier, reference components, and date. All relevant fields are present and rendered. |
| 5 | Koppeling detail page at /publicatie/{uuid} renders correctly | API | **PASS** | Tested two koppeling detail pages: (1) "Test Wizard App → DigiD" at /publicatie/97e3c3f1-4ddb-4734-beec-9e9fb611864f renders with correct fields and linked application card. (2) Legacy koppeling "←" at /publicatie/c8a8323e-650b-5577-9343-271d31568368 renders without crash, showing resolved fields (BRK - Basisregistratie Kadaster, Enable-U 2Secure as intermediair). Pages render without crash. Breadcrumb shows Home > Zoeken > Koppeling. |

### Detailed Findings: Search Page (/zoeken)

**General search (no type filter):** Shows 12,693 results sorted A-Z. Koppelingen appear mixed with other types (Applicatie, Organisatie). Koppelingen are identifiable by the "Koppeling" type badge and chain-link icon.

**Filtered search (`?type=koppeling`):** Returns **0 results** with "Geen resultaten gevonden" message. This is a significant bug -- the type filter for koppelingen does not work, despite 3,427 koppelingen existing in the API.

**Koppeling display in search results:**
- Legacy koppelingen with orphaned references show arrow-only titles ("←", "→", "↔") with "Onbekend" for missing module names
- Newly created koppelingen (e.g., "Test Wizard App → DigiD") show correct readable titles
- Standaardversies display as UUIDs in search cards (e.g., "419ba65d-7202-4195-babd-e6a1d493bfd4") because the `/api/names/` endpoint returns 404 for these references

### Detailed Findings: Koppeling Detail Pages

**Newly created koppeling: "Test Wizard App → DigiD"** (UUID: `97e3c3f1-4ddb-4734-beec-9e9fb611864f`)
- Page title (h1): "1e041054-4a21-47b9-94ca-a36c363ed49b → DigiD" -- **BUG: shows UUID of Applicatie A instead of its name**
- Koppeling section correctly shows: "Test Wizard App → DigiD"
- Applicatie A: Test Wizard App (resolved correctly in body)
- Buitengemeentelijke voorziening: DigiD (resolved correctly)
- Richting: AnaarB (→)
- Status: in gebruik
- Applicaties tab: Shows "Test Wizard App" (Aangeboden door Test Leverancier BV) with full details
- **Screenshot:** `14-koppeling-detail-good.png`

**Legacy koppeling: "←"** (UUID: `c8a8323e-650b-5577-9343-271d31568368`)
- Page title (h1): "←" (just an arrow)
- Koppeling visual display: "null ← BRK - Basisregistratie Kadaster" -- **BUG: "null" literal text for missing Applicatie A**
- Applicatie A: "-" (field shows dash for missing reference)
- Buitengemeentelijke voorziening: BRK - Basisregistratie Kadaster (resolved correctly)
- Richting: BnaarA (←)
- Transportprotocol: extern
- Status: In gebruik
- Intermediair: Enable-U 2Secure (resolved correctly)
- Standaardversies: "419ba65d-7202-4195-babd-e6a1d493bfd4" -- **UUID instead of human-readable name** (name resolution fails with 404)
- Applicaties tab: Shows "Enable-U 2Secure" (Aangeboden door Enable U) with full details
- **Screenshot:** `15-koppeling-detail-legacy.png`

### Koppeling Wizard

The wizard was accessible from the dashboard "Koppeling toevoegen" button:
- Title: "Uw Koppeling toevoegen"
- Steps: 1) Een koppeling zoeken (with sub-step Gebruiksinformatie), 3) Controleren
- Includes application dropdown for selecting the source application
- "Controleren op bestaande koppeling" guidance section with two methods
- Info alert about using the search page as an alternative workflow
- "Ik kan de gewenste koppeling niet vinden" fallback button (initially disabled until app selected)
- "Volgende" button disabled until application selected (correct validation)
- **Screenshot:** `03-koppeling-wizard.png`

### UI Bugs Found

1. **Koppeling page title (h1) shows UUID instead of application name (BUG):** On the koppeling detail page, the h1 heading renders "1e041054-4a21-47b9-94ca-a36c363ed49b → DigiD" instead of "Test Wizard App → DigiD". The koppeling body section correctly resolves the name, but the page title uses the raw UUID. This is a code bug in the title rendering logic.

2. **"null" literal text for missing references (BUG):** When a koppeling's Applicatie A or B is null/missing, the visual connection display renders literal "null" text (e.g., "null ← BRK"). The field correctly shows "-", but the visual display does not null-check. This is a code bug.

3. **Search type filter for "koppeling" returns 0 results (BUG):** Searching with `?type=koppeling` returns "Geen resultaten gevonden" despite 3,427 koppelingen existing in the API. The type filter is broken for koppelingen.

4. **Standaardversies show UUIDs instead of names (BUG/DATA):** The `/api/names/{uuid}` endpoint returns 404 for standaardversie references, causing UUIDs to be displayed instead of human-readable standard names. This may be a missing name resolution endpoint or data issue.

5. **Arrow-only titles for legacy koppelingen (DATA QUALITY):** Legacy koppelingen with orphaned module references display titles like "←", "→", "↔" with "Onbekend" for missing modules. Per testing notes, this is caused by bad client data, not a code bug. Newly created koppelingen display correct titles.

### Console Errors

| Page | Error Count | Details |
|------|-------------|---------|
| /zoeken | 8 | 404 for org object (x1), 404 for /api/names/ (x7 -- standaardversie UUID resolution failures) |
| /publicatie/97e3c3f1... (Test Wizard) | 8 | 404 for org object, multiple 404 for name resolution |
| /publicatie/c8a8323e... (legacy) | 2 | 404 for org object, 404 for /api/names/ |
| /forms/gebruik/koppeling | 3 | 404 for org object (x2), 404 for org deelnemers |

### Verdict: **PARTIAL**

Criteria 2, 4, and 5 pass -- detail pages render correctly with all relevant fields, buitengemeentelijke voorzieningen display correctly, and pages render without crash. Criterion 1 fails because the type filter returns 0 results and legacy koppelingen show arrow-only titles (though newly created ones are correct). Criterion 3 is partial -- no crashes occur (graceful in the stability sense), but "null" text and UUIDs are displayed for missing references.

### Comparison with Previous Test Runs

| Run | Status | Key Change |
|-----|--------|------------|
| Re-test #3 | PARTIAL | No koppelingen data found; wizard tested; display criteria untestable |
| Re-test #4 | PARTIAL | 2 koppelingen found via beheer page; detail pages verified; "null" display bug found |
| Re-test #5 (current) | **PARTIAL** | Search page tested: type filter broken (0 results). Detail pages verified (PASS). New bugs found: h1 shows UUID, "null" text persists, name resolution 404s. |

### Evidence

| Screenshot | Description |
|------------|-------------|
| `10-koppelingen-search-empty.png` | Search page with `type=koppeling` filter returning 0 results |
| `11-search-results-koppelingen.png` | Search results showing koppelingen with arrow-only titles |
| `12-search-koppelingen-titles.png` | Close-up of koppeling search results with "Onbekend" and UUID standaardversies |
| `13-test-wizard-koppeling.png` | "Test Wizard App → DigiD" koppeling in search results (correct title) |
| `14-koppeling-detail-good.png` | Detail page of properly created koppeling (h1 UUID bug visible) |
| `15-koppeling-detail-legacy.png` | Detail page of legacy koppeling with "null" text and UUID standaardversies |

---

## Cross-Cutting Observations

### Organisation Register Object 404 (Persistent)

Every authenticated page load produces 1-3 console 404 errors because the frontend fetches the organisation register object using the Nextcloud org UUID (`5ba08c6a-5fd8-48f0-ba14-99d9f974159e`) rather than the register object's own UUID (`5b7c5db6-be83-4727-845b-785f69f9ad09`). This is a known architectural issue (dual organisation system). It does not cause crashes but:
- Produces persistent 404 errors in the console
- Causes "Mijn Organisatie" page to show minimal data (name only, no details)
- Causes all organisation action buttons to be disabled (Bewerk contactgegevens, Bewerk korte beschrijving, Bewerk lange beschrijving, Deelnames)

### Organisation Actions Disabled

On the "Mijn Organisatie" page, all four action buttons in the "Acties" dropdown are disabled:
- Bewerk contactgegevens
- Bewerk korte beschrijving
- Bewerk lange beschrijving
- Deelnames

This appears to be caused by the organisation register object 404 -- without the organisation data, the edit actions cannot be enabled. The "Deelnames" button is particularly important for the samenwerking persona to manage member municipalities.

### Applicatie Schema Slug Mismatch (NEW)

The Applicatie wizard at `/forms/gebruik/applicatie` queries schema slug "applicatie" in the voorzieningen register. This schema does not exist. The correct slug is "module" (6,106 entries). The server returns `ValidationException: Schema not found` at `ObjectService.php:501`. This prevents the samenwerking user (and likely all users) from completing the application registration wizard via this route.

### Navigation

The authenticated user has access to the "Beheer" section via the top navigation bar. The dashboard shows three wizard buttons for quick access. Sub-pages (My Account, My Organisation) are accessible via links in the welcome section.

### Performance

All pages loaded within acceptable timeframes. No API calls exceeded the 500ms SLOW threshold or 1000ms PERFORMANCE_FAIL threshold. The search page with 12,693 results loaded in under 5 seconds.

---

## Console Errors Summary

### Persistent Errors (every authenticated page)
1. **Organisation 404** -- `/api/objects/voorzieningen/organisatie/5ba08c6a-5fd8-48f0-ba14-99d9f974159e` returns 404 on every page due to Nextcloud org UUID / register object UUID mismatch.

### Page-Specific Errors
2. **Names API 404** -- `/api/names/{uuid}` returns 404 on koppeling pages for standaardversie references and non-existent application references.
3. **Schema not found** -- `/api/objects/voorzieningen/applicatie` returns server error because schema slug "applicatie" does not exist in the voorzieningen register (should be "module").
4. **Organisation deelnemers 404** -- `/api/objects/voorzieningen/organisatie/{uuid}?_extend[]=deelnemers` returns 404 on wizard pages.

### Fixed Since Previous Tests
- **TypeError crash on org switch** -- Previously reported `TypeError: Cannot read properties of undefined (reading 'includes')` is confirmed FIXED.
- **Wizard availability** -- Dashboard now shows three wizard buttons for samenwerking organisations (was "Geen wizards beschikbaar" in re-test #4).

### Ignored (as per instructions)
- Favicon 404s
- ResizeObserver loop errors
- Service worker failures

---

## Performance Summary

| Page | Load Time | Status |
|------|-----------|--------|
| /login | Normal | OK |
| /beheer | Normal | OK (despite 404 errors) |
| /beheer/my-account | Normal | OK |
| /beheer/my-organisation | Normal | OK (despite 404, actions disabled) |
| /forms/gebruik/applicatie | Normal | OK (but search returns no results) |
| /forms/gebruik/koppeling | Normal | OK |
| /forms/gebruik/dienst | Normal | OK |
| /zoeken | Normal | OK |
| /zoeken?type=koppeling | Normal | OK (but 0 results returned) |
| /publicatie/{uuid} | Normal | OK |

No API calls exceeded 500ms (SLOW) or 1000ms (PERFORMANCE_FAIL) thresholds.

---

## Overall Summary

| Issue | Title | Previous | Current | Key Change |
|-------|-------|----------|---------|------------|
| #57 | Pakketten opvoeren voor samenwerkingsverband | PARTIAL (r4) | **PARTIAL** | Wizard buttons now visible on dashboard (was "Geen wizards"). Applicatie wizard broken by schema slug mismatch. Member delegation not implemented. |
| #186 | Koppelingen | PARTIAL (r4) | **PARTIAL** | Search page tested: type filter broken. Detail pages render (PASS). New h1 UUID bug found. Name resolution 404s cause UUID display. |

### New Bugs Found in Re-test #5

1. **[HIGH] Applicatie wizard schema slug mismatch** -- Frontend queries "applicatie" schema but correct slug is "module". No applications can be found or registered via the wizard.
2. **[HIGH] Koppeling type filter returns 0 results** -- Search with `?type=koppeling` returns empty despite 3,427 koppelingen in the API.
3. **[MEDIUM] Koppeling h1 title shows UUID** -- Page title shows application UUID instead of resolved name (e.g., "1e041054-... → DigiD" instead of "Test Wizard App → DigiD").
4. **[MEDIUM] "null" literal text in koppeling visual display** -- Missing module references render as literal "null" text instead of graceful fallback.
5. **[MEDIUM] Standaardversie names not resolving** -- `/api/names/{uuid}` returns 404 for standaardversie references, showing raw UUIDs.
6. **[LOW] Organisation action buttons all disabled** -- On Mijn Organisatie, all actions (including Deelnames for member management) are disabled due to org object 404.

### Recommendations

1. **Issue #57:**
   - Fix the schema slug in the Applicatie wizard: change "applicatie" to "module" (or configure proper alias)
   - Resolve the organisation UUID mismatch to enable Mijn Organisatie action buttons
   - Enable the "Deelnames" functionality for samenwerking member municipality management
   - Implement samenwerking-specific features: acting on behalf of members, collective license management

2. **Issue #186:**
   - Fix the koppeling type filter on the search page (currently returns 0 results)
   - Fix the h1 title rendering to use resolved application names instead of UUIDs
   - Fix the "null" text rendering in the visual connection display -- use "-" or "Onbekend" instead
   - Fix or implement the `/api/names/` endpoint for standaardversie references
   - Consider displaying "Applicatie niet gevonden" for non-existent application references

3. **General:**
   - Resolve the Nextcloud org UUID / register object UUID mapping so organisation 404 errors stop
   - Enable organisation action buttons when org data can be loaded

---

## Screenshots Index

| File | Description |
|------|-------------|
| `01-login-dashboard.png` | Dashboard after login showing "Test Samenwerking" and three wizard buttons |
| `02-applicatie-wizard.png` | Applicatie wizard step 1 (multi-step form loaded) |
| `03-koppeling-wizard.png` | Koppeling wizard step 1 |
| `04-dashboard-full.png` | Full dashboard page showing wizard buttons and welcome section |
| `05-dienst-wizard.png` | Dienst wizard step 1 (Toevoegen dienst) |
| `06-my-organisation.png` | My Organisation page showing "Geen korte beschrijving" |
| `07-org-actions-disabled.png` | Organisation actions dropdown with all options disabled |
| `08-my-account.png` | Mijn Account page with correct user data |
| `09-applicatie-wizard-no-results.png` | Applicatie wizard showing "No options" (schema slug mismatch) |
| `10-koppelingen-search-empty.png` | Search page with type=koppeling filter returning 0 results |
| `11-search-results-koppelingen.png` | General search results showing koppelingen with arrow-only titles |
| `12-search-koppelingen-titles.png` | Close-up of koppeling entries with "Onbekend" labels and UUID standaardversies |
| `13-test-wizard-koppeling.png` | "Test Wizard App → DigiD" in search results (correct readable title) |
| `14-koppeling-detail-good.png` | Detail page of newly created koppeling (h1 UUID bug visible) |
| `15-koppeling-detail-legacy.png` | Detail page of legacy koppeling with "null" text and UUID standaardversies |
