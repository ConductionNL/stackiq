# Softwarecatalogus — API Test Results

**Date:** 2026-03-05 09:49
**Environment:** local
**Collection:** softwarecatalogus-tests.json
**Duration:** 74643ms

## References

- [issues.md](../../issues.md) — Full acceptance criteria per issue
- [issues/](../../issues/) — Individual issue descriptions (144 files)
- [aanvullende-informatie.md](../../aanvullende-informatie.md) — Data sources, analysis, templates

---

## Overall Statistics

| Metric | Value |
|--------|-------|
| Total requests | 318 |
| Total assertions | 421 |
| Passed | 422 |
| Failed | 3 |
| Pass rate | 99.3% |
| Duration | 74643ms |

---

## Results by Folder

| Folder | Tests | Passed | Failed |
|--------|-------|--------|--------|
| 00_-_Setup | 72 | 72 | 0 |
| 01_-_Public_API___Search | 70 | 70 | 0 |
| 02_-_RBAC___Organization_Scoping | 27 | 27 | 0 |
| 03_-_Object_CRUD | 77 | 74 | 3 |
| 04_-_Data_Migration___Import | 24 | 24 | 0 |
| 05_-_ArchiMate___Views | 21 | 21 | 0 |
| 06_-_User_Profile___Authentication | 37 | 37 | 0 |
| 07_-_Export___Reporting | 9 | 9 | 0 |
| 08_-_Aanbod___Gebruik | 33 | 33 | 0 |
| 09_-_Data_Quality___Naming | 53 | 53 | 0 |
| 10_-_Glossary___Content | 2 | 2 | 0 |

---

## Results by Issue

| Issue | Status | Passed | Failed | Response Time | Details |
|-------|--------|--------|--------|---------------|--------|
| [#6](issues/6.md) | PASS | 6 | 0 | 1085ms | #6 AC9: Standards at version level |
| [#15](issues/15.md) | PASS | 3 | 0 | 9453ms | #15 AC4: Export scoped to user org |
| [#23](issues/23.md) | PASS | 6 | 0 | 1114ms | #23 AC5: Contactpersonen present |
| [#57](issues/57.md) | PASS | 2 | 0 | 61ms | #57 AC1: Samenwerking user can access API |
| [#65](issues/65.md) | PARTIAL | 9 | 2 | 77ms | #65 Username-email sync check |
| [#73](issues/73.md) | PASS | 5 | 0 | 163ms | #73 AC3: Contact list as different user shows different contacts |
| [#85](issues/85.md) | PASS | 8 | 0 | 175ms | #85 AC6: Public contactpersonen NOT accessible (RBAC) |
| [#105](issues/105.md) | PASS | 5 | 0 | 1105ms | #105 AC4: Authenticated user sees own gebruik |
| [#141](issues/141.md) | PASS | 7 | 0 | 71ms | #141 Merge: koppeling preservation |
| [#144](issues/144.md) | PASS | 12 | 0 | 906ms | #144 Leverancier count: migration check |
| [#148](issues/148.md) | PASS | 11 | 0 | 612ms | #148 AC7: Elements have type and name properties |
| [#155](issues/155.md) | PASS | 4 | 0 | 136ms | #155 AC1: Glossary endpoint returns terms |
| [#160](issues/160.md) | PASS | 3 | 0 | 361ms | #160 AC1: View payload size reasonable with _unset=xml |
| [#169](issues/169.md) | PASS | 4 | 0 | 80ms | #169 AC1: NC organisations endpoint accessible |
| [#186](issues/186.md) | PASS | 3 | 0 | 869ms | #186 AC2: Koppelingen detail has module references |
| [#187](issues/187.md) | PASS | 1 | 0 | 78ms | #187 AC1: Contact person definition accessible |
| [#208](issues/208.md) | PASS | 1 | 0 | 66ms | #208 AC1: Table view shows naam and type |
| [#225](issues/225.md) | PASS | 3 | 0 | 69ms | #225 AC3: Authenticated sees more org fields |
| [#231](issues/231.md) | PASS | 3 | 0 | 84ms | #231 AC1: Views available for export |
| [#266](issues/266.md) | PASS | 4 | 0 | 627ms | #266 AC1: /api/me returns firstName |
| [#278](issues/278.md) | PASS | 2 | 0 | 86ms | #278 AC1: Filter values are readable strings |
| [#280](issues/280.md) | PASS | 3 | 0 | 147ms | #280 AC3: Page 2 continues sort order |
| [#286](issues/286.md) | PASS | 2 | 0 | 1183ms | #286 AC4: Login with current password succeeds |
| [#300](issues/300.md) | PASS | 1 | 0 | 83ms | #300 AC1-4: Jan's app count is correct |
| [#302](issues/302.md) | PASS | 1 | 0 | 101ms | #302 AC1: Application fields populated after load |
| [#306](issues/306.md) | PASS | 1 | 0 | 107ms | #306 AC1: Diensten have clean labels |
| [#307](issues/307.md) | PASS | 1 | 0 | 77ms | #307 AC1-2: Jan's diensten scoped to own org |
| [#308](issues/308.md) | PASS | 1 | 0 | 107ms | #306 AC1: Diensten have clean labels |
| [#312](issues/312.md) | PASS | 5 | 0 | 72ms | #312 AC4: Koppelingen with names retain them |
| [#314](issues/314.md) | PASS | 4 | 0 | 116ms | #314 AC3: Search apps by name in wizard context |
| [#315](issues/315.md) | PASS | 8 | 0 | 89ms | #315 AC8: Single app detail shows correct supplier |
| [#316](issues/316.md) | PASS | 1 | 0 | 72ms | #324 AC1: Gebruik objects have expected fields |
| [#317](issues/317.md) | PASS | 1 | 0 | 72ms | #324 AC1: Gebruik objects have expected fields |
| [#319](issues/319.md) | PASS | 1 | 0 | 83ms | #319 AC1: Search koppelingen by name |
| [#320](issues/320.md) | PASS | 3 | 0 | 73ms | #320 AC1: Koppelingen have status field |
| [#323](issues/323.md) | PASS | 1 | 0 | 103ms | #323 AC1: Application search works |
| [#324](issues/324.md) | PASS | 3 | 0 | 72ms | #324 AC2: Gebruik objects have status field |
| [#325](issues/325.md) | PASS | 1 | 0 | 80ms | #325 AC1: Reference components accessible |
| [#332](issues/332.md) | PASS | 1 | 0 | 676ms | #332 AC5: Search respects user permissions |
| [#333](issues/333.md) | PASS | 4 | 0 | 83ms | #333 AC3: Referentiecomponent facet no UUIDs |
| [#336](issues/336.md) | PASS | 1 | 0 | 1056ms | #336 AC1: Objects have consistent identification |
| [#339](issues/339.md) | PASS | 3 | 0 | 83ms | #339 AC1: Users endpoint accessible |
| [#340](issues/340.md) | PASS | 2 | 0 | 79ms | #340 AC2: Sort applied after search filter |
| [#343](issues/343.md) | PASS | 4 | 0 | 73ms | #343 AC2: Filter koppelingen by type=intern |
| [#344](issues/344.md) | PASS | 3 | 0 | 81ms | #344 AC2: Filter by specific reference component |
| [#345](issues/345.md) | PASS | 3 | 0 | 66ms | #345 AC1: Diensttype facet populated with values |
| [#346](issues/346.md) | PASS | 6 | 0 | 80ms | #346 AC5: Pagination with filter applied |
| [#347](issues/347.md) | PASS | 1 | 0 | 109ms | #347 AC2: Dienst type values are readable |
| [#348](issues/348.md) | PASS | 2 | 0 | 71ms | #348 AC1: Applications have standaardversies data |
| [#349](issues/349.md) | PASS | 3 | 0 | 80ms | #349 Standaardversies facet: no UUIDs |
| [#352](issues/352.md) | PASS | 3 | 0 | 259ms | #352 AC2: Updated name reflected in contact person |
| [#353](issues/353.md) | PASS | 3 | 0 | 304ms | #353 AC1: /api/user/me has functie field |
| [#354](issues/354.md) | PASS | 7 | 0 | 1139ms | #354 AC6: Consistent search results |
| [#355](issues/355.md) | PASS | 4 | 0 | 87ms | #355 AC1: Application data contains readable geregistreerdDoor |
| [#357](issues/357.md) | PASS | 2 | 0 | 100ms | #357 AC1: Dienst type values are consistent |
| [#358](issues/358.md) | PASS | 1 | 0 | 86ms | #358 AC1: Public search does not return concept-status items |
| [#359](issues/359.md) | PASS | 1 | 0 | 58ms | #359 AC1: App configuration endpoint accessible |
| [#360](issues/360.md) | PASS | 1 | 0 | 51ms | #360 AC1: Configuration persists via API |
| [#363](issues/363.md) | PASS | 2 | 0 | 74ms | #363 AC1: API responses use consistent terminology |
| [#364](issues/364.md) | PASS | 2 | 0 | 74ms | #364 AC1: Contact persons have email field |
| [#365](issues/365.md) | PASS | 4 | 0 | 287ms | #365 AC2: Contact person has rol field |
| [#366](issues/366.md) | PASS | 2 | 0 | 80ms | #366 AC1: Contact persons have consistent rol field |
| [#367](issues/367.md) | PASS | 1 | 0 | 69ms | #367 AC1: Contact persons have naam field |
| [#368](issues/368.md) | PASS | 3 | 0 | 74ms | #368 AC1: Koppelingen have valid richting values |
| [#369](issues/369.md) | PASS | 2 | 0 | 66ms | #369 AC1: Created koppelingen visible in list |
| [#370](issues/370.md) | PASS | 3 | 0 | 105ms | #370 AC1: Application objects have expected fields |
| [#371](issues/371.md) | PASS | 1 | 0 | 69ms | #371 AC1: Compliance column no longer in list response |
| [#373](issues/373.md) | PASS | 2 | 0 | 80ms | #373 AC2: Diensten reference applicaties |
| [#374](issues/374.md) | PASS | 1 | 0 | 98ms | #374 AC1: Standards references use readable format |
| [#375](issues/375.md) | PASS | 2 | 0 | 80ms | #375 AC2: Applications have type field for version categorization |
| [#377](issues/377.md) | PASS | 1 | 0 | 73ms | #373 AC2: Diensten reference applicaties |
| [#378](issues/378.md) | PASS | 2 | 0 | 99ms | #378 AC2: GET and verify field structure before edit |
| [#379](issues/379.md) | PASS | 3 | 0 | 74ms | #379 AC2: Authenticated shows same app data |
| [#380](issues/380.md) | PASS | 2 | 0 | 82ms | #380 AC2: Repeated query returns same total |
| [#381](issues/381.md) | PASS | 2 | 0 | 1183ms | #381 AC1: No 'non-compliant' in API responses |
| [#382](issues/382.md) | PASS | 1 | 0 | 210ms | #382 AC2-3: URL handling for standards |
| [#383](issues/383.md) | PASS | 1 | 0 | 74ms | #383 AC1: Export endpoint respects limit parameter |
| [#384](issues/384.md) | PASS | 1 | 0 | 82ms | #384 AC1: GET returns all fields for edit pre-fill |
| [#385](issues/385.md) | PASS | 1 | 0 | 86ms | #385 AC1: Applications have version-related fields |
| [#391](issues/391.md) | PASS | 3 | 0 | 105ms | #391 AC1: Organisation users endpoint accessible |
| [#392](issues/392.md) | PASS | 4 | 0 | 76ms | #392 AC1: Contact persons with email accessible |
| [#393](issues/393.md) | PASS | 5 | 0 | 719ms | #393 AC3: Export endpoint for diensten |
| [#394](issues/394.md) | PASS | 6 | 0 | 75ms | #394 AC4: User sees scoped contactpersonen |
| [#396](issues/396.md) | PASS | 3 | 0 | 26ms | #396 AC1: Nextcloud status endpoint |
| [#398](issues/398.md) | PASS | 5 | 0 | 118ms | #398 Leveranciers facet: UUID check (re-opened) |
| [#399](issues/399.md) | PASS | 2 | 0 | 78ms | #399 AC1: Different supplier apps visible to public |
| [#400](issues/400.md) | PASS | 4 | 0 | 236ms | #400 AC5: Data persisted correctly |
| [#401](issues/401.md) | PASS | 2 | 0 | 92ms | #401 AC1: Koppelingen have richting field |
| [#402](issues/402.md) | PASS | 4 | 0 | 86ms | #402 AC2: Second identical request returns same data |
| [#403](issues/403.md) | PASS | 1 | 0 | 66ms | #403 AC1: Koppelingen reference modules (dependency chain exists) |
| [#405](issues/405.md) | PASS | 2 | 0 | 74ms | #405 AC2: Application relations can be checked before delete |
| [#406](issues/406.md) | PASS | 2 | 0 | 105ms | #406 AC1-2: No siteimprove in page source |
| [#407](issues/407.md) | PASS | 2 | 0 | 102ms | #407 AC1: Application standards accessible |
| [#409](issues/409.md) | PASS | 1 | 0 | 95ms | #409 AC1: Footer links in unauthenticated page |
| [#411](issues/411.md) | PASS | 4 | 0 | 79ms | #411 AC3: Organisaties have website populated |
| [#413](issues/413.md) | PASS | 3 | 0 | 356ms | #413 AC1: Views endpoint returns filtered views |
| [#414](issues/414.md) | PASS | 2 | 0 | 158ms | #414 AC2: Cross-org usage visibility |
| [#417](issues/417.md) | PASS | 2 | 0 | 70ms | #391 AC2: Contact persons accessible for activation check |
| [#418](issues/418.md) | PASS | 4 | 0 | 582ms | #418 AC2: Diensten list in single call |
| [#419](issues/419.md) | PASS | 2 | 0 | 78ms | #419 AC1: Standard version data accessible |
| [#420](issues/420.md) | PASS | 2 | 0 | 710ms | #420 AC2: Gemeente-beheerder sees aanbod |
| [#430](issues/430.md) | PASS | 1 | 0 | 82ms | #430 AC1: Application list response structure |
| [#431](issues/431.md) | PASS | 1 | 0 | 62ms | #431 AC1: /api/user/me has middleName field |
| [#432](issues/432.md) | PASS | 2 | 0 | 89ms | #432 AC2: Koppeling detail name matches list |
| [#433](issues/433.md) | PASS | 2 | 0 | 80ms | #433 AC1: Koppelingen have expected fields populated |
| [#434](issues/434.md) | PASS | 2 | 0 | 80ms | #434 AC2: Contact created for account holder |
| [#435](issues/435.md) | PASS | 5 | 0 | 90ms | #435 AC2: Leverancier filter returns expected apps |
| [#436](issues/436.md) | PASS | 4 | 0 | 73ms | #436 AC4: Public organisatie list returns 200 |
| [#437](issues/437.md) | PASS | 4 | 0 | 137ms | #437 AC3: Koppelingen have type field |
| [#438](issues/438.md) | PASS | 2 | 0 | 68ms | #438 AC1: Filter by specific diensttype |
| [#439](issues/439.md) | PASS | 4 | 0 | 115ms | #439 AC4: Empty search doesn't cause errors |
| [#440](issues/440.md) | PASS | 4 | 0 | 72ms | #440 AC4: Filter by Community organisations |
| [#441](issues/441.md) | PASS | 1 | 0 | 67ms | #441 AC1: Applications have version information |
| [#442](issues/442.md) | PASS | 2 | 0 | 77ms | #378 AC2: GET and verify field structure before edit |
| [#443](issues/443.md) | PASS | 1 | 0 | 68ms | #443 AC3: Dienst API returns diensttypen as array |
| [#447](issues/447.md) | PASS | 2 | 0 | 82ms | #447 AC3: Search excludes concept organisations (authenticated, non-admin) |
| [#450](issues/450.md) | PASS | 1 | 0 | 69ms | #450 AC3: No legacy publish-status flags on organisations |
| [#451](issues/451.md) | PASS | 2 | 0 | 79ms | #451 AC1: Koppeling standaardversie resolves to display names |
| [#452](issues/452.md) | PARTIAL | 1 | 1 | 69ms | #452 AC1: Applicatie overview returns correct koppelingen count |
| [#453](issues/453.md) | PASS | 3 | 0 | 87ms | #453 AC1: Search with type=koppeling returns scoped facet counts |
| [#454](issues/454.md) | PASS | 3 | 0 | 75ms | #454 AC1: Koppeling search is not scoped by organisation |
| [#455](issues/455.md) | PASS | 4 | 0 | 73ms | #455 AC2: Public API returns application contactpersonen |

---

## Failed Tests

| Issue | Test | Error | Request |
|-------|------|-------|---------|
| #65 | #65 AC8: Editing updates data | expected 404 to be one of [ 200, 201 ] | #65 AC8: Editing contact person updates data |
| #65 | #65 AC8: Edit reflected in GET | expected response to have status code 200 but got 404 | #65 AC8: Verify edit persisted |
| #452 | #452 AC1: Applicaties have a koppelingen count field | expected false to be true | #452 AC1: Applicatie overview returns correct koppelingen count |

---

## Tests Without Issue Reference

| Test | Status | Request |
|------|--------|---------|
| Nextcloud is reachable | PASS | Health Check - Nextcloud Status |
| Returns valid JSON with version info | PASS | Health Check - Nextcloud Status |
| Group 'aanbod-beheerder' created or exists | PASS | Create Group: aanbod-beheerder |
| Group 'gebruik-beheerder' created or exists | PASS | Create Group: gebruik-beheerder |
| Group 'functioneel-beheerder' created or exists | PASS | Create Group: functioneel-beheerder |
| Group 'software-catalog-users' created or exists | PASS | Create Group: software-catalog-users |
| Group 'software-catalog-admins' created or exists | PASS | Create Group: software-catalog-admins |
| User 'jan.pietersen@test.nl' created or exists | PASS | Create User: jan.pietersen@test.nl |
| User 'jan.vandeberg@testleverancier.nl' created or exists | PASS | Create User: jan.vandeberg@testleverancier.nl |
| User 'maria.vanderberg@test.nl' created or exists | PASS | Create User: maria.vanderberg@test.nl |
| User 'mark.jansen@test.nl' created or exists | PASS | Create User: mark.jansen@test.nl |
| User 'linda.bakker@test.nl' created or exists | PASS | Create User: linda.bakker@test.nl |
| User 'peter.vandijk@test.nl' created or exists | PASS | Create User: peter.vandijk@test.nl |
| User 'sarah.devries@test.nl' created or exists | PASS | Create User: sarah.devries@test.nl |
| User added to group or already member | PASS | Add jan.pietersen to aanbod-beheerder |
| User added to group or already member | PASS | Add jan.pietersen to software-catalog-users |
| User added to group or already member | PASS | Add jan.vandeberg to aanbod-beheerder |
| User added to group or already member | PASS | Add jan.vandeberg to software-catalog-users |
| User added to group or already member | PASS | Add maria.vanderberg to gebruik-beheerder |
| User added to group or already member | PASS | Add maria.vanderberg to software-catalog-users |
| User added to group or already member | PASS | Add mark.jansen to gebruik-beheerder |
| User added to group or already member | PASS | Add mark.jansen to software-catalog-users |
| User added to group or already member | PASS | Add linda.bakker to gebruik-beheerder |
| User added to group or already member | PASS | Add linda.bakker to software-catalog-users |
| User added to group or already member | PASS | Add peter.vandijk to functioneel-beheerder |
| User added to group or already member | PASS | Add peter.vandijk to gebruik-beheerder |
| User added to group or already member | PASS | Add peter.vandijk to aanbod-beheerder |
| User added to group or already member | PASS | Add peter.vandijk to software-catalog-admins |
| User added to group or already member | PASS | Add peter.vandijk to software-catalog-users |
| User added to group or already member | PASS | Add sarah.devries to gebruik-beheerder |
| User added to group or already member | PASS | Add sarah.devries to software-catalog-users |
| Org created or exists | PASS | Create Org: Test Leverancier BV |
| Org created or exists | PASS | Create Org: Test Gemeente |
| Org created or exists | PASS | Create Org: Test Samenwerking |
| Org created or exists | PASS | Create Org: Test Leverancier 2 |
| Can list organisations | PASS | Lookup All NC Org UUIDs |
| Register org created or exists | PASS | Create Register Org: Test Leverancier BV |
| Register org created or exists | PASS | Create Register Org: Test Gemeente |
| Register org created or exists | PASS | Create Register Org: Test Samenwerking |
| Register org created or exists | PASS | Create Register Org: Test Leverancier 2 |
| Can list register organisations | PASS | Lookup All Register Org UUIDs |
| Can list register organisations | PASS | Lookup All Register Org UUIDs |
| Can list register organisations | PASS | Lookup All Register Org UUIDs |
| Can list register organisations | PASS | Lookup All Register Org UUIDs |
| Can list register organisations | PASS | Lookup All Register Org UUIDs |
| jan.pietersen@test.nl joined Test Leverancier BV | PASS | Join jan.pietersen to Test Leverancier BV |
| jan.pietersen@test.nl active org set | PASS | Set Active: jan.pietersen -> Test Leverancier BV |
| jan.vandeberg@testleverancier.nl joined Test Leverancier 2 | PASS | Join jan.vandeberg to Test Leverancier 2 |
| jan.vandeberg@testleverancier.nl active org set | PASS | Set Active: jan.vandeberg -> Test Leverancier 2 |
| maria.vanderberg@test.nl joined Test Gemeente | PASS | Join maria.vanderberg to Test Gemeente |
| maria.vanderberg@test.nl active org set | PASS | Set Active: maria.vanderberg -> Test Gemeente |
| mark.jansen@test.nl joined Test Gemeente | PASS | Join mark.jansen to Test Gemeente |
| mark.jansen@test.nl active org set | PASS | Set Active: mark.jansen -> Test Gemeente |
| linda.bakker@test.nl joined Test Samenwerking | PASS | Join linda.bakker to Test Samenwerking |
| linda.bakker@test.nl active org set | PASS | Set Active: linda.bakker -> Test Samenwerking |
| Contact created or exists | PASS | Create Contact: Jan Pietersen |
| Contact created or exists | PASS | Create Contact: Maria van der Berg |
| Contact created or exists | PASS | Create Contact: Mark Jansen |
| Contact created or exists | PASS | Create Contact: Linda Bakker |
| Can list contacts | PASS | Lookup Test Contact UUIDs |
| Applicatie created | PASS | Create Test Applicatie Leverancier |
| Applicatie 2 created | PASS | Create Test Applicatie Leverancier 2 |
| Dienst created | PASS | Create Test Dienst Leverancier |
| Gemeente applicatie created | PASS | Create Test Applicatie Gemeente |
| Can list applicaties | PASS | Lookup Test Applicatie UUIDs |
| Profile seeded for jan.pietersen@test.nl | PASS | Seed Profile: jan.pietersen |
| Profile seeded for jan.vandeberg@testleverancier.nl | PASS | Seed Profile: jan.vandeberg |
| Profile seeded for maria.vanderberg@test.nl | PASS | Seed Profile: maria.vanderberg |
| Profile seeded for mark.jansen@test.nl | PASS | Seed Profile: mark.jansen |
| Profile seeded for linda.bakker@test.nl | PASS | Seed Profile: linda.bakker |
| Profile seeded for peter.vandijk@test.nl | PASS | Seed Profile: peter.vandijk |
| Profile seeded for sarah.devries@test.nl | PASS | Seed Profile: sarah.devries |

