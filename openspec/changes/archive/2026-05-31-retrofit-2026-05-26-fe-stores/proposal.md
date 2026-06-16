# Retrofit — fe-stores

Describes observed behavior of the frontend Pinia stores, the object-operations store plugin, the theme service and the heartbeat utility as REQ(s) under the `fe-stores` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every non-trivial frontend method gets a spec).

## Affected code units

- src/store/modules/settings.js
- src/store/modules/organisatie.js
- src/store/modules/navigation.js
- src/store/modules/catalog.js
- src/store/plugins/softwarecatalogPlugin.js
- src/services/getTheme.js
- src/utils/heartbeat.js

## Approach

- Describe the observed state, actions and side effects of each store module, the shared object-operations plugin, the theme helper and the heartbeat client.
- Group actions implementing the same observable behavior under one REQ per module.
- Annotate each method with `@spec` pointing at the matching task. Pure framework plumbing (Pinia plugin installer, generic `$patch` passthrough, class constructor) is marked `@spec exclude`.

## Impact

- Documentation only. No runtime behavior change.
