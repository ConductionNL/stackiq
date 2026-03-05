# Softwarecatalogus — API Test Results

**Date:** 2026-03-04 09:23
**Environment:** local
**Collection:** softwarecatalogus-tests.json
**Duration:** 6787ms

## References

- [issues.md](../../issues.md) — Full acceptance criteria per issue
- [issues/](../../issues/) — Individual issue descriptions (144 files)
- [aanvullende-informatie.md](../../aanvullende-informatie.md) — Data sources, analysis, templates

---

## Overall Statistics

| Metric | Value |
|--------|-------|
| Total requests | 60 |
| Total assertions | 75 |
| Passed | 67 |
| Failed | 14 |
| Pass rate | 82.7% |
| Duration | 6787ms |

---

## Results by Folder

| Folder | Tests | Passed | Failed |
|--------|-------|--------|--------|
| 03_-_Object_CRUD | 81 | 67 | 14 |

---

## Results by Issue

| Issue | Status | Passed | Failed | Response Time | Details |
|-------|--------|--------|--------|---------------|--------|
| [#6](issues/6.md) | PARTIAL | 3 | 6 | 722ms | #6 AC9: Standards at version level |
| [#65](issues/65.md) | PARTIAL | 6 | 2 | 144ms | #65 AC3: Contact person has organisatie field |
| [#73](issues/73.md) | PASS | 5 | 0 | 240ms | #73 AC3: Contact list as different user shows different contacts |
| [#312](issues/312.md) | PASS | 5 | 0 | 57ms | #312 AC4: Koppelingen with names retain them |
| [#314](issues/314.md) | PASS | 4 | 0 | 86ms | #314 AC3: Search apps by name in wizard context |
| [#354](issues/354.md) | PASS | 4 | 0 | 79ms | #354 AC3: Search fetches sufficient results for dropdown |
| [#365](issues/365.md) | PARTIAL | 1 | 4 | 74ms | #365 AC4: Contact without rol field saves OK |
| [#368](issues/368.md) | PASS | 3 | 0 | 58ms | #368 AC1: Koppelingen have valid richting values |
| [#369](issues/369.md) | PASS | 2 | 0 | 57ms | #369 AC1: Created koppelingen visible in list |
| [#370](issues/370.md) | PASS | 3 | 0 | 59ms | #370 AC1: Application objects have expected fields |
| [#371](issues/371.md) | PASS | 1 | 0 | 62ms | #371 AC1: Compliance column no longer in list response |
| [#373](issues/373.md) | PASS | 2 | 0 | 55ms | #373 AC2: Diensten reference applicaties |
| [#375](issues/375.md) | PASS | 2 | 0 | 67ms | #375 AC2: Applications have type field for version categorization |
| [#377](issues/377.md) | PASS | 1 | 0 | 55ms | #373 AC2: Diensten reference applicaties |
| [#378](issues/378.md) | PASS | 2 | 0 | 62ms | #378 AC2: GET and verify field structure before edit |
| [#382](issues/382.md) | FAIL | 0 | 2 | 48ms | #382 AC2-3: URL handling for standards |
| [#384](issues/384.md) | PASS | 1 | 0 | 70ms | #384 AC1: GET returns all fields for edit pre-fill |
| [#400](issues/400.md) | PASS | 4 | 0 | 456ms | #400 AC5: Data persisted correctly |
| [#403](issues/403.md) | PASS | 1 | 0 | 57ms | #403 AC1: Koppelingen reference modules (dependency chain exists) |
| [#405](issues/405.md) | PASS | 2 | 0 | 62ms | #405 AC2: Application relations can be checked before delete |
| [#430](issues/430.md) | PASS | 1 | 0 | 60ms | #430 AC1: Application list response structure |
| [#436](issues/436.md) | PASS | 4 | 0 | 143ms | #436 AC4: Public organisatie list returns 200 |
| [#437](issues/437.md) | PASS | 2 | 0 | 58ms | #437 AC1-2: Imported user creates koppeling |
| [#439](issues/439.md) | PASS | 4 | 0 | 99ms | #439 AC4: Empty search doesn't cause errors |
| [#441](issues/441.md) | PASS | 1 | 0 | 59ms | #441 AC1: Applications have version information |
| [#442](issues/442.md) | PASS | 2 | 0 | 67ms | #378 AC2: GET and verify field structure before edit |
| [#450](issues/450.md) | PASS | 1 | 0 | 58ms | #450 AC3: No legacy publish-status flags on organisations |

---

## Failed Tests

| Issue | Test | Error | Request |
|-------|------|-------|---------|
| #6 | #6 AC2: Can set compliance status (standaardversies) | expected 404 to be one of [ 200, 201 ] | #6 AC2: PATCH applicatie with standaardversies |
| #6 | #6 AC2: Can set compliance status (standaardversies) | expected 404 to be one of [ 200, 201 ] | #6 AC2: PATCH applicatie with standaardversies |
| #6 | #6 AC5: Standards visible on detail | expected response to have status code 200 but got 404 | #6 AC6: Standards persist after saving |
| #6 | #6 AC8: Standards field is array or null | No data, empty input at 1:1

^ | #6 AC6: Standards persist after saving |
| #6 | #6 AC5: Standards visible on detail | expected response to have status code 200 but got 404 | #6 AC6: Standards persist after saving |
| #6 | #6 AC8: Standards field is array or null | No data, empty input at 1:1

^ | #6 AC6: Standards persist after saving |
| #65 | #65 AC8: Editing updates data | expected 404 to be one of [ 200, 201 ] | #65 AC8: Editing contact person updates data |
| #65 | #65 AC8: Edit reflected in GET | expected response to have status code 200 but got 404 | #65 AC8: Verify edit persisted |
| #365 | #365 AC1: Editing does NOT produce 400 error | expected 404 to be one of [ 200, 201 ] | #365 AC1: Edit contact saves without 400 |
| #365 | #365 AC1: Editing does NOT produce 400 error | expected 404 to be one of [ 200, 201 ] | #365 AC1: Edit contact saves without 400 |
| #365 | #365 AC3: Changes visible after saving | expected response to have status code 200 but got 404 | #365 AC3: Changes persisted |
| #365 | #365 AC3: Changes visible after saving | expected response to have status code 200 but got 404 | #365 AC3: Changes persisted |
| #382 | #382: URL field saved correctly | expected 404 to be one of [ 200, 201 ] | #382 AC2-3: URL handling for standards |
| #382 | #382: URL field saved correctly | expected 404 to be one of [ 200, 201 ] | #382 AC2-3: URL handling for standards |

