---
kind: code
depends_on:
  - architecture-views-editor
---

# Put an architecture view into a Word or PowerPoint document

## Summary

An application owner who looks at a view in stackiq can download it as an SVG image, or ask for a Word document or a PowerPoint slide that holds the view with its name, description, legend and date. Stackiq draws the image. The document is made by filinq, the fleet's document app, and lands in the user's Files, where the office suite opens it. When filinq is not there, the two document actions say so and the image download still works.

## Why

This change builds one row of the stackiq parity matrix: `stackiq:arch-views-office`, "Put architecture views into Word or PowerPoint documents straight from the tool." It comes from the Helmond architecture repository tender, https://www.tenderned.nl/aankondigingen/overzicht/398728; the matrix note reads "Helmond REQ19 asks for architecture views embedded in office documents."

No competitor rates yes. Three rate partial:
- GEMMA Softwarecatalogus: "Download de kaart met de knop [download SVG] ... De kaart volledig schaalbaar" (https://www.softwarecatalogus.nl/Hoe%20print%20ik%20een%20kaart%3F).
- SAP LeanIX: "Using the HTML Embed Code, you can embed and have live data from the SAP LeanIX inside a tool such as Confluence and PowerPoint" (https://help.sap.com/docs/leanix/ea/using-reports), with diagrams exported as PDF, SVG and PNG (https://help.sap.com/docs/leanix/ea/importing-and-exporting-diagrams).
- BlueDolphin: "To use the image of a view, for example, in a document, you can download the view as a file in PNG, SVG, PDF" (https://help.bluedolphin.io/en/articles/11967514-download-a-view).

The lane decided build on tender demand and a core area.

## What stackiq has today

- The only view exports are ArchiMate exchange files: `POST /api/archimate/export` and `GET /api/archimate/export/organization/{organizationUuid}` (`appinfo/routes.php:97-98`). No image, Word or PowerPoint output exists in `lib/` or `src/`.
- No page draws a view today; `architecture-views-editor` adds the view page on `CnGraphCanvas`. `CnGraphCanvas` in `@conduction/nextcloud-vue` 2.57.1 has no image export, and the library has no image export dependency.
- The stored view holds everything a picture needs: `xml.viewNodes` with `x`, `y`, `width`, `height`, `parent`, `name` and `type`, and `xml.viewRelationships` with source, target, type and bendpoints (`lib/Service/ArchiMateImportService.php:2886` to :3058 and :3364).
- `ViewService::getView` reads one view without RBAC (`lib/Service/ViewService.php:307`, the read at :328); `architecture-views-editor` limits that path to imported views.
- Stackiq holds no filinq integration: `grep -rn -i "docudesk\|filinq" lib src` finds only a comment in `lib/Repair/MigrateSchemaApplicationId.php:26`.

## What this change builds

- `lib/Service/ViewImageService.php`, which draws a view's stored geometry as a standalone SVG, and `GET /api/views/{viewId}/image.svg`, which reads the view with RBAC on.
- `lib/Service/ViewDocumentGateway.php` and `POST /api/views/{viewId}/document`, which hand the SVG and the view's text to filinq for a Word or PowerPoint file in the user's Files.
- An Export menu on the view page with Download SVG, Create Word document and Create PowerPoint slide, with the last two disabled and explained when filinq cannot take the request.

## Out of scope

- Making the Word or PowerPoint file. That is filinq's (ADR-075, ADR-087): its template rendering, its office format codec and its conversions. This change needs filinq's published document contract (ADR-075 Decision 1) and does not define it.
- Inserting a view into a document that is already open in the office suite. ADR-087 Decision 4 allows that only as a suite-specific extra behind a probe.
- PNG and PDF downloads. Word and PowerPoint read SVG, and filinq can convert when a template needs a bitmap.
- Live embedding that updates when the view changes, as LeanIX offers.

## Risks

- filinq's document contract does not exist yet at the shas read: ADR-075 is Proposed, OpenRegister 4fee776 has no capability registry (`grep -rn "pdf-export" openregister-ro/lib` finds nothing), and `@conduction/nextcloud-vue` 2.57.1 has no `CnIntegrationGate`. The SVG download ships on its own; the two document actions stay disabled with a notice until the contract lands. This is the sibling half the change assumes.
- filinq's documented backends are Mpdf and PhpWord (ADR-075 Context). A PowerPoint file needs a presentation writer or a conversion through `IConversionManager` (ADR-087 Decision 1), which filinq has to confirm.
