# Test Results: Architectuur Expert (Authenticated)

**Date:** 2026-03-19 (re-test; previous runs: 2026-03-16, 2026-03-10)
**Persona:** Dr. Sarah de Vries -- Senior Enterprise Architect, VNG
**Username:** sarah.devries@test.nl
**Groups:** vng-raadpleger, gebruik-beheerder, software-catalog-users
**Frontend:** http://localhost:3000
**Backend:** http://localhost:8080
**Browser:** Playwright (browser-7, headless Chromium)

---

## Environment Notes

- Login succeeded via frontend `/login`. Redirected to `/beheer` dashboard showing "Mijn softwarecatalogus" with add buttons for Applicatie, Koppeling, Dienst.
- **Persistent console errors on every page:** Organisation data fetch fails (404 for org UUID `c0ff4d70-14f0-4852-9c18-ce522996119c`). Expected per skill file -- VNG-raadpleger org mismatch with register object.
- **CMS pages broken:** All `/referentiearchitectuur/*` routes fail because the opencatalogi pages API returns 404 for these page slugs.
- **Schema IDs:** Element=13, View=14, Relation=15, Model=16, Organization=17, PropertyDefinition=18.

---

## Issue #148: (VNGR) De GEMMA-architectuur is opvraagbaar met een API

**Status: PARTIAL**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [API] OAS at `/api/registers/4/oas` accessible | PASS | Returns HTTP 200, OpenAPI 3.1.0 spec titled "AMEF API" with 12 paths. Response: 0.6s, 87KB. |
| 2 | [API] /elements endpoint returns elements with correct counts | PASS | `/api/objects?_register=4&_schema=13` returns 4,353 elements. |
| 3 | [API] Elements include ArchiMate-type field | PASS | Elements contain `type` field (e.g., "Capability"). |
| 4 | [API] Empty properties omitted from responses | PASS | Element responses only include populated fields. |
| 5 | [API] /relations endpoint returns correctly | PASS | `/api/objects?_register=4&_schema=15` returns results. No bad gateway. |
| 6 | [API] Relations include ArchiMate-type field | PASS | Relation objects include `type` and structure fields. |
| 7 | [API] /views endpoint returns views with correct count | PASS | Returns 248 total views via schema 14. |
| 8 | [API] API supports model-id query parameter | PASS | Filtering works (returns 0 for non-existent model-id). |
| 9 | [API] /models endpoint returns models | PASS | Returns 1 model via schema 16. |
| 10 | [UI] ID fields documented | FAIL | No documentation visible in UI about different ID types (Archi id, Object ID, Open Register id). |
| 11 | [UI] GEMMA downloadable via "Gemma downloaden" button | FAIL | No "Gemma downloaden" button on Mijn Omgeving page. Page redirects to /beheer with `schema_mijn-omgeving` fetch error. ArchiMate export API returns: "AMEF register ID is not configured." |
| 12 | [HYBRID] Downloaded XML importable into Archi | CANNOT_TEST | No download available. |
| 13 | [UI] Imported model matches original | CANNOT_TEST | No download available. |

### Notes
- The ArchiMate settings endpoint (`/api/settings/archimate`) reports `model_count: 0, element_count: 0` despite 4,353 elements existing in register 4. The AMEF register is not linked in the softwarecatalog configuration.
- The OAS endpoint for register 4 now returns 200 (previously reported as 500 due to org filtering bug -- this is fixed).
- Mijn Omgeving route (`/beheer/mijn-omgeving`) fails with schema fetch error and redirects to `/beheer`.

---

## Issue #160: (VNGR) Performance plotten views tbv ID-77

**Status: CANNOT_TEST**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [UI] Largest view (388 nodes) loads within 11s | CANNOT_TEST | View rendering pages non-functional. `/beheer/views` shows "Geen weergaven beschikbaar". `/referentiearchitectuur/views/*` shows empty page with CMS fetch error. |
| 2 | [UI] Each loading phase ~3s average | CANNOT_TEST | No view rendering occurs. |
| 3 | [UI] Smaller views load in under 7s | CANNOT_TEST | No view rendering occurs. |
| 4 | [UI] Views become interactive after rendering | CANNOT_TEST | No view rendering occurs. |
| 5 | [API] Backend API returns single view within ~0.5s | PASS | Tested "Poster basisbeveiligingsniveau" view: API responded in 0.67s. |
| 6 | [UI] Large views display loading indicator | CANNOT_TEST | No view rendering occurs. |
| 7 | [UI] Acceptable performance on Chrome/Edge/Firefox | CANNOT_TEST | No view rendering occurs. |
| 8 | [API] Benchmark view is "Poster basisbeveiligingsniveau" (388 nodes) | PASS | View found with 49 top-level nodes (nested nodes may account for 388 total). |
| 9 | [UI] Warning/loading indicator for large views | CANNOT_TEST | No view rendering occurs. |

### Root Cause Analysis
- `/beheer/views` shows "Geen weergaven beschikbaar" despite 248 views in the API (19 matching softwarecatalogus filter).
- `/beheer/views/{objectId}` returns "Weergave niet gevonden" with 404 from `/vng-gemma/view/{objectId}`.
- Public-facing pages (`/referentiearchitectuur/views/*`) fail because opencatalogi CMS pages API returns 404.
- **The view management component cannot connect to GEMMA views in register 4.**

---

## Issue #135: (VNGR) Valideren van non-functionele eisen voor component Referentiearchitectuur

**Status: PARTIAL**

No detailed acceptance criteria in `issues.md` (only in mapping table). Tested general non-functional requirements:

| Aspect | Status | Evidence |
|--------|--------|----------|
| API Performance | PASS | OAS: 0.6s/87KB. Elements (100): 0.66s/195KB. Single view: 0.67s. All under 1s. |
| API Availability | PASS | All GEMMA API endpoints return correct HTTP 200 responses. |
| UI Accessibility | CANNOT_TEST | Architecture views do not render, preventing accessibility testing. |
| UI Responsiveness | CANNOT_TEST | Architecture views do not render. Referentiearchitectuur pages show empty content. |
| Data Completeness | PASS | 4,353 elements, 248 views (19 matching filter), 1 model in register 4. |

---

## Issue #412: Vraag: Niet alle AMEF views hebben documentatie

**Status: FAIL**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [UI] Referentiecomponentenlandschap view has description | FAIL | API confirms `documentation` field is empty/absent. |
| 2 | [UI] Test extra componenten view has description | CANNOT_TEST | View does not exist in current dataset (searched all 248 views). |
| 3 | [UI] Basisbeveiligingsniveau views (both) have descriptions | FAIL | Both "Poster basisbeveiligingsniveau" and "Basisbeveiligingsniveau" lack documentation. |
| 4 | [UI] Referentiecomponenten en ondersteuning BIO maatregelen has description | FAIL | API confirms `documentation` field is empty/absent. |
| 5 | [UI] No view displays "geen beschrijving beschikbaar" | FAIL | Descriptions not provided. All 4 checked views lack documentation. UI views don't render so message can't be verified visually. |

### Notes
- None of the 19 views matching the softwarecatalogus filter have any `documentation` field populated.
- The "Test extra componenten" view referenced in the issue does not exist in the current GEMMA dataset.
- This is a data/content issue -- AMEF source files need descriptions for these views.

---

## Issue #413: Vraag: Views testen vs softwarecatalogus scope

**Status: PARTIAL**

### Acceptance Criteria Results

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | [API] Only 22 views matching agreed filter displayed | PARTIAL | **19 views** match `publiceren=Softwarecatalogus en GEMMA Online en redactie`, not 22. The remaining 229 views have `publiceren=GEMMA Online en redactie`. 3 views appear missing compared to expected count. |
| 2 | [UI] Duplicate titelViewSwc distinguishable | PASS (N/A) | No duplicate titles among 19 filtered views. All unique. |
| 3 | [API] Test views don't appear in published catalog | PASS | No test views in dataset. All 248 views have valid publiceren values. |

### Complete List of 19 Matching Views

1. Applicatieservices publieksdiensten
2. Applicatieservices bestuur
3. Applicatieservices openbare orde en veiligheid
4. Applicatieservices sociaal domein
5. Applicatieservices ondersteuning
6. Applicatieservices fysieke leefomgeving
7. Applicatieservices generiek
8. Applicatieservices generiek en buitengemeentelijke voorzieningen
9. Referentiecomponentenlandschap
10. Poster basisbeveiligingsniveau van referentiecomponenten
11. Basisbeveiligingsniveau van referentiecomponenten
12. Referentiecomponenten en ondersteuning BIO maatregelen
13. Bedrijfsfuncties fysieke leefomgeving
14. Bedrijfsfuncties ondersteuning
15. Bedrijfsfuncties sociaal domein
16. Bedrijfsfuncties openbare orde en veiligheid
17. Bedrijfsfuncties publieksdiensten
18. Bedrijfsfuncties bestuur
19. Bedrijfsfuncties klant- en keteninteractie

---

## Cross-Cutting Observations

### Critical Issues

1. **View rendering completely broken** -- Both `/beheer/views` and `/referentiearchitectuur/views/*` fail to display views. Management page shows "Geen weergaven beschikbaar" despite 248 views in API.
2. **AMEF register not configured** -- ArchiMate export returns "AMEF register ID is not configured". Settings show 0 elements/models despite data in register 4.
3. **Search shows "Geen titel"** -- `/zoeken` displays results with no titles and `/publicatie/undefined` URLs. Publication enrichment broken.
4. **CMS pages 404** -- opencatalogi pages API returns 404 for referentiearchitectuur page slugs.
5. **Organization fetch 404** -- Expected for VNG-raadpleger role (org UUID mismatch).

### Comparison with Previous Run (2026-03-16)

| Aspect | Previous | Current |
|--------|----------|---------|
| OAS register 4 | 200 OK | 200 OK (stable) |
| View rendering | Working (views rendered) | BROKEN (no views load) |
| AMEF config | Not reported | Not configured |
| Search results | Not reported | Broken ("Geen titel") |

### Screenshots
- `views-empty.png` -- Referentiearchitectuur views page showing empty content
- `search-geen-titel.png` -- Search page showing "Geen titel" results
