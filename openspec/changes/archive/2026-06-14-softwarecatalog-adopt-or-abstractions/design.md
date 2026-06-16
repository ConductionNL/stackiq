# Design — softwarecatalog-adopt-or-abstractions

## Reuse analysis

| Capability | Reuse from | Why |
|------------|-----------|-----|
| App-manifest schema + loader | `@conduction/nextcloud-vue` (`src/schemas/app-manifest.schema.json`, `useAppManifest`) | Single source of truth per `hydra/openspec/changes/adopt-app-manifest/`. |
| Index / detail page-type renderers | nc-vue `CnIndexPage`, `CnDetailPage` (already used) | Existing views already wrap these. Manifest declares which schema each consumes. |
| Register / schema ID resolution | OR `RegisterResolverService` (`openregister/openspec/changes/register-resolver-service/`) | Eliminates 5 duplicated `getValueString(...register/schema...)` call shapes. |
| Tenant context | `useTenantContext()` from nc-vue (`nextcloud-vue/openspec/changes/multi-tenancy-context/`) | Cache invalidation + write header stamping. |
| i18n source-of-truth metadata | OR `sourceLanguage` (`openregister/openspec/changes/i18n-source-of-truth/`) | Read-only consumption — display badge. |
| API language negotiation | OR `?_lang=` + `X-Translation-Target-Language` (`openregister/openspec/changes/i18n-api-language-negotiation/`) | Wire into single `orClient.js` composable. |
| Pinia stores | SoftwareCatalog's existing entity-typed Pinia stores | Keep. Migrate fetch URL building into `orClient.js`. |
| Sidebars / dialogs / modals | Existing `src/sidebars/`, `src/dialogs/`, `src/modals/` | Register via manifest's `slots` and `customComponents`. No reorganisation needed. |

### What we deliberately do NOT reuse

- **OR's lifecycle annotations** — applications and components do
  not have a status state machine that benefits from
  `x-openregister-lifecycle`. Procurement / publication workflow
  is out of scope here.
- **OR's notification engine** — no notification triggers in
  SoftwareCatalog today. If product reasons emerge, follow-up
  change adopts `x-openregister-notifications`.
- **VNG `Softwarecatalogus/` client repo** — read-only, separate.

### What's deferred

- **Concept Organisations** consumption — the
  `conceptOrganisatiesWidget.js` integrates with an external feed.
  Phase 1 declares the page in the manifest with
  `type: "custom"`; future change may model it as `type: "index"`
  if the feed exposes a register-shaped contract.

## Public API / migration shape

### `src/manifest.json` (new file)

```json
{
  "$schema": "https://unpkg.com/@conduction/nextcloud-vue@latest/dist/schemas/app-manifest.schema.json",
  "version": "0.1.0",
  "dependencies": ["openregister"],
  "menu": [
    { "id": "apps", "label": "softwarecatalog.menu.apps", "icon": "icon-apps", "route": "/apps", "section": "main", "order": 10 },
    { "id": "components", "label": "softwarecatalog.menu.components", "icon": "icon-component", "route": "/components", "section": "main", "order": 20 },
    { "id": "organisations", "label": "softwarecatalog.menu.organisations", "icon": "icon-organisation", "route": "/organisations", "section": "main", "order": 30 },
    { "id": "catalogs", "label": "softwarecatalog.menu.catalogs", "icon": "icon-catalog", "route": "/catalogs", "section": "main", "order": 40 },
    { "id": "concept-organisations", "label": "softwarecatalog.menu.conceptOrganisations", "icon": "icon-concept", "route": "/concept-organisations", "section": "main", "order": 50 },
    { "id": "settings", "label": "softwarecatalog.menu.settings", "icon": "icon-settings", "route": "/settings", "section": "settings", "permission": "admin" }
  ],
  "pages": [
    {
      "id": "apps-index",
      "route": "/apps",
      "type": "index",
      "title": "softwarecatalog.pages.apps",
      "config": {
        "register": "@resolve:apps_register",
        "schema": "@resolve:apps_schema",
        "columns": ["name", "organisation", "status", "version"]
      }
    },
    {
      "id": "apps-detail",
      "route": "/apps/:id",
      "type": "detail",
      "title": "softwarecatalog.pages.app",
      "config": {
        "register": "@resolve:apps_register",
        "schema": "@resolve:apps_schema"
      },
      "slots": { "sidebar": "AppSidebar" }
    }
    // ... components, organisations, catalogs (similar shape)
    , {
      "id": "concept-organisations-index",
      "route": "/concept-organisations",
      "type": "custom",
      "title": "softwarecatalog.pages.conceptOrganisations",
      "component": "ConceptOrganisationsPage"
    }
    , {
      "id": "settings",
      "route": "/settings",
      "type": "custom",
      "title": "softwarecatalog.pages.settings",
      "component": "SettingsPage"
    }
  ]
}
```

Notes:
- `@resolve:{key}` sentinel: same convention as LarpingApp adoption
  change. The renderer pre-processor runs `RegisterResolverService`
  (or its frontend equivalent) at render time.
- `slots: { sidebar: "AppSidebar" }` registers
  `src/sidebars/AppSidebar.vue` for the apps detail page.

### `src/composables/orClient.js` (new file)

Identical contract to LarpingApp's `orClient.js`:

```js
export function useOrClient () {
  const baseUrl = '/index.php/apps/openregister/api'

  async function fetchObject ({ register, schema, uuid }) {
    const lang = OC.getLocale().split('_')[0]
    const url = `${baseUrl}/objects/${register}/${schema}/${uuid}?_lang=${lang}`
    return axios.get(url, { headers: buildHeaders() })
  }

  async function patchObject ({ register, schema, uuid, body, targetLang }) {
    const url = `${baseUrl}/objects/${register}/${schema}/${uuid}`
    const headers = buildHeaders()
    if (targetLang) headers['X-Translation-Target-Language'] = targetLang
    return axios.patch(url, body, { headers })
  }

  return { fetchObject, patchObject }
}
```

### Service migrations (Phase 2)

All five files follow the same pattern: inject
`RegisterResolverService` and replace the resolver call.

`lib/Service/ModuleComplianceService.php`:

```php
// before
$register = $this->config->getValueString('softwarecatalog', 'modules_register', '');
$schema = $this->config->getValueString('softwarecatalog', 'modules_schema', '');

// after
$pair = $this->resolver->resolveForObjectType('modules');
[$register, $schema] = [$pair->registerId, $pair->schemaId];
```

Same shape for the other four classes with their respective
object types: `gebruik`, `organisations`, `views`,
`user-profile-organisation`.

### Migration risk surface

| Risk | Mitigation |
|------|-----------|
| `RegisterResolverService` not yet deployed | DI null-check fallback to legacy `getValueString` (Phase 2) plus deprecation log. |
| 5-file resolver migration introduces typo / incorrect object-type string | Unit tests assert each service's `resolveForObjectType` argument matches the existing config-key naming convention. |
| Manifest validation fails CI on first introduction | Tier 2 keeps router hand-wired; failed validation does not break runtime. |
| Tenant switch on detail page may interrupt user mid-edit | Detail navigates back; pending edits are lost (existing UX). Document in spec; future change MAY add an unsaved-changes guard. |
| External feeds (GEMMA, GitHub) used by sync services have their own auth context — confused with NC tenant context | Sync services run server-side; `useTenantContext()` is frontend-only. No conflict. |
| `concept-organisations` page differs from other entity pages | `type: "custom"` covers the difference; widget keeps its existing implementation. |

## Open design questions

1. **Q1 — `@resolve:{key}` sentinel.** Same as LarpingApp Q1.
   Local pre-processor for now; upstream when ≥2 apps need it.

2. **Q2 — Multi-tenancy gating.** Same as LarpingApp Q2. Ship
   Phases 1-3 first; Phase 4 trails the nc-vue release.

3. **Q3 — Sidebar slot conventions.** Apps detail page registers
   `slot.sidebar = AppSidebar`. Should organisations / catalogs
   also expose sidebars by default, or keep them off until the user
   opts in? Recommend default-on for the entities that have sidebars
   today (apps, organisations); off for the others.

4. **Q4 — Concept-organisations page.** The widget pulls from an
   external feed (GEMMA / GitHub). It's modelled as `type: "custom"`
   in this change. Should we declare it as `type: "index"` with a
   pseudo-source URN (e.g.
   `softwarecatalog:concept-organisations`) so the page-type
   contract is uniform? Recommend: stay `custom` until nc-vue grows
   a "data source URN" concept.

5. **Q5 — User-profile event listener resolver injection.**
   `UserProfileUpdatedEventListener` reacts to a Nextcloud user
   profile event and writes a SoftwareCatalog organisation
   membership row. Should the listener inject the resolver in
   its constructor (DI-via-app-container) or fetch it lazily from
   the container? Recommend constructor injection for testability.

6. **Q6 — Sync service interval keys.** Each sync service has
   non-register `getValueString` keys for cron interval, retry
   policy, feature flags. Phase 2.4 keeps these on `IAppConfig`.
   Should we collect them under a `softwarecatalog/openspec/specs/
   sync-tunables/spec.md` so a future audit doesn't re-flag them as
   "hardcoded keys"? Recommend yes — small extra capability spec
   that documents the intentional separation.

7. **Q7 — Translation target on sync writes.** When sync services
   write to OR (e.g. updating an application's description from
   GitHub), should they stamp `X-Translation-Target-Language`?
   GitHub README content is typically English. Recommend: yes,
   stamp `en` so OR's i18n source-of-truth tracks where the
   content came from.
