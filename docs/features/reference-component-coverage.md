<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Reference component coverage (GEMMA plate)

Shows, for one organisation, which GEMMA reference components its
applications in use cover, where it has a gap and where two or more
applications cover the same component. It answers the information manager's
question "where are we missing something, and where do we have too much?"

Specifications:
[`reference-component-coverage`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md)
and
[`gemma-plate`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md).

> Screenshots of the coverage table, the map and the Overlap section are
> still pending a live-instance capture; the Playwright-captured images
> follow in a later docs pass.

## Where the coverage comes from

A usage (`gebruik`) names the reference components it is used for in
**Used for reference components**. For each reference component of the
imported GEMMA model the coverage counts the applications in use that name it:

| State | Meaning |
|-------|---------|
| **Gap** | No application in use covers the component. |
| **Gap, fillable** | A gap, while an application the organisation already uses declares the component on its module. |
| **Covered** | Exactly one application covers it. |
| **Overlap** | Two or more applications cover it. |

Usages with status Acquisition, Planned or Phased out do not count; any other
status, including an unknown one, does. Two usages of the same module are one
application. Usages that name no reference component cover nothing, and the
page says how many there are.

Without an imported GEMMA model there are no reference components; the page
then says "Import the GEMMA model to see coverage." instead of an empty table.

## The GEMMA plate page

**Reports & Compliance → GEMMA plate** (`/gemma-plaat`):

- an organisation picker (only organisations the user may read are served);
- a **Download** button for the same rows as CSV;
- a summary: reference components, covered, gaps (with how many are
  fillable) and overlap, and a truncation notice when the organisation has
  more applications in use than one report reads;
- the filters **All**, **Gaps**, **Fillable gaps** and **Overlap**;
- the components grouped as the GEMMA model groups them, each tile with its
  applications, its state in words and a border colour that repeats it;
- a detail panel for the chosen component, naming the applications in use
  that could fill a fillable gap.

### Grouping the plate by a GEMMA view

The **GEMMA view** picker lists the GEMMA views imported with the GEMMA model, each with its GEMMA release when the import recorded one. Pick a view, for example *GEMMA Applicatiearchitectuur, gemeentebreed*, and the plate draws that view's reference components in the view's own groups (such as Front-office, Mid-office, Back-office en basisregistraties), each with its state and the organisation's applications. Clear the picker to see the whole model grouped by the components' own GEMMA grouping.

The chosen view is kept in the address as `?view=<id>`, so a link opens the same plate. Without an imported GEMMA model the picker is empty and the page says the model is needed.

### What comparable organisations use

Under **Layers**, switch on **What comparable organisations use**. The table below the plate then shows per reference component your own applications and the applications comparable organisations use for it, with how many organisations use each. Select a component on the plate and its side panel lists the same applications with their counts, so a gap shows what others fill it with.

Comparable means the same organisation type (for example municipality) and the same inhabitants band: under 30,000; 30,000 to 60,000; 60,000 to 100,000; 100,000 to 250,000; above 250,000. Set the number of inhabitants on the organisation's page. An organisation without that number is compared with every organisation of its type. The table's heading names the type and band compared with.

#### The anonymity rule

The counts are computed on the server over all usages, and the answer never contains another organisation's id or name. An application used by fewer than 3 comparable organisations shows **fewer than 3** instead of a count, so a single organisation's choice cannot be read off the table. Only members of the organisation can open its comparison.

### GEMMA definitions in place

Select a reference component on the plate and the side panel shows its GEMMA definition. On the **Applications** and **Services** pages, the list *What the reference components are* under the GEMMA filters gives each reference component an information button. It opens the component's name *from GEMMA*, its definition, and a link **More in GEMMA Online**. A component without a definition shows only its name and the link.

### Coverage on a GEMMA view

Below the plate, **Coverage on a GEMMA view** lets the user pick an imported
GEMMA view and draws it read-only. Every node of a reference component carries
its state twice: as a border colour (error for a gap, success for covered,
warning for an overlap) and as a text badge with the number and names of its
applications. A node of any other element, such as a grouping, carries no
state. A node finds its component through its `elementRef`, which is the
component's ArchiMate identifier.

## API

`GET /apps/stackiq/api/reference-component-coverage?organisation=<uuid>`
answers 403 before any query when the user may not read the organisation.
Each row of `components` holds `id`, `identifier` (the ArchiMate identifier),
`name`, `group`, `documentation`, `state`, `fillable`, `fillableBy` and
`applications`. `&format=csv` returns the same rows as CSV.

## Overlap in the portfolio report

The Portfolio rationalization report shows the same overlap next to ageing:
see [Portfolio rationalization](portfolio-rationalization-time.md#overlap).
