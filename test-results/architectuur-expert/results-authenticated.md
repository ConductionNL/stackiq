# Test Results: Architectuur Expert (Authenticated)

**Date:** 2026-03-16 (re-test; previous run: 2026-03-10)
**Persona:** Dr. Sarah de Vries -- Senior Enterprise Architect, VNG
**Username:** sarah.devries@test.nl
**Groups:** vng-raadpleger, gebruik-beheerder, software-catalog-users
**Frontend:** http://localhost:3000
**Backend:** http://localhost:8080
**Browser:** Playwright (browser-7, headless Chromium)

---

## Environment Notes

- Login succeeded via frontend `/login`. Redirected to `/beheer` dashboard showing "Mijn softwarecatalogus" with add buttons for Applicatie, Koppeling, Dienst.
- Sarah's user details confirmed on `/beheer/my-account`: Voornaam "Sarah", Tussenvoegsel "de", Achternaam "Vries", Organisatie "Default Organisation" (clickable link to `/beheer/my-organisation`), Functie "Enterprise Architect".
- **Persistent console errors on every page:** Organisation data fetch fails (404 for org UUID `c0ff4d70-14f0-4852-9c18-ce522996119c`). Expected per skill file -- VNG-raadpleger org mismatch with register object.
- **Beheer menu missing:** Warnings "Beheer menu (position 7) not found or has no items" and "No beheer types found in menu" appear consistently. No sidebar navigation for beheer types for this user.
- **Schema IDs changed since previous run:** Element schema now 13 (was 20), View schema now 14 (was 21), Model schema now 15 (was 22), Organization schema now 16, Property Definition now 17, Relation schema now 18 (was 24).

---

## Issue #148: (VNGR) De GEMMA-architectuur is opvraagbaar met een API

**Status: PARTIAL**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [API] OAS documentation accessible at `/api/registers/4/oas` | FAIL | Returns HTTP 500 (HTML error page). **Regression from previous run** where it returned HTTP 200. Register 3 OAS also returns 500. Known bug: register has `organisation` set. |
| 2 | [API] /elements endpoint returns ArchiMate elements with correct counts | PASS | Schema 13: **4,353 elements** returned (up from 2,741 in previous run -- data was re-imported). |
| 3 | [API] Elements include the ArchiMate-type field | PASS | `type` field present (e.g., "Capability"). |
| 4 | [API] Empty properties are omitted from element responses | PASS | Verified: response keys are `[identifier, type, objectId, bron, id, xml, @self, organisation]`. |
| 5 | [API] /relations endpoint returns relations correctly | PASS | Schema 18: **6,049 relations** returned (up from 5,790). HTTP 200. |
| 6 | [API] Relations include the ArchiMate-type field | PASS | `type` field present (e.g., "Access"). |
| 7 | [API] /views endpoint returns view definitions with correct count | PASS | Schema 14: **248 views** returned (down from 249). |
| 8 | [API] The API supports a model-id query parameter | FAIL | Query `?model-id=...` returns 0 results. Parameter not recognized. Consistent with previous run. |
| 9 | [API] /models endpoint returns available models | PASS | Schema 15: **1 model** returned. |
| 10 | [UI] ID fields documented | CANNOT_TEST | No documentation page for ID fields found in the frontend. |
| 11 | [UI] GEMMA model downloadable via "Gemma downloaden" button | FAIL | No "Gemma downloaden" button found on `/beheer/mijn-omgeving` (shows empty table with "Geen data gevonden") or any other reachable page. Previous run found the button but it failed with CORS error. Button appears to have been removed or page routing changed. |
| 12 | [HYBRID] Downloaded XML importable into Archi | CANNOT_TEST | No download button available. |
| 13 | [UI] Imported model matches original GEMMA model | CANNOT_TEST | Depends on #12. |

### API Data Summary (Register 4 -- GEMMA/AMEFF)

| Schema ID | Name | Object Count | Notes |
|-----------|------|-------------|-------|
| 13 | Element | 4,353 | +1,612 vs previous run |
| 14 | View | 248 | -1 vs previous run |
| 15 | Model | 1 | Unchanged |
| 16 | Organization | 1 | New schema (not in previous run) |
| 17 | Property Definition | 74 | Unchanged |
| 18 | Relation | 6,049 | +259 vs previous run |

### Bugs Found

1. **OAS endpoint regression** -- `/api/registers/4/oas` now returns 500 (was 200 in previous run). Both register 3 and 4 affected.
2. **"Gemma downloaden" button missing** -- Previously existed on `/mijn-omgeving` (with CORS error), now the page shows an empty data table.
3. **Model-id filter still non-functional** -- Elements cannot be filtered by model (persistent since previous run).

---

## Issue #160: (VNGR) Performance plotten views tbv ID-77

**Status: CANNOT_TEST (UI) / PARTIAL (API)**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [UI] Largest view (388 nodes) loads within 11 seconds | CANNOT_TEST | Frontend route `/referentiearchitectuur/views/{id}` returns empty page. CMS page fetch 404. |
| 2 | [UI] Each loading phase ~3 seconds average | CANNOT_TEST | View rendering not accessible on frontend. |
| 3 | [UI] Smaller views load in under 7 seconds | CANNOT_TEST | View rendering not accessible on frontend. |
| 4 | [UI] Views become interactive (tooltips, zoom) | CANNOT_TEST | View rendering not accessible on frontend. |
| 5 | [API] Backend API for single view returns within ~0.5s | PASS | View list query: ~0.55s for paginated results. Within acceptable range. |
| 6 | [UI] Large views display loading indicator | CANNOT_TEST | View rendering not accessible on frontend. |
| 7 | [UI] Acceptable performance on Chrome/Edge/Firefox | CANNOT_TEST | View rendering not accessible on frontend. |
| 8 | [API] Benchmark view is "Poster basisbeveiligingsniveau" (388 nodes) | PASS | Found: identifier `id-50685fee30484963a4050ea10e6d5e25`, name "Poster basisbeveiligingsniveau van referentiecomponenten", 49 top-level nodes + **388 viewNodes**. |
| 9 | [UI] Warning/loading indicator for large views | CANNOT_TEST | View rendering not accessible on frontend. |

### Technical Details

- **Referentiearchitectuur pages are broken.** Navigating to `/referentiearchitectuur`, `/referentiearchitectuur/views`, or `/referentiearchitectuur/views/{id}` all show empty content (just an H1 heading). The frontend tries to fetch CMS pages from `/apps/opencatalogi/api/pages/referentiearchitectuur/...` which returns 404. Only "home" and "about" CMS pages exist.
- Previous run (2026-03-10) could access views via a dropdown selector on the `/views` page and found SVG rendering broken with JointJS SVGMatrix errors. That entire page is now inaccessible.
- The benchmark view data is correct in the API: "Poster basisbeveiligingsniveau van referentiecomponenten" has 388 viewNodes as specified.

### Largest Views by Top-Level Node Count

| Rank | Name | Nodes | ViewNodes |
|------|------|-------|-----------|
| 1 | Technologiecomponenten en -services mapping | 114 | -- |
| 2 | RSGB Model | 73 | -- |
| 3 | RGBZ Model | 55 | -- |
| 3 | Bedrijfsfuncties 'klant- en keteninteractie' | 55 | -- |
| 5 | OW standaarden | 54 | -- |
| 6 | Poster betrouwbaarheidscriteria | 52 | -- |
| 7 | Beleidsdomeinen en Iv3 | 52 | -- |
| 8 | **Poster basisbeveiligingsniveau** | **49** | **388** |

### Regression from Previous Run

- Previous run: View selector page loaded, views could be selected from dropdown (limited to 100), SVG rendering attempted but failed with SVGMatrix errors.
- Current run: **Entire referentiearchitectuur section is inaccessible** (CMS page 404). This is a more severe regression.

---

## Issue #135: (VNGR) Valideren van non-functionele eisen voor component Referentiearchitectuur

**Status: CANNOT_TEST**

**Note:** This issue has no detailed acceptance criteria in `issues.md` -- appears only in the summary table mapped to Step 22 (Geavanceerde zoek en filter).

### Non-Functional Requirements Assessment

| Requirement | Status | Evidence |
|-------------|--------|----------|
| **Functionality** -- Architecture views render | CANNOT_TEST | Referentiearchitectuur section completely inaccessible (CMS page 404). |
| **Performance** -- API response times | PASS | View list query: ~0.55s. Element/relation queries: <1s. |
| **Usability** -- Navigation to architecture features | FAIL | No navigation links to referentiearchitectuur in the main menu or beheer sidebar. Only Privacy/Terms in main nav. |
| **Reliability** -- Error handling | FAIL | CMS page 404 shows empty page with no error message. Organisation fetch errors on every page load. |
| **Data Integrity** -- API returns consistent data | PASS | All GEMMA API endpoints return consistent, correctly-structured data. |
| **Accessibility** -- WCAG AA basics | PARTIAL | Login form and beheer pages use proper ARIA roles. Architecture features untestable. |

### Regression from Previous Run

- Previous run assessed this as FAIL due to SVG rendering errors and AMEFF export `falset()` typo.
- Current run: **Cannot even reach the architecture UI** to test. The regression is more severe.

---

## Cross-Cutting Observations

### Critical Issues Found

1. **Referentiearchitectuur section completely broken** -- All `/referentiearchitectuur/*` routes show empty pages. CMS page API returns 404 for all referentiearchitectuur paths. Only "home" and "about" CMS pages exist. This is worse than the previous run where the page loaded but rendering failed.

2. **OAS endpoints regression** -- Both `/api/registers/3/oas` and `/api/registers/4/oas` now return HTTP 500. Previously (2026-03-10), register 4 OAS returned a full OpenAPI 3.1.0 specification.

3. **Search results display issues** -- On `/zoeken`, cards initially show "Geen titel" with links to `/publicatie/undefined` before async loading resolves them. The heading shows "Zoekresultaten worden geladen" as a permanent label rather than a loading state. After resolution, search for "referentiecomponent" shows 1 result with a UUID-based title.

4. **Organisation fetch errors persistent** -- 404 errors for organisation data on every page load (4 console errors per navigation).

5. **Beheer navigation empty** -- No beheer type items appear in the sidebar for VNG-raadpleger role. Warnings logged on every page.

6. **"Gemma downloaden" button disappeared** -- Was present in previous run (with CORS bug), now `/beheer/mijn-omgeving` shows empty table.

### Console Error Summary (Per Navigation)

| Error | Count | Severity |
|-------|-------|----------|
| Organisation fetch 404 | 4 per page | Medium (expected for VNG-raadpleger) |
| CMS page 404 (referentiearchitectuur) | 1 per architecture page | Critical |
| "Beheer menu not found" warning | 2 per beheer page | Low (expected for this role) |

### Screenshots

| File | Description |
|------|-------------|
| `search-geen-titel.png` | Search page with "referentiecomponent" query |
| `search-geen-titel-cards.png` | Search results showing UUID-based card after loading |

---

## Test Data Cleanup

No test data was created during testing. All tests were read-only (API GET requests and UI navigation).

---

## Overall Summary

| Issue | Status | Previous Status | Change |
|-------|--------|-----------------|--------|
| #148 | PARTIAL (7/9 API pass, 0/4 UI) | PARTIAL (8/9 API pass, 0/4 UI) | Regression: OAS endpoint now 500, Gemma download button gone |
| #160 | CANNOT_TEST (UI) / PARTIAL (API) | FAIL | Regression: entire views section inaccessible (was partially working) |
| #135 | CANNOT_TEST | FAIL | Regression: architecture section unreachable |

### Critical Regressions Since 2026-03-10

1. **OAS endpoint broken** (was working)
2. **Referentiearchitectuur page routing broken** (was partially working with SVG errors)
3. **"Gemma downloaden" button removed/hidden** (was present with CORS bug)

### Persistent Issues (Unchanged)

1. Model-id query filter non-functional
2. Organisation fetch errors for VNG-raadpleger
3. No loading indicators on views
