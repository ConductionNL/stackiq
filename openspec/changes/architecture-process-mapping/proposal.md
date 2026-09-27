---
kind: code
depends_on:
  - architecture-views-editor
  - landscape-usage-registration
---

# Model business processes and link their steps to the applications that support them

## Summary

A municipal information manager records the organisation's business processes in stackiq, step by step, and names for each step the applications in use that support it. Each step carries a risk level and a compliance check. The process page shows the steps as a flow, and the page of an application in use lists the process steps it supports. A process can point at the GEMMA reference process it follows.

## Why

This change builds two rows of the stackiq parity matrix.

- `stackiq:arch-process-mapping`, "Model business processes and link them to the applications that support them." No tender or feature request names it. SAP LeanIX rates yes: "business context subtype 'Process : Processes show the different steps and interactions', related to applications" (https://help.sap.com/docs/leanix/ea/business-context-modeling-guidelines). BlueDolphin rates yes: "The Create BPMN diagram button allows you to create a diagram directly from a business process" (https://help.bluedolphin.io/en/articles/11967673-process-linked-to-ea-perspectives) and "For each application, you will find different processes in which the selected application is involved" (https://help.bluedolphin.io/en/articles/11967500-getting-started-with-process-publication-portal). The lane decided build because two competitors rate yes and architecture is a core area.
- `stackiq:arch-process-step-fields`, "Record structured fields on individual process steps, such as risk level or a compliance check." The demand is a changelog entry, https://bluedolphin.io/blog/july-2026-bluedolphin-updates/. BlueDolphin rates yes: customers can "define questionnaires directly on BPMN elements such as tasks and events" to "centralize documentation like risk levels, compliance checks, and technical specifications" (https://help.bluedolphin.io/en/articles/15874771-questionnaires-for-bpmn-elements). Decided build: core area.

The matrix notes for both rows hold: stackiq models no processes.

## What stackiq has today

- The register holds 20 schemas (`lib/Settings/softwarecatalogus_register.json`, `components.schemas`) and none is a process. The `stackiq` register (:817) lists 15 of them, the `vng-gemma` register (:916) the five AMEF schemas.
- The ArchiMate import keeps every element type. It copies `xsi:type` into `type` without a filter (`lib/Service/ArchiMateImportService.php:973-980` and :5244-5249), and it sets an element's uuid to its GEMMA object id (:4794-4798), so the uuid survives a re-import. The GEMMA release in `lib/Settings/GEMMA_release.xml` holds 157 `BusinessProcess` elements, such as "Bedrijfsproces Behandelen vergunningaanvraag" (:950). These are VNG's reference processes. No page shows them: the only AMEF page, Standaarden (`src/manifest.json:701`), filters on `gemmaType` standaard.
- The organisation's applications are `usage` objects (register.json:2654). A usage links to reference components (`usedForReferenceComponents`) and, through `GebruikSyncService`, to AMEF element ids in `amefElements` (`lib/Service/GebruikSyncService.php:170-272`), never to a process.
- OpenRegister's flows (`src/manifest.json:1057`, page Flows) are automation flows with a BPMN export (`openregister-ro/appinfo/routes.php:825`). They run work; they do not describe how the municipality works.

## What this change builds

- Two schemas in the `stackiq` register through a fragment `lib/Settings/register.d/architecture-process-mapping.json`: `process` and `processStep`, with the step fields risk level, risk note, compliance check, compliance note and checked on.
- A Processes index page and a process detail page with a steps list and a read-only step flow on `CnGraphCanvas`, and a step detail page, under the Architecture menu group.
- A list "Process steps this application supports" on the usage detail page `GebruikDetail`.
- A link from a process to the GEMMA reference process it follows.

## Out of scope

- Drawing processes freehand in BPMN. The step flow is read-only and follows the step order. A BPMN editor can follow once the process data is in use.
- Customer-defined questionnaires on steps. This change ships a fixed set of step fields. See design D5 for why.
- Putting processes into the ArchiMate export. The organisation export (`lib/Service/ArchiMateExportService.php:2734`) draws applications into GEMMA views, and adding processes there is a later change.
- Drafting a process with an assistant. `architecture-assistant-drafted-views` drafts views only.
- Automation. Running a process is OpenRegister's flow engine (ADR-065), not this change.

## Risks

- A step lists usages across the whole organisation. A usage the reader may not see is left out of the list by OpenRegister RBAC, which can make a step look unsupported. The step detail says how many linked applications are hidden.
- GEMMA reference processes exist only after a GEMMA import. On an instance without one, the reference field offers nothing to pick, which is correct but can look broken. The field's help text says so.
