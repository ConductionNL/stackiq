# portal-contribution specification

## ADDED Requirements

### Requirement: REQ-PORT-005 Stackiq offers the published applications as a public record list

The portal contribution SHALL declare a public record list `softwareCatalogue` on every audience it serves, naming the provider methods `publicCatalogue` and `publicCatalogueEntry`. `publicCatalogue()` SHALL return the published applications as `{id, title, subtitle}`, the subtitle naming the supplier and the licence, bounded at 500. `publicCatalogueEntry(id)` SHALL return the application as `{title, subtitle, summary, columns, rows}` with its reference components as rows, and SHALL return null for an application that is not published. An application is published when its publication date is set and not in the future and its depublication date is empty or in the future.

#### Scenario: A visitor finds a published application
@e2e exclude The list is read by portaliq without a session; tests/Unit/Service/PublicCatalogueRecordsTest.php asserts the entries and the record, and portaliq's site test places the block.

- **GIVEN** OpenZaak, published yesterday by Conduction with licence EUPL-1.2 and the reference component Zaakregistratiecomponent
- **WHEN** portaliq asks stackiq for the `softwareCatalogue` list and then for OpenZaak's record
- **THEN** the list holds OpenZaak with the subtitle "Conduction · EUPL-1.2"
- **AND** the record names Zaakregistratiecomponent in its rows

#### Scenario: An unpublished application stays out
@e2e exclude A publication rule; tests/Unit/Service/PublicCatalogueRecordsTest.php asserts that drafts, future publications and depublished applications are left out and that their record is null.

- **GIVEN** an application without a publication date and one depublished last week
- **WHEN** portaliq asks for the list or for either record
- **THEN** neither is in the list and both records are null
