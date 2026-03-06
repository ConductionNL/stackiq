# Frontend Review: tilburg-woo-ui Changes

**Reviewed on:** 2026-03-01
**Diff scope:** 30 files changed, +423 / -226 lines

---

## 1. con-standards-table.js (#379, #381) -- Compliance display changes

**Changes:**
- "COMPLIANT" label changed to "ONDERSTEUND (MET BEWIJS)"
- "ONDERSTEUND" kept as-is
- "NIET ONDERSTEUND" used for all non-compliant (previously non-verplicht used gray instead of red)
- Color for non-ondersteund changed: previously verplicht=red, aanbevolen=gray; now all non-ondersteund = red (#dc3545)
- New `status` field propagated from `versie.status` into standard entries
- New `isInactiveStatus()` helper filters out "einde ondersteuning" / "teruggetrokken" standards
- Inactive standards now shown in a separate "Niet-actieve standaardversies" section
- All groups (verplicht, aanbevolen, toegevoegd) now sorted alphabetically by name

**Validation findings:**
- ACCEPTABLE: The compliance status labels ("ONDERSTEUND", "ONDERSTEUND (MET BEWIJS)", "NIET ONDERSTEUND") are hardcoded inline in JSX. There is NO constants file for compliance status labels -- `labels.constants.js` only contains navigation/UI labels, not domain labels. Given these are domain-specific status labels tied to the standards table rendering logic, inline hardcoding is acceptable.
- ACCEPTABLE: The inactive status check (`einde ondersteuning`, `teruggetrokken`) is correctly hardcoded -- these are backend enum values from the standaardversie schema `status` field.
- ACCEPTABLE: Color values (#28a745 green, #A86200 amber, #dc3545 red) are hardcoded inline styles. This is consistent with the existing pattern in this file -- the entire standards table uses inline styles throughout.
- WARNING: The simplification from 3-color to 2-color (removing gray for aanbevolen non-compliant, making everything red) changes the UX meaning: previously, aanbevolen non-compliance was visually softer (gray). Now it's the same red as verplicht non-compliance. This is intentional per the ticket but worth noting.
- NO ISSUE: The alphabetical sorting and inactive-standards separation are clean implementations.

**Verdict: APPROVED** -- No existing patterns were bypassed.

---

## 2. con-beheer-page-config-factory.js (#373) -- Schema-driven headers

**Changes:**
- `defaultHeaders` for module/applicaties changed from `['naam', 'referentieComponenten', 'standaarden', 'categorie', 'links']` to `[]` (empty)
- Removed custom `standaarden` and `diensten` columns from customHeaders
- Added new `compliancy` column in customHeaders
- Removed `diensten` from `extend` array; kept `['moduleVersies', 'contactpersoon']`

**Validation findings:**
- CORRECT PATTERN: Setting `defaultHeaders: []` is the correct approach to let the schema's `table.default` flags drive column visibility. The `con-generic-beheer-page.js` (lines 750-766) implements a 3-tier priority:
  1. Schema `table.default` flags (highest priority)
  2. Config `defaultHeaders` (fallback)
  3. Show all headers (final fallback)
  By setting `defaultHeaders: []`, the factory defers entirely to the schema definition, which is the intended architecture.
- ACCEPTABLE: Removing `standaarden` and `diensten` custom columns and adding `compliancy` is a domain change, not an architecture issue.

**Verdict: APPROVED** -- Correctly uses schema-driven pattern.

---

## 3. ac-forms-koppeling.js (3 files) (#437) -- n.v.t. enum fix

**Changes:**
- `{ value: 'n.v.t', label: 'N.v.t' }` changed to `{ value: 'n.v.t.', label: 'N.v.t.' }` in 3 files:
  - `gebruik-koppeling/ac-forms-koppeling.js`
  - `ac-forms-koppeling/ac-forms-koppeling.js`
  - `ac-forms-product/components/con-form-koppelingen-stage.js`

**Validation findings:**
- DATA FIX: This fixes a mismatch between the frontend enum value (`n.v.t`) and the backend schema enum value (`n.v.t.` with trailing period). The correction ensures data saved from forms matches the schema.
- WARNING: These enum values (`n.v.t.`, `bestandsoverdracht`, `digikoppeling`, etc.) are hardcoded in 3 separate files. They SHOULD ideally come from the schema definition -- the koppeling schema already defines these as enum values in the `type` property. However, the form components use custom wizard UIs with custom dropdown rendering, not the generic `ConSchemaEnhancedField` which would auto-populate enums from schema. Refactoring to read from schema would require significant wizard restructuring.
- ACCEPTED AS-IS: The hardcoding is a known pattern in the wizard forms. The fix correctly aligns values across all 3 files.

**Verdict: APPROVED with NOTE** -- Enum values should ideally come from schema, but the wizard architecture prevents this cleanly. The fix is correct.

---

## 4. ac-register.js (#431) -- Tussenvoegsel field

**Changes:**
- Added `middleName` (tussenvoegsel) field to the registration form contact information
- Added `debouncedSetMiddleName` handler
- Layout changed from 2-column grid (voornaam | achternaam) to 3-column within a `span 2` grid cell (voornaam 2fr | tussenvoegsel 1fr | achternaam 2fr)
- Success message now uses `[firstName, middleName, lastName].filter(Boolean).join(' ')` pattern
- Field IDs fixed from duplicate `id='name-field'` to unique `id='firstname-field'`, `id='middlename-field'`, `id='lastname-field'`

**Validation findings:**
- CORRECT PATTERN: The `middleName` field follows the exact same pattern as existing `firstName` and `lastName` fields:
  - Uses `useDebouncedInput` for onChange
  - Calls `setOrganizationData('contactPersons.middleName', value)`
  - The `[firstName, middleName, lastName].filter(Boolean).join(' ')` pattern is already used throughout the codebase (user.store.js, ac-header.js, ac-my-account.js, etc.)
- The initial state already had `middleName: ''` in the contactPersons default (line 57).
- The API submission already sent `tussenvoegsel: organization.contactPersons[0].middleName` (line 165).
- The field is correctly NOT marked as required (tussenvoegsel is optional in Dutch naming).
- GOOD FIX: The duplicate `id='name-field'` to unique IDs is an accessibility improvement.

**Verdict: APPROVED** -- Follows existing patterns exactly.

---

## 5. con-schema-enhanced-field.js (#354) -- Parameter mismatch fix

**Changes:**
- `handleSearch: async (fieldPath, query)` changed to `handleSearch: async (fieldPath, refSchemaSlug, searchQuery)`
- `refOptionsResult.fetchOptions(fieldPath, query)` changed to `refOptionsResult.fetchOptions(fieldPath, refSchemaSlug, searchQuery)`
- Debug emoji removed from console.info

**Validation findings:**
- CORRECT FIX: The `fetchOptionsForField` function in `use-ref-options.js` (line 203) has the 3-parameter signature: `async (fieldPath, refSchemaSlug, searchQuery = '')`. The callers (lines 423, 466) also use 3 parameters. The old 2-parameter wrapper was missing `refSchemaSlug`, which would have caused `fetchOptionsForField` to receive `undefined` for `refSchemaSlug` and fail silently (early return at line 204: `if (!currentRegister || !refSchemaSlug || !object) return`).
- This was a genuine bug: searches via `$ref` fields in the enhanced field component would silently fail because `refSchemaSlug` was never passed through.

**Verdict: APPROVED** -- Genuine bug fix, correctly aligns signatures.

---

## 6. publications.store.js (#436, #280) -- Loading state + relevance sort

**Changes:**
- `loading.status` default changed from `false` to `true`
- `updateQuery()` now defaults to `_order: { '_relevance': 'desc' }` when `_search` is present but no explicit `_order` was provided

**Validation findings:**
- LOADING STATE: Setting `loading.status = true` on init means the UI will show a loading indicator immediately rather than briefly flashing "0 Resultaten" before data loads. This is correct for a store that fetches on mount. The `BeheerTable` also received a similar fix (`useState(shouldFetchData || shouldFetchDataProperties)` instead of `useState(false)`).
- SSR CONCERN: This app uses Preact, not SSR. The `true` initial state is fine.
- RELEVANCE SORT: The relevance default when searching is good UX -- alphabetical sort makes no sense when full-text searching. The `hasExplicitOrder` check ensures URL-based sort parameters are still respected.
- The `SEARCH_RESULTS_LOADING` label is correctly defined in `labels.constants.js` (line 40) and used in both `ac-search.js` and the publications store.

**Verdict: APPROVED** -- Both changes are correct UX improvements.

---

## 7. ac-forms-koppeling.js (gebruik) (#314) -- Org type detection

**Changes:**
- Added `fullActiveOrganisation` dependency (already available via `useEffect` fetch in the component)
- Organisation type detection: `const orgType = fullActiveOrganisation?.type`
- For `Leverancier` or `Community` orgs: sets `allowedModuleIdsFromGebruik = 'ORGANISATION_OWNED'` marker
- This marker triggers a different fetch path: modules filtered by `organisation=activeOrgId` (own modules only)
- For `Gemeente/Samenwerking`: unchanged behavior (fetch gebruik records, derive module IDs)
- Search handler also adds `organisation=` filter for Leverancier/Community

**Validation findings:**
- CORRECT APPROACH: The `fullActiveOrganisation` is fetched in the same component from the organisatie API, so its `type` field is available. The user store (`store.user.activeOrganization`) does NOT expose `type` -- it only has `uuid`, `id`, `name`. So reading `type` from the fetched full organisation object is the correct approach.
- MAGIC STRING: Using `'ORGANISATION_OWNED'` as a sentinel value is a code smell but functionally correct. The alternative would be a separate state variable for the fetch strategy.
- API PATTERN: Filtering modules by `organisation=activeOrgId` is the correct way to get modules owned by a Leverancier -- it uses the RBAC `@self.organisation` field.

**Verdict: APPROVED** -- Correctly implements org-type-aware module fetching.

---

## 8. con-forms-dienst.js + components (#274) -- Updated wizard texts

**Changes:**
- Step title "Dienstverlening op uw applicaties" changed to "Registreer uw dienst"
- Section heading "Dienst informatie" changed to "Dienst gegevens" / "Registreer uw dienst"
- Wizard heading changed from dynamic `Uw {editModeTitle}` to static `Dienst updaten` / `Dienst registreren`
- Description paragraph completely rewritten with clearer explanation
- New sub-section "Informatie over uw dienst" with instructional text
- Added placeholder for `beschrijvingKort` field

**Validation findings:**
- HARDCODED TEXT: All wizard texts are hardcoded in JSX. There is NO i18n/translation system in this codebase -- the `t()` calls found via grep are template literal syntax, not translation functions. The app is Dutch-only by design (government municipality software).
- NO CMS: There is no CMS or content management for wizard texts. All text is in component source.
- CONSISTENT: This matches the pattern in all other wizard forms (applicatie, gebruik, product wizards).

**Verdict: APPROVED** -- Hardcoded Dutch text is the established pattern; no translation system exists.

---

## 9. ac-dashboard.js (#410) -- Updated welcome text

**Changes:**
- Welcome text for leverancier dashboard rewritten:
  - Old: detailed bullets about what you can do (applicaties, koppelingen, GEMMA, beschikbaarheid)
  - New: simple list of registerable items (Applicaties, Diensten, Koppelingen, Standaarden)
  - Old: "Gebruik de acties bovenaan deze pagina"
  - New: "Een nieuw item publiceert u via de opties in het linkermenu"
  - Old: "Gemeenten gebruiken deze informatie bij het vergelijken, selecteren en inkopen"
  - New: "Gemeenten gebruiken deze informatie om een beter beeld te krijgen van de markt"

**Validation findings:**
- SAME PATTERN AS #8: Dashboard text is hardcoded in JSX, consistent with the entire codebase.
- NO CONFIG/CMS exists for dashboard content.
- The text change reflects updated product positioning and is a content update, not a code architecture issue.

**Verdict: APPROVED** -- Consistent with established pattern.

---

## 10. Label changes across 15 files (#376) -- "Applicatie Versie" to "Applicatieversie"

**Changes across these locations:**
- `con-publication-type-badge.js`: SCHEMA_NAMES map
- `con-schema-resolver.js`: DISPLAY_NAME_OVERRIDES map
- `breadcrumbs.constants.js`: breadcrumb label
- `con-use-facet-name-resolution.js`: facet bucket label
- `con-normalize-schema-name.js`: normalize function
- `con-schema-tab-display-helpers.js`: singular/plural translations map
- `con-beheer-page-config-factory.js`: title + label
- `con-form-modal-config-factory.js`: title
- `con-module-version-detail-page-content.js`: placeholder, tooltip, error text
- `con-gebruik-step-informatie.js`: label, placeholder
- `ac-publication-moduleversie.js`: heading fallback
- `ac-publication-softwarecatalogus.js`: info text (removed "compliancy")

**Validation findings:**
- PATTERN ISSUE: The label "Applicatieversie" is defined in **6 separate mapping/constants locations** plus hardcoded in **9 component files**. These should ideally come from a single source of truth. The codebase has multiple independent "schema name to display name" mappings:
  1. `SCHEMA_NAMES` in con-publication-type-badge.js
  2. `DISPLAY_NAME_OVERRIDES` in con-schema-resolver.js
  3. `translations` in con-schema-tab-display-helpers.js
  4. `normalizeSchemaName()` in con-normalize-schema-name.js
  5. `useFacetNameResolution` hook
  6. Various factory configs
- RECOMMENDATION: These should be consolidated into a single `SCHEMA_DISPLAY_NAMES` constant. The duplication means every label change requires touching 6+ files.
- HOWEVER: All 15 files were correctly updated in this diff. No instances of the old "Applicatie Versie" remain.

**Verdict: APPROVED with TECH DEBT NOTE** -- All instances correctly updated, but the 6-way duplication of schema display names should be consolidated.

---

## Additional Changes Not in Original Scope

### con-dienst-details-page-content.js + ac-publication-dienst.js -- Organisatie filtering
- Dienst detail pages now filter out `organisatie` items from related tabs (uses/used)
- Uses `schemaCache.get()` to resolve schema slug from numeric ID
- Wraps in `useMemo` with correct dependencies

**Verdict: APPROVED** -- Clean implementation, removes redundant "aanbieder" relation from dienst pages.

### con-related-tabs.js -- Schema resolution + tab ordering
- Added `useResolveSchemaIds(mergedItems)` hook to resolve numeric schema IDs to slugs
- Added `moduleversie`, `organisatie`, `koppeling` to tab ordering config
- Waits for schema resolution before showing tabs (`schemaResolutionLoading`)

**Verdict: APPROVED** -- Fixes tab grouping for schemas returned as numeric IDs.

### con-form-modal-config-factory.js -- Contactpersoon role filtering
- Leverancier orgs: `rollen` field hidden entirely (auto-assigned as Aanbod-beheerder)
- Gemeente/Samenwerking: `rollen` shows selectable roles, excludes `Organisatie-beheerder`
- Uses `context?.user?.activeOrganization?.type` for org type detection

**Verdict: APPROVED** -- Correctly scopes role options by org type.

### con-beheer-table.js -- Initial loading state
- `useState(false)` changed to `useState(shouldFetchData || shouldFetchDataProperties)`
- Prevents flash of "Geen data gevonden" before first fetch completes

**Verdict: APPROVED** -- Eliminates visual flicker on page load.

---

## Summary

| Area | Verdict | Notes |
|------|---------|-------|
| Standards table (#379, #381) | APPROVED | Inline labels acceptable, no constants file for domain labels |
| Config factory (#373) | APPROVED | Correctly uses schema-driven table.default pattern |
| Koppeling enum (#437) | APPROVED | Fix aligns frontend with schema; hardcoding is wizard pattern |
| Register tussenvoegsel (#431) | APPROVED | Follows existing field addition pattern exactly |
| Enhanced field params (#354) | APPROVED | Genuine bug fix, 3-param signature matches callers |
| Publications store (#436, #280) | APPROVED | Loading state + relevance sort are correct UX fixes |
| Org type detection (#314) | APPROVED | Reads type from fetched org object (correct source) |
| Wizard texts (#274) | APPROVED | Hardcoded Dutch is the established pattern |
| Dashboard text (#410) | APPROVED | Content update, consistent with pattern |
| Label changes (#376) | APPROVED + TECH DEBT | All 15 files updated; consolidation recommended |

**Overall: All changes are APPROVED.** No custom solutions were implemented where existing patterns/components already handled things. The main tech debt identified is the 6-way duplication of schema display name mappings, which should be consolidated into a single constants file.

---

Reviewed by: Claude Opus 4.6
