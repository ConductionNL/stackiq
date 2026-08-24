---
status: proposed
---

# Stackiq Beta Cross-Surface Alignment

## Purpose

Stackiq's code metadata (`appinfo/info.xml`), product page (conduction.nl), and docs (stackiq.conduction.nl) SHALL describe the same, code-verified feature set and licence, so the app is beta-release-ready.

## Requirements

### Requirement: License Consistency

The app SHALL declare EUPL-1.2 as its license consistently across `appinfo/info.xml`, the shipped `LICENSE` file, and docs referencing the license.

#### Scenario: info.xml licence tag

- **GIVEN** `appinfo/info.xml`
- **WHEN** the `<licence>` tag is read
- **THEN** it MUST read `EUPL-1.2`, matching the shipped `LICENSE` file (European Union Public Licence v1.2)
- @e2e exclude metadata-only, no runtime surface

#### Scenario: Government features doc license line

- **GIVEN** `docs/GOVERNMENT-FEATURES.md`
- **WHEN** its licence/attribution line is read
- **THEN** it MUST read "EUPL-1.2" and "Codeberg", not "AGPL" / "GitHub"
- @e2e exclude docs-only, no runtime surface

### Requirement: Product Page Reflects Shipped Features Only

The conduction.nl product page (EN + NL) SHALL describe only features verifiable against `lib/` and `src/`, and SHALL NOT assert integrations, targets, or widgets that do not exist in code.

#### Scenario: No fabricated discovery-tool integration

- **GIVEN** the product page's feature list
- **WHEN** it describes inventory sourcing
- **THEN** it MUST NOT claim discovery from Microsoft Intune, Jamf, GLPI, or OCS Inventory via OpenConnector (no such integration exists in `lib/` or `src/`)
- @e2e exclude marketing-copy-only, no runtime surface

#### Scenario: Federation named accurately

- **GIVEN** the product page's federation description
- **WHEN** it names the federation mechanism
- **THEN** it MUST describe it as optional synchronization via OpenCatalogi's directory network (per `FederationService::OPENCATALOGI_APP_ID` and its graceful-degradation behaviour), and MUST NOT assert a hard-coded target such as "Forum Standaardisatie" that does not appear in code
- @e2e exclude marketing-copy-only, no runtime surface

#### Scenario: Version and status match info.xml

- **GIVEN** the product page hero
- **WHEN** version and status are shown
- **THEN** version MUST derive from `appinfo/info.xml`'s `<version>` (0.2.x → "v0.2") and status MUST read "Beta"
- @e2e exclude marketing-copy-only, no runtime surface

### Requirement: Docs Cover All Shipped Feature Areas

`docs/FEATURES.md` SHALL enumerate every major shipped feature area: software/module/connection registration, contract administration with approval, GEMMA standards/compliance matrix/ArchiMate import-export, application lifecycle/portfolio roadmap, reviews, federated synchronization (via OpenCatalogi), automatic user provisioning, and open data publishing with moderated self-registration.

#### Scenario: Contract administration documented

- **GIVEN** `docs/FEATURES.md`
- **WHEN** its feature sections are read
- **THEN** it MUST include a section describing contract tracking with an approval workflow (matching `ContractApprovalController`)
- @e2e exclude docs-only, no runtime surface

#### Scenario: Standards/compliance/ArchiMate documented

- **GIVEN** `docs/FEATURES.md`
- **WHEN** its feature sections are read
- **THEN** it MUST include a section describing the standards register, compliance matrix, and ArchiMate import/export (matching `ComplianceMatrixView.vue`, `ArchiMateImportService`/`ArchiMateExportService`)
- @e2e exclude docs-only, no runtime surface
