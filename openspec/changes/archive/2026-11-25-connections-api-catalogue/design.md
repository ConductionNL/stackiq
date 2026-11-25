# Design: connections-api-catalogue

Read at development `49e65cb4`.

## Context

Stackiq's catalogue schemas live in `lib/Settings/softwarecatalogus_register.json`. Per ADR-037 a change adds its schema in a fragment under `lib/Settings/register.d/`, which `SettingsService::loadSettings()` deep-merges into the monolith at load (`lib/Service/SettingsService.php:1653-1680`). Lists append in that merge (`deepMergeConfig`, :7338), so a fragment can add a schema to the register's list.

## D1. A new schema in a fragment

File `lib/Settings/register.d/application-interfaces.json` with:

- `components.schemas.applicationInterface`, schema.org type `WebAPI`:

| property | type | notes |
|---|---|---|
| `name` | string, required | |
| `shortDescription`, `longDescription` | string, markdown for the long one | |
| `module` | `$ref` module, required | the application that offers the API, `inversedBy: interfaces` |
| `style` | enum `REST`, `SOAP`, `GraphQL`, `event or message`, `file`, `other` | facetable |
| `version` | string | |
| `specificationUrl` | string, format uri | an OpenAPI, WSDL or AsyncAPI document |
| `documentationUrl` | string, format uri | |
| `standardVersions` | array of `$ref` element, `queryParams: gemmaType=standaardversie` | the same picker as `connection.standardVersions` |
| `status` | enum `in development`, `in use`, `end of support`, `withdrawn` | the connection's values, with an `x-openregister-lifecycle` on those exact values |
| `publicationDate`, `depublicationDate` | date-time | |

- `authorization` copied from `module` (`register.json` module schema): organisation-scoped read for `aanbod-beheerder`, read for `gebruik-beheerder`, public read after `publicationDate`; create and update for the groups that may edit a module.
- `components.registers.stackiq.schemas: ["applicationInterface"]` and `components.registers.stackiq.configuration.schemas.applicationInterface: { "magicMapping": true, "autoCreateTable": true }`, like every other catalogue schema (`register.json:853` onwards).
- On `connection`, a new optional property `interface` (`$ref` applicationInterface, `x-relation-filter` on `module` equal to the connection's `moduleB`), added through the same fragment.

Rejected: a `subtype` value on `connection`. An API exists before anyone connects to it, has its own version and specification, and serves many connections; LeanIX models it as its own fact sheet for that reason.

## D2. Pages

In `src/manifest.d/application-interfaces.json`:

- `Apis`, route `/apis`, `type: index`, schema `applicationInterface`, columns name, module, style, version, status; `filterMenu: true`.
- `ApiDetail`, route `/apis/:id`, `type: detail`: data widget, files, related (connections that name it), history tab.
- A menu child `APIs` under the `Modules` (Applications) entry, next to Connections from `connections-catalogue-pages`. No new top-level entry (ADR-097).

On `ModuleDetail` (`src/manifest.json:491`) an `object-list` widget `md-apis` with filter `{ "module": "@objectId" }`, `rowRoute: ApiDetail`, and `allowCreate: true` with the application prefilled.

## Declarative versus imperative

All declarative: a schema with a lifecycle and relations, manifest pages, one object-list widget (ADR-031). No PHP.

## Seed data

`lib/Settings/stackiq_mock_register.json` gains two demo APIs on one demo application: a REST API with a specification URL pointing at a placeholder `https://example.org/openapi.json`, and an event API.

## Risks

- `ModuleDetail` also changes in `connections-catalogue-pages` and `landscape-application-page`; the widgets stack below each other.
- A schema added through a fragment has never been tried for a brand-new schema in this app (the two existing fragments modify schemas). Task 1 proves the merge with a unit test before any page work.
