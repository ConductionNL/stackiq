# Context Brief: suite-wizard

## What
A guided wizard to register an application **suite** (e.g. "Centric Leefomgeving") and its member applications in one pass, plus the suite index/detail surfaces to view them. Closes softwarecatalog#372.

## Why (evidence)
- VNG Softwarecatalogus issue **#242** — the retired "product" concept should be replaced by a suite wizard; the datamodel object already exists in the incumbent.
- Corroborated by a broader UX theme: **21 wizard-labelled VNG issues** (guided flows are one of the top user-wish clusters).

## Current state — the schema ALREADY EXISTS and is unused
`suite` is defined in `lib/Settings/softwarecatalogus_register.json` with:
`naam`, `beschrijvingKort`, `beschrijvingLang`, `logo`, `website`, `contactpersoon`, `applicaties` (related-object array).
`suite_schema` is already wired in `voorzieningen_config`. There is **no** suite Vue view, no wizard, no controller — this is purely additive UI over an existing schema. Verify the exact property shapes yourself before building.

## Scope
IN:
- A multi-step wizard (create suite → attach member applications → confirm) reachable from a nav entry and/or the Applications page.
- Suite index page + suite detail page (members listed, links through to each module).
- Attaching **existing** modules to a suite, and showing suite membership on the module detail page.
- i18n (EN keys + nl + en_US), unit tests, docs page.

OUT: creating brand-new modules from inside the wizard (attach existing only — keep the change small); suite-level contracts/licensing; migrating the legacy "product" concept.

## Design constraints
- **Register changes go in a NEW `lib/Settings/register.d/suite-wizard.json` FRAGMENT — never edit the monolith.** Per ADR-037 (`lib/Settings/register.d/README.md`) each change ships its own fragment. This is not just convention: the import version is computed from `info.version` + a hash of the `register.d/*.json` fragments, so **a monolith edit is a silent no-op on every installed instance** (see softwarecatalog#391). If `suite` needs no schema change at all, add no fragment.
- ADR-001: all data via OpenRegister, no custom tables. ADR-008 Controller→Service if any backend is needed (prefer none — the frontend can use the object store directly).
- ADR-012: use `@conduction/nextcloud-vue` components (CnIndexPage / CnFormDialog / modal-in-its-own-file); do NOT hand-roll a stepper if the library offers one. `NcSelect` needs an `inputLabel` prop (a11y gate). Modals/dialogs MUST live in their own file under `src/modals/` or `src/dialogs/`.
- 🔑 When registering an object type in the store, register by **schema SLUG** against `voorzieningenConfig.register` — the pattern `useSelfFetchList.js` uses. Do NOT resolve via `voorzieningen_config.<x>_schema`; several of those keys are never populated (that exact mistake made the portfolio-report org picker dead — sc#392).
- Spec deltas: `### Requirement: <name>` headers; MUST/SHALL on the requirement's FIRST physical line; no angle brackets in requirement bodies; `#### Scenario:` GIVEN/WHEN/THEN per MUST/SHALL.
- `@spec` anchors → canonical `openspec/specs/<capability>/spec.md#requirement-<kebab>`, NEVER a change dir (archive moves it).
