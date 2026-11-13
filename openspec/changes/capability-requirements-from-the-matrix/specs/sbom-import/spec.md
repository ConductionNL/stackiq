## ADDED Requirements

### Requirement: SPDX 2.x JSON files are parsed into the same component list

Stackiq SHALL accept an SBOM in SPDX 2.x JSON next to CycloneDX JSON. The SPDX parser SHALL produce the same component fields, name, version, purl and licences, and SHALL reject an `spdxVersion` outside `SPDX-2.*`. XML and tag-value files SHALL be refused.

#### Scenario: A supplier uploads an SPDX file

- **GIVEN** a module version and an SPDX 2.3 JSON document with three packages
- **WHEN** the supplier uploads it on the version's Components tab
- **THEN** the tab lists three components with name, version and purl

#### Scenario: An unsupported SPDX version is refused

- **GIVEN** a JSON document with `spdxVersion` `SPDX-3.0`
- **WHEN** it is uploaded
- **THEN** the upload is refused with a message naming the version
- **AND** no component is saved
