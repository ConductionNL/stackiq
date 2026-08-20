# Tasks — vendor-product-master-data

## 1. Semantic types (ADR-048)

- [x] 1.1 `organisatie` schema: add `configuration.jsonld.type =
  https://schema.org/Organization` and `configuration.implements =
  ["https://schema.org/Organization", "https://openregister.app/ns#Vendor"]`.
- [x] 1.2 `module` and `suite` schemas: add `configuration.jsonld.type =
  https://schema.org/SoftwareApplication`.
- [x] 1.3 `module.aanbieder`, `dienst.aanbieder`, `koppeling.aanbieder`: add
  `referenceSemanticType = https://openregister.app/ns#Vendor` alongside the
  existing `$ref`/`objectConfiguration.handling: related-object` (local `$ref`
  kept). (`gebruik.aanbieder` also references organisatie but is out of the
  spec's stated scope — left unchanged; noted as a follow-up candidate.)

## 2. MDM markers (ADR-045)

- [x] 2.1 `organisatie`: add `x-openregister-dedup` (exact `contactsUid` match,
  weight 1, threshold 0.7) and `x-openregister-quality` (completeness of
  `contactsUid`, `type`, `status`). Deviation: the design's "fallback similarity
  on resolved contact name + website" is NOT expressible as an OR schema-field
  matchRule because `organisatie` has no local `naam`/`website` — identity lives
  on the external NC contact via `contactsUid`. So `contactsUid` exact is the
  operative rule; name/website similarity fallback deferred (needs an
  OR-side contact-resolving match method).
- [x] 2.2 `module`: add `x-openregister-dedup` (naam normalized+levenshtein +
  aanbieder exact + website normalized; threshold 0.7) and
  `x-openregister-quality` (completeness of `naam`, `aanbieder`, `licentie`,
  `beschrijvingLang`).
- [x] 2.3 No app-local dedup/merge/survivorship code added. `FederationMerger`
  confirmed unchanged (stays provenance-only).

## 3. Validate + import

- [x] 3.1 Re-validated `softwarecatalogus_register.json` (well-formed JSON;
  single canonical file, no register.d fragment merge → no dup-key risk). Marker
  placement verified against OR's readers: `DuplicateDetectionService` and
  `QualityStatisticsService` both read the annotations off
  `Schema::getConfiguration()`, and OR folds top-level `x-openregister-*` into
  `configuration` on hydrate — markers placed directly in `configuration`
  (matching the app's existing `x-openregister-lifecycle` convention).
- [~] 3.2 Live-verify (serialized `@type`, OR-materialised `qualityScore`)
  DEFERRED: requires re-importing the schema into a live OR instance; the shared
  dev container serves the main checkout, not this worktree, and re-importing to
  the shared instance is against the no-deploy-to-shared-instance rule. Static
  contract verified instead (see 3.1).
- [~] 3.3 Federation two-peer duplicate-candidate demo DEFERRED (same live-OR
  reason as 3.2). Dedup rule statically verified against
  `DuplicateDetectionService::loadAnnotation`.

## 4. Tune + docs

- [x] 4.1 Similarity thresholds set (org 0.7 on exact contactsUid; module 0.7 on
  weighted naam/aanbieder/website). Numeric cutoffs are the tunable part; keys
  are fixed by the spec. Real-federated-data tuning is a follow-up.
- [~] 4.2 `docs/features/` screenshot of the OR duplicate-candidate + golden
  record surface DEFERRED (needs live OR import, per 3.2).
- [x] 4.3 No new user-facing labels introduced (schema-metadata only) — no i18n
  strings needed.
