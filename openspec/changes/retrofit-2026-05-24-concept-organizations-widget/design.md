# Design — Retrofit Concept Organizations Widget

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

The dashboard widget already exists and is registered via `Application::register()`. Coverage scan bucketed `load()` as 2b and the metadata getters as `plumbing`. This change retro-specifies `load()` so the cluster moves to Bucket 1.

## Decisions

- **Cluster mode** — no existing capability covers dashboard widgets in softwarecatalog.
- **1 REQ / 1 method** — the metadata getters are plumbing. Only `load()` carries an enforceable contract.
- **Spec dependency order explicitly.** webpack's splitChunks produces these chunk names; the load order is what makes the widget work, not the chunk names. The REQ captures both.

## Out of scope

- Refactoring the widget to a single-bundle / dynamic-import shape — open a separate issue.
- Removing the `softwarecatalog-` prefix or aligning with the openbuild widget naming — out of scope.

## References

- Umbrella: ConductionNL/softwarecatalog#285
- Coverage report: openspec/coverage-report.md (2026-05-24)
- Source: lib/Dashboard/ConceptOrganisatiesWidget.php
