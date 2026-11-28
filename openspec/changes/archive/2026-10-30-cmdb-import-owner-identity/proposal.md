---
kind: code
depends_on: [cmdb-import-mapping-view]
---

# Proposal: cmdb-import-owner-identity

## Summary

The CMDB import recognises an application owner by employee number first, then by e-mail address, then by name. The business-owner pack maps two optional columns, `Applicatie Eigenaar (E-mail)` and `Applicatie Eigenaar (Personeelsnummer)`, whose names are placeholders: the administrator renames their source in OpenRegister's pack editor once the export's real column names are known, and no stackiq release is needed for that.

## Motivation

Owners are matched on their display name, so two people with the same name become one contact (Jira WOO-586, F29). Gemeente Rotterdam answered on 2026-10-15 that it can add the owner's e-mail address and employee number to the CMDB sheets, and that the employee number never changes; on 2026-10-19 it said it knows the column names but first wants to align them across its teams. Shipping placeholders now, editable in OpenRegister (cmdb-import-mapping-view), lets the municipality pick the names later without waiting for a release.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cmdb-export-import`: MODIFIED REQ-CMDB-010 (owner matching on employee number and e-mail address, the two optional columns as placeholders, the business-owner pack's allowed fields).

## Affected Projects

- [ ] Project: `stackiq`: the pack rules, the business-owner pack and the profile under `lib/Settings/cmdb-import/`, the owner resolution in `CmdbExportImportService`, `StackiqContactSyncService`, documentation, tests.

## Scope

### In Scope

- `email` and `employeeNumber` as fields the business-owner pack may map.
- The shipped business-owner pack 1.1.0 with the two placeholder columns; both listed as absent on the CMDB sheets, so an export without them raises no warning.
- Matching an owner on a contact UID derived from the employee number and the municipality, in the "Stackiq CMDB owners" address book; creating the contact with that UID.

### Out of Scope

- Storing the employee number itself anywhere.
- Merging the contact an owner had before the employee number arrived into the new one.
- Changing the profile's column lists in OpenRegister: the profile is not an editable pack.

## Risks

- An owner first imported by name or e-mail address gets a second contact once the employee number arrives; the old contact stays in the address book. Documented.
- An unedited stored business-owner pack is replaced by 1.1.0 on update (REQ-CMDB-022); an edited one is kept and the new columns are then not added, with the existing "newer shipped version not applied" warning.
