<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Reference component coverage (GEMMA plate)

Shows, for one organisation, which GEMMA reference components its
applications in use cover, where it has a gap and where two or more
applications cover the same component. It answers the information manager's
question "where are we missing something, and where do we have too much?"

Specification:
[`openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md).

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
