# Tasks — vendor-product-master-data

## 1. Semantic types (ADR-048)

- [ ] 1.1 `organisatie` schema: add `configuration.jsonld.type =
  https://schema.org/Organization` and `configuration.implements =
  ["https://schema.org/Organization", "https://openregister.app/ns#Vendor"]`.
- [ ] 1.2 `module` and `suite` schemas: add `configuration.jsonld.type =
  https://schema.org/SoftwareApplication`.
- [ ] 1.3 `module.aanbieder`, `dienst.aanbieder`, `koppeling.aanbieder`: add
  `referenceSemanticType = https://openregister.app/ns#Vendor` alongside the
  existing `$ref`/`objectConfiguration.handling: related-object` (do NOT remove
  the local `$ref`).

## 2. MDM markers (ADR-045)

- [ ] 2.1 `organisatie`: add `x-openregister-dedup` (exact `contactsUid` match;
  fallback similarity on resolved contact name + website) and
  `x-openregister-quality` (completeness of `contactsUid`, `type`, `status`).
- [ ] 2.2 `module`: add `x-openregister-dedup` (similarity of `naam` +
  `aanbieder` + `website`) and `x-openregister-quality` (completeness of `naam`,
  `aanbieder`, `licentie`, description).
- [ ] 2.3 Do NOT add any app-local dedup/merge/survivorship code. Confirm
  `FederationMerger` is unchanged (stays provenance-only).

## 3. Validate + import

- [ ] 3.1 Re-validate `softwarecatalogus_register.json` (well-formed JSON,
  schema still imports via the OR Repair step — ADR-037).
- [ ] 3.2 On a dev instance, confirm serialized `organisatie`/`module`/`suite`
  objects carry the expected `@type`; confirm OR materialises `qualityScore` on
  new saves.
- [ ] 3.3 Seed two peer mirrors of one real vendor via federation intake;
  confirm OR's duplicate-candidate surface lists them as a merge candidate.

## 4. Tune + docs

- [ ] 4.1 Tune the dedup similarity thresholds against real federated data
  (keys are fixed by the spec; cutoffs are tuned here).
- [ ] 4.2 Document the vendor/product master-data behaviour in `docs/features/`
  with a screenshot of the OR duplicate-candidate + golden-record surface for
  softwarecatalog vendors (Playwright MCP).
- [ ] 4.3 NL + EN strings for any new labels introduced (English i18n keys).
