# Retrofit — fe-settings-ui

Describes observed behavior of the frontend settings UI as REQ(s) under the `fe-settings-ui` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every non-trivial frontend method gets a spec).

## Affected code units

- src/views/settings/Settings.vue
- src/views/settings/SoftwareCatalogSettings.vue
- src/views/settings/sections/OpenRegisterIntegration.vue
- src/views/settings/sections/UserGroupsConfiguration.vue
- src/views/settings/sections/EmailConfiguration.vue
- src/views/settings/sections/CronjobConfiguration.vue
- src/views/settings/sections/OrganizationSynchronization.vue
- src/views/settings/sections/ArchiMateImportExport.vue
- src/views/settings/sections/StatisticsOverview.vue
- src/views/settings/sections/VersionInformation.vue
- src/navigation/Configuration.vue

## Approach

- Describe the observed behavior of each settings section (load config, edit, save, test, status feedback).
- Group methods implementing the same observable behavior under one REQ per section.
- Annotate each method with `@spec` pointing at the matching task.

## Impact

- Documentation only. No runtime behavior change.
