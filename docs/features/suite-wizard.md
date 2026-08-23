<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Suite wizard

Lets a catalogue editor register an application **suite** — a bundled
product made up of one or more existing applications, e.g. "Centric
Leefomgeving" — and attach its member applications in one guided pass. This
replaces the retired incumbent "product" concept per
[VNG Softwarecatalogus issue #242](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/242)
and stackiq#372.

Specification: [`openspec/specs/suite-wizard/spec.md`](../../openspec/specs/suite-wizard/spec.md).

## Registering a suite

Open **Suites** from the navigation menu, then click **New suite**. The
guided wizard has three steps:

1. **Details** — name, short description (required), long description, and
   website of the suite.
2. **Applications** — attach one or more applications that are *already* in
   the catalogue. Creating a brand-new application from the wizard is not
   supported — attach existing ones only. At least one application must be
   attached before you can continue.
3. **Confirm** — review the suite's details and the list of attached
   applications, then click **Create suite**.

On success, the wizard closes and takes you to the new suite's detail page.

## Suite index and detail

The **Suites** page lists every suite in the catalogue. Opening a suite
shows its own data (name, descriptions, website, logo, contact person)
alongside its attached applications, with a link through to each
application's own detail page.

## Suite membership on an application

An application's own detail page shows which suite(s), if any, include it.
This uses OpenRegister's generic, bidirectional relation index (the same
mechanism every other "which objects reference me" panel in this app relies
on) rather than a bespoke lookup — so a suite's `applicaties` list and a
module's "which suite am I in" view are always the same underlying data,
read from two directions.

## Data model

`suite` is an OpenRegister schema in the `voorzieningen` register:

| Field | Type | Notes |
|-------|------|-------|
| `naam` | string | required |
| `beschrijvingKort` | string | required |
| `beschrijvingLang` | markdown | optional |
| `logo` | file (base64) | optional |
| `website` | url | optional |
| `contactpersoon` | related object → `contactpersoon` | optional, not set by the wizard |
| `applicaties` | array of related objects → `module` | the suite's member applications |

Out of scope for this feature: creating new modules from the wizard,
suite-level contracts/licensing, and migrating the legacy "product" concept.
