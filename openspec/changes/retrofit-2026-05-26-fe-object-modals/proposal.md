# Retrofit — fe-object-modals

Describes observed behavior of the frontend object-lifecycle modals and dialogs as REQ(s) under the `fe-object-modals` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every non-trivial frontend method gets a spec).

## Affected code units

- src/modals/object/ViewObject.vue
- src/modals/object/ObjectModal.vue
- src/modals/object/MergeObject.vue
- src/modals/object/MigrationObject.vue
- src/modals/object/UploadObject.vue
- src/modals/object/DownloadObject.vue
- src/modals/object/DeleteObject.vue
- src/modals/object/LockObject.vue
- src/modals/object/MassDeleteObject.vue
- src/modals/object/MassDepublishObjects.vue
- src/modals/object/MassLockObjects.vue
- src/modals/object/MassPublishObjects.vue
- src/modals/object/MassUnlockObjects.vue
- src/modals/object/MassValidateObjects.vue
- src/components/SelectedObjectsList.vue

## Approach

- Describe the observed user-facing behavior of each modal/dialog (open/close, validation, store dispatch, result feedback).
- Group methods implementing the same observable behavior under one REQ.
- Annotate each method with `@spec` pointing at the matching task.

## Impact

- Documentation only. No runtime behavior change.
