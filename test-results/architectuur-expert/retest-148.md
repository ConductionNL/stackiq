# Retest #148: De GEMMA-architectuur is opvraagbaar met een API

**Date:** 2026-03-16
**Tester:** Claude (browser-7, headless)
**Previous blocker:** OAS endpoint returned HTTP 500 for register 4 (org filter bug)

---

## OAS Endpoint Tests (API)

| Test | Result | Details |
|------|--------|---------|
| OAS register 2 HTTP status | PASS | HTTP 200 |
| OAS register 4 HTTP status | PASS | HTTP 200 (was 500) |
| OAS register 4 valid JSON | PASS | openapi: 3.1.0, title: "AMEF API" |
| OAS register 4 paths | PASS | 12 paths (element, model, organization, property-definition, relation, view - each with list + detail) |
| OAS register 4 schemas | PASS | 9 schemas (Element, Model, Organization, Property_Definition, Relation, View + Error, PaginatedResponse, _self) |
| OAS register 2 valid JSON | PASS | openapi: 3.1.0, title: "Template Register API", 2 paths, 4 schemas |
| OAS unauthenticated access | PASS | Both registers return 200 without auth headers |

## Acceptance Criteria Status

| # | Criterion | Status | Notes |
|---|-----------|--------|-------|
| 1 | [API] OAS accessible at `/api/registers/4/oas` | PASS | Was 500, now 200. Org filter bug fixed. |
| 2 | [API] /elements endpoint returns ArchiMate elements | PASS | Previously verified |
| 3 | [API] Elements include ArchiMate-type field | PASS | Previously verified |
| 4 | [API] Empty properties omitted from responses | PASS | Previously verified |
| 5 | [API] /relations endpoint returns relations | PASS | Previously verified |
| 6 | [API] Relations include ArchiMate-type field | PASS | Previously verified |
| 7 | [API] /views endpoint returns view definitions | PASS | Previously verified |
| 8 | [API] model-id query parameter works | PASS | Previously verified |
| 9 | [API] /models endpoint returns available models | PASS | Previously verified |
| 10 | [UI] ID fields documented | FAIL | Not implemented |
| 11 | [UI] "Gemma downloaden" button on Mijn omgeving | FAIL | No such button exists in frontend source code. ArchiMate export exists in settings but not as a user-facing GEMMA download. |
| 12 | [HYBRID] Downloaded XML imports into Archi | FAIL | Blocked by #11 |
| 13 | [UI] Imported model matches original GEMMA model | FAIL | Blocked by #11 |

## Summary

- **PASS: 9/13** (all API criteria)
- **FAIL: 4/13** (all UI/hybrid criteria)
- **Key fix confirmed:** The OAS endpoint for register 4 (GEMMA/AMEF) now returns HTTP 200 with valid OpenAPI 3.1.0 JSON. The previous 500 error caused by the organisation filter bug is resolved.
- **Remaining work:** The "Gemma downloaden" button, ID field documentation, and Archi XML export/import validation are not yet implemented.

## Additional Observations

- The `/referentiearchitectuur` page on the frontend (localhost:3000) fails to load content: the API call to `/apps/opencatalogi/api/pages/referentiearchitectuur` returns an error. This is a separate issue from #148.
- The beheer page loads correctly after login but the referentiearchitectuur CMS page does not render.
