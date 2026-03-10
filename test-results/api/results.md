# Softwarecatalogus — API Test Results

**Date:** 2026-03-10 (Run 2)
**Environment:** local (http://localhost:8080)
**Tool:** Newman v6 + Postman Collection
**Duration:** 2m 17.2s

## Summary

| Metric | Executed | Failed |
|--------|----------|--------|
| Iterations | 1 | 0 |
| Requests | 334 | 0 |
| Test scripts | 329 | 0 |
| Pre-request scripts | 381 | 0 |
| **Assertions** | **454** | **1** |

**Pass rate:** 99.8% (453/454)

## Failed Tests

| # | Test | Detail |
|---|------|--------|
| #433 AC2 | Koppelingen have moduleB field | `expected +0 to be above +0` — No koppelingen have `moduleB` populated. Data migration issue: koppeling import may not be mapping the second module reference. |

## Previously Failing (now fixed)

### #400 — Koppeling save (was 3 failures, now 0)
### #437 — Imported leverancier koppeling (was 2 failures, now 0)

**Root cause:** `$uuidPat` variable was undefined in `ValidateObject.php:transformPropertyForOpenRegister()`. A null pattern was passed to the JSON Schema validator, which threw "pattern value must be a string" and returned a misleading 403 error.

**Fix:** Added `$uuidPat` definition at the top of `transformPropertyForOpenRegister()`.

## Performance

- Average response time: 384ms
- Min: 43ms
- Max: 24.1s
- Std dev: 2.2s
- Total data received: 4.95MB

## Per-Folder Results

| Folder | All Pass? |
|--------|-----------|
| 00 - Setup | Yes |
| 01 - Public API & Search | Yes |
| 02 - RBAC & Organization Scoping | Yes |
| 03 - Object CRUD | Yes |
| 04 - Data Migration & Import | No (1 fail: #433 AC2) |
| 05 - ArchiMate & Views | Yes |
| 06 - User Profile & Authentication | Yes |
| 07 - Export & Reporting | Yes |
| 08 - Aanbod & Gebruik | Yes |
| 09 - Data Quality & Naming | Yes |
| 10 - Glossary & Content | Yes |
| 11 - Publications & Catalogs | Yes |

## Notes

- Non-existent publication returns 500 instead of 404 (known issue, test accommodates both)
- All RBAC scoping tests pass — organization-based filtering works correctly
- All UUID resolution tests pass — facets show readable names
- Test count increased from 424 to 454 assertions (30 new tests added since last run)
