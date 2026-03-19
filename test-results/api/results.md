# GEMMA Softwarecatalogus — API Test Results (Newman)

**Date:** 2026-03-19
**Environment:** http://localhost:8080 (Backend)
**Method:** Newman/Postman API tests
**Duration:** 57.9s
**Average response time:** 152ms (min: 32ms, max: 2.2s)

---

## Overall Results

| Metric | Count |
|--------|-------|
| **Total requests** | 336 |
| **Total assertions** | 456 |
| **Passed** | 456 |
| **Failed** | 0 |
| **Pass rate** | 100% |

---

## Bugs Fixed During This Test Run

### 1. Register Entity missing `languages` property (OpenRegister)
- **File:** `openregister/lib/Db/Register.php`
- **Impact:** ALL API endpoints returning 500 — the `languages` column existed in DB but Entity class didn't declare it
- **Fix:** Added `languages` property, type declaration, and jsonSerialize output

### 2. Named parameter mismatch in SaveObject (OpenRegister)
- **File:** `openregister/lib/Service/Object/SaveObject.php:2764`
- **Impact:** ALL PATCH/PUT operations returning 500 — `rbac:` parameter name didn't match `$_rbac` in MagicMapper::find()
- **Fix:** Changed `rbac:` to `_rbac:` and `multitenancy:` to `_multitenancy:`

### 3. Deelnemers endpoint status code bug (Softwarecatalog)
- **File:** `softwarecatalog/lib/Controller/AangebodenGebruikController.php:552-555`
- **Impact:** Deelnemers endpoint always returned HTTP 500 even on success — empty `if` block
- **Fix:** Added proper status code handling (200/404/403) matching the pattern used elsewhere in the controller

---

## HTML Report

Full interactive report: [report.html](report.html)
