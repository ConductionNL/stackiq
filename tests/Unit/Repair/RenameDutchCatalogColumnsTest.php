<?php

/**
 * Unit tests for RenameDutchCatalogColumns.
 *
 * Covers the two decisions that determine what the migration touches — which
 * shard tables are in scope, and when a rename is refused — plus the exemption
 * that keeps the step off the GEMMA/ArchiMate import.
 *
 * The DDL/DML paths are deliberately not unit-tested: they need a live
 * database. What IS testable in isolation is the logic deciding which tables
 * and columns are in scope, and that is what these tests pin.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Repair;

use OCA\SoftwareCatalog\Repair\RenameDutchCatalogColumns;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\SoftwareCatalog\Repair\RenameDutchCatalogColumns
 */
class RenameDutchCatalogColumnsTest extends TestCase {
	/**
	 * The step under test.
	 *
	 * @var RenameDutchCatalogColumns
	 */
	private RenameDutchCatalogColumns $step;

	/**
	 * Build the step WITHOUT running its constructor, then inject a logger.
	 *
	 * The constructor is skipped because mocking IDBConnection drags in
	 * Doctrine types this app's unit environment does not install.
	 *
	 * $logger IS still required, though: hasCollision() logs when it refuses an
	 * ambiguous rename, and a readonly promoted property left uninitialised
	 * throws "must not be accessed before initialization" the moment that path
	 * runs. An earlier version of this file skipped the constructor and set
	 * nothing; the collision test then errored in CI for exactly that reason,
	 * while the local standalone check of the same logic passed — because it
	 * exercised the algorithm as a free function, with no object state at all.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$class = new ReflectionClass(RenameDutchCatalogColumns::class);
		$this->step = $class->newInstanceWithoutConstructor();

		$logger = $class->getProperty('logger');
		$logger->setAccessible(true);
		$logger->setValue($this->step, new NullLogger());

	}//end setUp()

	/**
	 * Invoke a private method on the step.
	 *
	 * @param string $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$m = new ReflectionMethod(RenameDutchCatalogColumns::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->step, $args);
	}//end call()

	/**
	 * Read a private constant off the step.
	 *
	 * @param string $name Constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name) {
		return (new ReflectionClass(RenameDutchCatalogColumns::class))->getConstant($name);
	}//end constant()

	/**
	 * A shard of this register with a non-exempt schema is migrated.
	 *
	 * @return void
	 */
	public function testMatchesAnOrdinaryShard(): void {
		self::assertTrue(
			$this->call('isMigratableShard', ['oc_openregister_table_13_50', 'openregister_table_13_', []])
		);

	}//end testMatchesAnOrdinaryShard()

	/**
	 * A wire-exempt schema is NOT migrated.
	 *
	 * This is the heart of the step. Schemas 44 (`element`), 49 (`relation`)
	 * and 45 (`view`) hold the GEMMA/GGM model imported from VNG, and 46
	 * (`model`) / 48 (`property-definition`) the ArchiMate Open Exchange
	 * containers. Their property names ARE the import's wire format. Migrating
	 * them would rewrite the import contract, and the symptom would be a GEMMA
	 * re-import silently writing nulls.
	 *
	 * @return void
	 */
	public function testDoesNotMigrateWireExemptSchemas(): void {
		$marker = 'openregister_table_13_';
		$excluded = [44, 45, 46, 48, 49];

		foreach ([44, 45, 46, 48, 49] as $id) {
			self::assertFalse(
				$this->call('isMigratableShard', ["oc_openregister_table_13_$id", $marker, $excluded]),
				"Schema $id is wire-exempt and must not be migrated"
			);
		}

		// A non-exempt neighbour in the same register still is.
		self::assertTrue(
			$this->call('isMigratableShard', ['oc_openregister_table_13_50', $marker, $excluded])
		);

	}//end testDoesNotMigrateWireExemptSchemas()

	/**
	 * A derived or non-shard table sharing the marker is left alone.
	 *
	 * This is what the digits-only suffix check guards. It is NOT what stops
	 * register 13 matching register 130 — the marker already ends in '_', so
	 * that collision cannot occur.
	 *
	 * @return void
	 */
	public function testDoesNotMatchDerivedOrNonShardTables(): void {
		$marker = 'openregister_table_13_';
		self::assertFalse($this->call('isMigratableShard', ['oc_openregister_table_13_50_backup', $marker, []]));
		self::assertFalse($this->call('isMigratableShard', ['oc_openregister_table_13_audit', $marker, []]));
		self::assertFalse($this->call('isMigratableShard', ['oc_openregister_registers', $marker, []]));

	}//end testDoesNotMatchDerivedOrNonShardTables()

	/**
	 * Two Dutch columns targeting one English name are refused, not merged.
	 *
	 * `beschrijving`, `beschrijving_lang` and `omschrijving` all mean
	 * `description`. They do not co-occur today, but a silent merge would
	 * destroy one of two values, so the step must migrate neither.
	 *
	 * @return void
	 */
	public function testRefusesAmbiguousRename(): void {
		$columns = ['description', 'beschrijving_lang', 'name'];
		self::assertTrue($this->call('hasCollision', ['tbl', $columns, 'description']));

	}//end testRefusesAmbiguousRename()

	/**
	 * A single source for a destination is not a collision.
	 *
	 * @return void
	 */
	public function testSingleSourceIsNotACollision(): void {
		$columns = ['beschrijving_kort', 'name'];
		self::assertFalse($this->call('hasCollision', ['tbl', $columns, 'short_description']));
		self::assertFalse($this->call('hasCollision', ['tbl', $columns, 'name']));

	}//end testSingleSourceIsNotACollision()

	/**
	 * The GEMMA and ArchiMate schemas are all listed as exempt.
	 *
	 * Listing `model` and `property-definition` is deliberate even though
	 * neither carries a column the map targets today: it makes a property added
	 * later exempt by default rather than migrated by omission.
	 *
	 * @return void
	 */
	public function testWireSchemasAreExempt(): void {
		$slugs = $this->constant('WIRE_SCHEMA_SLUGS');
		self::assertIsArray($slugs);
		foreach (['element', 'relation', 'view', 'model', 'property-definition'] as $slug) {
			self::assertContains($slug, $slugs, "$slug carries an external wire format and must be exempt");
		}

	}//end testWireSchemasAreExempt()

	/**
	 * Every destination is snake_case, never camelCase.
	 *
	 * MagicMapper stores `shortDescription` as `short_description`, and its
	 * de-duplication path DROPS a camelCase column whose snake_case twin
	 * exists — so a camelCase destination would be deleted on the next sync.
	 *
	 * @return void
	 */
	public function testEveryDestinationIsSnakeCase(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertIsArray($map);
		foreach ($map as $old => $new) {
			self::assertSame(
				strtolower($new),
				$new,
				"Destination '$new' (from '$old') must be snake_case, not camelCase"
			);
		}

	}//end testEveryDestinationIsSnakeCase()

	/**
	 * The step reports a human-readable name.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		self::assertNotSame('', $this->step->getName());

	}//end testGetName()

	// ── softwarecatalog#492: the ordering guard ────────────────────────────

	/**
	 * The guard's full truth table.
	 *
	 * Both halves are required, and each one alone is wrong in a different
	 * direction — so all four combinations are pinned rather than the two that
	 * happen to occur today.
	 *
	 * @return void
	 */
	public function testRenameIsSafeOnlyWhenTheRegisterHasMoved(): void {
		// The register has moved: English declared, Dutch gone. Follow it.
		self::assertTrue(
			$this->call('renameIsSafe', ['name', 'name', ['name', 'description']]),
			'The destination is declared and the source is not — the data should follow'
		);

		// TODAY's state: Dutch still declared, English declared nowhere.
		// This is the case that made #492 a data-loss bug.
		self::assertFalse(
			$this->call('renameIsSafe', ['name', 'name', ['name', 'description']]),
			'The register still declares the Dutch name — renaming would orphan the data'
		);

		// Ambiguous window: the register declares BOTH. Writes and reads could
		// land on different columns, so defer rather than guess.
		self::assertFalse(
			$this->call('renameIsSafe', ['name', 'name', ['name', 'name']]),
			'Both names declared is ambiguous, not a green light'
		);

		// The property was simply dropped: neither name is declared. There is
		// no destination to move to.
		self::assertFalse(
			$this->call('renameIsSafe', ['name', 'name', ['description']]),
			'Neither name declared — there is nothing to move the data into'
		);

	}//end testRenameIsSafeOnlyWhenTheRegisterHasMoved()

	/**
	 * An unreadable schema declares nothing, so nothing is migrated.
	 *
	 * `declaredColumnsOf()` returns null on failure and `run()` skips the whole
	 * table; this pins the adjacent case, where it returns an empty set.
	 *
	 * @return void
	 */
	public function testAnEmptyDeclaredSetMigratesNothing(): void {
		foreach ($this->constant('COLUMN_MAP') as $old => $new) {
			self::assertFalse(
				$this->call('renameIsSafe', [$old, $new, []]),
				"'$old' must not move when the schema declares nothing"
			);
		}

	}//end testAnEmptyDeclaredSetMigratesNothing()

	/**
	 * The column-name transform matches MagicMapper's, name for name.
	 *
	 * The comparison is only meaningful if both sides spell the name the same
	 * way. `beschrijvingKort` must become `beschrijving_kort`, or the guard
	 * would defer every rename forever — and a guard that always says no is as
	 * useless as one that always says yes, while being much harder to notice.
	 *
	 * @return void
	 */
	public function testSanitizeColumnNameMirrorsMagicMapper(): void {
		$cases = [
			'name' => 'name',
			'beschrijvingKort' => 'beschrijving_kort',
			'beschrijvingLang' => 'beschrijving_lang',
			'shortDescription' => 'short_description',
			'publicationDate' => 'publication_date',
			'depublicationDate' => 'depublication_date',
			'contactPerson' => 'contact_person',
			'property-definition' => 'property_definition',
		];

		foreach ($cases as $property => $expected) {
			self::assertSame(
				$expected,
				$this->call('sanitizeColumnName', [$property]),
				"'$property' must materialise as '$expected'"
			);
		}

	}//end testSanitizeColumnNameMirrorsMagicMapper()

	/**
	 * Against the register this repo actually ships, the step is a NO-OP.
	 *
	 * This is the regression test for softwarecatalog#492, and it is the one
	 * that would have caught it. The register still declares `naam`,
	 * `beschrijvingKort`, `contactpersoon`, `publicatiedatum` and friends on
	 * every in-scope schema, and declares none of the English destinations
	 * there — so every rename must defer.
	 *
	 * It is written against the SHIPPED FILE rather than a fixture on purpose.
	 * A fixture would pin what I believed the register said; the file pins what
	 * it says. When the register is renamed to English this test starts failing,
	 * which is the correct moment for a human to look — at which point the
	 * expectation flips to "these renames are now permitted", in the same PR
	 * that renames the register (decidesk#467's shape).
	 *
	 * @return void
	 */
	public function testShippedRegisterStillDeclaresDutchSoNothingMoves(): void {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		self::assertFileExists($path, 'softwarecatalogus_register.json must exist');

		$register = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($register);

		$schemas = ($register['components']['schemas'] ?? []);
		self::assertIsArray($schemas);
		// Positive control: the extraction must actually be finding schemas,
		// otherwise an empty loop below would pass for free.
		self::assertGreaterThan(10, count($schemas), 'the register extraction found no schemas');

		$wire = $this->constant('WIRE_SCHEMA_SLUGS');
		$map = $this->constant('COLUMN_MAP');

		$checked = 0;
		foreach ($schemas as $slug => $schema) {
			if (in_array($slug, $wire, true) === true) {
				continue;
			}

			$declared = [];
			foreach (array_keys(($schema['properties'] ?? [])) as $property) {
				$declared[] = $this->call('sanitizeColumnName', [(string)$property]);
			}

			foreach ($map as $old => $new) {
				if (in_array($old, $declared, true) === false) {
					continue;
				}

				$checked++;
				self::assertFalse(
					$this->call('renameIsSafe', [$old, $new, $declared]),
					"Schema '$slug' still declares '$old'; moving it to '$new' would orphan the data"
				);
			}
		}

		// Second positive control: if this were 0 the assertions above never
		// ran, and a silently empty loop is exactly how #492 shipped green.
		self::assertGreaterThan(
			20,
			$checked,
			'no Dutch column was inspected — the guard was not actually exercised'
		);

	}//end testShippedRegisterStillDeclaresDutchSoNothingMoves()
}//end class
