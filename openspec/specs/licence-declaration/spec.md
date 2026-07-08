# licence-declaration Specification

## Purpose
TBD - created by archiving change fix-licence-declaration-consistency. Update Purpose after archive.
## Requirements
### Requirement: The declared licence is EUPL-1.2 everywhere a tool reads it

The app SHALL declare EUPL-1.2 as its licence in every machine-read location,
matching the EUPL-1.2 `LICENSE` file actually shipped. `appinfo/info.xml` SHALL
declare `<licence>EUPL-1.2</licence>` (replacing `agpl`). `composer.json`,
`publiccode.yml`, the README badge, and the `LICENSE` file — already EUPL-1.2 —
SHALL remain EUPL-1.2. No location SHALL declare AGPL or any licence other than
the one in the `LICENSE` file.

#### Scenario: App Store metadata matches the shipped licence

- **WHEN** an administrator views the app's licence in the Nextcloud App Store or via `occ app:list`
- **THEN** the declared licence is EUPL-1.2
- **AND** it matches the `EUROPEAN UNION PUBLIC LICENCE v. 1.2` text in the repository `LICENSE` file
- **AND** no surface reports AGPL

#### Scenario: Metadata files agree

- **WHEN** `appinfo/info.xml`, `composer.json`, and `publiccode.yml` are inspected
- **THEN** each declares EUPL-1.2
- **AND** none declares a different licence

### Requirement: Every PHP source file declares EUPL-1.2 via SPDX

Every PHP file under `lib/` SHALL carry `SPDX-License-Identifier: EUPL-1.2`
inside its main file docblock, replacing the `AGPL-3.0-or-later` identifier
currently present in 66 of 78 files. Copyright and author lines SHALL be
preserved unchanged. No PHP file under `lib/` SHALL retain an `AGPL-3.0`
identifier.

#### Scenario: SPDX scan finds no AGPL identifier

- **WHEN** an SPDX/REUSE scanner or `grep -rl 'AGPL-3.0' lib/ --include='*.php'` runs over the source tree
- **THEN** it finds zero files declaring an AGPL identifier
- **AND** every PHP file under `lib/` declares `SPDX-License-Identifier: EUPL-1.2`

#### Scenario: A redistributor reading per-file headers inherits EUPL terms

- **WHEN** a downstream redistributor reads the `SPDX-License-Identifier` docblock of any `lib/` PHP file
- **THEN** it reads EUPL-1.2
- **AND** it does not read a licence that contradicts the repository `LICENSE` file

