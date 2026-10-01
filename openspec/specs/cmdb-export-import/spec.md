---
capability: cmdb-export-import
status: in-progress
built_by: openspec/changes/cmdb-export-import
---

# cmdb-export-import Specification

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [cmdb-export-import](../../changes/cmdb-export-import/) _(active)_ — admin uploads a TOPdesk CMDB export (xlsx); stackiq upserts modules, vendor organisations, usages and owner contact persons for one municipality from the two CMDB sheets, matched on APPID, mapped by OpenRegister migration packs (kind: code)

## Purpose

A Nextcloud admin imports a TOPdesk CMDB export (xlsx) into stackiq for one
municipality. Every application row becomes, or updates, a `module` with its
manufacturer `organization`, a `usage` that links it to the municipality, and
`contactPerson` objects for its owners, all stored as OpenRegister objects
(ADR-001). The mapping is declarative JSON executed by OpenRegister's mapping
engine (ADR-031), so a newer export can be imported again without duplicates,
and OpenCatalogi and Portaliq can show the result (Jira WOO-586).

## Requirements

Detailed requirements (REQ-CMDB-001 … REQ-CMDB-014) are defined in the active
change's delta spec —
[`openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md`](../../changes/cmdb-export-import/specs/cmdb-export-import/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Stackiq imports a TOPdesk CMDB export into OpenRegister objects (REQ-CMDB-000)

Stackiq MUST offer Nextcloud admins one import path for a TOPdesk CMDB export
(xlsx) that writes only OpenRegister objects in the `stackiq` register
(`module`, `organization`, `usage`, `contactPerson`), with no app-local table,
and that matches rows on the TOPdesk APPID so that a repeated import
creates no duplicates.

#### Scenario: A repeated import adds no objects

- GIVEN a TOPdesk export imported once for a municipality
- WHEN the same export is imported again for that municipality
- THEN the number of `module`, `organization`, `usage` and `contactPerson` objects SHALL be unchanged
- @e2e exclude umbrella anchor; the behaviour is covered by REQ-CMDB-006 in the change's delta spec (tests/e2e/spec-coverage/cmdb-import.spec.ts and tests/Unit/Service/CmdbExportImportServiceTest.php)

## Notes

- Follows the upload patterns of `sbom-import` and `archimate-import`.
- Related: stackiq#373 (live TOPdesk connector), stackiq#1127 (record
  reconciliation), stackiq#1134 (ITSM exchange).
