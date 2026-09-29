---
kind: code
depends_on: []
---

# Licence contracts record the licence metric and the seats bought and in use

## Summary

A municipality that buys 400 user licences for an application cannot write that down in stackiq, and cannot see that 460 people now use it. This change gives a licence contract a licence metric, the number of licences bought and the number in use, shows the two against each other on the contract, and adds a seats section to the License posture page that lists every licence contract with its use and flags the ones that are over.

## Why

This change covers two matrix rows.

- `stackiq:ctr-seat-count`, "Track the number of licences bought against the number in use." Stackiq rates itself no. GLPI rates yes, from its source at 11.0.9: `install/mysql/glpi-empty.sql:6774` `glpi_softwarelicenses.number` is the bought quantity, and `src/SoftwareLicense.php:158` `computeValidityIndicator` compares it with the assigned items and flags over use (`src/SoftwareLicense.php:1030`). TOPdesk rates yes: "add fields to fill the number of licences you have purchased and still have left ... the Relationship grid widget on the software cards will display details about the licences" (https://docs.topdesk.com/en/managing-licences-in-asset-management.html).
- `stackiq:ctr-licence-model`, "Record the licence model of an application, such as open source, per user or per organisation." Stackiq rates itself partial: an application records open or closed source and which open source licence, but no licence metric. GLPI rates yes, from its source at 11.0.9: every licence has a type, `install/mysql/glpi-empty.sql:6775` `glpi_softwarelicenses.softwarelicensetypes_id`. This row is below the bar on its own and rides with `stackiq:ctr-seat-count`: seat counting needs the metric that says what one seat is.

## What stackiq has today

Read at development 49e65cb4.

- `lib/Settings/softwarecatalogus_register.json:6937` `module.licentietype` holds Closed source or Open source, and `:6965` `module.licence` holds one of five open source licence names. Neither says per user, per device or per organisation.
- `lib/Settings/softwarecatalogus_register.json:3341` `catalogContract.contractType` holds SLA, Licence or Maintenance. A Licence contract has no quantity field. The properties run from `:3281` to `:3462`, and none records a count.
- `lib/Settings/softwarecatalogus_register.json:3301` a contract points at exactly one `usage`, which in turn points at the module.
- `src/views/LicensePostureView.vue` (page `LicensePosture` at `src/manifest.json:1005`, route `/license-posture`) shows the open and closed share of the running portfolio, a per-vendor rollup and a per-organisation report. It counts deployments (`src/utils/licensePosture.js:114` `deploymentCount`), not licences.
- `openspec/features.overlay.json:149` lists `license-and-seat-tracking` as `soon`: "Track license models and seats next to your contracts."
- The archived change 2026-07-07-software-license-posture counted deployments as "the basis for any entitlement conversation" and left the entitlement itself unrecorded.

## What this change builds

- Three properties on `catalogContract`: `licenceMetric` (per named user, per concurrent user, per device, per inhabitant, per organisation, other), `licencesBought` and `licencesInUse`.
- A seats panel on the contract detail page that shows licences in use against licences bought, with a clear over-use state.
- A seats section on the License posture page: one row per licence contract with a count, showing the application, the organisation, the metric, bought, in use and the state, with over-use rows first.
- The `license-and-seat-tracking` overlay entry moves from `soon` to `available` once the pages ship.

## Out of scope

- Discovering how many licences are in use. Stackiq is not a discovery agent (matrix category), so the application owner records the number. Reading it from an identity provider or a supplier portal is an outside system, and outside systems belong to integriq (ADR-091).
- Licence keys and licence files. Documents go through filinq (ADR-075, ADR-087).
- Cost per seat and true-up invoices. The annualised cost stays with `src/utils/contractCost.js`, and billing belongs to shillinq.
- A licence metric on the module. The module is the supplier's record, and the supplier sells one product under several metrics.

## Risks

- A recorded in-use number goes stale. The panel shows when the contract was last changed, from the object's metadata, so a reader can judge how old the number is.
- New properties change the schema: the `catalogContract` version and the register version must move up, or the import skips the change.
