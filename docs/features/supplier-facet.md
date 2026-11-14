<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Supplier facet

The **Applications** (`/modules`) and **Services** (`/diensten`) pages narrow
the list by **Supplier** (Leverancier), next to the four GEMMA facets
(reference component, standard, application service, domain). It is the
first facet GEMMA Softwarecatalogus users look for.

Specification: [`openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md).

## What it counts

- On **Applications**, an application counts under the organisation in its
  `provider` (shown as Supplier).
- On **Services**, a service counts under its **own** `provider`, the
  Provider column the list shows, not the suppliers of the applications
  behind it.
- Each entry is one organisation, chosen by its id and shown by its name.
  Two organisations with the same name stay two entries; an organisation
  without a name shows under its id.
- Counts follow the other facets and the search like every facet: the
  supplier's own counts are not narrowed by the supplier selection, and a
  chosen supplier narrows the other facets and the list.

## Keeping a choice

- The choice is in the address as `_gf_supplier`, so a link or a reload
  keeps it.
- A saved facet view keeps the chosen suppliers and chooses them again
  when it is applied.

## Demo data

A fresh demo import gives three applications and three services a supplier:
on `/modules` the facet shows **Voorbeeld Name 2** with 2 and
**Voorbeeld Name 3** with 1.

## How it is built

`lib/Service/FacetService.php` computes `supplier` as its fifth dimension
over the same RBAC-scoped base set as the GEMMA facets, so it shares their
narrowing and their cache. The names come from one bounded read of the
configured organisation schema. The dimension set is declared once in
`FacetService::DIMENSIONS`; `FacetController` reads its filters from it and
`src/services/facets.js` mirrors it, with tests holding all three to the same
five names. The cache key includes the dimension set, so an answer cached
before the supplier facet existed is not served after it.
