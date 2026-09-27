# Design: landscape-dependent-field-options

Read at development `49e65cb4`, OpenRegister development `4fee776`, `@conduction/nextcloud-vue` 2.57.1.

## Context

OpenRegister reads `x-openregister-dependent-values` from a property: `{ "controlledBy": "<other property>", "allowed": { "<controlling value>": ["<allowed>", ...] } }` (`openregister lib/Service/Rules/DependentValueTable.php:55`, the shape at :106-128). A controlling value the table does not list leaves the property unconstrained (:30-40). `DependentValueListener` refuses an object save with code `dependent-value-not-allowed` when a value is outside its row. The library form narrows relation pickers by `x-relation-filter` (`CnFormDialog.vue:1183`), but nothing in `@conduction/nextcloud-vue` 2.57.1 reads the dependent-values annotation (a search of `src/` finds none).

## D1. Two tables in a register fragment

`lib/Settings/register.d/dependent-field-options.json`:

- `components.schemas.module.properties.licence["x-openregister-dependent-values"]`: `controlledBy: licentietype`, `allowed: { "Open source": [the five licences of the enum], "Closed source": [] }`.
- `components.schemas.organization.properties.samenwerkingtype["x-openregister-dependent-values"]`: `controlledBy: type`, `allowed: { "Collaboration": [the collaboration types], "Municipality": [], "Supplier": [], "Community": [] }`.

The fragment adds a key to existing property objects; the deep merge unions object keys (`lib/Service/SettingsService.php:7338`), so nothing else in those properties changes.

## D2. The stray enum value

`organization.samenwerkingtype.enum` in `lib/Settings/softwarecatalogus_register.json` loses `samenwerkingtype`. That edit goes in the monolith, because a fragment can only append to a list (lists merge by `array_merge`, `SettingsService.php:7352`). The organization schema version is bumped with it.

## D3. Existing rows

A repair step `lib/Repair/ClearDisallowedDependentValues.php`, registered in `appinfo/info.xml` before the register import, clears `licence` on closed-source modules and `samenwerkingtype` on organisations that are not a collaboration, and logs how many it cleared. Without it the first edit of such a row after the import would be refused for a field the user did not touch.

## D4. The form half lives in the library

`CnFormDialog` gains the same treatment for `x-openregister-dependent-values` that `relationFilterDecls` gives `x-relation-filter`: when the controlling field changes, the dependent field's options become the table row, and a value outside it is cleared. That is a change in ConductionNL/nextcloud-vue; this change bumps `package.json` to the release that ships it, and until then the save-time refusal from OpenRegister is shown as the form error.

Rejected: a stackiq-only form wrapper. ADR-012 keeps form behaviour in the shared library, and every app with an enum pair gains from it.

## Declarative versus imperative

Declarative: two annotations OpenRegister already enforces (ADR-031). The repair step is the one imperative piece, a one-off data fix.

## Seed data

The demo register's modules and organisations already satisfy both tables; the repair step's test uses its own fixtures.

## Risks

- A future licence value added to the enum must also be added to the table, or it is refused for open-source modules. The unit test compares the enum with the table's Open source row.
