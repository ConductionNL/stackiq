# vendor-product-master-data Specification

## Purpose
TBD - created by archiving change vendor-product-master-data. Update Purpose after archive.
## Requirements
### Requirement: The vendor master advertises its canonical semantic types

The `organisatie` schema SHALL declare `configuration.jsonld.type:
https://schema.org/Organization` and `configuration.implements:
["https://schema.org/Organization", "https://openregister.app/ns#Vendor"]`, so
it advertises that it provides the canonical software-vendor role (ADR-048).
The declaration SHALL be schema metadata only — no controller or service change.

#### Scenario: Vendor objects serialize with the semantic type

- **WHEN** an `organisatie` object is serialized by OpenRegister
- **THEN** its `@type` is `https://schema.org/Organization`
- **AND** the schema advertises `https://openregister.app/ns#Vendor` among its implemented capability URIs

#### Scenario: The fleet vendor role resolves to stackiq when installed

- **WHEN** another app declares a property referencing `https://openregister.app/ns#Vendor` and stackiq is installed with `implements` advertising that role
- **THEN** the reference is resolvable to stackiq's `organisatie` objects
- **AND** stackiq continues to function standalone when no other app consumes the role

### Requirement: Product schemas advertise SoftwareApplication and vendor references name their role

The `module` and `suite` schemas SHALL declare `configuration.jsonld.type:
https://schema.org/SoftwareApplication`. The `aanbieder` property on `module`,
`dienst`, and `koppeling` SHALL declare `referenceSemanticType:
https://openregister.app/ns#Vendor` alongside its existing local `$ref` to
`organisatie`, so the reference is legible by canonical kind without changing
its standalone resolution.

#### Scenario: Product objects serialize as SoftwareApplication

- **WHEN** a `module` or `suite` object is serialized
- **THEN** its `@type` is `https://schema.org/SoftwareApplication`

#### Scenario: Vendor reference declares the role while resolving locally

- **WHEN** the `aanbieder` field of a `module`, `dienst`, or `koppeling` is inspected
- **THEN** it declares `referenceSemanticType` = the canonical vendor role
- **AND** it still resolves to a local `organisatie` object exactly as before

### Requirement: Vendor and product master data is deduplicated by OpenRegister, not app code

The `organisatie` and `module` schemas SHALL declare `x-openregister-dedup` so
OpenRegister's MDM surface detects duplicate vendors and products and offers
golden-record survivorship and reversible merge. The `organisatie` dedup rule
SHALL treat an identical `contactsUid` as the same vendor, falling back to
similarity of the resolved contact name and website; the `module` dedup rule
SHALL match on similarity of `naam`, `aanbieder`, and `website`. No app-local
dedup service, merge controller, or survivorship engine SHALL be introduced;
`FederationMerger` SHALL remain provenance-only.

#### Scenario: The same vendor from two peers becomes one duplicate candidate

- **WHEN** federation imports mirrors of the same real vendor from two different peer catalogs
- **THEN** OpenRegister's duplicate-candidate surface lists them as a merge candidate
- **AND** a steward can resolve them to a single golden vendor through the OR merge surface
- **AND** no stackiq-local code performed the reconciliation

#### Scenario: Duplicate products by name and supplier are surfaced

- **WHEN** two `module` records share a highly similar `naam` and the same `aanbieder`
- **THEN** OpenRegister surfaces them as duplicate product candidates

### Requirement: Vendor and product master data is quality-scored declaratively

The `organisatie` and `module` schemas SHALL declare `x-openregister-quality`
so OpenRegister materialises a `qualityScore` / `qualityStatus` on each object
from schema metadata. Vendor quality SHALL reflect completeness of the contact
link, type, and status; product quality SHALL reflect completeness of `naam`,
`aanbieder`, `licentie`, and a description. Scoring SHALL be performed by
OpenRegister, not by app code.

#### Scenario: A vendor missing its contact link scores lower

- **WHEN** an `organisatie` object has no `contactsUid` and another has a complete contact link, type, and status
- **THEN** OpenRegister materialises a lower `qualityScore` on the incomplete vendor
- **AND** both scores are available to the OR MDM quality surface without app-side computation

