# Context Brief: vendor-visibility-rbac

## What
A server-enforced visibility matrix that hides an organisation's **applicatielandschap (gebruik), koppelingen, and contracts** from vendor-role users and from other organisations, unless explicitly shared (deelname) or published as open data. Includes an audit of existing endpoints for leak paths and regression tests proving a vendor cannot enumerate another org's landscape.

## Why (evidence)
- VNG Softwarecatalogus issue #105 ("leverancier mag applicatielandschap niet zien") — plus leak bug reports #315, #394, #455 in the incumbent product: this is a known, recurring vulnerability class in this product category.
- 32 organisatie/RBAC-labelled VNG issues; 192 security + 208 privacy tender requirements in the mapped set.
- Specter canonical feature: `vendor-visibility-rbac` (must, demand 36) — highest-demand item of the build wave.

## Current state (read these specs first)
- `openspec/specs/aangeboden-gebruik-api` — afnemer/ambtenaar/deelnemer role scoping already exists for offered-usage.
- `openspec/specs/deelnames-gebruik` — deelname queries use a scoped RBAC bypass; understand it before extending.
- `openspec/specs/softwarecatalog-adopt-or-abstractions` — tenant context via `X-OpenRegister-Organisation`; RegisterResolver.
- `openspec/specs/open-data-publishing` — published-only anonymous surface (PII-stripped) is the ONLY intended public path.
- `openspec/changes/organisation-parent-hierarchy-rbac-fix` (pending on development) — related RBAC work; do not conflict.
- lib/: sc-handlers, organisatie-service specs describe role groups (beheerder/inkoper/ambtenaar).

## Scope
IN: define the visibility matrix (role × object type × relationship), server-side enforcement in the services/handlers that serve gebruik/koppelingen/contract reads, deny-by-default for cross-org access, leak-path audit of routes.php endpoints touching those objects, tests (incl. negative tests per role), docs.
OUT: UI permission editor, new sharing flows, changes to open-data publishing.

## Design constraints
- **Fail closed.** Known trap (OpenRegister or#2025): a custom-scope veto evaluated AFTER a default-open grant is dead code — enforce deny BEFORE any default grant path.
- Publish state is RBAC, not a self-serve flag.
- ADR-001 OR storage only; ADR-008 layering; ADR-009 tests mandatory for security changes (hydra gate security-change-has-tests will check).
- OpenSpec delta headers MUST be `### Requirement: <name>`.
