# Design: suite-wizard

## Context
`suite` is a fully-specified OpenRegister schema (`lib/Settings/softwarecatalogus_register.json`) with `naam`, `beschrijvingKort` (both required), `beschrijvingLang`, `logo`, `website`, `contactpersoon` (single related-object → `contactpersoon`) and `applicaties` (array of related-object → `module`). It has zero Vue surface. The change is purely additive frontend UI over this existing schema; ADR-001/ADR-008 push implementation toward "frontend talks to OpenRegister directly", and grounding confirmed no backend controller is needed.

## Goals / Non-Goals
**Goals:** a guided 3-step wizard to create a suite and attach existing modules; a suite index + detail page; suite membership visible from a module's own detail page.
**Non-Goals:** creating new modules from the wizard; suite-level contracts/licensing; migrating the legacy "product" concept; any PHP controller/service (no backend code is added).

## Decisions

### Decision 1: No register fragment
The `suite` schema already carries every field the wizard needs (verified by reading `lib/Settings/softwarecatalogus_register.json` directly — `naam`, `beschrijvingKort`, `beschrijvingLang`, `logo`, `website`, `contactpersoon`, `applicaties`). Per ADR-037, a fragment is only added when the schema itself changes. Since it does not, `lib/Settings/register.d/` gets no new file for this change.

### Decision 2: `CnWizardDialog`, not a hand-rolled stepper
`@conduction/nextcloud-vue` ships `CnWizardDialog` (multi-step modal: progress indicator, per-step slot, `validate` hook, `setResult`/`setError` result phase) — exactly the shape the brief asks for, and already used in this exact way by `openbuild/src/dialogs/CreateApplicationWizard.vue`. Building a custom stepper would violate ADR-012 (use the library's components; don't hand-roll what it already offers). The wizard lives at `src/dialogs/SuiteWizardDialog.vue` (it wraps `CnWizardDialog`, which wraps `NcDialog` — a dialog, not an `NcModal`, so per the modal-isolation rule it belongs under `src/dialogs/`, matching the OpenBuild precedent) with one sub-component per step under `src/dialogs/SuiteWizard/`.

### Decision 3: Register `suite` and `module` object types by schema slug, not via `voorzieningen_config.<x>_schema`
Mirrors `PortfolioReport.vue`'s documented fix for the sc#392 dead-picker bug: `<type>_schema` keys in the settings config blob are only populated for a handful of types (module/compliancy/moduleVersie/sbomComponent) and `suite` is not one of them. The wizard calls `objectStore.registerObjectType(slug, slug, voorzieningenConfig.register, { registerSlug: 'voorzieningen', schemaSlug: slug })` for both `suite` and `module` before it fetches or saves — OpenRegister's `/api/objects/{register}/{schemaSlugOrId}` accepts a schema slug interchangeably with a numeric id, so this works with no dependency on the config blob ever holding a `suite_schema`/`module_schema` key. The Suites index page itself needs no such call: passing `register="voorzieningen"` `schema="suite"` straight to `CnIndexPage` triggers the library's own self-fetch path (`CnIndexPage/useSelfFetchList.js`), which performs the identical registration internally.

### Decision 4: The wizard writes plain UUID references, matching the `related-object` convention
`CnFormDialog`'s reference-field handling (`onReferenceSelected`) stores a related-object value as the referenced object's id string, not a nested object (`formData[field.key] = String(value)`); the same schema declares `objectConfiguration.handling: "related-object"` identically for both single-object fields (e.g. `module.aanbieder`) and array-of-object fields (e.g. `suite.applicaties`) — there is no separate embedding convention for arrays. The wizard therefore submits `applicaties` as a plain array of module UUID strings.

### Decision 5: No ModuleDetail manifest change is needed for "suite membership on the module detail page"
`ModuleDetail` already carries a `type: "related"` widget (`md-related`). `CnRelatedObjectsWidget`'s self-fetch mode calls OpenRegister's `/uses` + `/used` sub-resources and merges them into one "Objects" tab. `ObjectsController::used()` (openregister `lib/Controller/ObjectsController.php`) is a **generic, backend-tracked, bidirectional** relation index ("B → A means B references A") — it is not scoped to fields a specific app declared or expects; any object that stores another object's id anywhere in a `related-object`-handled property is discoverable from the referenced object's `/used` response. Once a suite's `applicaties` array contains a module's id, that module's existing `md-related` widget surfaces the suite with zero widget-config changes, exactly as `KwetsbaarheidDetail`'s reverse-linked "Affected applications" panel already relies on the same mechanism for `kwetsbaarheid.modules`. Adding a bespoke widget would duplicate functionality the platform already provides and risk drifting from it.

### Decision 6: Suite detail page mirrors the `ContactpersoonDetail`/`ModuleDetail` archetype
`SuiteDetail` gets a `data` widget (8-wide) with the suite's own scalar/reference fields (`naam`, `beschrijvingKort`, `beschrijvingLang`, `website`, `logo`, `contactpersoon`) and a `related` widget (4-wide) that surfaces both the forward `contactpersoon` reference and the forward `applicaties` array (via the same `/uses`+`/used` mechanism as Decision 5) with click-through navigation to each module's `ModuleDetail` page. A suite does not communicate, so per the comms hard-rule no Emails/Meetings widgets are placed. Audit trail stays a sidebar tab.

### Decision 7: Wizard requires at least one attached application to advance past step 2
The suite schema itself does not mark `applicaties` as required, but a suite with zero members defeats the feature's purpose (grouping applications). The wizard's `validate` step-hook blocks advancing from the `applications` step until at least one module is selected, surfaced as a `CnWizardDialog` validation-error banner — this keeps the requirement enforced in the UI without touching the schema's own `required` array (which stays scoped to what OpenRegister itself must reject).

### Decision 8: Pure logic extracted to `src/utils/suiteWizard.js`
Payload construction (`buildSuitePayload`) and step-validity checks (`isDetailsStepValid`, `isApplicationsStepValid`) are pure functions in their own module, unit-tested directly — mirroring the existing `src/utils/translationBadge.js` + co-located `.spec.js` convention (Jest) already used throughout this app for exactly this "extract the pure logic, test it directly, keep the `.vue` thin" shape. `npx vitest run` re-runs the existing `tests/vitest/**` suite as a regression check.

## Risks / Trade-offs
- [Risk] Decision 5 (no ModuleDetail widget change) relies on OpenRegister's generic relation index actually covering array-of-object `related-object` fields identically to scalar ones. → Mitigation: confirmed by reading `ObjectsController::used()`/`getObjectUsedBy()` directly (generic, not field-scoped) and by the precedent of `KwetsbaarheidDetail`'s existing reverse-linked panel over an array field (`kwetsbaarheid.modules`) using the same widget type.
- [Risk] Requiring ≥1 application at wizard-submit time (Decision 7) is stricter than the schema. → Mitigation: this is a UI-only gate on the wizard's own guided flow; a suite can still be edited afterwards (via the generic edit form) to have zero or additional applications — the schema's own validation is untouched.

## Migration Plan
Not applicable — no schema, data, or backend change. New Vue files + manifest additions are additive; nothing is removed or renamed. Rollback is a plain code revert.

## Open Questions
None.
