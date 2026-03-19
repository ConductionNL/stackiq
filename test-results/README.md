# GEMMA Softwarecatalogus — Test Results Summary

**Date:** 2026-03-19
**Environment:** http://localhost:8080 (Backend)
**Method:** API tests (Newman/Postman)
**Duration:** 58.2s

---

## Overall Results

| Metric | Value |
|--------|-------|
| **Total requests** | 334 |
| **Total assertions** | 454 |
| **Passed** | 447 (98.5%) |
| **Failed** | 7 (1.5%) |

| Status | Issues |
|--------|--------|
| **PASS** | ~50+ issues |
| **FAIL** | 5 distinct issues |

---

## FAIL Issues (Requires Attention)

| Issue | Title | Severity | Failures | Summary |
|-------|-------|----------|----------|---------|
| #414 | Deelnemers read access | HIGH | 1 | Server 500 on deelnemers endpoint — missing schema or route |
| #400 | Koppeling save | HIGH | 3 | Koppeling CRUD broken — creation fails, cascading to re-save and persistence checks |
| #452 | Koppelingen count | MEDIUM | 1 | `_extend` doesn't resolve koppelingen relation on applicaties |
| #144 | Search functionality | LOW | 1 | Missing `beschrijvingKort` field in search results |
| #344 | Ref component filters | LOW | 1 | Reference component facet returns 0 buckets — no data or facet config issue |

---

## Critical Findings

1. **#414 — Deelnemers endpoint returns 500**: The deelnemers schema/route appears broken. This is a server error that needs immediate investigation.
2. **#400 — Koppeling CRUD broken**: Creating a koppeling via API fails silently (no match in list after creation), causing 3 cascading test failures. Core data management functionality affected.
3. **#452 — Koppelingen not resolved via _extend**: The `_extend` parameter on applicatie queries doesn't return related koppelingen, meaning the UI cannot show koppeling counts on applicatie overview pages.

---

## Performance

- **Average response time:** 154ms
- **Fastest:** 31ms
- **Slowest:** 3.1s (setup/initialization requests)
- **Standard deviation:** 267ms

All API endpoints (except setup) responded within acceptable thresholds.

---

## Recommendations

### Immediate
1. Investigate #414 deelnemers endpoint 500 error — check if schema exists in register
2. Debug #400 koppeling creation — verify the POST endpoint and schema authorization rules

### High Priority
3. Fix #452 koppelingen `_extend` resolution — check relation configuration in schema
4. Populate `beschrijvingKort` field in module data (#144)

### Before Next Test Run
5. Run browser tests for full UI coverage (`/swc:test browser`)
6. Verify #344 reference component data exists in the register

---

## Reports

- **API results:** [api/results.md](api/results.md)
- **HTML report:** [api/report.html](api/report.html)
