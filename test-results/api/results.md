# GEMMA Softwarecatalogus — API Test Results (Newman)

**Date:** 2026-03-19
**Environment:** http://localhost:8080 (Backend)
**Method:** Newman/Postman API tests
**Duration:** 58.2s
**Average response time:** 154ms (min: 31ms, max: 3.1s)

---

## Overall Results

| Metric | Count |
|--------|-------|
| **Total requests** | 334 |
| **Total assertions** | 454 |
| **Passed** | 447 |
| **Failed** | 7 |
| **Pass rate** | 98.5% |

---

## Failed Assertions (7)

### 1. #144 AC5: Results contain beschrijvingKort
- **Folder:** 01 - Public API & Search
- **Issue:** #144 — Search functionality
- **Error:** `expected false to be true`
- **Analysis:** Search results missing `beschrijvingKort` field — may not be populated in module data

### 2. #344 AC3: Multiple reference components available
- **Folder:** 01 - Public API & Search
- **Issue:** #344 — Reference component filters (extended)
- **Error:** `expected +0 to be above +0`
- **Analysis:** No reference component facet buckets returned — referentieComponenten facet has 0 values

### 3. #414: Deelnemers endpoint accessible
- **Folder:** 02 - RBAC & Organization Scoping
- **Issue:** #414 — Deelnemers read access
- **Error:** `expected 500 to be one of [ 200, 404 ]`
- **Analysis:** Deelnemers endpoint returns HTTP 500 instead of proper response — server error

### 4. #400 AC3: Koppeling visible in list
- **Folder:** 03 - Object CRUD
- **Issue:** #400 — Koppeling save
- **Error:** `expected false to be true`
- **Analysis:** Created koppeling not found in list after creation — possibly cascading from creation issue

### 5. #400 AC4: Re-save works without errors
- **Folder:** 03 - Object CRUD
- **Issue:** #400 — Koppeling save
- **Error:** `expected 404 to be one of [ 200, 201 ]`
- **Analysis:** PUT to koppeling returns 404 — object not found for re-save (cascading from AC3)

### 6. #400 AC5: Data persisted correctly
- **Folder:** 03 - Object CRUD
- **Issue:** #400 — Koppeling save
- **Error:** `expected response to have status code 200 but got 404`
- **Analysis:** GET for koppeling returns 404 — cascading from failed creation

### 7. #452 AC1: Applicatie has koppelingen array via _extend
- **Folder:** 03 - Object CRUD
- **Issue:** #452 — Koppelingen count in applicatie overview
- **Error:** `Should find Makelaarsuite: expected +0 to be above +0`
- **Analysis:** `_extend` on applicatie does not return koppelingen — relation not resolved

---

## Issues Summary

### FAIL (5 distinct issues)
| Issue | Title | Failures | Severity | Summary |
|-------|-------|----------|----------|---------|
| #414 | Deelnemers read access | 1 | HIGH | Server 500 on deelnemers endpoint |
| #400 | Koppeling save | 3 | HIGH | Koppeling CRUD broken — cascading failures |
| #452 | Koppelingen count | 1 | MEDIUM | `_extend` doesn't resolve koppelingen relation |
| #144 | Search functionality | 1 | LOW | Missing `beschrijvingKort` field in results |
| #344 | Ref component filters | 1 | LOW | Reference component facet returns 0 buckets |

### PASS (all other tested issues)
All assertions passed for: #85, #315, #343, #345, #346, #440, #105, #300, #307, #394, #6, #65, #73, #365, #382, #437, #23, #435, #148, #160, #393, #413, #266, #286, #352, #353, #396, #15, #354, #418, #419, #420, #186, #347, #381, #406, #407, #409, #155, #332, #280, #302, #333, #336, #340, #349, #358, #363, #374, #398, #443, and Publications & Catalogs tests.

---

## HTML Report

Full interactive report: [report.html](report.html)
