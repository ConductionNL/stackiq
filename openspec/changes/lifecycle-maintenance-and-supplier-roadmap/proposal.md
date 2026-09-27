---
kind: code
depends_on:
  - landscape-usage-registration
---

# Follow planned maintenance and a supplier's roadmap

## Summary

A supplier announces planned maintenance on a product, with a time window and the expected impact, and the organisations that use the product see it on their dashboard and on the product's page, and the owners of their usage get a notification. The supplier also publishes a roadmap: a short statement of direction and the planned versions with their expected dates, drawn as a timeline on the product's page and listed across the catalogue as planned releases.

## Why

Rows from the stackiq matrix:

- `stackiq:life-maintenance-window`, "Follow planned maintenance announced for an application." Rated no and marked specified with no change directory, a claim without a change, so this is the missing change. TOPdesk rates yes: https://docs.topdesk.com/en/operations-management.html, "you can easily schedule operational activities in the user-friendly planner", with assets linked to operational activity cards.
- `stackiq:mkt-supplier-roadmap`, "Read a supplier's declared roadmap and planned releases for a product." Rated partial, built, one competitor yes: GEMMA Softwarecatalogus (https://www.softwarecatalogus.nl/node/13683, "Alle pakketversies en planningen ... gelijk zichtbaar de planning van de diverse pakketversies"). It rides with `life-maintenance-window`: the feature overlay pairs them as `maintenance-and-supplier-roadmap`, and its missing half is the roadmap view this change adds.

## What stackiq has today

- `openspec/features.overlay.json` lists `maintenance-and-supplier-roadmap` ("Follow planned maintenance and what your suppliers ship next") with status soon. Nothing is built for maintenance: no schema in `lib/Settings/softwarecatalogus_register.json` and no page.
- A supplier can register a future version: `moduleVersion` (register.json:7651 schema, version 0.1.4) has status in development, in use, end of support, withdrawn and the dates `dateInDevelopment`, `dateInUse`, `dateEndSupport`, `dateWithdrawn`, read public. The Module versions page (`src/manifest.json`, page `Moduleversies`, route `/moduleversies`) lists version, module, `dateInUse` and status. There is no roadmap view and no place for a declared direction.
- The `moduleVersion` lifecycle (register.json:7887) names in ontwikkeling, in gebruik, einde ondersteuning and teruggetrokken, while the enum and the rows hold the English values, so the release, sunset and withdraw transitions never match a row.

## What this change builds

1. A `maintenanceWindow` schema: the product, optionally the version, a title, start and end, the expected impact (no impact, degraded, unavailable) and a status (planned, in progress, completed, cancelled).
2. A "Planned maintenance" section on the product page and a dashboard widget listing upcoming maintenance for products the user's organisation uses.
3. A notification to the business and technical owners of every usage of the product when maintenance is announced, and a reminder a day before it starts.
4. A roadmap on the product page: the supplier's declared direction (`roadmapStatement` on the product) and the product's versions on a timeline, planned ones first.
5. A Planned releases quick filter and planned-date column on the Module versions list.
6. The `moduleVersion` lifecycle on the enum values, with the schema version bumped, so a supplier releases a planned version from its page.

## Out of scope

- Putting maintenance windows in users' Nextcloud calendars; the open change `adopt-integration-leaves` brings the calendar leaf, and a follow-up can link windows to it.
- Maintenance an organisation plans itself on its own infrastructure; that is service desk operations management, which the matrix category keeps out of stackiq.
- Notifying about a new version once it is released (`stackiq:life-new-version-notice`, owned by openregister).

## Risks

- A supplier may announce maintenance for a product whose users have no owners set; the widget still shows it, only the notification has nobody to reach. The announcement dialog says how many owners will be notified.
