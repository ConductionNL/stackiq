# Design: contracts-licence-seats

Read at development 49e65cb4.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Fragment | new `lib/Settings/register.d/contracts-licence-seats.json` (ADR-037) | adds `licenceMetric`, `licencesBought` and `licencesInUse` to `catalogContract.properties` (the monolith's block runs from `lib/Settings/softwarecatalogus_register.json:3281` to `:3462`) and raises the schema `version` (`:3270`) |
| Page | `src/manifest.json:556` `ContractDetail` | one entry in `bodyWidgets` (`:578`), after `ct-approval` |
| Component | new `src/components/contracts/ContractSeatsPanel.vue`, registered in `src/customComponents.js` next to `ContractApprovalPanel` (`:22`, `:82`) | reads the contract, renders the library `CnProgressBar` inside `CnWidgetWrapper` |
| Util | `src/utils/licensePosture.js` | new pure `seatPosition(contract)` and `seatRows(contracts, usages, modules)` next to `perVendorRollup` (`:197`) |
| View | `src/views/LicensePostureView.vue` (page `LicensePosture`, `src/manifest.json:1005`) | a fourth section, Seats, after the per-organisation section; it already fetches `catalogContract` (`:432`) |
| Overlay | `openspec/features.overlay.json:149` | `license-and-seat-tracking` status `soon` to `available` once shipped |

No controller, route or store change: the contract fields are edited through the existing detail form, and the posture view already loads contracts, usages and modules through `objectStore` (`src/views/LicensePostureView.vue:429` to `:432`).

## Decisions

### D1. The counts live on the contract

`licencesBought` is part of what the organisation bought, so it belongs on the contract. `licencesInUse` would be more natural on the `usage`, which is the deployment. But no page creates or edits a `usage` today (matrix row `stackiq:land-usage-record`: `src/manifest.json` has no page on schema `usage`), and a contract points at exactly one usage (`lib/Settings/softwarecatalogus_register.json:3301`). On the contract, the application owner edits both numbers in one form and the comparison needs no join.

Rejected: `licencesInUse` on `usage`. It would be write-only through the API until the usage page exists.

### D2. The metric lives on the contract, not on the module

The row asks for the licence model of an application. The module is the supplier's published record, read by every organisation in the catalogue (its `authorization.read` lets the public read published modules). One municipality's licence terms do not belong on it, and a supplier sells one product per named user to one customer and per inhabitant to another. The contract is where the metric the organisation actually bought is known. `module.licentietype` and `module.licence` stay as they are and keep answering the open or closed source half of the row.

Rejected: a `licenceMetric` on the module, copied into the contract. Two copies drift.

### D3. Metrics that cannot be counted are not compared

`Per organisation` and `Other` have no seat. For those the panel and the seats section show "Not counted" and no bar. For the other four metrics, `seatPosition()` returns one of: `within` (in use at most bought), `over` (in use above bought), `unknown` (either number empty).

### D4. The panel uses library parts only

`ContractSeatsPanel` is a body widget for the same reason as `ContractApprovalPanel`: the built-in `stat` widget (`src/manifest.json:565` `ct-value`) shows one aggregated number and cannot set two fields of one object against each other. The panel composes `CnWidgetWrapper` and `CnProgressBar` (`@conduction/nextcloud-vue` 2.57.1, `src/components/CnProgressBar/CnProgressBar.vue`, item fields `count` and `total`) and draws nothing of its own (ADR-012). Colours come from the bar's `variant`, `success` or `error`, which map to Nextcloud variables (ADR-003).

## Declarative versus imperative

The comparison is a derived read over one object, computed in the browser like the rest of the posture page (`src/utils/licensePosture.js` header: "Nothing is stored; every figure is derived at query time"). Nothing is stored, so there is no lifecycle, aggregation or notification rule to declare. An over-use notification is a possible follow-up as an `x-openregister-notifications` rule, and is not part of this change.

## Seed data

`catalogContract` gains three properties, so the demo descriptor `lib/Settings/stackiq_mock_register.json` (contracts from `:7608`) gets matching values.

| Field | Contract 1 | Contract 2 | Contract 3 | Contract 4 |
|---|---|---|---|---|
| `@self.slug` | contract-contract-1-1 | contract-contract-2-2 | contract-contract-3-3 | contract-seats-5 |
| `contractType` | SLA | Licence | Maintenance | Licence |
| `licenceMetric` | empty | Per named user | empty | Per inhabitant |
| `licencesBought` | empty | 400 | empty | 58000 |
| `licencesInUse` | empty | 460 | empty | 57120 |

Contract 2 shows over use, contract 4 shows use within the licence, and the SLA and maintenance contracts show no seats panel content.

## Risks

- **A stale in-use number reads as fact.** The panel prints the contract's last update date from `@self.updated`, so the reader sees when the number was entered.
- **Big numbers.** A per inhabitant licence runs into tens of thousands. The panel formats both numbers with the user's locale and the bar works on the ratio, so size does not matter.
- **Validation.** Both counts are integers with `minimum: 0`. OpenRegister rejects a negative number on save; the form shows that error as it does for every other field.
