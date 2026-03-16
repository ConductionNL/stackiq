# Softwarecatalogus — API Test Results

**Date:** 2026-03-16
**Environment:** local (http://localhost:8080)
**Tool:** Newman v6 + Postman Collection
**Duration:** 28s
**Average response time:** 65ms (min: 21ms, max: 985ms)

## Summary

| Metric | Executed | Failed |
|--------|----------|--------|
| Iterations | 1 | 0 |
| Requests | 334 | 0 |
| Test scripts | 329 | 1 |
| Pre-request scripts | 381 | 0 |
| **Assertions** | **454** | **37** |

**Pass rate:** 91.9% (417/454)

## Failed Assertions by Issue

### Folder 01 - Public API & Search (3 failures)
| # | Assertion | Issue |
|---|-----------|-------|
| 1 | #144 AC1: Search returns results | #144 |
| 2 | #144 AC5: Results contain beschrijvingKort | #144 |
| 3 | #344 AC3: Multiple reference components available | #344 |

### Folder 03 - Object CRUD (4 failures)
| # | Assertion | Issue |
|---|-----------|-------|
| 4 | #400 AC3: Koppeling visible in list | #400 |
| 5 | #400 AC4: Re-save works without errors | #400 |
| 6 | #400 AC5: Data persisted correctly | #400 |
| 7 | #452 AC1: Applicatie has koppelingen array via _extend | #452 |

### Folder 04 - Data Migration & Import (1 failure)
| # | Assertion | Issue |
|---|-----------|-------|
| 8 | #435 AC3: Import total is substantial | #435 |

### Folder 10 - Glossary & Content (5 failures)
| # | Assertion | Issue |
|---|-----------|-------|
| 9 | #155 AC1: Glossary endpoint returns data | #155 |
| 10 | #155 AC2: Glossary search accessible | #155 |
| 11 | #155 AC3: Glossary has terms | #155 |
| 12 | #155 AC4: Glossary search is case-insensitive | #155 |
| 13 | #332: Authenticated search works | #332 |

### Folder 11 - Publications & Catalogs (25 failures — systemic)
The OpenCatalogi publications API returns HTTP 500 errors across all endpoints.

| # | Assertion | Notes |
|---|-----------|-------|
| 14 | Catalogi endpoint returns 200 | HTTP 500 |
| 15-17 | Catalog existence, slug, config | JSON parse errors (HTML 500 response) |
| 18-20 | Publications endpoint, results, pagination | HTTP 500 |
| 22-26 | Search, structure, pagination | HTTP 500 |
| 27-29 | Faceted search, facets, counts | HTTP 500 |
| 30-31 | Schema-filtered facet, readable names | HTTP 500 |
| 32-33 | Dienst facet, diensttype | HTTP 500 |
| 34-36 | Publication detail (404), metadata | HTTP 404/500 |
| 37-38 | Catalog-scoped search | JSON parse errors |

## Analysis

**Folders 00-10 (core API): 12 failures**
- **#144**: Publications-based search not returning results
- **#344**: Only 1 reference component available (data gap)
- **#400**: Koppeling CRUD — visibility and re-save broken
- **#452**: `_extend` not returning koppelingen array on applicaties
- **#435**: Import data count below threshold
- **#155**: Glossary endpoint not implemented or empty
- **#332**: Authenticated search endpoint issue

**Folder 11 (Publications & Catalogs): 25 failures — SYSTEMIC**
The entire OpenCatalogi publications API returns HTTP 500. This is a single root cause issue, not 25 separate bugs. The publications/catalogs feature needs investigation.

## Passing Folders (highlights)
- **00 - Setup**: 68/68 passed — all test data created successfully
- **01 - Public API & Search**: Most assertions pass — facets, pagination, UUID resolution working
- **02 - RBAC & Organization Scoping**: All passing — org scoping works correctly
- **05 - ArchiMate & Views**: All passing
- **06 - User Profile & Authentication**: All passing
- **08 - Aanbod & Gebruik**: All passing
- **09 - Data Quality & Naming**: All passing

## HTML Report
Available at: `softwarecatalog/test-results/api/report.html`
