# Tasks: module-overlay-rendering

> **Build note (2026-06-10):** the placeholder "Implementation planning"
> task has been replaced with an honest scoping note. The frontend GEMMA
> view renderer (JointJS) is NOT in this `softwarecatalog` repo — it
> lives in the downstream GEMMA-view-consuming apps (currently the
> Conduction website and `pipelinq`). The backend API this overlay needs
> ships from this repo (see `view-enrichment-api` and `deelnames-gebruik`).

## Scope clarification

- [~] Task 1 — JointJS overlay node rendering for modules — **OUT OF
  SCOPE for `softwarecatalog`**: the GEMMA view renderer lives in
  downstream view-consumers. This repo owns the data layer
  (`view-enrichment-api` / `deelnames-gebruik`) that the renderer
  consumes. Move this task to a consumer-repo openspec change once a
  consumer commits to picking it up.
- [~] Task 2 — Parent-child node positioning with topological sort —
  **OUT OF SCOPE for `softwarecatalog`**: rendering concern lives in
  the consumer; the API exposes the parent/child references on the
  enriched response.
- [~] Task 3 — Color coding + interactive tooltips — **OUT OF SCOPE
  for `softwarecatalog`**: UX presentation concern lives in the
  consumer; the API distinguishes deelnames vs regular gebruik via
  `type: 'deelnames'`.
- [~] Task 4 — Paper freeze/unfreeze performance pattern — **OUT OF
  SCOPE for `softwarecatalog`**: JointJS-specific optimisation lives
  in the consumer.

## Cross-references

- Backend (in this repo): see `view-enrichment-api/tasks.md` and
  `deelnames-gebruik/tasks.md` — the data API for module overlays.
- Frontend renderer (not in this repo): tracked under the consumer
  app's openspec change once committed.

## Acceptance criteria

This change is reclassified as a scoping note: the rendering tasks
move to the consumer app's openspec backlog. The API surface required
to drive the overlay is in place via `view-enrichment-api` and
`deelnames-gebruik`.
