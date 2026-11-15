---
kind: code
depends_on:
  - connections-catalogue-pages
  - landscape-usage-registration
---

# Record the servers, databases and devices your applications run on

## Summary

Stackiq records applications, their versions, suites and the connections between them. It cannot record what they run on: the database server, the virtual machine, the laptop fleet, the network switch. A municipal information manager who hears that PostgreSQL 13 is out of support cannot find which of their applications depend on it. This change adds technology components owned by an organisation, with a class, a lifecycle and relations to each other, links an application in use to the components it runs on, and gives each component its end of support date, copied from the end-of-life feed when the component is a known software version. Stackiq records these; it does not discover them.

## Why

This change covers three matrix rows.

- `stackiq:ops-hardware-assets`, "Register hardware such as laptops and servers alongside software." Stackiq rates itself no. GLPI rates yes from its source at 11.0.9: `src/autoload/CFG_GLPI.php:208` `asset_types` lists Computer, Monitor, NetworkEquipment and the other hardware types managed next to software. TOPdesk rates yes: "Think of a router that provides a computer with access to your network, or a printer" (https://docs.topdesk.com/en/linking-assets-to-other-assets.html).
- `stackiq:ops-ci-relations`, "Record configuration items and their relations in a CMDB." Stackiq rates itself partial: applications, versions, suites and connections are recorded with relations, but there are no configuration item classes beyond applications. GLPI rates yes from its source: the asset types plus appliances, with relations as appliance membership (`src/Appliance_Item.php:45`) and impact relations (`install/mysql/glpi-empty.sql:1247` `glpi_impactrelations`). TOPdesk rates yes: "You register these functionalities as custom link types, and these link types are shown in the graphical overview of assets" (https://docs.topdesk.com/en/linking-assets-to-other-assets.html).
- `stackiq:life-tech-obsolescence`, "Track the lifecycle of underlying technology, such as a database or framework, not only applications." Stackiq rates itself no. SAP LeanIX rates yes: "SAP LeanIX helps you gain an overview of your application landscape's obsolescence risk exposure" (https://help.sap.com/docs/leanix/ea/obsolescence-risk-management). This row is below the bar on its own and rides with `stackiq:ops-hardware-assets`: its missing half is the relation from an application to the platform it runs on, with that platform's lifecycle, which the technology components record.

`ops-hardware-assets` is built new. `ops-ci-relations` is partial and built: this change builds configuration item classes beyond applications, and their relations.

## What stackiq has today

Read at development 49e65cb4, with the lead's merged changes on development 9a5ece6a.

- The stackiq register holds sector, suite, module, catalogService, vulnerability, contactPerson, organization, usage, catalogContract, connection, software-review, compliancy, moduleVersion, sbomComponent and bioMeasure (`lib/Settings/softwarecatalogus_register.json:817` onwards). None of them is hardware or infrastructure.
- `module` (`:6777`) is the application as the supplier offers it. `module.type` holds Application or System software, and `module.eolProductSlug` maps it to the end-of-life feed. `moduleVersion` (`:7649`) holds `dateEndSupport`, `eolSource` and `eolUpdatedOn`.
- `lib/Service/EolSyncService.php:290` `findMappedModules()` reads every module with an `eolProductSlug`, and the service stamps the matching versions' end of support from the feed. Nothing links a version of system software to the applications that run on it.
- `sbomComponent` (`:7920`) records the libraries inside a version, with name, version, purl, licences and CVE ids, and no lifecycle.
- `usage` (`:2654`) is an organisation's deployment of an application. `landscape-usage-registration` gives it the pages `Gebruik` and `GebruikDetail`.
- `connection` (`:3563`) links two applications. `connections-catalogue-pages` gives it the pages `Koppelingen` and `KoppelingDetail`.
- The GEMMA reference model in `lib/Settings/GEMMA_release.xml` has no technology layer elements (one `Artifact`, no `Node`, `Device` or `SystemSoftware`), so technology is organisation data, not reference data.

## What this change builds

- A `technologyComponent` schema: name, class (server, virtual machine, container platform, database, middleware, runtime or framework, operating system, network device, storage, end-user device, other), the ArchiMate technology type it maps to, the owning organisation, status, manufacturer, model, serial number, asset tag, location, the software version it is (a `moduleVersion` of a System software module), an end of support date, and the components it runs on.
- `usage.runsOn`: the components an application in use runs on.
- A Technology index and detail page under Applications. The detail page shows the components this one runs on, the components running on it, and the applications in use that run on it.
- The end-of-life sync copies a version's end of support onto the components that are that version, so a database server shows PostgreSQL 13's end of support without anyone typing it.
- On `GebruikDetail`, a Runs on list that marks a component past its end of support.

## Out of scope

- Discovering hardware or software on the network, or reading it from an inventory agent or a monitoring tool. The matrix category says stackiq is not a discovery agent. An import from an outside CMDB belongs to integriq (ADR-091).
- Exporting technology components in the ArchiMate export. The schema records the ArchiMate type so the export can map it later.
- Impact analysis across connections and technology in a graph. `connections-diagram-and-graph-export` draws the application landscape; adding technology to that view is a follow-up.
- Warranty, purchase and depreciation of hardware. Contracts cover what was bought; money belongs to shillinq.

## Risks

- Infrastructure details help an attacker. The schema's read rule is the organisation's own users and the catalogue admins, never suppliers or the public.
- The `moduleVersion` lifecycle still names Dutch states while its rows hold English values (`x-openregister-lifecycle` at `lib/Settings/softwarecatalogus_register.json:7887`). This change reads `dateEndSupport` and uses no `moduleVersion` transition, so it does not fix that; `lifecycle-maintenance-and-supplier-roadmap` owns it.
- A second place for platform software. System software stays a `module` in the catalogue (the product); a `technologyComponent` is one organisation's installed instance of it. The design keeps the two apart by pointing from the component to the version.
