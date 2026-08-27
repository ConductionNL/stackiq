<?php

/**
 * Tests for the stored-enum-value migration's map.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Repair;

use OCA\Stackiq\Repair\RenameDutchCatalogDecisions;
use OCA\Stackiq\Repair\RenameDutchCatalogValues;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The value map, checked against the schemas it is supposed to migrate.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Stackiq\Repair\RenameDutchCatalogValues
 * @covers \OCA\Stackiq\Repair\RenameDutchCatalogDecisions
 */
final class RenameDutchCatalogValuesTest extends TestCase {

	/**
	 * The shipped register, decoded once.
	 *
	 * @var array<string, mixed>
	 */
	private array $register;

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$this->register = (json_decode((string)file_get_contents($path), true) ?? []);

	}//end setUp()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testStepNamesItself(): void {
		$step = (new ReflectionClass(RenameDutchCatalogValues::class))->newInstanceWithoutConstructor();
		self::assertStringContainsString('value', strtolower($step->getName()));

	}//end testStepNamesItself()

	/**
	 * No OLD value is still declared in any enum.
	 *
	 * This is the check that matters. The schema edit and the data migration
	 * are two halves of one change, and they drift silently: if a Dutch value
	 * is still declared, the schema was not fully translated; the migration
	 * would then rewrite rows to a value the schema rejects.
	 *
	 * @return void
	 */
	public function testNoMappedOldValueIsStillDeclared(): void {
		$declared = [];
		$collect = static function ($node, ?string $prop) use (&$collect, &$declared): void {
			if (is_array($node) === false) {
				return;
			}

			foreach ($node as $key => $value) {
				if ($key === 'properties' && is_array($value) === true) {
					foreach ($value as $name => $def) {
						$collect($def, (string)$name);
					}

					continue;
				}

				if ($key === 'enum' && is_array($value) === true && $prop !== null) {
					foreach ($value as $member) {
						$declared[$prop][] = $member;
					}

					continue;
				}

				$collect($value, $prop);
			}
		};

		$collect($this->register, null);

		foreach (RenameDutchCatalogValues::VALUE_MAP as $property => $values) {
			foreach (array_keys($values) as $old) {
				self::assertNotContains(
					$old,
					($declared[$property] ?? []),
					sprintf("'%s' is still declared on '%s' — the schema edit and this migration disagree", $old, $property)
				);
			}
		}

	}//end testNoMappedOldValueIsStillDeclared()

	/**
	 * Every NEW value is declared, so the migration cannot write an orphan.
	 *
	 * @return void
	 */
	public function testEveryNewValueIsDeclared(): void {
		$declared = [];
		$raw = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json');
		foreach (RenameDutchCatalogValues::VALUE_MAP as $values) {
			foreach ($values as $new) {
				$declared[$new] = str_contains($raw, '"' . $new . '"');
			}
		}

		foreach ($declared as $new => $present) {
			self::assertTrue($present, sprintf("'%s' is written by the migration but declared nowhere", $new));
		}

	}//end testEveryNewValueIsDeclared()

	/**
	 * The map's property names snake down to the columns it will UPDATE.
	 *
	 * A column name that does not match what MagicMapper materialised makes
	 * the migration a silent no-op — it finds no column and rewrites nothing.
	 *
	 * @return void
	 */
	public function testPropertiesSnakeToRealColumnNames(): void {
		$decisions = new RenameDutchCatalogDecisions();

		self::assertSame('registered_by', $decisions->sanitizeColumnName('registeredBy'));
		self::assertSame('data_exchange_direction', $decisions->sanitizeColumnName('dataExchangeDirection'));
		self::assertSame('status', $decisions->sanitizeColumnName('status'));

		foreach (array_keys(RenameDutchCatalogValues::VALUE_MAP) as $property) {
			$column = $decisions->sanitizeColumnName($property);
			self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $column, $property);
		}

	}//end testPropertiesSnakeToRealColumnNames()
	/**
	 * A table only gets the rewrites for columns it actually has.
	 *
	 * Shard tables are per-schema, so most carry only a few of the mapped
	 * columns — and an UPDATE against a column the table lacks is an error, not
	 * a no-op.
	 *
	 * @return void
	 */
	public function testPlannedRewritesSkipColumnsTheTableLacks(): void {
		$decisions = new RenameDutchCatalogDecisions();

		$planned = $decisions->plannedRewrites(
			['costPeriod' => ['Maandelijks' => 'Monthly', 'Jaarlijks' => 'Annually']],
			['cost_period', 'name']
		);
		self::assertCount(2, $planned);
		self::assertSame('cost_period', $planned[0]['column']);
		self::assertSame('Maandelijks', $planned[0]['old']);
		self::assertSame('Monthly', $planned[0]['new']);

		$none = $decisions->plannedRewrites(
			['costPeriod' => ['Maandelijks' => 'Monthly']],
			['name']
		);
		self::assertSame([], $none, 'a column the table lacks yields no work');

		self::assertSame([], $decisions->plannedRewrites([], ['cost_period']));

	}//end testPlannedRewritesSkipColumnsTheTableLacks()

	/**
	 * The shipped map plans real work against a table that has its columns.
	 *
	 * @return void
	 */
	public function testShippedMapPlansWorkForKnownColumns(): void {
		$decisions = new RenameDutchCatalogDecisions();
		$columns = array_map(
			static fn (string $p): string => (new RenameDutchCatalogDecisions())->sanitizeColumnName($p),
			array_keys(RenameDutchCatalogValues::VALUE_MAP)
		);

		$planned = $decisions->plannedRewrites(RenameDutchCatalogValues::VALUE_MAP, $columns);

		$expected = array_sum(array_map('count', array_values(RenameDutchCatalogValues::VALUE_MAP)));
		self::assertCount($expected, $planned);

	}//end testShippedMapPlansWorkForKnownColumns()
}//end class
