# Design: landscape-change-entry-type

Read at development `49e65cb4`, OpenRegister development `4fee776`.

## Context

Stackiq keeps applications in `module` and services in `catalogService` (`lib/Settings/softwarecatalogus_register.json:6779` and `:1326` schemas). Both share `name`, `shortDescription`, `longDescription`, `website`, `contactPerson`, `provider`, `logo`, `koppelingen`, `publicationDate` and `depublicationDate`. A module also holds licence, hosting, reference components, standards and versions; a service holds `modules` and a service `type`. OpenRegister's `MoveObject` moves an object between schemas without a copy: the uuid, audit trail, versions, files, notes and watchers stay keyed on the same uuid (`openregister lib/Service/Object/MoveObject.php:3-24`), and the endpoint answers 422 when the object does not fit (`ObjectsController.php:5219-5223`).

## D1. A type-change service

New `lib/Service/EntryTypeService.php`:

- `preview(string $uuid, string $targetType): array` returns `carried` (fields both schemas declare), `dropped` (fields only the source has, with their values), and `blockers`: incoming references the target cannot hold. For Application to Service the blockers are usages (`usage.module`), versions (`moduleVersion.module`) and connections (`connection.moduleA`, `moduleB`); for Service to Application, contracts (`catalogContract.service`).
- `change(string $uuid, string $targetType): array` refuses when `blockers` is not empty, clears the dropped fields with one update, then calls the move (`POST /api/objects/{register}/{schema}/{id}/move`, in process through OpenRegister's `MoveObject` service resolved from the container) and returns the outcome.
- Application to System software and back is not a move: it sets `module.type`.

Routes in `appinfo/routes.php`: `GET /api/entries/{uuid}/type-change?target=` (preview) and `POST /api/entries/{uuid}/type-change` (change), both `#[NoAdminRequired]`. The service reads the object under the caller's own permissions, and OpenRegister's move authorises both sides again, so a caller who cannot edit the entry or create in the target gets a 403 or 422.

Rejected: create in the target and delete the source. It mints a second uuid and orphans the audit trail, files and relations, which is the problem the row names.

## D2. The action and the preview

A dialog `ChangeEntryTypeDialog` in `src/dialogs/` (ADR-004: dialogs live in their own file), opened from a header action on `ModuleDetail` (`src/manifest.json:491`) and from a row action on the Services list. It shows the preview in three lists (carried, dropped, blockers), disables Confirm while there are blockers, and after the move opens the entry at its new page.

## D3. module.type on the form

`module.type` (`register.json` module schema) becomes `visible: true`, so the form and the data widget show Application or System software. No dialog is needed for that switch.

## Declarative versus imperative

The move itself is OpenRegister's. The preview and the guard are stackiq code because they depend on stackiq's schema pairs; there is no `x-openregister-*` construct for "which schemas may an object move between".

## Seed data

No schema added. `module.type` changes visibility only.

## Risks

- A future schema that references `module` would be missed by the blocker list. The list is built from the register's `$ref` properties at runtime, not hard-coded.
