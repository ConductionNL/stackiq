# Context Brief: catalog-ratings

## What
Turn the dormant `beoordeeling` (review) schema into a working, **moderated** ratings-and-testimonials feature — and close the authorization hole it currently ships with. Closes softwarecatalog#375.

## Why (evidence)
- VNG Softwarecatalogus issue **#49** — peer municipalities want experiences/ratings when selecting software. Selecting software on peer experience is a core catalog job-to-be-done.
- Competitive: peer-comparison is exactly the value the centralised GEMMA registry cannot easily offer.

## 🔴 Security gap to close (found 2026-07-24 — do this even if the feature is trimmed)
The `beoordeeling` schema today has:
- `authorization: {"read": ["public"]}` — **no create / update / delete rules at all**
- **no author field and no owner/organisation field**
- its own description says it is *"onderdeel van het vastgestelde datamodel maar wordt niet daadwerkelijk in de applicatie gebruikt"*

So reviews are world-readable with undefined write rules and no attributable author. Shipping a ratings UI on top of that without fixing it would be irresponsible. Properties today: `naam`, `beschrijvingKort`, `beschrijvingLang`, `waardering` (the rating), `modules`, `diensten`, `koppelingen`, `gebruik`.

Also latent: the manifest's `Reviews` index declares an **`auteur` column that does not exist on the schema** — a dead column to fix.

## Scope
IN:
- Schema: add author binding (the submitting user) + owning organisation; explicit `authorization` create/update/delete rules (author or org-admin may edit their own; deletion restricted); keep public read ONLY for **approved** reviews.
- A `status`/moderation field with an approval workflow, reusing the existing `ModerationQueue.vue` pattern already built for anonymous organisation registration — do not invent a second moderation mechanism.
- Submit-a-review flow (rating + testimonial) from a module/dienst detail page.
- Aggregate rating display (average + count) on module/dienst detail, and the `auteur` column fixed on the Reviews index.
- i18n (EN keys + nl + en_US), unit tests, docs.

OUT: cross-organisation reputation scoring; review replies/threads; notifying vendors of new reviews (a notification rule already exists for reviews — reuse, don't extend); anonymous public review submission.

## Design constraints
- **Register changes go in a NEW `lib/Settings/register.d/catalog-ratings.json` FRAGMENT — never edit the monolith.** Per ADR-037 (`lib/Settings/register.d/README.md`). The import version is computed from `info.version` + a hash of the `register.d/*.json` fragments, so **a monolith edit is a silent no-op on every installed instance** (softwarecatalog#391).
- **Fail closed**: unapproved reviews must not be publicly readable. Known OR trap (or#2025) — a veto evaluated AFTER a default-open grant is dead code, so deny before any grant. `publish` is RBAC, not a self-serve flag.
- Author identity must come from the server session, never from client-supplied input (else anyone can forge an author).
- ADR-001 OpenRegister storage only; ADR-008 layering; ADR-012 Cn components (modals in their own file; `NcSelect` needs `inputLabel`).
- 🔑 Register object types in the store by **schema SLUG** against `voorzieningenConfig.register` (the `useSelfFetchList.js` pattern) — several `voorzieningen_config.<x>_schema` keys are never populated; that exact mistake made the portfolio-report org picker dead (sc#392).
- Security change ⇒ `hydra-gate-security-change-has-tests` requires tests; include NEGATIVE tests (unapproved review not publicly readable; non-author cannot edit someone else's review).
- Spec deltas: `### Requirement: <name>` headers; MUST/SHALL on the FIRST physical line; no angle brackets in requirement bodies; `#### Scenario:` GIVEN/WHEN/THEN per MUST/SHALL.
- `@spec` anchors → canonical `openspec/specs/<capability>/spec.md#requirement-<kebab>`, NEVER a change dir.
