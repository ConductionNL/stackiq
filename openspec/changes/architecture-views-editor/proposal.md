---
kind: code
depends_on: []
---

# Draw, tag and compare architecture views in stackiq

## Summary

An application owner can draw an ArchiMate view in stackiq: place elements, connect them, save the view and open it again. Views get tags, a status and an owner, and the views list filters on all three. An owner can save a named version of a view and compare two versions, with additions, removals and changes marked on the canvas and listed as text. Imported GEMMA views open read-only, and Copy to edit makes a drawn copy.

## Why

Three rows of the stackiq parity matrix are built by this change.

- `stackiq:arch-modelling`, "Draw and edit architecture models yourself inside the tool." SAP LeanIX rates yes: "free draw and data flow diagrams edited in the diagram editor, with an ArchiMate 3.2 shape template" (https://help.sap.com/docs/leanix/ea/importing-and-exporting-diagrams). BlueDolphin rates yes: "users create and edit ArchiMate architecture views in the view editor" (https://help.bluedolphin.io/en/articles/11967507-create-an-architecture-view). No tender or feature request names it. The lane decided build because two competitors rate yes and architecture is a core area.
- `stackiq:arch-diagram-version-compare`, "Compare two saved versions of an architecture diagram and see what was added, removed or changed." The demand is a changelog entry, https://updates.leanix.net/announcements/compare-the-content-of-different-diagram-versions. SAP LeanIX rates yes: "Selecting Compare Changes ... on a prior diagram version shows color-coded highlighting (green for additions, red for removals, yellow for modifications), a side-by-side view of differences, and an additional text-based summary". The matrix holds no evidence URL beyond the changelog entry. Decided build: core area.
- `stackiq:arch-view-tags`, "Tag saved diagrams and views and filter the view list by tag, owner or status to find them again." The demand is a changelog entry, https://help.bluedolphin.io/en/articles/16096602-discover-and-manage-views-in-the-views-list. BlueDolphin rates yes: "Bring structure to your Views list with tags", with view filters "Owner Contributors Tags Favorited Private Project Status Type" (https://bluedolphin.io/product-news/). Decided build: core area.

The rated rows say stackiq renders no architecture view. The pending row `stackiq:arch-gemma-views` rates stackiq partial with state built. Both hold, because they describe different things. The API that serves enriched views exists (`appinfo/routes.php:185-187`), and so does the organisation export that draws applications into copies of GEMMA views (`lib/Service/ArchiMateExportService.php:2734`). No page draws a view: `src/store/modules/view.js:15` defines `useViewStore`, nothing under `src/` imports it, and nothing under `src/` reads `viewNodes` or `viewRelationships`. The pending row's own note says the same.

## What stackiq has today

- The AMEF register `vng-gemma` (`lib/Settings/softwarecatalogus_register.json:916`) holds the schemas `element` (:4130), `view` (:5299), `model` (:5689), `property-definition` (:6140) and `relation` (:6300). The `view` schema is at version 0.0.7 (:5304) and its authorization only grants public read (:5678). It has no tag, status or owner field of its own.
- The ArchiMate import writes each view with `@self.id` set to the ArchiMate identifier (`lib/Service/ArchiMateImportService.php:5394`) and stores the diagram under `xml.viewNodes` and `xml.viewRelationships`: a flat node list with `parent` references, `elementRef`, `x`, `y`, `width` and `height` (:2886 to :3058), and connections with `modelRelationshipId`, `sourceId` and `targetId` (:3364). It saves through `saveObjects` (:1587), which updates an object with the same id. A re-import overwrites every imported view.
- `lib/Controller/ViewController.php:82` and `lib/Service/ViewService.php:108` serve `GET /api/views`, and `ViewService::transformView` (:1451) turns `xml.viewNodes` into `viewNodes` with a `position` and a `style`. The list is cached for all callers under one key, `views_list` (:234), for 30 minutes (:74).
- The full ArchiMate export reads every AMEF object with `_rbac: false` and `_multitenancy: false` (`lib/Service/ArchiMateExportService.php:844`).
- `src/manifest.json` has one page over the AMEF register, Standaarden (:701), filtered to `gemmaType` standaard. The main menu has 15 entries next to Dashboard, 13 after `src/menu-layout.json` relocates two of them.
- OpenRegister keeps an audit trail per object and can revert to a version (`openregister-ro/appinfo/routes.php:1344` and :1453). It replaces any changed value over 65,536 bytes with a descriptor (`openregister-ro/lib/Db/AuditTrailPayloadHelper.php:51` and :150).
- `@conduction/nextcloud-vue` 2.57.1 ships `CnGraphCanvas` (`src/components/CnGraphCanvas/CnGraphCanvas.vue`, a Vue Flow canvas with `nodes`, `edges` and `readOnly` props), `CnVersionHistory` and the `useObjectLock` composable (`src/composables/useObjectLock.js:66`).

## What this change builds

- A register fragment `lib/Settings/register.d/architecture-views.json` that adds `tags`, `status`, `origin` and `basedOn` to `view`, adds `origin` to `element` and `relation`, declares the view status lifecycle, and adds a `view-version` schema to the `vng-gemma` register.
- A Views index page and a view editor page in `src/manifest.d/architecture-views.json`, reached from an Architecture menu group that takes the place of the top-level Standards entry.
- A canvas editor on `CnGraphCanvas` that places elements, draws connections backed by `relation` objects, and saves in the shape the import already writes.
- Copy to edit for imported views, which open read-only.
- Save version and Compare versions, with a diff keyed by node and connection id.
- Backend guards: drawn views stay out of the shared `/api/views` list and out of the full ArchiMate export.

## Out of scope

- Drawing the organisation's own applications (stackiq `module` objects) into a view. The organisation export does that into copies of GEMMA views today, and the pending row `stackiq:arch-gemma-views` covers it.
- Drafting a view with an assistant. That is `architecture-assistant-drafted-views`, which depends on this change.
- Putting a view into Word or PowerPoint. That is `architecture-views-to-office-documents`.
- Business processes on a view. That is `architecture-process-mapping`.
- Editing an imported GEMMA view in place, and writing a drawn view back into the GEMMA model.
- Live co-editing. One editor holds the lock (ADR-033), others read.

## Risks

- A drawn view in the AMEF register sits next to VNG's GEMMA content. The `origin` field and the two backend guards keep them apart. A future import path that forgets the guard would mix them, so the guards get their own tests.
- Snapshots are copies of the node list. A view with many versions grows the register. Versions are only saved on an explicit action, not on every save.
