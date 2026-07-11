## ADDED Requirements

### Requirement: New organisations MUST inherit a parent linkage from the active organisation
When a user with an active organisation creates a new organisation via `OrganisatieService`, the new organisation's `parent` MUST be set to the active organisation's uuid, and the creating user MUST retain read/write access to the newly created organisation.
This restores hierarchy modelling (gemeente/samenwerkingsverband, moederorganisatie/deelnemende partij) that was disabled by a prior RBAC-related hotfix.

#### Scenario: Organisation created while another organisation is active
- GIVEN a user whose active organisation is A
- WHEN the user creates a new organisation B via the softwarecatalog
  organisation-creation flow
- THEN B's `parent` MUST equal A's uuid
- AND the creating user MUST be able to read and write B immediately after
  creation (no access regression)

#### Scenario: Organisation created with no active organisation
- GIVEN a user with no active organisation set
- WHEN the user creates a new organisation C
- THEN C's `parent` MUST be null (root organisation), unchanged from current
  behaviour

#### Scenario: Parent assignment that would break creator access is rejected, not silently applied
- GIVEN the create-then-link fix path (design.md option B) is in effect
- WHEN setting `parent` on a newly created organisation would remove the
  creating user's access to it
- THEN the parent assignment MUST be rolled back (organisation stays a root
  organisation)
- AND the creation call MUST surface an error/warning rather than silently
  returning a now-inaccessible organisation
