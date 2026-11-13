# Tasks: contracts-readable-by-owner-only

## 1. Register rule
- [ ] 1.1 In `lib/Settings/softwarecatalogus_register.json`, replace `{"group": "ambtenaar", "crossOrganisation": true}` in the `catalogContract` read rule with `{"group": "ambtenaar", "match": {"_organisation": "$organisation"}}`.
- [ ] 1.2 Check the register.d fragments that touch `catalogContract` (`contracts-licence-seats.json`, `contracts-to-decidesk.json`, `sharing-itsm-exchange.json`) and remove any read grant that is not match-scoped, other than `admin` and `software-catalog-admins`.

## 2. Custom read paths
- [ ] 2.1 List every PHP read of a contract that passes `_rbac: false` or `_multitenancy: false` (for example `ContractApprovalService::loadContract()`) and make each one check the caller's organisation against the contract's `_organisation` before it reads, or run it as a documented system path.

## 3. Tests
- [ ] 3.1 A register test asserts that no `catalogContract` read grant other than `admin` and `software-catalog-admins` lacks a `_organisation` match.
- [ ] 3.2 A test per custom read path from 2.1: an `ambtenaar` of organisation B gets no field of organisation A's contract; an `ambtenaar` of A and an admin do.

## 4. Capability row
- [ ] 4.1 Set `org-data-segregation` to built in `openspec/parity/capabilities.json` with the rule and test as evidence, then archive this change.
