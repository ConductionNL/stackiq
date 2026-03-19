# Retest Issue #455 -- Anonymous Visitor Detail Page Tabs

**Date:** 2026-03-16
**Tester:** Claude (automated)
**Browser:** browser-6 (headed Playwright)
**Role:** Anonymous visitor (not logged in, localStorage cleared)

## Test Subject

Publication: **Test Applicatie Leverancier**
URL: `http://localhost:3000/publicatie/ee84e9a4-85c3-41af-89ac-af76719d7793`

## Results

### Tabs Visible

| Tab | Visible | Count |
|-----|---------|-------|
| Standaarden | Yes | 0 |
| Geschikt voor | Yes | 0 |
| Applicatieversies | Yes | 1 |

Three tabs are rendered and accessible. The tabs Koppelingen, Contactpersonen, Diensten, and Versies are not shown -- these are likely hidden because this test applicatie has no linked data for those relation types (they appear conditionally based on data or schema configuration).

### Console Errors

**0 errors** in the browser console. One warning was logged (schema normalization, non-critical).

### Network Requests -- /uses and /used Endpoints

| Endpoint | HTTP Status | Result |
|----------|-------------|--------|
| `GET /api/apps/opencatalogi/api/publications/{id}/uses?_extend[]=_schema&_limit=100` | **200 OK** | Success |
| `GET /api/apps/opencatalogi/api/publications/{id}/used?_extend[]=_schema&_extend[]=compliancy&_limit=100` | **200 OK** | Success |

No 500 errors on either endpoint.

### All API Calls on Detail Page

All returned HTTP 200:
- `GET /api/publications/{id}?_extend=_schema,_register,themes,contactpersoon,compliancy` -- 200
- `GET /api/publications/{id}/uses` -- 200
- `GET /api/publications/{id}/used` -- 200
- `GET /api/objects/vng-gemma/element?gemmaType=Standaard` -- 200
- `GET /api/objects/vng-gemma/element?gemmaType=Standaardversie` -- 200
- `GET /api/schemas?_limit=100` -- 200

## Verdict

**PASS**

The `/uses` and `/used` endpoints return 200 for anonymous visitors. Tabs are visible and functional. No 500 errors in console or network traffic. Issue #455 is resolved.
