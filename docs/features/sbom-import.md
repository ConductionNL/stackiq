<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# SBOM import

Imports a Software Bill of Materials (SBOM) — CycloneDX 1.5/1.6 JSON, with
SPDX 2.3 JSON as an optional second format — for a specific `moduleVersie`
(a released version of an application), parsing its components into
`sbomComponent` OpenRegister objects and surfacing them on a **Components**
tab with licenses, summary counts, and a render-time cross-reference against
the existing `kwetsbaarheid` (vulnerability) register.

Specification: [`openspec/specs/sbom-import/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/sbom-import/spec.md).

## Uploading an SBOM

On a module version's detail page, open the **Components** sidebar tab.
Choose a format (CycloneDX or SPDX, both JSON) and a file, then **Import
SBOM**:

```
POST /apps/stackiq/api/moduleversies/{moduleVersieUuid}/sbom
multipart/form-data: sbomFile=<file>, format=cyclonedx-json|spdx-json
```

The upload is rejected — before the parser ever runs — when it exceeds the
configured maximum size (10 MB by default) or is not valid JSON. Importing
requires admin group membership, or membership of a manage-tier group
**and** manage-ACL (RBAC read) on the version's parent application; anyone
else gets a 403 and no objects change.

```json
{
  "success": true,
  "operationId": null,
  "moduleVersieUuid": "b2c3d4e5-...",
  "componentCount": 3,
  "previousComponentCount": 0,
  "distinctLicenseCount": 2,
  "vulnerabilityPairCount": 0,
  "sbomFormat": "cyclonedx-json",
  "sbomFileName": "sbom.json"
}
```

## Re-import replaces, never accumulates

Importing a second SBOM for the same version **replaces** the previous
component set: the previous live `sbomComponent` objects are soft-deleted
and the newly parsed set is created. Already-trashed rows from an earlier
replace are never re-queried or re-deleted (OpenRegister's default search
already excludes `_deleted` rows). If the create step fails partway through,
the version is left with no live component set rather than a mixed
old/new one — a re-run of the import starts clean either way. This mirrors
the same replace-not-accumulate model used elsewhere in this app rather than
introducing an import-history/audit-log concept.

Both the soft-delete and the create step run in bounded batches (~100
objects per OpenRegister call). Imports whose parsed component count
exceeds 50 start a `progress-tracking` operation, update it per batch, and
complete it — the operation id is returned in the response so the frontend
can poll `GET .../sbom?operationId=...` for `{ phase, percentage,
processed_items }`. Smaller imports complete synchronously and the response
already carries the final counts.

## What gets stored

Each parsed component persists as one `sbomComponent` OpenRegister object,
related to its `moduleVersie`:

| Field | Source |
|---|---|
| `name`, `version` | CycloneDX/SPDX component name + version |
| `purl` | Package URL (`pkg:...`) |
| `licenses` | SPDX license id(s)/expression(s), or free text |
| `type` | CycloneDX component type (`library`, `application`, …) |
| `hashes` | Informational file hashes — never used for matching |
| `bomRef` | CycloneDX `bom-ref` — within-import traceability only |
| `vexCveIds` | CVE ids the SBOM's own VEX block associates with this component's `bom-ref` — a raw fact from the source document, not a stored vulnerability match |

Three optional provenance fields are set on the `moduleVersie` itself on
every successful import: `sbomLastImportedAt`, `sbomFormat`, `sbomFileName`
— shown as a "last imported ⟨date⟩ from ⟨file⟩" line on the Components tab.

## Vulnerability matching — computed, never stored

The Components tab cross-references each imported component against the
existing `kwetsbaarheid` register using two bounded, local strategies —
never an outbound HTTP call to an external advisory feed (OSV.dev, NVD, …):

1. **Confirmed match** — a component's VEX-extracted `vexCveIds` compared,
   case-insensitively, against `kwetsbaarheid.cveCode`.
2. **Possible match** — the component's `name` (or the package segment of
   its `purl`) compared, case-insensitively (substring), against
   `kwetsbaarheid.naam`, scoped to `kwetsbaarheid` records whose `modules`
   already reference the version's parent `module`. A same-name
   vulnerability recorded against a *different* application never surfaces
   here.

Both matches are computed at render time by
[`src/utils/sbomVulnerabilityMatch.js`](../../src/utils/sbomVulnerabilityMatch.js)
— nothing is written back to either `sbomComponent` or `kwetsbaarheid`.
Editing a `kwetsbaarheid`'s `cveCode`/`naam` after an import changes the
match set on next render, with no re-import required. This feeds
`module-vulnerability-tracking` rather than forking a parallel vulnerability
model.

## Components tab

The **Components** tab on a module version's detail page (`SbomComponentsPanel`)
shows:

- Summary counts — total components, distinct licenses, matched
  vulnerabilities.
- The "last imported" provenance line, when an import has happened.
- The upload control (format select + file input + Import button).
- The component table (name, version, package URL, licenses) with a
  **Confirmed match** / **Possible match** badge per matched component.
- An empty state with the upload control when no SBOM has been imported yet.

## Out of scope

- Outbound calls to an external vulnerability/advisory service — that
  integration, if built, belongs in `openconnector` (per
  `feedback_integrations-not-leaves`).
- SBOM generation/export — this feature only imports.
- License-policy evaluation (allow/deny lists, obligations) — only the raw
  license identifiers are captured.
- Transitive dependency graphs — the component **list** only; `bomRef` is
  captured for future use but no dependency-edge graph is parsed or
  rendered.
