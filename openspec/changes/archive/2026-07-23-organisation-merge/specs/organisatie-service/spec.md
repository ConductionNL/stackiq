## MODIFIED Requirements

### Requirement: The system SHALL update the active flag of an OpenRegister organisation from a SoftwareCatalog status (REQ-002)

`updateOrganizationStatus(organizationUuid, objectData)` MUST find the OpenRegister `Organisation` by SC UUID via `OrganisationMapper::findByUuid`, map `objectData['beoordeling']` (default `'actief'`) through `mapStatus` to a boolean, call `setActive` + `save`. On success it MUST return `true`; on any exception it MUST log + return `false` (never propagate).

`mapStatus(status)` MUST normalise its input (lowercase + trim) and return: `true` for `actief` / `active`; `false` for `inactief` / `inactive` / `deactief`; `false` for `samengevoegd` (the organisation-merge tombstone status — a merged-away organisation MUST NOT be reported as active); `true` for any other unrecognised value (default-active for unknown statuses).

#### Scenario: Active status maps to true
- WHEN `mapStatus('Actief')` is called
- THEN the return value MUST be `true`

#### Scenario: Inactive variants map to false
- WHEN `mapStatus(' inactief ')` is called
- THEN the return value MUST be `false`
- AND `mapStatus('deactief')` MUST also return `false`

#### Scenario: Merged (tombstoned) status maps to false
- WHEN `mapStatus('samengevoegd')` is called
- THEN the return value MUST be `false`

#### Scenario: Unknown status defaults to active
- WHEN `mapStatus('pending')` is called
- THEN the return value MUST be `true`

#### Scenario: Update success
- GIVEN an organisation exists in OR with the supplied UUID
- WHEN `updateOrganizationStatus('uuid-1', ['beoordeling' => 'inactief'])` is called
- THEN the OR organisation's `active` flag MUST be `false` after the call
- AND the method MUST return `true`

#### Scenario: Tombstoning via merge also deactivates the OR entity
- GIVEN an organisation exists in OR with the supplied UUID
- WHEN `updateOrganizationStatus('uuid-1', ['beoordeling' => 'samengevoegd'])` is called (as part of `organisation-merge` tombstoning the source)
- THEN the OR organisation's `active` flag MUST be `false` after the call
- AND the method MUST return `true`
