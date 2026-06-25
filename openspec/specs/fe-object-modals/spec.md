---
status: done
---

# fe-object-modals Specification

## Purpose
Provides the frontend object modals for working with OpenRegister objects: viewing detail, creating and editing, merging, migrating between register/schema, uploading and downloading files, deleting and locking, bulk operations over a selection, and managing the selected-objects list. Each modal validates input and dispatches the matching Pinia store action, reporting success or failure.

@e2e exclude Vue object-modal components (view/create/edit/merge/migrate/upload/download/delete/lock/mass-ops/selection) — every scenario drives a modal over live OpenRegister object data and dispatches a Pinia store action (save/merge/migrate/upload/mass-op/selection mutation). These are exercised by the Vue component + store unit tests (vitest) with mocked stores; they are interaction flows over seeded data, not navigable manifest-page renders, so they are not Playwright UI-smoke surfaces. The pages that host these modals are covered by the manifest index/detail render tests.

## Requirements
### Requirement: View object detail modal (REQ-FE-101)

The object detail modal SHALL render a read-only view of the active object, exposing its properties, related data, files, audit trail and available actions, and MUST allow the user to close the modal and trigger object actions.

`ViewObject.vue` loads the active object from the store, formats its properties for display, lazily fetches related/linked data and files, and dispatches lifecycle actions (publish, lock, delete, edit) back to the store.

#### Scenario: View an object
- WHEN the detail modal opens for an active object
- THEN it MUST display the object's properties and available actions

#### Scenario: Close the modal
- WHEN the user closes the modal
- THEN the active object MUST be cleared from navigation state

### Requirement: Create/edit object modal (REQ-FE-102)

The object modal SHALL allow creating a new object or editing an existing one, validating the form and dispatching a save to the store.

`ObjectModal.vue` builds the form from the schema, validates input, and on submit dispatches a create or update action and surfaces success/error feedback.

#### Scenario: Save a valid object
- WHEN the user submits a valid form
- THEN the modal MUST dispatch a save action and report the result

### Requirement: Merge objects (REQ-FE-103)

The merge modal SHALL let the user pick a target object and merge the active object into it, previewing the field-level resolution before dispatching the merge.

`MergeObject.vue` lists merge candidates, lets the user resolve conflicting fields, and dispatches the merge action.

#### Scenario: Merge two objects
- WHEN the user confirms a merge with a chosen target and field resolution
- THEN the modal MUST dispatch the merge and report the result

### Requirement: Migrate object between register/schema (REQ-FE-104)

The migration modal SHALL let the user move an object to a different register/schema, mapping source properties to target properties before dispatching the migration.

`MigrationObject.vue` loads target register/schema options, builds a property mapping, validates it, and dispatches the migration.

#### Scenario: Migrate an object
- WHEN the user confirms a property mapping to a target schema
- THEN the modal MUST dispatch the migration and report the result

### Requirement: Upload/download object files (REQ-FE-105)

The file modals SHALL let the user upload files to an object and download object files/exports.

`UploadObject.vue` validates and uploads selected files to the active object; `DownloadObject.vue` triggers a download/export of the object in the chosen format.

#### Scenario: Upload a file
- WHEN the user selects and confirms files to upload
- THEN the modal MUST dispatch the upload and report the result

#### Scenario: Download an object
- WHEN the user confirms a download/export format
- THEN the modal MUST trigger the corresponding download

### Requirement: Delete/lock single object (REQ-FE-106)

The delete and lock dialogs SHALL confirm the action with the user and dispatch the delete or lock/unlock action for the active object.

`DeleteObject.vue` confirms and dispatches deletion; `LockObject.vue` confirms and dispatches lock/unlock.

#### Scenario: Confirm a delete
- WHEN the user confirms deletion
- THEN the dialog MUST dispatch the delete action and report the result

### Requirement: Bulk object operations (REQ-FE-107)

The bulk-operation modals SHALL apply an operation (delete, depublish, publish, lock, unlock, validate) to all currently selected objects, reporting per-object progress and the aggregate result.

`MassDeleteObject.vue`, `MassDepublishObjects.vue`, `MassPublishObjects.vue`, `MassLockObjects.vue`, `MassUnlockObjects.vue` and `MassValidateObjects.vue` each confirm the operation, iterate the selection through the store's mass-operation action, and surface progress and outcome.

#### Scenario: Bulk publish selected objects
- WHEN the user confirms a bulk publish over the current selection
- THEN the modal MUST dispatch the operation for each selected object and report the aggregate result

### Requirement: Selected-objects list management (REQ-FE-108)

The selected-objects list SHALL display the current selection and allow removing individual entries or clearing the whole selection.

`SelectedObjectsList.vue` renders the selection from store state and dispatches selection mutations.

#### Scenario: Remove an object from the selection
- WHEN the user removes an item from the selected list
- THEN it MUST be removed from the selection state

