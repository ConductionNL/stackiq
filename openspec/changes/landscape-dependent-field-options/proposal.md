---
kind: config
depends_on: []
---

# Let the options of one field depend on another

## Summary

The catalogue's forms already narrow a picker by another field: the version picker follows the chosen application and the contact person picker follows the chosen supplier. Plain option lists do not: a closed-source application can still pick an open-source licence, and a municipality can pick a collaboration type. This change declares which values of one list are allowed for each value of another, so OpenRegister refuses a wrong pair on save and the form offers only the allowed options.

## Why

Row from the stackiq matrix:

- `stackiq:land-dependent-fields`, "Make the options of one field depend on another, such as model depending on brand." Rated no, no competitor rates yes. Roadmap demand: https://tip.topdesk.com/c/87-field-dependencies-brand-type-model- (TOPdesk). Core area (landscape), which is why it is built.

Re-reading the code for this change showed the rating is too low, so the matrix is corrected to partial in the same pull request: relation pickers already depend on another field (see below). What this change builds is the missing half, dependent option lists.

## What stackiq has today

- Relation pickers that follow another field: `usage.moduleVersion` with `x-relation-filter: { module: @object.module }`, `module.contactPerson` and `catalogService.contactPerson` and `catalogService.modules` on `@object.provider` (`lib/Settings/softwarecatalogus_register.json`, usage, module and catalogService schemas). The library form honours `@object.<field>` filters (`@conduction/nextcloud-vue` 2.57.1, `src/components/CnFormDialog/CnFormDialog.vue:1183`).
- Plain option lists that do not: `module.licence` (five open-source licences) is offered whatever `module.licentietype` says, and `organization.samenwerkingtype` is offered whatever `organization.type` says. The `samenwerkingtype` enum also holds the stray value `samenwerkingtype`, its own name.
- OpenRegister declares dependent option lists as a table, `x-openregister-dependent-values` with `controlledBy` and `allowed`, and refuses a pair outside the table on save (`openregister lib/Service/Rules/DependentValueTable.php:55`, `lib/Listener/DependentValueListener.php`). No stackiq property uses it, and the library form does not read it.

## What this change builds

1. `x-openregister-dependent-values` on `module.licence` (controlled by `licentietype`: open-source licences only for Open source) and on `organization.samenwerkingtype` (controlled by `type`: collaboration types only for Collaboration).
2. The stray `samenwerkingtype` value removed from its own enum.
3. A form that offers only the allowed options, by asking `@conduction/nextcloud-vue` to read the annotation in `CnFormDialog`, the way it reads `x-relation-filter`.

## Out of scope

- The library change itself lives in ConductionNL/nextcloud-vue; this change names it and pins the release that carries it. Until then, OpenRegister's save-time refusal is the guard and the form shows every option.
- An admin screen to edit the tables. The tables live in the register fragment, reviewed like code.

## Risks

- Existing rows may hold a pair the table now refuses (a closed-source application with a licence set). The design adds a repair step that clears such values before the table is imported.
