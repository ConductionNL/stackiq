# Software Catalogus Features

Software Catalogus is a GEMMA-compliant software portfolio management application for Nextcloud. It enables organisations to register, track, and publish their software landscape — applications, modules, integrations, and contacts — in an open, standardized way aligned with Dutch GEMMA and Common Ground principles.

All data is stored as OpenRegister objects (no own database tables). OpenRegister is a required dependency.

## Feature Index

| Feature | Description |
|---------|-------------|
| [Software Registration](#software-registration) | Register and manage applications (voorzieningen) in the landscape |
| [GEMMA Faceted Search](#gemma-faceted-search) | Filter the application/service catalogue by GEMMA architecture dimension, with live counts |
| [Module Tracking](#module-tracking) | Break applications down into functional modules |
| [Connection Mapping](#connection-mapping) | Map integrations (koppelingen) between applications and modules |
| [Organisation and Contact Management](#organisation-and-contact-management) | Manage organisations and their contact persons |
| [Contract Management](#contract-management) | Track license and service agreements |
| [Federated Synchronisation](#federated-synchronisation) | Sync catalogue data across organisations and sources |
| [Automatic User Provisioning](#automatic-user-provisioning) | Create Nextcloud users from catalogue contacts |
| [ArchiMate Import/Export](#archimate-importexport) | Exchange software landscape data in ArchiMate format |
| [Open Data Publishing](#open-data-publishing) | Expose the catalogue as a public open-data API |
| [GEMMA Compliance](#gemma-compliance) | Built around VNG GEMMA Softwarecatalogus reference |

## Software Registration

Register every application (*voorziening*) in the organisation's IT landscape with full metadata:

- Name, description, and status (in use, planned, decommissioned)
- Supplier and owning organisation
- Repository URL and license information
- GEMMA applicatie reference
- Usage counts (*gebruik*) per organisation
- Version history and module breakdown

The dashboard displays totals and recent changes across all registered software.

**Key service:** `lib/Service/AanbodService.php`
**Controller:** `lib/Controller/AanbodController.php`

## GEMMA Faceted Search

The **Applications** (`/modules`) and **Services** (`/diensten`) index pages offer faceted filtering by GEMMA architecture dimension, alongside free-text search:

- **Referentiecomponent** and **Standaard** — read directly off the module's own `referentieComponenten`/`standaardVersies` links.
- **Domein** and **Applicatieservice** — resolved transitively via the module's linked GEMMA `element` objects (an application has no direct field for either; domein comes from the linked referentiecomponent element, applicatieservice from a `relation` object connecting that element to an `Applicatieservice`-typed element).
- Counts update live as other facets are applied — a facet's own count is never narrowed by its own selection, so it always reads "how many results if I also add this filter". Multiple values within one dimension combine with OR; selections across different dimensions combine with AND.
- Free-text search narrows the candidate set before facet counts are computed, so search and facets are always consistent with each other and with the object list.
- The filter selection is reflected in the URL (`_gf_`-prefixed query parameters) so a filtered view is shareable, bookmarkable, and survives a page reload.
- A filter selection can be saved as a named view (via OpenRegister's generic saved-search Views API) and reopened later from the page's "Saved views" menu.
- Facet counts are computed through the same RBAC/tenant-scoped query path as the page's own object list — a restricted user's counts never reflect objects outside their visible scope.

**Key service:** `lib/Service/FacetService.php`
**Controller:** `lib/Controller/FacetController.php`
**Endpoint:** `GET /apps/stackiq/api/facets/{schema}` (`schema`: `module` or `dienst`)

## Module Tracking

Each application can be decomposed into functional **modules** (*modules*). A module represents a distinct component or capability within an application:

- Name, description, and version
- Parent application reference
- Integration point documentation
- Compliance and standard references

Modules can be linked to connections (*koppelingen*) to map how they interact with other modules and applications.

**Key service:** `lib/Service/ModuleRegistrationService.php`, `lib/Service/ModuleVersionService.php`, `lib/Service/ModuleComplianceService.php`

## Connection Mapping

**Connections** (*koppelingen*) describe the integrations between applications and modules in the landscape. Each connection records:

- Source module or application
- Target module or application
- Connection type (API, file exchange, database, message queue)
- Protocol and standard (REST, StUF, Digikoppeling, etc.)
- Status and responsible parties

Visualizing connections reveals landscape dependencies and highlights integration complexity before system changes.

**Key service:** `lib/Service/GebruikService.php`
**Controller:** `lib/Controller/GebruikController.php`

## Organisation and Contact Management

Maintain a registry of **organisations** (*organisaties*) that use, supply, or manage software in the landscape:

- Organisation name, type (municipality, supplier, partner), and contact details
- Automatic Nextcloud group creation per organisation
- Role-based membership: **beheerder** (manager) and **medewerker** (member)
- Manager-employee relationships: beheerders become Nextcloud managers for their organisation's users

**Contact persons** (*contactpersonen*) are individuals linked to organisations:

- Name, email, phone, and role
- Optional conversion to Nextcloud user accounts
- Linked to application usage records

**Key services:** `lib/Service/OrganisatieService.php`, `lib/Service/ContactpersoonService.php`, `lib/Service/OrganizationSyncService.php`
**Controller:** `lib/Controller/ContactpersonenController.php`

## Contract Management

Track **contracts** associated with software usage:

- License type and terms
- Contract start and end dates
- Linked application and supplier
- Renewal alerts and status tracking

Contracts are stored as OpenRegister objects, enabling audit trails and version history.

## Federated Synchronisation

Software Catalogus can synchronise catalogue data across organisations via the open-data network:

- Import software listings from external Software Catalogus instances
- Merge and deduplicate entries using slug-based identifiers
- Schedule regular sync runs via background jobs
- Resolve conflicts using configurable merge strategies

This enables a federated, collaborative catalogue across Dutch municipalities without a central authority.

**Key service:** `lib/Service/StackiqService.php`
**Subservices:** `lib/Service/Stackiq/` (dedicated handlers per sync scenario)

## Automatic User Provisioning

When an organisation and its contacts are registered in the catalogue, Software Catalogus can automatically provision Nextcloud user accounts:

- Create a Nextcloud account for each contact person linked to an organisation
- Assign the user to the organisation's Nextcloud group
- Apply beheerder or medewerker role based on the contact's catalogue role
- Sync organisation hierarchy: managers are marked as managers of their groups

User provisioning is triggered by OpenRegister object events and runs in the background.

**Key service:** `lib/Service/UserService.php` (via `AangebodenGebruikService.php`)
**Controller:** `lib/Controller/AangebodenGebruikController.php`

## ArchiMate Import/Export

Exchange IT landscape data with enterprise architecture tools using the **ArchiMate** standard:

- **Import:** Parse ArchiMate XML exchange files and create corresponding voorzieningen, modules, and koppelingen in the catalogue
- **Export:** Generate ArchiMate exchange files from the current catalogue state for use in architecture tools (BiZZdesign, Archi, etc.)

The ArchiMate integration maps GEMMA Softwarecatalogus objects to ArchiMate application layer elements:

| Catalogue Object | ArchiMate Element |
|-----------------|-------------------|
| Voorziening | ApplicationComponent |
| Module | ApplicationComponent (nested) |
| Koppeling | AssociationRelationship |
| Organisatie | BusinessActor |

**Key services:** `lib/Service/ArchiMateService.php`, `lib/Service/ArchiMateImportService.php`, `lib/Service/ArchiMateExportService.php`

## Open Data Publishing

The catalogue is published as an open-data API. All registered applications, modules, and connections are accessible via public endpoints:

- Standard JSON-API format responses
- GEMMA-compatible metadata fields
- Schema.org compatibility for search engine indexing
- Pagination and filtering support

The **View** system provides tailored read-only projections of catalogue data for public consumption.

**Key service:** `lib/Service/ViewService.php`
**Controller:** `lib/Controller/ViewController.php`

## GEMMA Compliance

Software Catalogus is built around the **GEMMA Softwarecatalogus** reference architecture defined by VNG-Realisatie:

| Catalogue Concept | GEMMA Reference |
|------------------|-----------------|
| Voorziening | Applicatie (GEMMA Softwarecatalogus) |
| Module | Module / Component |
| Koppeling | Koppeling |
| Organisatie | Organisatie |
| Contactpersoon | Contactpersoon |
| Contract | Contract |

**Standards:** GEMMA Softwarecatalogus (VNG), Common Ground principles, Schema.org, WCAG AA, EUPL-1.2.

## Data Model

| Object | Dutch Term | GEMMA Mapping | Description |
|--------|-----------|---------------|-------------|
| Application | Voorziening | Applicatie | Software application in the landscape |
| Module | Module | Module/Component | Functional component of an application |
| Connection | Koppeling | Koppeling | Integration between modules or applications |
| Organisation | Organisatie | Organisatie | Using or supplying organisation |
| Contact | Contactpersoon | Contactpersoon | Individual linked to an organisation |
| Contract | Contract | Contract | License or service agreement |
| Usage | Gebruik | Gebruik | Organisation's use of an application |

All objects are stored as JSON in OpenRegister schemas with full audit trails, versioning, and cross-app data sharing.
