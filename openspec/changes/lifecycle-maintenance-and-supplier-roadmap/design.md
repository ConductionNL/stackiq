# Design: lifecycle-maintenance-and-supplier-roadmap

Read at development `9a5ece6a`, `@conduction/nextcloud-vue` 2.57.1, OpenRegister development `4fee776`.

## Context

A product (`module`) has versions (`moduleVersion`, `lib/Settings/softwarecatalogus_register.json:7651` schema) that suppliers maintain, readable by the public. Organisations use products through usages (`usage`), and `landscape-usage-registration` gives each usage a business and a technical owner. OpenRegister notifications resolve recipients from groups, object ACLs or an object field (`openregister lib/Service/Notification/NotificationRecipientResolver.php:187`).

## D1. The maintenanceWindow schema

`lib/Settings/register.d/maintenance-and-roadmap.json` adds `maintenanceWindow` to the `stackiq` register:

| property | type | notes |
|---|---|---|
| `module` | `$ref module`, required | the product |
| `moduleVersion` | `$ref moduleVersion` | `x-relation-filter: { module: @object.module }` |
| `title` | string, required | |
| `description` | string, markdown | |
| `startsAt`, `endsAt` | date-time, required | |
| `impact` | enum `no impact`, `degraded`, `unavailable` | |
| `status` | enum `planned`, `in progress`, `completed`, `cancelled` | `x-openregister-lifecycle` on these exact values: start, complete, cancel |
| `notifyUserIds` | array of string, `hideOnForm: true` | written by D3 |

Authorization: create and update for the supplier of the product (`aanbod-beheerder` with `provider` matching the active organisation, as on `module`); read for everyone who may read the product.

It also adds `roadmapStatement` (string, markdown, title "Roadmap") to `module`.

## D2. Where maintenance shows

- `ModuleDetail` (`src/manifest.json:491`): an `object-list` `md-maintenance` over `maintenanceWindow` with filter `{ module: @objectId }`, sorted on `startsAt`, columns title, start, end, impact, status, and Add for the supplier.
- Dashboard (`src/manifest.json`, page `Dashboard`): a widget "Planned maintenance" backed by a small custom component `UpcomingMaintenanceWidget` (`src/components/maintenance/UpcomingMaintenanceWidget.vue`): it reads the active organisation's usages, collects their modules, and lists planned windows for those modules in the next 30 days.

## D3. Notifying the owners

A listener on OpenRegister's object created event for `maintenanceWindow` (`lib/Listener/MaintenanceRecipientsListener.php`) resolves the usages of the window's product, collects their `businessOwner` and `technicalOwner` contact persons, maps them to Nextcloud users (as `landscape-owner-attestation` does) and writes `notifyUserIds`. Two declared rules on the schema then send the messages (`x-openregister-notifications`): `announced` on update of `notifyUserIds` and `starts-tomorrow` scheduled daily with a filter on `startsAt` within one day and status planned, both with recipient `{ kind: field, field: notifyUserIds }`.

Rejected: sending notifications from the listener. The dialect already delivers, translates and records them (ADR-031); the listener only computes who.

## D4. The roadmap

`ModuleDetail` gets a body widget `ProductRoadmap` (`src/components/roadmap/ProductRoadmap.vue`) that shows `roadmapStatement` and the product's versions on the library's `CnTimelineView` (`src/components/CnTimelineView/CnTimelineView.vue`), each version placed on `dateInUse` (planned or actual) with its status, planned versions first. The Module versions page (`Moduleversies`) gains a column for `dateInDevelopment` and a quick filter "Planned releases" (status `in development`), matching the GEMMA Softwarecatalogus planning facet.

## D5. The moduleVersion lifecycle

In the monolith (a fragment cannot replace list values): the lifecycle at register.json:7887 becomes initial `in development`, final `withdrawn`, release (`in development` to `in use`), sunset (`in use` to `end of support`), withdraw (`in use`, `end of support` to `withdrawn`), and the schema version goes to 0.1.5 with a changelog line, the fix the register changelog 2.4.4 (register.json:7) records for `organization`.

## Declarative versus imperative

Schema, lifecycle, notification rules, list widgets and quick filter are declarative (ADR-031). The recipient listener and the two read-only widgets are the imperative parts.

## Seed data

One demo maintenance window next week on a demo product with a usage, and a roadmap statement with one planned version.

## Risks

- The listener runs on create; owners added to a usage later are not notified for an already announced window. The widget still shows it to them.
