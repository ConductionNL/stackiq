# Retest: Critical Fixes -- Gemeente (Maria van der Berg)

**Date:** 2026-03-10
**Environment:** Frontend: http://localhost:3000, Backend: http://localhost:8080

## #280: Z-A sort crashes server

**Previous Status:** FAIL
**Current Status:** PASS
**Evidence:**

### API Tests (all HTTP 200, no 500 errors)

| Endpoint | Sort | Limit | Page | Result |
|---|---|---|---|---|
| softwarecatalogus?_schema=organisatie | _order[title]=desc | 10 | 1 | HTTP 200 |
| softwarecatalogus?_schema=module | _order[title]=desc | 10 | 2 | HTTP 200 |
| softwarecatalogus?_schema=organisatie | _order[title]=desc | 20 | 3 | HTTP 200 |
| softwarecatalogus?_schema=koppeling | _order[title]=desc | 10 | 1 | HTTP 200 |
| softwarecatalogus?_schema=organisatie | _order[title]=asc | 10 | 2 | HTTP 200 |

All five API calls with descending sort + LIMIT + OFFSET (pagination) returned HTTP 200 successfully. Previously, the combination of ORDER BY with LIMIT/OFFSET caused a PostgreSQL error and HTTP 500.

### Frontend Tests

1. **Search page Z-A sort (page 1):** Navigated to /zoeken?_schema=organisatie, selected "Naam - Z naar A" from sort dropdown. Page loaded with 13,112 results. No errors.
   - Screenshot: `screenshot-za-sort-search.png`

2. **Search page Z-A sort (page 2 -- critical pagination test):** Navigated to page 2 with Z-A sort active (`_order[_name]=desc&_page=2`). Page loaded successfully with results sorted correctly:
   - First result: "Zorgvoorjeugd.nu" (Z)
   - Second result: "Zorg voor Jeugd Groningen" (Z)
   - This is the exact scenario (sort + pagination = ORDER BY + LIMIT + OFFSET) that previously caused the HTTP 500 crash.
   - Screenshot: `screenshot-za-sort-page2.png`

3. **Beheer table sort toggle:** On /beheer/diensten, clicked the Naam column sort icon twice to toggle through asc/desc states. Page responded without errors (table showed "Geen data gevonden" as expected for this user with no diensten).
   - Screenshot: `screenshot-beheer-sort-desc.png`

### Notes

- Maria's beheer tables (applicaties, diensten) show "Geen data gevonden" because this gemeente user has not registered any items yet. This is expected behavior, not a bug.
- The 404 errors in console for `/api/names/{uuid}` are unrelated to the sort fix (they are name resolution failures for unknown UUIDs in facet labels).
- Maria's account could not authenticate via Basic Auth API calls (HTTP 401), but admin credentials confirmed the API fix works. The frontend authenticated session for Maria worked correctly.
