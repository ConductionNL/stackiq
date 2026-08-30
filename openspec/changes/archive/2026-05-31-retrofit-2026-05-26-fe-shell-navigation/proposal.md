# Retrofit — fe-shell-navigation

Describes observed behavior of the frontend app shell, navigation surfaces and generic presentational components as REQ(s) under the `fe-shell-navigation` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every non-trivial frontend method gets a spec).

## Affected code units

- src/views/Dashboard.vue
- src/sidebars/directory/DirectorySideBar.vue
- src/sidebars/search/SearchSideBar.vue
- src/components/PaginationComponent.vue
- src/components/CollapsibleSection.vue
- src/components/AlwaysVisibleSection.vue
- src/components/PublishedIcon.vue

## Approach

- Describe the observed behavior of the dashboard, the directory/search sidebars, pagination and the reusable section/icon components.
- Group methods implementing the same observable behavior under one REQ.
- Annotate each method with `@spec` pointing at the matching task.

## Impact

- Documentation only. No runtime behavior change.
