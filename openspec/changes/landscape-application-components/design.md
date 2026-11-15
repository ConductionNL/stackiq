# Design: landscape-application-components

Read at development `49e65cb4`.

## Context

`module` (`lib/Settings/softwarecatalogus_register.json:6779` schema, version 0.3.3) is the application. It has versions (`moduleVersion.module`), usages (`usage.module`), connections (`connection.moduleA` and `moduleB`), standards and reference components. `suite` (`register.json:1137` schema) groups applications that are sold together. LeanIX models components as child applications, and GEMMA reference components apply to components as much as to whole applications, so a component stays a `module`.

## D1. One self relation on module

Through `lib/Settings/register.d/application-components.json`:

| property | type | notes |
|---|---|---|
| `partOf` | `$ref module` | title "Part of", `x-relation-filter: { "provider": "@object.provider" }` so a component belongs to an application of the same supplier; `inversedBy: components` |

and a computed inverse `components` (array of `$ref module`, `hideOnForm: true`, `x-relation-filter: { "partOf": "@objectId" }`), the same pattern `moduleVersion.usages` uses (`register.json:7651` schema).

A module whose `partOf` points at itself, or at one of its own components, is refused: a `x-openregister-validation` rule if OpenRegister's dialect supports a not-self check, else a check in `ModuleRegistrationService` before save (the service that already hooks module saves, `lib/Service/ModuleRegistrationService.php`).

Rejected: a separate `applicationComponent` schema. A component would then lose versions, usages, connections and compliance claims, which all point at `module`.

## D2. Pages

- `ModuleDetail` (`src/manifest.json:491`): an `object-list` widget `md-components`, filter `{ "partOf": "@objectId" }`, `rowRoute: ModuleDetail`, `allowCreate: true` with `partOf` and `provider` filled in. `partOf` joins the data widget's `include` list, so a component shows its parent.
- `Modules` page (`src/manifest.json`, component `FacetedCatalogIndexView`): a quick filter "Whole applications" (default) with `partOf` empty, and "All, including components". The page's quick filters already compose with the facet narrowing (its `_note`).

## Declarative versus imperative

A relation, a filter and page widgets (ADR-031). The only imperative part is the cycle check, and only if the dialect has no rule for it.

## Seed data

The demo register gains one demo application with two components.

## Risks

- `x-relation-filter` on `provider` means a component of another supplier's product cannot be recorded; LeanIX allows it. Suppliers register their own products, so this matches who may edit.
