## ADDED Requirements

### Requirement: Properties SHALL be classified into three tiers before any rename

Every Dutch property SHALL be classified as a published external identifier, a VNG import
field name, or softwarecatalog's own storage vocabulary. Only the third tier SHALL be
renamed, and the classification SHALL be recorded before any rename lands.

#### Scenario: A published external identifier is preserved

- **WHEN** a property carries a namespaced key such as `ggm-` or `gemma-`, or holds a
  code from the published BIO 2.0 measures list, or a NORA value
- **THEN** it SHALL be preserved exactly
- **AND** the schema SHALL carry a marker naming the standard it comes from

#### Scenario: A VNG import field is mapped rather than stored

- **WHEN** a field name originates in the VNG Softwarecatalogus import payload
- **THEN** the name SHALL be preserved at the importer boundary
- **AND** the value SHALL be mapped onto an English storage property

#### Scenario: A namespaced and an unprefixed twin coexist

- **WHEN** a schema carries both an unprefixed Dutch property and a namespaced twin, such
  as `toelichting` alongside `gemma-toelichting` and `ggm-toelichting`
- **THEN** only the unprefixed property SHALL be renamed
- **AND** the resulting mixed-language property set SHALL be accepted as correct

### Requirement: The data migration SHALL be written and tested before the rename merges

The migration SHALL be authored and validated against copied production data before the
schema rename is merged, because softwarecatalog holds thousands of client-imported
production objects. This app SHALL NOT be treated as reseedable.

#### Scenario: Stored objects are counted correctly

- **WHEN** the stored-object count is measured
- **THEN** it SHALL read the per-schema shard tables rather than the shared objects table
- **AND** it SHALL exclude soft-deleted rows

#### Scenario: The migration is validated before merge

- **WHEN** the rename is proposed for merge
- **THEN** the migration SHALL already have been run successfully against copied
  production data
- **AND** the rename SHALL NOT merge ahead of it

#### Scenario: A reseed is not offered as an alternative

- **WHEN** a migration is considered optional because other apps reseed
- **THEN** that reasoning SHALL be rejected for this app
- **AND** the imported production data SHALL be treated as the constraint

### Requirement: The importer SHALL be updated in the same commit as the schema

The VNG import mapping SHALL land together with the schema rename. An importer still
writing the old keys reintroduces the old vocabulary on every run without raising an
error.

#### Scenario: Importer and schema move together

- **WHEN** a storage property is renamed to English
- **THEN** the importer's mapping to that property SHALL be updated in the same commit
- **AND** the change SHALL NOT be split across two merges

#### Scenario: An import is exercised as the acceptance check

- **WHEN** the rename and migration have been applied
- **THEN** a VNG import SHALL be re-run and its result diffed
- **AND** a passing test suite SHALL NOT be accepted in place of that run

### Requirement: Schema renames SHALL move atomically with the properties that reference them

A schema name and every `$ref` to it SHALL be renamed in one commit, because reference
resolution is instance-global and a dangling slug can bind to another app's schema
instead of failing.

#### Scenario: A schema and its referring properties move together

- **WHEN** a schema such as `contactpersoon` or `organisatie` is renamed
- **THEN** every property referencing it SHALL be renamed in the same commit
- **AND** the rename SHALL NOT be staged across commits

#### Scenario: Reference resolution is asserted to stay within the app

- **WHEN** the rename has landed
- **THEN** each reference SHALL be asserted to resolve to a softwarecatalog schema
- **AND** resolving to a same-named schema owned by another app SHALL be treated as a defect

### Requirement: Committed debug scripts SHALL be deleted rather than renamed

Debug scripts carrying Dutch names SHALL be removed from the repository rather than
migrated into the English vocabulary.

#### Scenario: A root-level debug script is removed

- **WHEN** the rename sweep encounters a committed debug script named for a Dutch concept
- **THEN** the script SHALL be deleted
- **AND** it SHALL NOT be renamed and retained
