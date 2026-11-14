---
kind: code
depends_on: []
---

# Contracts are readable only by the owning organisation

## Why

The `catalogContract` read rule in `lib/Settings/softwarecatalogus_register.json` grants the `ambtenaar` group `crossOrganisation: true`. Every civil servant of every organisation can therefore read every other organisation's contracts: amounts, suppliers, end dates and seats. The `vendor-visibility-rbac` spec allowed this as an exception (REQ-006). Ruben closed the exception on 13 November 2026 (decision 409): a contract is readable by the organisation that owns it, plus admins, and nobody else.

## What Changes

- REQ-006 of `vendor-visibility-rbac` is replaced by a requirement without the `ambtenaar` and counterparty exceptions. Only `admin` and `software-catalog-admins` read across organisations.
- The `ambtenaar` read grant on `catalogContract` becomes match-scoped to `_organisation`, like every other role.
- The register.d fragments that extend `catalogContract` (`contracts-licence-seats.json`, `contracts-to-decidesk.json`, `sharing-itsm-exchange.json`) keep no unscoped read grant.
- Custom endpoints that read contracts with `_rbac: false` or `_multitenancy: false` check the owning organisation before they read.

Breaking for one group of readers: an `ambtenaar` of organisation B no longer sees organisation A's contracts. That is the point of the change.
