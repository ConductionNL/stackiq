# Design — Retrofit Aanbod Listings

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`AanbodController` exposes the public HTTP surface for browsing and acting on software-catalogue offers. The companion `AanbodService` is already in Bucket 1 (covered by `method-decomposition#REQ-DECOMP-011`). This change captures the controller-level contract that REQ-DECOMP-011 only touches obliquely.

## Decisions

- **Cluster mode** — no existing capability covers HTTP-level aanbod behaviour.
- **3 REQs / 4 methods** — `parseQueryOptions` collapses into REQ-001 as plumbing. List / accept / deny are the three observable behaviours.
- **HTTP status mapping spelled out per REQ.** The controller has a tight per-endpoint mapping (400 / 403 / 404 / 500 / 200) — every distinct mapping is captured because callers depend on it.
- **No fix for `@PublicPage` posture.** All three endpoints are public and rely on session-level org context for authorisation. This is the observed behaviour and is documented in Notes; revisiting it belongs to a security ADR pass.

## Out of scope

- Refactoring `AanbodController` against ADR-005 (auth annotations) — open a separate issue if needed.
- Service-side behaviour — already in `method-decomposition#REQ-DECOMP-011`.

## References

- Umbrella: ConductionNL/softwarecatalog#285
- Coverage report: openspec/coverage-report.md (2026-05-24)
- Source: lib/Controller/AanbodController.php
