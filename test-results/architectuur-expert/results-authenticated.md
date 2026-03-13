# Test Results: Architectuur Expert (Authenticated)

**Date:** 2026-03-10
**Persona:** Dr. Sarah de Vries -- Senior Enterprise Architect, VNG
**Username:** sarah.devries@test.nl
**Groups:** vng-raadpleger, gebruik-beheerder, software-catalog-users
**Frontend:** http://localhost:3000
**Backend:** http://localhost:8080
**Browser:** Playwright (browser-7, headless Chromium)
**Authentication:** ENABLE_AUTHENTICATION=false in frontend; backend session via Nextcloud login

---

## Environment Notes

- Frontend runtime config has `ENABLE_AUTHENTICATION: false` -- the frontend operates without user authentication. All tests reflect unauthenticated frontend behavior while using authenticated backend API calls via curl.
- The Nextcloud session cookie (port 8080) does not transfer to the frontend (port 3000) due to different origins.
- Homepage CMS page returns 500 (`/api/apps/opencatalogi/api/pages/home`), falling back to default layout.
- Referentiearchitectuur CMS page returns 404 (`/api/apps/opencatalogi/api/pages/referentiearchitectuur`).

---

## Issue #148: (VNGR) De GEMMA-architectuur is opvraagbaar met een API

**Status: PARTIAL**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [API] OAS documentation accessible at `/api/registers/4/oas` | PASS | Returns full OpenAPI 3.1.0 spec with tags for Element, Model, Organization, Property Definition, Relation, View. HTTP 200. |
| 2 | [API] /elements endpoint returns ArchiMate elements with correct counts | PASS | Schema 20 (elements): 2741 elements returned. Response time: 16ms. |
| 3 | [API] Elements include the ArchiMate-type field | PASS | Elements have `type` field (e.g., "Capability"). |
| 4 | [API] Empty properties are omitted from element responses | PASS | Element keys: `[identifier, type, objectId, bron, id, xml, @self, organisation]` -- no empty property fields visible. |
| 5 | [API] /relations endpoint returns relations correctly (not "bad gateway") | PASS | Schema 24 (relations): 5790 relations returned. HTTP 200. Response time: 15ms. |
| 6 | [API] Relations include the ArchiMate-type field | PASS | Relations have `type` field (e.g., "Flow"). |
| 7 | [API] /views endpoint returns view definitions with correct count | PASS | Schema 21 (views): 249 views returned. Response time: 10ms. |
| 8 | [API] The API supports a model-id query parameter | FAIL | Querying `?model-id=id-b58b6b03-a59d-472b-bd87-88ba77ded4e6` on elements returns 0 results. Elements do not have a `model_identifier` field, so filtering by model-id does not work. |
| 9 | [API] /models endpoint returns available models | PASS | Schema 22 (models): 1 model ("GEMMA") returned. Response time: 7ms. |
| 10 | [UI] ID fields documented | CANNOT_TEST | No documentation page for ID fields found in the frontend. |
| 11 | [UI] GEMMA model downloadable via "Gemma downloaden" button | FAIL | Button exists on `/mijn-omgeving` page. Clicking it triggers CORS error: frontend at :3000 tries to fetch directly from `http://localhost:8080/apps/openregister/api/objects/vng-gemma/model` instead of using the `/api/apps/` proxy. Error: "Fout bij gemma downloaden." Screenshot: `gemma-download-error.png`. |
| 12 | [HYBRID] Downloaded XML importable into Archi | CANNOT_TEST | Download fails (see #11), so import cannot be tested. |
| 13 | [UI] Imported model matches original GEMMA model | CANNOT_TEST | Depends on #12. |

### API Summary

| Endpoint | Schema | Total Objects | Response Time |
|----------|--------|--------------|---------------|
| Elements | 20 | 2,741 | ~16ms |
| Views | 21 | 249 | ~10ms |
| Models | 22 | 1 | ~7ms |
| Property Definitions | 23 | 74 | ~7ms |
| Relations | 24 | 5,790 | ~15ms |

### Bugs Found

1. **GEMMA download CORS error** -- The "GEMMA downloaden" button on `/mijn-omgeving` fetches directly from the backend host (port 8080) instead of routing through the frontend proxy (`/api/apps/...`). This causes a CORS block and shows "Fout bij gemma downloaden."
2. **Model-id filter not functional** -- The `model-id` query parameter on the elements endpoint returns 0 results because elements do not have a `model_identifier` field linking them to a model.

---

## Issue #160: (VNGR) Performance plotten views tbv ID-77

**Status: FAIL**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [UI] Largest view (388 nodes) loads within 11 seconds | FAIL | The SVG container renders but remains empty (no ArchiMate elements visible). JointJS library fails with `SVGMatrix` errors. View is completely blank. |
| 2 | [UI] Each loading phase ~3 seconds average | FAIL | No phases observable -- rendering does not complete. |
| 3 | [UI] Smaller views load in under 7 seconds | FAIL | Tested "Actoren en rollen" view. SVG container appears in ~2 seconds but renders blank. Same SVGMatrix error. |
| 4 | [UI] Views become interactive (tooltips, zoom) | FAIL | No content is rendered, so no interactivity possible. Pan/zoom library initializes but has nothing to pan/zoom. |
| 5 | [API] Backend API for single view returns within ~0.5s | FAIL | Benchmark view (Poster basisbeveiligingsniveau, 1.17 MB response) takes ~2.1 seconds. Smaller views take ~0.5-1s. The benchmark view exceeds the 0.5s target by 4x. |
| 6 | [UI] Large views display a loading indicator | FAIL | No loading indicator shown. The page transitions from "Selecteer een weergave" to blank SVG container without any loading state. |
| 7 | [UI] Acceptable performance on Chrome/Edge/Firefox | CANNOT_TEST | Views don't render at all, so cross-browser testing is moot. |
| 8 | [API] Benchmark view is "Poster basisbeveiligingsniveau" (388 nodes) | PASS | View exists in the API (id=id-50685fee30484963a4050ea10e6d5e25), returns 1.17 MB of data. |
| 9 | [UI] Warning/loading indicator for large views | FAIL | No indicator of any kind shown. |

### Technical Details

- The view selector dropdown loads 100 views (out of 249 total). The benchmark view "Poster basisbeveiligingsniveau" is NOT in the first 100 and cannot be found by typing in the search box (client-side filter only).
- The `?selected=` URL parameter does NOT preselect a view -- navigating to `/views?selected=<id>` shows the default "Selecteer een weergave..." state.
- SVG rendering fails with: `TypeError: Failed to set the 'a' property on 'SVGMatrix': The provided double value is non-finite.`
- The SVG container initializes (id="svg-container", 100% x 800px with border) but the `joint-cells-layer` group remains empty.
- View list API takes ~5.4 seconds for 100 items.

### Bugs Found

1. **SVG view rendering completely broken** -- JointJS library fails to render any ArchiMate elements into the SVG. SVGMatrix errors indicate NaN/Infinity values in coordinate calculations.
2. **View selector limited to 100 items** -- Only 100 of 249 views are loaded in the dropdown. No pagination or "load more" mechanism.
3. **URL parameter `?selected=` not honored** -- Deep-linking to a specific view via URL does not work.
4. **No loading indicator** -- No visual feedback while view data is being fetched and (attempting to be) rendered.

---

## Issue #135: (VNGR) Valideren van non-functionele eisen voor component Referentiearchitectuur

**Status: FAIL**

**Note:** This issue has no detailed acceptance criteria in issues.md. Testing based on general non-functional requirements for the Referentiearchitectuur component (Step 22).

### Non-Functional Requirements Tested

| Requirement | Status | Evidence |
|-------------|--------|----------|
| **Functionality** -- Views render correctly | FAIL | Views do not render at all (blank SVG, SVGMatrix errors). |
| **Performance** -- Views load in acceptable time | FAIL | API responses for large views exceed 0.5s target (2.1s for benchmark). Client-side rendering fails entirely. |
| **Usability** -- View selector is intuitive | PARTIAL | Dropdown works for search/selection. But: limited to 100 views, no pagination, no URL deep-linking, no loading indicators. |
| **Reliability** -- Component handles errors gracefully | FAIL | SVGMatrix errors are uncaught. No user-facing error message when view rendering fails. GEMMA download fails with CORS error showing minimal error text. AMEFF export throws `falset()` undefined function error (HTTP 500). |
| **Accessibility** -- WCAG AA compliance | PARTIAL | View selector uses ARIA roles (combobox, listbox, options). But SVG content would need accessible labels/descriptions (untestable since rendering fails). |
| **Data integrity** -- API returns consistent data | PASS | All API endpoints return consistent counts and correct data structures. Schema mapping is correct (elements, relations, views, models, property definitions). |

### Additional Issues Found

1. **AMEFF export broken** -- POST to `/api/archimate/export` returns HTTP 500: `Call to undefined function OCA\SoftwareCatalog\Service\falset()` in `ArchiMateExportService.php` line 1214. This is a typo (`falset()` instead of `false`).
2. **Referentiearchitectuur CMS page missing** -- `/referentiearchitectuur` route returns empty page (CMS page not configured, 404 on pages API).

---

## Console Error Summary

| Page | Error | Severity |
|------|-------|----------|
| `/` (Home) | 500 on `/api/apps/opencatalogi/api/pages/home` | Medium -- falls back to default |
| `/mijn-omgeving` | CORS error on GEMMA download (fetches from :8080 directly) | High -- blocks GEMMA download |
| `/views` (with view selected) | `SVGMatrix: non-finite double value` (2 occurrences) | Critical -- blocks all view rendering |
| `/referentiearchitectuur` | 404 on CMS page | Medium -- page content missing |

## Network Performance Summary

| Request | Time | Status |
|---------|------|--------|
| View list (100 items) | ~5.4s | SLOW |
| Single view (benchmark, 1.17 MB) | ~2.1s | SLOW (target: 0.5s) |
| Elements list (limit=0, count only) | ~14ms | OK |
| Models list | ~7ms | OK |
| Views list (count only) | ~10ms | OK |

---

## Test Data Cleanup

No test data was created during testing. All tests were read-only (API GET requests and UI navigation).

---

## Overall Summary

| Issue | Status | Key Blocker |
|-------|--------|-------------|
| #148 | PARTIAL (9/13 criteria) | GEMMA download CORS error; model-id filter non-functional |
| #160 | FAIL (1/9 criteria) | SVG view rendering completely broken (JointJS SVGMatrix errors) |
| #135 | FAIL | Views don't render; AMEFF export has `falset()` typo; no error handling |

### Critical Bugs (Blockers)

1. **SVG view rendering broken** -- No ArchiMate views can be displayed. JointJS fails with SVGMatrix coordinate errors. This blocks issues #160 and #135.
2. **AMEFF export `falset()` typo** -- `ArchiMateExportService.php:1214` calls undefined function `falset()`. Blocks all AMEFF exports.
3. **GEMMA download CORS** -- Frontend uses wrong base URL for GEMMA model download, causing CORS block.

### Medium Bugs

4. **View selector limited to 100 items** -- 149 views are inaccessible from the dropdown.
5. **URL deep-linking for views broken** -- `?selected=<id>` parameter is ignored.
6. **Model-id query filter non-functional** -- Elements cannot be filtered by model.
7. **No loading indicators on views page** -- No visual feedback during view loading/rendering.
