# Tasks — softwarecatalog notifications

- [x] Add `x-openregister-notifications` (rule `vulnerability-reported`, created, urgent) to `kwetsbaarheid` in lib/Settings/softwarecatalogus_register.json
- [x] Add `x-openregister-notifications` (rule `contract-expiry`, scheduled, enabled:false) to `contract` in lib/Settings/softwarecatalogus_register.json
- [x] Add `x-openregister-notifications` (rule `module-version-published`, created) to `moduleVersie` in lib/Settings/softwarecatalogus_register.json
- [x] Add `x-openregister-notifications` (rule `review-submitted`, created) to `beoordeeling` in lib/Settings/softwarecatalogus_register.json
- [x] Add nl + en `subject` strings to every rule (already specified in proposal.md)
- [x] Validate the register JSON still parses (e.g. `python3 -c "import json;json.load(open('lib/Settings/softwarecatalogus_register.json'))"`)
- [x] Confirm the `softwarecatalog-admins` group exists or remap `groups` recipients to a real NC group before enabling — remapped to `software-catalog-admins` (the group used throughout the file's authorization sections)
- [ ] Confirm engine support for a `scheduled` date-window filter on `eindDatum` before enabling `contract-expiry` — deferred; contract-expiry ships disabled; see Caveats in proposal.md
- [ ] Decide whether supplier delivery requires a relation-traversal recipient resolver or a structured supplier-uid field (file follow-up issue if so) — deferred; see Caveats in proposal.md

## Acceptance criteria

- The register JSON parses and every touched schema keeps its existing keys intact.
- Each rule uses only trigger types that work today (`created`, `scheduled`) — no dependency on the unshipped `updated`-field-change condition.
- Every rule's recipient references either an existing schema property, the record's manage-ACL, or a named group (no `field:` recipient pointing at a non-existent or nested-object property).
- Every rule has both `nl` and `en` subject strings.
- `vulnerability-reported`, `module-version-published`, `review-submitted` ship enabled; `contract-expiry` ships disabled by default.
