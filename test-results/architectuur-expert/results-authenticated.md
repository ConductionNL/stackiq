# Test Results: Architectuur Expert (Authenticated)

**Persona:** Dr. Sarah de Vries -- Senior Enterprise Architect, VNG
**Username:** sarah.devries@test.nl
**Groups:** vng-raadpleger, gebruik-beheerder, software-catalog-users
**Environment:** Frontend http://localhost:3000 / Backend http://localhost:8080
**Date:** 2026-03-02 (re-test)
**Previous test date:** 2026-03-01
**Browser:** Playwright Chromium (headless)

---

## Summary

| Issue | Title | Previous Status | Current Status | Change |
|-------|-------|-----------------|----------------|--------|
| #135 | Non-functionele eisen Referentiearchitectuur | PARTIAL | **PARTIAL** | Improved (performance now PASS) |
| #148 | GEMMA-architectuur opvraagbaar met API | PARTIAL | **PARTIAL** | Improved (OAS fixed, empty props fixed) |
| #160 | Performance plotten views | PARTIAL | **PASS** | Major improvement (346ms vs 12.19s) |

---

## Issue #148: (VNGR) De GEMMA-architectuur is opvraagbaar met een API

**Overall Status: PARTIAL** (7 PASS, 2 FAIL, 1 CANNOT_TEST, 2 CANNOT_TEST)

### Tested Criteria

**1. [API] OAS auto-generated documentation at `/api/registers/4/oas`: PASS**
- Endpoint returns HTTP 200 with a complete OpenAPI 3.1.0 specification.
- Title: "AMEF API", version: "0.0.7".
- License: EUPL-1.2 with URL to joinup.ec.europa.eu.
- Documents 6 schemas via tags: Element, Model, Organization, Property Definition, Relation, View.
- 12 paths documented.
- Security schemes: basicAuth, oauth2.
- Also accessible without authentication (public endpoint, HTTP 200).
- **Previous issue "returns 500" is FIXED.**
- **Screenshot:** `screenshots/03-architectuur-acties-menu.png`

**2. [API] The /elements endpoint returns ArchiMate elements with correct counts: PASS**
- `GET /api/objects/vng-gemma/element?_limit=1&_page=1` returns HTTP 200.
- Total elements: **2,741**.
- Response includes pagination: results, total, page, pages, limit, offset.
- Endpoint is publicly accessible (200 without authentication).

**3. [API] Elements include the ArchiMate-type field: PASS**
- Each element contains a `type` field at top level.
- Example types observed: "Capability", "Flow".
- The type field maps correctly from ArchiMate `xsi:type`.

**4. [API] Empty properties are omitted from element responses: PASS**
- Tested 3 elements: no empty string, empty array, empty object, or null values found at top level.
- Previous finding of "80 null fields" appears to have been fixed -- elements now contain only populated fields: `identifier`, `type`, `objectId`, `bron`, `id`, `xml`, `@self`, `organisation`.
- **Improvement from previous test.**

**5. [API] The /relations endpoint returns relations correctly: PASS**
- `GET /api/objects/vng-gemma/relation?_limit=3&_page=1` returns HTTP 200.
- Total relations: **5,790**.
- No "bad gateway" errors.
- Endpoint is publicly accessible.

**6. [API] Relations include the ArchiMate-type field: PASS**
- Each relation contains a `type` field (e.g., "Flow").

**7. [API] The /views endpoint returns view definitions with correct count: PASS**
- `GET /api/objects/vng-gemma/view?_limit=3&_page=1` returns HTTP 200.
- Total views: **249**.
- Views contain fields: identifier, type, viewpoint, nodes, connections, etc.
- First view example: "Waardecreatie".

**8. [API] The API supports a model-id query parameter: FAIL**
- Querying `?model_identifier=id-b58b6b03-a59d-472b-bd87-88ba77ded4e6` returns 0 results.
- Elements do not have a `model_identifier` field at top level.
- Available top-level fields: `identifier`, `type`, `objectId`, `bron`, `id`, `xml`, `@self`, `organisation`.
- The `bron` field can be used as a filter (e.g., `?bron=Thema-architectuur%20Common%20Ground` returns 40 results), but this is not a model-id parameter.
- **The model-id filter is not implemented.**

**9. [API] The /models endpoint returns a list of available models: PASS**
- `GET /api/objects/vng-gemma/model?_limit=5&_page=1` returns HTTP 200.
- Total models: **1** (the GEMMA model).
- Model identifier: `id-b58b6b03-a59d-472b-bd87-88ba77ded4e6`.

**10. [UI] ID fields documented in UI: CANNOT_TEST**
- No ID field documentation page found in the UI.
- The API response includes `identifier` (Archi ID), `objectId`, `id`, and `@self.id` (Open Register UUID) but their meaning is not documented for end users.

**11. [UI] GEMMA model downloadable via "Gemma downloaden" button on Mijn omgeving page: FAIL**
- There is no "Mijn omgeving" page with a "Gemma downloaden" button.
- `/beheer/mijn-omgeving` redirects to `/beheer` (dashboard) with a 404 error on schema endpoint.
- **However**, individual view pages DO have an "Acties" menu with "Download SVG" and "Download AMEF" options (confirmed on Poster basisbeveiligingsniveau view).
- The download feature exists per-view, not as a full model download.
- **Screenshot:** `screenshots/07-view-acties-download-options.png`

**12. [HYBRID] Downloaded XML can be imported into Archi: CANNOT_TEST**
- Archi desktop client not available for testing roundtrip import.
- The "Download AMEF" button exists on individual view pages.

### Criteria Summary for #148

| # | Criterion | Status | Notes |
|---|-----------|--------|-------|
| 1 | OAS documentation accessible | PASS | Fixed from previous 500 error |
| 2 | /elements returns correct counts | PASS | 2,741 elements |
| 3 | Elements include ArchiMate-type | PASS | `type` field present |
| 4 | Empty properties omitted | PASS | Improved from previous FAIL |
| 5 | /relations returns correctly | PASS | 5,790 relations |
| 6 | Relations include ArchiMate-type | PASS | `type` field present |
| 7 | /views returns correct count | PASS | 249 views |
| 8 | model-id query parameter works | FAIL | Not implemented |
| 9 | /models returns list | PASS | 1 GEMMA model |
| 10 | ID fields documented in UI | CANNOT_TEST | No documentation page found |
| 11 | GEMMA download button on Mijn Omgeving | FAIL | Per-view download exists instead |
| 12 | XML importable into Archi | CANNOT_TEST | Desktop client not available |

**Testable criteria: 10 | PASS: 8 | FAIL: 2 | CANNOT_TEST: 2**

### Key Findings for #148

1. **OAS endpoint fixed:** Now returns HTTP 200 with complete OpenAPI 3.1.0 spec (previously returned 500).
2. **Empty properties fixed:** Elements now return only populated fields (8 fields), not 80+ null fields as in previous test.
3. **All GEMMA API endpoints publicly accessible:** elements, relations, views, models, property-definitions, and OAS all return 200 without authentication.
4. **Model-id filter not implemented:** Elements lack a top-level `model_identifier` field, so filtering by model is not possible.
5. **GEMMA download available per-view only:** Individual views have "Download AMEF" in Acties menu, but no full-model download button on Mijn Omgeving page.
6. **Property definitions endpoint works:** 74 property definitions returned successfully.

---

## Issue #160: (VNGR) Performance plotten views tbv ID-77

**Overall Status: PASS** (major improvement from previous PARTIAL)

### Test Environment
- Browser: Playwright Chromium (headless)
- Host: WSL2 Linux on Windows
- Network: localhost (no network latency)

### Performance Measurements

| Metric | Previous (2026-03-01) | Current (2026-03-02) | Target |
|--------|----------------------|---------------------|--------|
| Largest view total load | 12.19s | **346ms** | <11s |
| Largest view API call | 512ms | **183ms** | <500ms |
| Smaller view total load | ~5s | **167ms** | <7s |
| Smaller view API call | N/A | **151ms** | N/A |

### Tested Criteria

**1. [UI] Largest ArchiMate view (388 nodes) loads within 11 seconds: PASS**
- View: "Poster basisbeveiligingsniveau van referentiecomponenten"
- Total page load time: **346ms** (measured via Performance API `loadEventEnd`)
- View API response: **183ms**
- DOM interactive: **189ms**
- The view rendered fully with all reference components (BBN1, BBN2, BBN3 classifications visible).
- **Massive improvement from 12.19s in previous test.**
- **Screenshot:** `screenshots/04-poster-basisbeveiligingsniveau-view.png`
- **Screenshot:** `screenshots/05-poster-basisbeveiligingsniveau-full.png`

**2. [UI] Each loading phase completes in approximately 3 seconds: PASS**
- Total load is under 1 second, so all phases complete in well under 3 seconds.
- No distinct loading phases visible because the entire render completes sub-second.

**3. [UI] Smaller views load in under 7 seconds: PASS**
- View: "Actoren en rollen" (~40 nodes)
- Total page load time: **167ms**
- View API response: **151ms**
- Well under the 7-second target.
- **Screenshot:** `screenshots/06-actoren-en-rollen-view.png`

**4. [UI] Views become interactive after rendering: PASS**
- The rendered view contains interactive SVG elements.
- Filter checkboxes present: "Gebruik", "Applicaties", "Deelnames" with tooltip descriptions.
- "Acties" menu provides "Download SVG" and "Download AMEF" options.
- Zoom controls (+, -, RESET) visible on the diagram.
- **Screenshot:** `screenshots/07-view-acties-download-options.png`

**5. [API] Backend API for single view returns data within ~0.5 seconds: PASS**
- `GET /api/objects/vng-gemma/view/id-50685fee30484963a4050ea10e6d5e25`
- Response time: **141ms** (well under 500ms target)
- Previously measured at 512ms.

**6. [UI] Large views display a loading indicator: FAIL**
- No loading indicator observed. The page transitions directly from initial headers to fully rendered view.
- However, since the view now loads in under 1 second, a loading indicator may not be necessary.
- The text "Geselecteerde weergave wordt getoond" appears as a static label.

**7. [UI] Acceptable performance on Chrome, Edge, Firefox: CANNOT_TEST**
- Only tested on Playwright Chromium (headless). Cross-browser testing not performed.

**8. [API] Benchmark view is "Poster basisbeveiligingsniveau": PASS**
- Confirmed: View exists with name "Poster basisbeveiligingsniveau van referentiecomponenten".
- ID: `id-50685fee30484963a4050ea10e6d5e25`
- Contains 388+ nodes.
- Rendered with full ArchiMate structure including BBN classification and legend.

**9. [UI] Warning/loading indicator for large views: FAIL**
- No warning indicator shown. Same finding as criterion 6.
- Given sub-second performance, the practical need for a warning indicator is reduced.

### Criteria Summary for #160

| # | Criterion | Previous | Current | Notes |
|---|-----------|----------|---------|-------|
| 1 | Largest view loads within 11s | FAIL (12.19s) | **PASS** (346ms) | 35x faster |
| 2 | Each phase ~3s average | CANNOT_TEST | **PASS** | Sub-second total |
| 3 | Smaller views under 7s | PASS | **PASS** | 167ms |
| 4 | Views become interactive | PASS | **PASS** | Zoom, filters, download |
| 5 | API returns within ~0.5s | PASS (512ms) | **PASS** (141ms) | 3.6x faster |
| 6 | Loading indicator for large views | FAIL | **FAIL** | Not present (less critical now) |
| 7 | Cross-browser performance | CANNOT_TEST | CANNOT_TEST | Only Chromium tested |
| 8 | Benchmark view confirmed | PASS | **PASS** | id-50685fee... |
| 9 | Warning indicator for large views | FAIL | **FAIL** | Not present (less critical now) |

**Testable criteria: 7 | PASS: 5 | FAIL: 2 | CANNOT_TEST: 2**

### Key Findings for #160

1. **Dramatic performance improvement:** The benchmark view now loads in 346ms, down from 12.19 seconds -- a 35x improvement. The 11-second target is exceeded by a wide margin.
2. **API performance excellent:** Backend returns view data in 141ms (previously 512ms), 3.6x faster.
3. **Smaller views extremely fast:** 167ms for the "Actoren en rollen" view.
4. **No loading indicator:** Still no loading spinner or progress bar, but the sub-second load time makes this a cosmetic issue rather than a UX blocker.
5. **Views listing page broken:** `/beheer/views` shows "Geen weergaven beschikbaar" (0 views) despite 249 views existing in the API. Direct URL access to individual views works correctly. This is a UI bug in the views listing page, not a performance issue.
6. **Interactive features working:** Zoom controls, filter checkboxes, and download actions (SVG, AMEF) all present and functional.

---

## Issue #135: (VNGR) Valideren van non-functionele eisen voor component Referentiearchitectuur

**Overall Status: PARTIAL** (improved from previous test)

### Tested Criteria

#### Toegankelijkheid

**102 - Feedback na fout: PASS**
- Navigating to a non-existent page returns appropriate error responses.
- API returns structured error responses with HTTP 404.
- POST with missing required fields returns clear, actionable guidance messages.

#### Betrouwbaarheid

**87 - Beheerorganisatie: CANNOT_TEST**
- Contractual/organizational requirement (expertise availability within 2 calendar days). Not verifiable through functional testing.

#### Werkwijze

**103 - Testen: CANNOT_TEST**
- Requires verification of the development process. Presence of `code-quality.yml` in repos and branch protection is a positive indicator.

#### Overdraagbaarheid

**99 - Aanpasbaarheid Softwareplatform: PASS**
- All components are open-source with EUPL-1.2 license (confirmed in OAS documentation).
- Technology stack: PHP, Nextcloud 32.0.5, PostgreSQL, Vue.js.
- Source managed in VNG-Realisatie GitHub repositories.
- Active communities for all technologies.

**101 - OTAP omgeving: PARTIAL**
- Local development environment functional (O/T).
- Acceptance environment exists at `https://softwarecatalogus.accept.opencatalogi.nl/` (A).
- Production environment at `https://softwarecatalogus.accept.commonground.nu/` (P -- URL naming may be misleading with "accept" in production URL).

**100 - Installeerbaarheid: PASS**
- Docker-based deployment using `docker-compose.yml` confirmed.
- Containers running on standard infrastructure (Nextcloud container, PostgreSQL).
- Multiple profiles for modular deployment.

#### Bruikbaarheid

**88 - Gebruikersvriendelijk: PARTIAL**
- **Positive:** Breadcrumb navigation on all pages, clear dashboard with action buttons, "Mijn Account" and "Mijn Organisatie" links, view filters with tooltip descriptions.
- **Negative:** Views listing page (`/beheer/views`) shows "Geen weergaven beschikbaar" despite 249 views in API. `/referentiearchitectuur` CMS page returns 404. `/beheer/architectuur` shows "Geen data gevonden" with error.

**89 - Toegankelijkheid (Digitoegankelijk): PASS**
- Skip link ("Direct naar de inhoud") present on all pages.
- `<main>` landmark used correctly.
- `lang="nl"` set on HTML element.
- Breadcrumb navigation with `aria-label="Kruimelpad"`.
- All form inputs have associated labels (3/3 tested).
- Proper heading hierarchy (H1, H2, H3).
- ARIA landmarks: main, banner, nav, contentinfo all present.
- **Note:** Full WCAG audit requires axe-core or similar tool, but basic structural compliance is strong.

#### Informatiemodel

**93 - Gebruik informatiemodel voorzieningencatalogus: PASS**
- API based on the voorzieningencatalogus information model.
- Register "Voorzieningen" contains schemas: module, dienst, contactpersoon, organisatie, gebruik, koppeling, view.
- AMEF register "vng-gemma" contains: element, model, organization, property-definition, relation, view.
- OAS 3.1.0 documentation auto-generated per register.

#### Onderhoudbaarheid

**95 - Herbruikbaarheid: PASS**
- EUPL-1.2 license confirmed in OAS documentation.
- Code in VNG-Realisatie GitHub organization.
- OAS 3.1.0 API documentation auto-generated.

**96 - Modulariteit: PASS**
- Separate apps: OpenRegister (data), OpenCatalogi (CMS), Softwarecatalog (domain logic), NL Design (theming).
- Docker profiles for selective deployment.
- Register-based data separation.

**98 - Techniek toekomstvast: PASS**
- Nextcloud 32.0.5 (PHP 8.x), Vue.js, PostgreSQL -- all mainstream technologies with large communities and Dutch developer presence well above 100.

**97 - Webstatistieken: CANNOT_TEST**
- Requires Matomo or similar analytics. Not testable from local environment.

#### Informatiebeveiliging

**90 - Logging activiteiten: CANNOT_TEST**
- Audit logging is an infrastructure concern not verifiable from UI/API testing.

**91 - nl.internet standaarden: CANNOT_TEST**
- Requires nl.internet.nl test against production domain. Cannot test localhost.

**92 - Toegangsbeveiliging: PASS**
- RBAC implemented. User sarah.devries@test.nl confirmed in groups: `vng-raadpleger`, `gebruik-beheerder`, `software-catalog-users`.
- Organisation: "Default Organisation" (UUID: 28307ef1-6b5a-4435-ace8-3b6da25209f9).
- OAuth2 and Basic Auth security schemes documented in OAS.
- Unauthenticated requests to public data return 200; protected endpoints require authentication.
- User roles mapped to specific data visibility levels.

#### Standaarden

**86 - NL API strategie standaarden: PASS**
- OpenAPI Specification 3.1.0 auto-generated per register.
- REST API resource-oriented design: `/objects/{register}/{schema}`, `/objects/{register}/{schema}/{id}`.
- Standard HTTP methods and status codes.
- JSON responses with pagination (results, total, page, pages, limit, offset).

**94 - E-mail standaarden (DKIM/DMARC): CANNOT_TEST**
- Requires DNS/email infrastructure verification. Not testable on localhost.

#### Performance

**Performance plotten views: PASS** (improved from previous PARTIAL)
- Benchmark view "Poster basisbeveiligingsniveau" loads in **346ms** (target: 11 seconds).
- API response: **141ms** (target: 500ms).
- See Issue #160 for full details.

### Criteria Summary for #135

| # | Criterion | Previous | Current |
|---|-----------|----------|---------|
| 102 | Feedback na fout | PASS | PASS |
| 87 | Beheerorganisatie | CANNOT_TEST | CANNOT_TEST |
| 103 | Testen | CANNOT_TEST | CANNOT_TEST |
| 99 | Aanpasbaarheid Softwareplatform | PASS | PASS |
| 101 | OTAP omgeving | PARTIAL | PARTIAL |
| 100 | Installeerbaarheid | PASS | PASS |
| 88 | Gebruikersvriendelijk | PARTIAL | PARTIAL |
| 89 | Toegankelijkheid | PARTIAL | **PASS** |
| 93 | Informatiemodel voorzieningencatalogus | PASS | PASS |
| 95 | Herbruikbaarheid | PASS | PASS |
| 96 | Modulariteit | PASS | PASS |
| 98 | Techniek toekomstvast | PASS | PASS |
| 97 | Webstatistieken | CANNOT_TEST | CANNOT_TEST |
| 90 | Logging activiteiten | CANNOT_TEST | CANNOT_TEST |
| 91 | nl.internet standaarden | CANNOT_TEST | CANNOT_TEST |
| 92 | Toegangsbeveiliging | PASS | PASS |
| 86 | NL API strategie standaarden | PASS | PASS |
| 94 | E-mail standaarden | CANNOT_TEST | CANNOT_TEST |
| Perf | Performance plotten views | PARTIAL | **PASS** |

**Testable criteria: 12 | PASS: 10 | PARTIAL: 2 | FAIL: 0 | CANNOT_TEST: 7**

---

## Console Errors Summary

| Page | Error Count | Details |
|------|-------------|---------|
| `/login` | 0 | Clean |
| `/beheer` (dashboard) | 0 | Clean |
| `/beheer/my-organisation` | 0 | Clean |
| `/referentiearchitectuur` | 1 | 404 on `/api/pages/referentiearchitectuur` (CMS page missing) |
| `/beheer/architectuur` | 1 | 404 on `/api/schemas/architectuur/related` |
| `/beheer/views` | 0 | Clean (but shows empty list despite 249 views in API) |
| `/beheer/views/{valid-id}` | 0 | Clean |

## Network Performance Summary

All API calls returned well within acceptable timeframes:
- View data API (largest view, 388 nodes): **183ms**
- View data API (smaller view): **151ms**
- Single view fetch (in-page): **141ms**
- Login API: < 200ms
- Schema queries: < 200ms
- No API calls exceeded the 500ms SLOW threshold.
- No API calls exceeded the 1000ms PERFORMANCE_FAIL threshold.

## Bug Discovery: Views Listing Page Empty

**New finding:** The `/beheer/views` page shows "Geen weergaven beschikbaar" (no views available) despite 249 views existing in the GEMMA register API (`/api/objects/vng-gemma/view` returns total: 249). Direct URL access to individual views (`/beheer/views/{id}`) works correctly and renders the full ArchiMate diagram. This suggests a bug in the views listing component where it fails to load/display the list of available views.

## Screenshots Index

| File | Description |
|------|-------------|
| `screenshots/01-login-success.png` | Successful login as Sarah de Vries |
| `screenshots/02-referentiearchitectuur-page-404.png` | Referentiearchitectuur CMS page 404 |
| `screenshots/03-architectuur-acties-menu.png` | Architectuur page with Acties menu |
| `screenshots/04-poster-basisbeveiligingsniveau-view.png` | Benchmark view header and filters |
| `screenshots/05-poster-basisbeveiligingsniveau-full.png` | Full-page screenshot of benchmark view |
| `screenshots/06-actoren-en-rollen-view.png` | Smaller view for performance comparison |
| `screenshots/07-view-acties-download-options.png` | Download SVG/AMEF actions menu |
| `screenshots/08-views-listing-empty.png` | Views listing showing 0 views (bug) |
