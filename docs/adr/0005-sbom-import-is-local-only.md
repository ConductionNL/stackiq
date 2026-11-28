# 5. SBOM import parses locally and makes no outbound HTTP calls

## Status

Accepted

## Context

Users upload software bills of materials (CycloneDX, optionally SPDX) for module versions. The parser must be easy to test, and the import should not reach out to external advisory feeds from this code path.

## Decision

- The parser is a pure service that takes the raw JSON text and returns normalised component records. It has no dependency on OpenRegister, validates `bomFormat` and `specVersion` before reading components, and throws a typed unsupported-format exception that the controller turns into a 422 response.
- Neither the parser, the import service nor the frontend matcher makes an HTTP call. The parser reads only the uploaded bytes and matching reads only local registers. External vulnerability enrichment is out of scope for this path.

## Consequences

- The parser is unit-testable with fixture files and no database.
- The no-network rule is enforced structurally: the parser has no network-capable dependency.
- The code is in `lib/Service/SbomParserService.php`, `lib/Service/SbomImportService.php` and `lib/Controller/SbomController.php`.

Source: `openspec/changes/archive/2026-07-23-sbom-import/design.md` (Decisions 1 and 7)
