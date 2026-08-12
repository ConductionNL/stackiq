# English Vocabulary Migration

Adoption of the ratified fleet English-vocabulary decision for this app's stored
data. The decision itself is owned by `hydra/openspec/changes/fleet-english-vocabulary`;
this spec records only what SoftwareCatalog must do to its own rows.

## Why a migration is required at all

OpenRegister does not store an object as a JSON blob keyed by property name.
Each schema property is a real, snake_cased **column** in the per-schema shard
table `oc_openregister_table_{register}_{schema}`. On schema sync MagicMapper
ADDS a column when the snake_cased name is absent and it never renames — there
is no `RENAME COLUMN` anywhere in openregister.

A register-only rename therefore leaves the data in the Dutch column while every
read looks at the English one and finds null: no error, no data loss, and
invisible to a suite that asserts against fixtures rather than migrated rows.

## Requirements

### Requirement: Renaming a stored property ships a data migration

Every rename of a property that OpenRegister materialises as a column SHALL ship
in the same change as a repair step that moves the stored values, or with
evidence recorded in the change that no rows exist.

#### Scenario: A property rename lands without its migration

<!-- @e2e exclude covered by tests/Unit/Repair/RenameDutchCatalogColumnsTest.php::testMatchesAnOrdinaryShard and ::testDoesNotMatchDerivedOrNonShardTables — this is a review-time and upgrade-time rule about which shard tables a repair step selects; it has no browser surface to drive, so a Playwright test could only re-assert the unit test through a slower harness -->

- **WHEN** a register fragment renames a property that has a materialised column
- **THEN** the change SHALL NOT be merged until a repair step covers that column
  or the change records a measured zero-row count for it

### Requirement: Externally-standardised schemas are exempt

The migration SHALL NOT touch schemas whose property names are the wire format
of an external standard, and SHALL resolve that exempt set at runtime rather
than assuming per-install ids.

The `element`, `relation` and `view` schemas carry the GEMMA/GGM architecture
model imported from VNG. Their `toelichting`, `bron`, `ggm-*` and `gemma-*`
properties are that import's field names, exempt under the fleet rule as
external standard names inside the adapter layer.

#### Scenario: The exempt set cannot be resolved

<!-- @e2e exclude covered by tests/Unit/Repair/RenameDutchCatalogColumnsTest.php::testDoesNotMigrateWireExemptSchemas and ::testWireSchemasAreExempt — the fail-closed path is reached only when a runtime id lookup fails during an upgrade, a state no browser session can induce -->

- **WHEN** the repair step cannot resolve the ids of the exempt schemas
- **THEN** it SHALL fail closed and migrate nothing, rather than risk rewriting
  the import contract

### Requirement: Ambiguous renames are refused, never merged

The migration SHALL refuse to migrate a column when two source columns in one
table target the same destination name, and SHALL log the refusal.

`beschrijving`, `beschrijving_lang` and `omschrijving` all mean `description`.
They do not co-occur in any schema today, but a later fragment could introduce a
pair, and a silent merge would destroy one of the two values.

#### Scenario: Two Dutch columns target one English name

<!-- @e2e exclude covered by tests/Unit/Repair/RenameDutchCatalogColumnsTest.php::testRefusesAmbiguousRename and ::testSingleSourceIsNotACollision — the collision is a property of the column set at upgrade time; reproducing it through the UI would mean shipping a deliberately broken schema to a live instance -->

- **WHEN** a shard table holds both `beschrijving` and `beschrijving_lang`
- **THEN** the step SHALL migrate neither and SHALL log the table, both sources,
  and the destination

### Requirement: The migration is non-destructive and idempotent

The migration SHALL leave every original column readable and SHALL be safe to
re-run.

#### Scenario: The English column already exists and is empty

<!-- @e2e exclude covered by tests/Unit/Repair/RenameDutchCatalogColumnsTest.php::testEveryDestinationIsSnakeCase, with the back-fill branch asserted there against a synthetic column set — the branch is chosen by DDL state during an upgrade, which a browser cannot observe or control -->

- **WHEN** MagicMapper has already added the English column before the step runs
- **THEN** the step SHALL copy the values across and SHALL leave the Dutch column
  in place, so the change remains reversible and a second run is a no-op
