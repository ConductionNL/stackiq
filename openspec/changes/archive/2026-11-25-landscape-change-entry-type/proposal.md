---
kind: code
depends_on: []
---

# Change the type of an entry without recreating it

## Summary

A supplier or a functional administrator turns an application into a service, a service into an application, or an application into system software, without deleting the entry and typing it again. The entry keeps its identity: its history, files, relations and links stay attached, because OpenRegister moves the object instead of copying it.

## Why

Row from the stackiq matrix:

- `stackiq:land-change-entry-type`, "Change the type of an existing entry without recreating it." Rated no, no competitor rates yes. Roadmap demand: https://tip.topdesk.com/c/89-changing-the-type-of-an-asset (TOPdesk). It sits in the product's core area (landscape), which is why it is built.

## What stackiq has today

- An entry lives in one schema: `module` (`lib/Settings/softwarecatalogus_register.json:6779` schema), `catalogService` (`:1326`) or `suite` (`:1137`). Nothing moves an object to another schema; the only route is delete and recreate, which loses its uuid and everything keyed on it.
- `module.type` (Application or System software) exists but is `visible: false` with default Application, so nobody can change it from a page.
- OpenRegister ships a move that keeps identity: `POST /api/objects/{register}/{schema}/{id}/move` (openregister `appinfo/routes.php:1245`, `lib/Controller/ObjectsController.php:5176`, `lib/Service/Object/MoveObject.php`). It authorises both sides and answers 422 when the object does not fit the target schema.

## What this change builds

1. A "Change type" action on the application page, and on the service rows of the Services list, offering: Application to Service, Service to Application, and Application to System software and back.
2. A preview of what carries over: the fields both schemas share, and the fields that would be dropped, before the user confirms.
3. A stackiq service that prepares the object for the target schema and calls OpenRegister's move, so the uuid, history, files and relations survive.
4. `module.type` shown and editable on the application form.

## Out of scope

- Suites: a suite groups applications and has no counterpart to turn into.
- Moving entries to another organisation: `landscape-move-between-organisations`.
- Bulk type changes.

## Risks

- Relations that point at the old schema by `$ref` (for example `usage.module`) keep the uuid but now point at an object in another schema. The preview lists incoming references that would no longer resolve, and the action refuses when the entry has usages or connections the target type cannot hold.
