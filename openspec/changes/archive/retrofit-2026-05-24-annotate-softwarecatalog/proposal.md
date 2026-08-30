# Retrofit — annotate softwarecatalog against existing specs

Retroactive annotation of 53 methods across 28 files against 13 REQs in 3 capabilities (method-decomposition, softwarecatalog-manifest-v1, softwarecatalog-store-migration). No code logic changes. No spec deltas (all REQs already exist under `openspec/changes/`).

Source: `openspec/coverage-report.md` generated 2026-05-24 (Bucket 1 only).

The `method-decomposition` capability is a refactoring spec — annotated methods are the **pre-refactor subjects**, not the post-refactor handlers. The retroactive `@spec` tags make this baseline traceable. The downstream `SyncHandler`/`ViewQueryBuilder`/etc. handlers will be annotated under their own changes when they are created.

See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
