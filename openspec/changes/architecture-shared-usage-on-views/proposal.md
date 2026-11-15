---
kind: code
depends_on:
  - architecture-views-editor
---

# Shared applications are drawn apart from your own on a GEMMA view

## Summary

An information manager opens a GEMMA view and sees which applications fill each reference component. Applications the organisation uses through a partner (deelnames) look different from the ones it runs itself, and each one names the partner. This works in two places: on the GEMMA view page inside stackiq, and in the organisation's ArchiMate export that opens in Archi.

## Why

This change covers one matrix row.

- `stackiq:arch-shared-overlay`, "On a GEMMA view, see the applications you share with partners, drawn apart from your own." Stackiq rates partial, state specified. The delivered change `2026-06-14-module-overlay-rendering` (archived) wrote the requirement in `openspec/specs/module-overlay-rendering/spec.md:55-79`: a deelnames node must be styled differently from an owned one and must carry a visual sign of shared ownership. It assumed a JointJS renderer in the external VNG frontend, which stackiq does not ship. The VNG Softwarecatalogus rates partial: a cooperation's packages show "alleen op de lijst en kaart van de samenwerking" (https://www.softwarecatalogus.nl/node/30355), but drawing shared apart from own is not described.

The backend half is built. The drawing half is missing in both places stackiq can draw a view.

## What stackiq has today

Read at development 8f74f890.

- `lib/Service/ViewService.php:463` reads shared usage when `include_deelnames_gebruik` is set, `:467-469` drops a shared usage when the organisation also owns one for the same element, and `:932-955` tags each one `_type: deelnames` with `_sourceOrganizationId` and `_sourceOrganization`. `:494` and `:501` put the owned list under `usage` and the shared list under `deelnamesGebruik` on each enriched view node.
- `lib/Controller/ViewController.php:308-313` exposes `include_gebruik` and `include_deelnames_gebruik` on `GET /api/views/{viewId}`.
- `lib/Service/ArchiMateExportService.php:2451-2471` builds the shared applications for the organisation export when the Deelnames box is ticked (`src/views/settings/sections/ArchiMateImportExport.vue:614`), then merges them with the owned ones before `copyAndEnrichViews()` (`:2474`, `:2731`). `processNodesForInjection()` (`:2956`) gives every nested application the same green style (`:3003-3004`). The only difference left is a separate folder, `Deelnames (Stackiq)` (`:3083`), which Archi shows in the model tree, not on the view.
- `src/store/modules/view.js:15` defines `useViewStore`, imported by nothing. No page in stackiq draws a GEMMA view today. The open change `architecture-views-editor` adds one: imported GEMMA views open read-only on `CnGraphCanvas` in `src/views/architecture/ArchitectureViewEditor.vue`.

## What this change builds

- On the read-only GEMMA view page from `architecture-views-editor`: a "Show applications" control with two switches, "Our applications" and "Shared with partners". Switched on, the page asks `GET /api/views/{viewId}` with `include_gebruik` and `include_deelnames_gebruik`, and draws each application inside its reference component.
- A shared application has a dashed border, the text "Shared" on the node, and "Shared by <partner>" in its tooltip and accessible name. An own application has a solid border. A legend under the canvas explains both.
- In the organisation ArchiMate export: shared applications get their own fill and line colour, and a property `Gedeeld door` naming the partner, so Archi draws them apart on every view copy. When an application is both owned and shared for the same reference component, it is drawn once, as owned, the rule `ViewService` already applies.

## Out of scope

- The external VNG frontend. It is a VNG client repository (`Softwarecatalogus/`) that this team does not commit to. Whether it draws deelnames apart is its own question.
- Drawing applications on views the user drew themselves. Drawn views hold elements placed by hand; the overlay is for imported GEMMA views.
- Editing usage from the view. A click opens the usage detail page; changes happen there.
- Which partners count as deelnames. That stays the deelnemers relation `ViewService` reads today.

## Risks

- `CnGraphCanvas` renders every node through `CnFlowNode`. If it does not pass a node's `class` through to Vue Flow's wrapper, the dashed border needs a small library change. Task 2 checks this first; if it fails, the builder files it against nextcloud-vue and the text badge still carries the difference.
- A large GEMMA view with many usages can hold several hundred extra nodes. The switches start off, so the plain view stays as fast as `architecture-views-editor` makes it.
- Colours in an ArchiMate exchange file are fixed RGB values, not theme variables. The export picks a pair with at least 3:1 contrast between the two fills, and the property carries the meaning for readers who cannot tell them apart.
