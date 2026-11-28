# Design: sharing-compliance-documents

Read at development `9a5ece6a`, OpenRegister development `4fee776`.

## Context

Compliance in stackiq today is claims (`compliancy`, `lib/Settings/softwarecatalogus_register.json:7406` schema), public, one per standard or BIO measure, with an evidence URL and files. DPIA facts sit on the product (`module.dpiaStatus` and friends). A document with its own audience does not fit a public claim, and a pentest report is not a claim about a standard.

## D1. The complianceDocument schema

`lib/Settings/register.d/compliance-documents.json`, added to the `stackiq` register:

| property | type | notes |
|---|---|---|
| `module` | `$ref module`, required | the product the document is about |
| `documentType` | enum `DPIA`, `processing agreement`, `pentest report`, `assurance report`, `certificate`, `ENSIA statement`, `other` | facetable |
| `title`, `summary` | string | the summary is readable by everyone who may read the document |
| `issuedOn`, `validUntil` | date | |
| `publisher` | `$ref organization` | set to the active organisation on create |
| `audience` | enum `public`, `government`, `named`, default `named` | |
| `sharedWithOrganisations` | array of `$ref organization` | used when `audience` is `named`; the publisher is always included |

`allowFiles: true` with tags matching the types. `x-openregister-quality` is not added here; `landscape-completeness-score` scores modules and usages only.

## D2. Read rules follow the audience

`authorization.read` on the schema:

- `{ "group": "public", "match": { "audience": "public" } }`
- for the catalogue's government groups (`ambtenaar`, `gebruik-beheerder`, `gebruik-raadpleger`, `functioneel-beheerder`): `{ "match": { "audience": "government" } }`
- for every catalogue group: `{ "match": { "sharedWithOrganisations": { "$contains": "$organisation" } } }`
- the publisher's own organisation: `{ "match": { "_organisation": "$organisation" } }`

Create and update: the groups that may edit a module (suppliers for their products) and the municipal groups (a municipality that ran its own DPIA). Update and delete are limited to the publisher's organisation by the `_organisation` match.

Rejected: adding an audience to `compliancy`. Claims are public by design and read by the compliance matrix (`src/utils/complianceMatrix.js`); mixing restricted documents in would hide cells from readers without saying why.

## D3. Pages

- `ModuleDetail` (`src/manifest.json:491`): an `object-list` `md-compliance-documents` over `complianceDocument` with filter `{ module: @objectId }`, columns type, title, valid until, publisher; `allowCreate: true` with `module` filled in.
- `src/manifest.d/compliance-documents.json`: an index `ComplianceDocuments` (`/compliance-documents`) with `filterMenu` on type and a quick filter "Expiring within 90 days" (`validUntil` within P90D), and a detail page with data, files and history. A menu child of the existing Compliance entry (ADR-097).
- The create form shows a confirmation when `audience` is set to public on a pentest report.

## Declarative versus imperative

All declarative: schema, read rules and pages (ADR-031). The form confirmation is a small handler on the library form.

## Seed data

Two demo documents on a demo product: a public processing agreement template and a pentest report shared with one demo municipality, so the read rules show in the demo.

## Risks

- The `$contains` read rule runs on a JSON array column; the schema test asserts OpenRegister builds a query for it, and an e2e case checks that an organisation outside the list sees nothing.
