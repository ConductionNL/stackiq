<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# GEMMA plate

The GEMMA plate shows the GEMMA reference components with the applications one organisation uses for each of them. Every component has a state: **Covered** (one application), **Gap** (none) or **Overlap** (more than one). A gap is **fillable** when an application the organisation already uses offers that component.

Specifications: [`reference-component-coverage`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md) and [`gemma-plate`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md).

## Choosing the organisation

Pick the organisation at the top of the page. With only one organisation to choose from, the plate opens on it straight away. **Download** gives the same coverage as a CSV file.

## Choosing a GEMMA view

The **GEMMA view** picker lists the GEMMA views imported with the GEMMA model, each with its GEMMA release when the import recorded one. Pick a view, for example *GEMMA Applicatiearchitectuur, gemeentebreed*, and the plate draws that view's reference components in the view's own groups (such as Front-office, Mid-office, Back-office en basisregistraties), each with its state and the organisation's applications. Clear the picker to see the whole model grouped by the components' own GEMMA grouping.

The chosen view is kept in the address as `?view=<id>`, so a link opens the same plate. Without an imported GEMMA model the picker is empty and the page says the model is needed.

## What comparable organisations use

Under **Layers**, switch on **What comparable organisations use**. The table below the plate then shows per reference component your own applications and the applications comparable organisations use for it, with how many organisations use each. Select a component on the plate and its side panel lists the same applications with their counts, so a gap shows what others fill it with.

Comparable means the same organisation type (for example municipality) and the same inhabitants band: under 30,000; 30,000 to 60,000; 60,000 to 100,000; 100,000 to 250,000; above 250,000. Set the number of inhabitants on the organisation's page. An organisation without that number is compared with every organisation of its type. The table's heading names the type and band compared with.

### The anonymity rule

The counts are computed on the server over all usages, and the answer never contains another organisation's id or name. An application used by fewer than 3 comparable organisations shows **fewer than 3** instead of a count, so a single organisation's choice cannot be read off the table. Only members of the organisation can open its comparison.

## GEMMA definitions in place

Select a reference component on the plate and the side panel shows its GEMMA definition. On the **Applications** and **Services** pages, the list *What the reference components are* under the GEMMA filters gives each reference component an information button. It opens the component's name *from GEMMA*, its definition, and a link **More in GEMMA Online**. A component without a definition shows only its name and the link.
