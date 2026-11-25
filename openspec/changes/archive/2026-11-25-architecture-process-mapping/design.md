# Design: architecture-process-mapping

Read at development 49e65cb4. Line numbers below are from that sha. `GebruikDetail` comes from the open change `landscape-usage-registration` (its `design.md`, `src/manifest.d/usages.json`), and the Architecture menu group from `architecture-views-editor` (its design D8).

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Register | `stackiq` register (`lib/Settings/softwarecatalogus_register.json:817`), new schemas `process` and `processStep` | through a new fragment `lib/Settings/register.d/architecture-process-mapping.json` |
| Register, read only | `vng-gemma` `element` (:4130) for the reference process, `usage` (:2654) for the supporting applications | no change to either schema |
| Pages | new `src/manifest.d/architecture-process-mapping.json` with `Processen` (index), `ProcesDetail` (detail) and `ProcesStapDetail` (detail) | |
| Pages | `GebruikDetail` in `src/manifest.d/usages.json` (from `landscape-usage-registration`) gains an `object-list` widget | |
| Menu | `src/menu-layout.json` relocates `Processen` under the `Architecture` group | |
| Views | new `src/views/architecture/ProcessStepFlow.vue` and `src/views/architecture/ProcessStepUsages.vue`, registered in `src/customComponents.js` | |
| Service, controller, routes | none | the frontend writes through OpenRegister's objects API (config rule "Uses OpenRegister API directly from frontend") |

The fragment merges through `SettingsService::loadSettings` (`lib/Service/SettingsService.php:1653-1680`, `deepMergeConfig` at :7338). It carries `components.schemas.process`, `components.schemas.processStep`, `components.registers.stackiq.schemas: ["process", "processStep"]` and `components.registers.stackiq.configuration.schemas.<slug>: {"magicMapping": true, "autoCreateTable": true}` for both.

## Decisions

### D1. A process is organisation data in the `stackiq` register

`process` and `processStep` live in the `stackiq` register next to `usage`, with the same authorization shape `usage` has (register.json, `usage.authorization`): create and update for the catalogue groups, read for `gebruik-beheerder` and `aanbod-beheerder` matched on `_organisation`. Processes are the municipality's own, like its usages, so OpenRegister multitenancy scopes them to the organisation that made them.

`process` carries `configuration.jsonld.type` `https://schema.org/HowTo` and `processStep` carries `https://schema.org/HowToStep`, in the way `module` carries `SoftwareApplication`.

Rejected: a process as an AMEF `element` of type `BusinessProcess` in the `vng-gemma` register, with steps as child elements and Composition relations. The AMEF register holds VNG's model, `element` has 86 properties built for GEMMA content, and a municipality's process would need the `origin` guards of `architecture-views-editor` D9 on every reader. The supporting applications are `usage` objects in the `stackiq` register, and a relation across the two registers is what the organisation data already does through `usedForReferenceComponents`.

### D2. The schemas

`process`:

| Field | Type | Notes |
|---|---|---|
| name | string, required | |
| description | string | |
| processOwner | related `contactPerson` | picked from the organisation's contact roles, as `landscape-usage-registration` does for owners |
| status | enum draft, active, retired, default draft, facetable | lifecycle in the Declarative section |
| referenceProcess | related `element` in `vng-gemma`, `objectConfiguration.queryParams` `type=BusinessProcess` | the GEMMA reference process this one follows |
| tags | list of strings, facetable | |

`processStep`:

| Field | Type | Notes |
|---|---|---|
| process | related `process`, required | |
| name | string, required | |
| description | string | |
| stepType | enum task, event, decision, default task | |
| position | integer, required | the order within the process |
| follows | list of `processStep` uuids | empty means "the step before it by position" |
| usages | list of related `usage` | the applications in use that support the step |
| riskLevel | enum not assessed, low, medium, high, default not assessed, facetable | |
| riskNote | string | |
| complianceCheck | enum not checked, compliant, not compliant, not applicable, default not checked, facetable | |
| complianceNote | string | |
| checkedOn | date | when the compliance check was last done |

The `referenceProcess` uuid is stable across GEMMA imports, because the import sets an element's uuid to its GEMMA object id (`lib/Service/ArchiMateImportService.php:4794-4798`).

### D3. Pages under the Architecture group

`Processen` (`/processen`) is a `CnIndexPage` (manifest `type: index`) over `process` with columns name, status, processOwner and tags, the schema facets in the sidebar, and quick filters All, Active and Draft.

`ProcesDetail` (`/processen/:id`) is a `type: detail` page on the ADR-062 grid with:
- a `data` widget for name, description, status, owner, reference process and tags,
- an `object-list` widget `process-steps` over `processStep` with filter `{"process": "@objectId"}`, columns position, name, stepType, riskLevel and complianceCheck, sorted on position, `rowRoute: ProcesStapDetail`,
- a body widget `ProcessStepFlow` (see D4),
- `lifecycleActions` on, and the History tab in the sidebar.

`ProcesStapDetail` (`/processtappen/:id`) is a `type: detail` page with a `data` widget over every step field and a body widget `ProcessStepUsages` that lists the step's usages. It is a small custom component rather than an `object-list`, because it compares the stored uuids with the returned objects to count the hidden ones (see Risks).

The menu entry `Processen` is relocated under the `Architecture` group that `architecture-views-editor` adds (its D8), so the top-level count does not grow (ADR-097).

### D4. The step flow is read-only on `CnGraphCanvas`

`ProcessStepFlow.vue` loads the steps of one process, turns each into a node (name, step type, risk level, and the names of the supporting applications) and draws an edge from each uuid in `follows` to the step, or from the step with the next lower position when `follows` is empty. It passes them to `CnGraphCanvas` (`@conduction/nextcloud-vue` 2.57.1, `src/components/CnGraphCanvas/CnGraphCanvas.vue`, props `nodes`, `edges` and `readOnly` at :178, :184 and :196) with `readOnly` true, and places them with `layoutFlowNodes` (`src/composables/flowGraphLayout.js:329`), imported through the package's `./src/*` export as `architecture-assistant-drafted-views` D4 does. A step with a high risk or a failed compliance check gets `--color-error` on its border, and the text on the node says the same, so colour is never the only signal (WCAG 1.4.1).

Rejected: an editable BPMN canvas. The rows ask to model processes and link applications, and a list with an order and a `follows` list does that. An editor is a second drawing tool next to the view editor, and it can come later on the same data.

Rejected: OpenRegister flows. A flow (`openregister-ro/appinfo/routes.php:825`, BPMN export) is an automation that runs; a business process here is a description of how the municipality works, with owners, risks and applications. Storing descriptions as flows would put non-runnable flows in the flow engine's list.

### D5. Fixed step fields, not customer-defined questionnaires

The step fields are properties of `processStep`: risk level, risk note, compliance check, compliance note and checked on. `CnDetailPage` and `CnFormDialog` render them from the schema, and the facets filter on them.

Rejected: questions a functional administrator defines per step type, answered per step, as BlueDolphin offers. That needs a question-definition schema and a form renderer for answers of any type, which is a second form system beside the schema-driven one (ADR-012). Letting an administrator add properties to `processStep` in OpenRegister's schema editor was also rejected: the repair step re-imports the register JSON and a version bump replaces the properties, so an added question would vanish on an update. The matrix row names risk level and a compliance check, which the fixed fields cover.

### D6. The application in use lists the steps it supports

`GebruikDetail` gets an `object-list` widget `usage-process-steps` over `processStep` with filter `{"usages": "@objectId"}`, columns process, name, riskLevel and complianceCheck, `rowRoute: ProcesStapDetail`, titled "Process steps this application supports". OpenRegister filters an array property on one value with a JSON containment test (`openregister-ro/lib/Db/MagicMapper/MagicSearchHandler.php:1601`), so no index or service is needed.

## Declarative versus imperative

- The process status lifecycle is declared as `configuration.x-openregister-lifecycle` on `process`: field `status`, initial draft, transitions activate (draft to active), retire (active to retired) and reopen (retired to draft). The `from` and `to` values are the enum values exactly (register changelog 2.4.4, register.json:7). `lifecycleActions` on `ProcesDetail` renders them.
- The step to process and step to usage links are `related-object` properties, so OpenRegister keeps them in its relation index. No PHP.
- The two lists are manifest `object-list` widgets with filters. No aggregation or notification is added.
- `ProcessStepFlow.vue` is the only imperative piece, and it only reads.

## Seed data

All objects live in the `stackiq` register. The reference process is the GEMMA element "Bedrijfsproces Behandelen vergunningaanvraag" (`lib/Settings/GEMMA_release.xml:950`), uuid `01f2e505-8245-44e5-860c-336a831eaae9`. On an instance without a GEMMA import the reference stays empty.

### Schema: `process`

| Field | Object 1 |
|---|---|
| slug | `seed-proces-vergunningaanvraag` |
| name | Behandelen vergunningaanvraag |
| description | Van intake tot besluit op een aanvraag voor een vergunning. |
| status | active |
| referenceProcess | `01f2e505-8245-44e5-860c-336a831eaae9` |
| tags | vergunningen |

### Schema: `processStep`

| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| slug | `seed-stap-intake` | `seed-stap-toetsen` | `seed-stap-besluiten` |
| process | `seed-proces-vergunningaanvraag` | `seed-proces-vergunningaanvraag` | `seed-proces-vergunningaanvraag` |
| name | Intake vergunningaanvraag | Toetsen indieningsvereisten | Besluiten vergunningaanvraag |
| stepType | event | task | decision |
| position | 1 | 2 | 3 |
| usages | `gebruik-topdesk-gem-leiden-deelnemers` | none | none |
| riskLevel | low | medium | high |
| complianceCheck | compliant | not checked | not compliant |
| complianceNote | | | Besluit wordt nog niet gearchiveerd volgens de selectielijst. |

The usage slug is one of the three usages the register already seeds (register.json `components.objects`).

## Risks

- **An array filter on MariaDB.** The containment test at `MagicSearchHandler.php:1601` is PostgreSQL SQL. The Playwright scenario for D6 runs on the CI database, and a MariaDB instance needs a check before this ships there.
- **Hidden usages.** RBAC can hide a linked usage from a reader. `ProcesStapDetail` compares the stored uuids with the returned objects and shows "1 linked application is not visible to you" instead of a silent gap.
- **Steps out of order.** Two steps with the same position draw side by side. The index sorts on position and then name, and the form warns on a duplicate position without refusing it.
