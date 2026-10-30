---
kind: code
depends_on: []
---

# Requirements for module versions and services, written after the fact

## Summary

Two built features have no requirement that says them. Module versions (a released version of an application, with the date it came into use) and services (what a supplier delivers on top of applications, such as hosting or support) are registered on their own pages, but their rows cite specs that cover something else: the generic manifest rules and the GEMMA facets. This change writes the two requirements so both rows can cite them, and adds the tests and `@spec` tags that keep them true. No new behaviour.

## Rows

- `land-module-versions` "Record the released versions of a module, with the date each came into use." Built: schema `moduleVersion` (`version`, `status`, `dateInDevelopment`, `dateInUse`, `dateEndSupport`, `dateWithdrawn`) in `lib/Settings/softwarecatalogus_register.json`, the Module versions index (`/moduleversies`) and detail page (`/moduleversies/:id`) in `src/manifest.json`. Board SkModuleversie.
- `land-services` "Register a service a supplier delivers on top of applications, such as hosting or support." Built: schema `catalogService` (`name`, `provider`, `modules`, `type` from Functional management, Application management, Technical management, Implementation support, Training, Licence reseller) and the Services page (`/diensten`, the faceted index), plus the Services list on an organisation. Board SkApplicaties draws services as the Diensten tab with its count, which is what the Services page shows. The board draws no service detail page and no hosting service type, so the row evidence that names them as missing is not a gap against the canon (decision 130).

## Screens

See `screens.md`.
