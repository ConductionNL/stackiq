# catalogue-services Specification (delta)

## Purpose

How stackiq registers the services a supplier delivers on top of applications. Covers row land-services.

## ADDED Requirements

### Requirement: A supplier's service is registered over one or more applications (REQ-CSV-001)

stackiq SHALL record a service with a name, its provider organisation, one or more service types (Functional management, Application management, Technical management, Implementation support, Training, Licence reseller) and the applications it covers. Name, provider and type SHALL be required. The Services page SHALL list services with name, provider, type and publication date and filter them by the GEMMA facets of the applications they cover, and an organisation's page SHALL list the services it provides.

#### Scenario: A supplier registers hosting and support
- GIVEN a supplier with the application "Mozard"
- WHEN the supplier registers the service "Beheer en hosting Mozard" with types Technical management and Application management, covering Mozard
- THEN the Services page lists it with the supplier and both types

#### Scenario: A service without a provider is refused
- GIVEN the service form
- WHEN a user saves a service without a provider
- THEN the save is refused and the provider field is marked required

#### Scenario: The organisation page lists its services
- GIVEN the supplier provides two services
- WHEN a user opens the supplier's organisation page
- THEN the Services list shows both
