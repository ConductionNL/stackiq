---
kind: code
depends_on:
  - landscape-application-page
---

# Record the applications your organisation uses, with status, version and owners

## Summary

An information manager records that the organisation uses an application: which version it runs, where it stands in its lifecycle, and who owns it on the business side and the technical side. Today this record (a usage, `gebruik`) exists in the data but no stackiq page creates or edits it. This change adds the pages, fixes the usage lifecycle so its transitions work, and adds the two owner fields.

## Why

Rows from the stackiq matrix:

- `stackiq:land-register-application`, "Register an application your organisation uses, with its supplier, description and status." Rated partial, built. Four competitors rate yes, among them GEMMA Softwarecatalogus (https://www.softwarecatalogus.nl/node/30355, "klik dan op de knop + achter de beschrijving van het pakket om het pakket toe te voegen aan je omgeving ... Vul onder Planning bij Status in gebruik in"), SAP LeanIX (https://help.sap.com/docs/leanix/ea/application-modeling-guidelines), BlueDolphin (https://help.bluedolphin.io/en/articles/11967529-welcome-to-the-objects) and GLPI (source read at 11.0.9, `src/Appliance.php:350` search option Status). The missing half: a lifecycle status for the application an organisation uses, set on a usage page. Core area (landscape).
- `stackiq:life-version-in-use`, "Record which version of an application your organisation currently runs." Rated partial, built. Two competitors rate yes: GEMMA Softwarecatalogus (https://www.softwarecatalogus.nl/node/30355, "Pakketversie - selecteer de versie die in gebruik is") and GLPI (source read at 11.0.9, `src/Item_SoftwareVersion.php:39`). The missing half: a usage page where the organisation sets the version.
- `stackiq:land-application-owner`, "Name the business owner and the technical owner responsible for an application." Rated partial, built. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/subscription-roles, "roles that map to your organization's positions, such as application owner") and GLPI (source read at 11.0.9, `glpi_appliances` holds `users_id` and `users_id_tech`, `src/Appliance.php:186` and `:240`). The missing half: a separate business owner and technical owner for the organisation that uses the application, on its usage. Core area.

## What stackiq has today

- The `usage` schema (`lib/Settings/softwarecatalogus_register.json:2656`, version 1.5.0) holds `consumer`, `module`, `moduleVersion` (filtered to the module's versions), `status` (Acquisition, Planned, In production, To be phased out, Phased out; default In production), five phase dates, `contactPerson` (hidden), `koppelingen`, `plannedReplacement` and the TIME classification.
- No manifest page uses the schema. `LifecycleRoadmapView.vue:397` and the portfolio report read usages, and the EOL badges depend on `usage.moduleVersion`, but nobody can set it in stackiq.
- The usage lifecycle names Verwerving, Gepland, In productie, Uit te faseren and Uitgefaseerd, while the enum and the migrated rows (`lib/Repair/RenameDutchCatalogValues.php:80-84`) hold the English values, so no transition matches a row.
- `usage.objectNameField` is `consumer`, so every usage of one organisation carries the same name in lists and pickers.

## What this change builds

1. A usage index page (`Gebruik`, `/gebruik`, "Applications in use") and a usage detail page, under Applications in the menu.
2. An "Add to our landscape" action on the application page that opens the usage form with the application and the active organisation filled in.
3. Business owner and technical owner fields on the usage, picked from the organisation's contact persons.
4. The usage lifecycle on the enum values, so Plan, Go live, Phase out and Retire work from the detail page.
5. A usage name built from the application and the organisation.
6. An "Applications in use" list on the organisation page.

## Out of scope

- Filling phase dates when the status changes (`stackiq:life-dates-follow-status`, deferred: feature request without a competitor yes).
- Suggested connections for a usage: `connections-derived-dependencies`.
- A status on the product itself: a product's own status is its versions' status (`moduleVersion.status`).

## Risks

- Suppliers can read usages of their products (`provider` read rule). The owner fields name people of the using organisation; the design keeps them readable only for that organisation.
