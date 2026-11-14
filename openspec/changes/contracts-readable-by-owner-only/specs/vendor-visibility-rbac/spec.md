## ADDED Requirements

### Requirement: Contracts MUST be readable only by the owning organisation and admins (REQ-006)

Because contract CRUD runs entirely through the OpenRegister object store (ADR-022, `contract-administration`), contract read visibility MUST be governed by the `contract` schema's RBAC read rule. A contract MUST be readable only by users whose active organisation is the contract's owning organisation (`_organisation`), plus the `admin` group and the app's super-user group `software-catalog-admins`. Every other role granted a contract read, `ambtenaar` included, MUST be match-scoped to the caller's own organisation via the schema's `_organisation` field. An unscoped grant or a `crossOrganisation` grant for any role other than `admin` and `software-catalog-admins` is a spec violation. A custom endpoint that reads a contract with RBAC or multitenancy switched off MUST check the owning organisation before it reads.

#### Scenario: Vendor cannot read another organisation's contract
- GIVEN a contract owned by municipality A, and a user in the `aanbod-beheerder` group whose active organisation is vendor V
- WHEN the user attempts to read the contract via the OpenRegister object API
- THEN the read MUST be denied (empty/404, governed by the schema RBAC rule)
- AND V MUST NOT receive any field of the contract object

#### Scenario: The owning organisation and admins read the contract
- GIVEN a contract owned by municipality A
- WHEN a user whose active organisation is A, or a user in the `admin` group, reads the contract
- THEN the read MUST succeed

#### Scenario: A civil servant of another organisation cannot read the contract
- GIVEN a contract owned by organisation A, and a user in the `ambtenaar` group, not `admin`, not `software-catalog-admins`, whose active organisation is B
- WHEN the user lists contracts or reads this contract via the OpenRegister object API
- THEN the contract MUST NOT be in the list and the read MUST be denied, empty result or 404
- AND B MUST NOT receive any field of the contract object

#### Scenario: A civil servant of the owning organisation reads the contract
- GIVEN a contract owned by organisation A, and a user in the `ambtenaar` group whose active organisation is A
- WHEN the user reads the contract
- THEN the read MUST succeed

#### Scenario: Municipality-side role cannot read another organisation's contract
- GIVEN a contract owned by organisation A, and a user in the `gebruik-beheerder` group, not `admin`, not `software-catalog-admins`, whose active organisation is B, not A
- WHEN the user attempts to read the contract via the OpenRegister object API
- THEN the read MUST be denied, empty result or 404, governed by the schema RBAC rule
- AND B MUST NOT receive any field of the contract object

#### Scenario: software-catalog-admins retains unrestricted contract read access
- GIVEN a contract owned by any organisation
- WHEN a user in the `software-catalog-admins` group reads the contract via the OpenRegister object API
- THEN the read MUST succeed regardless of the user's active organisation

#### Scenario: A custom endpoint checks the owner before an unscoped read
- GIVEN an app endpoint that reads a contract with `_rbac: false` or `_multitenancy: false`
- WHEN a user whose active organisation is not the contract's owner, and who is not an admin, calls it
- THEN the endpoint MUST refuse before the unscoped read is issued

## REMOVED Requirements

### Requirement: Contract reads MUST deny non-counterparty cross-organisation access via the OpenRegister schema RBAC rule (REQ-006)
**Reason**: It let every `ambtenaar` and the counterparty read contracts of other organisations. Decision 409 (13 November 2026) closes that exception.
**Migration**: Replaced by "Contracts MUST be readable only by the owning organisation and admins (REQ-006)". The `ambtenaar` read grant on `catalogContract` becomes match-scoped to `_organisation`.
