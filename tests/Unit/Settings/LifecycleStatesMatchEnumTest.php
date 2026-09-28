<?php

/**
 * Every lifecycle state a register schema declares is a value of its status enum.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/connections-catalogue-pages/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-004-the-connection-schema-offers-transitions-and-a-picker-that-match-its-data
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE DEFECT UNDER TEST (stackiq#1140).
 *
 * #520 translated the status enums to English and migrated the stored rows
 * (lib/Repair/RenameDutchCatalogValues.php), but the x-openregister-lifecycle
 * blocks of usage, catalogContract, connection and moduleVersion kept the
 * Dutch state names. A transition whose `from` names a value no row can hold
 * is never offered, and an `initial` outside the enum writes an invalid
 * value. Nothing raises an error, so the only instrument is this walk: every
 * lifecycle in both shipped register files, every state it names, against
 * the enum of the field it drives.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class LifecycleStatesMatchEnumTest extends TestCase {

	/**
	 * The register files that ship schemas with lifecycles.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function registerFiles(): array {
		return [
			'softwarecatalogus_register.json' => ['softwarecatalogus_register.json'],
			'stackiq_mock_register.json' => ['stackiq_mock_register.json'],
		];
	}//end registerFiles()

	/**
	 * Every lifecycle state is a value of the driven field's enum.
	 *
	 * @param string $file The register file under lib/Settings.
	 *
	 * @return void
	 */
	#[DataProvider('registerFiles')]
	public function testEveryLifecycleStateIsAnEnumValue(string $file): void {
		$path = __DIR__ . '/../../../lib/Settings/' . $file;
		$this->assertFileExists($path);
		$register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($register, $file . ' must be valid JSON');

		$schemas = $register['components']['schemas'] ?? [];
		$lifecycles = 0;
		$outside = [];

		foreach ($schemas as $slug => $schema) {
			$lifecycle = $schema['configuration']['x-openregister-lifecycle'] ?? null;
			if (is_array($lifecycle) === false) {
				continue;
			}

			$lifecycles++;
			$field = $lifecycle['field'] ?? 'status';
			$enum = $schema['properties'][$field]['enum'] ?? null;
			$this->assertIsArray($enum, "$file: $slug.$field drives a lifecycle and must declare an enum");

			foreach ($this->statesOf($lifecycle) as $where => $state) {
				if (in_array($state, $enum, true) === false) {
					$outside[] = "$slug $where '$state'";
				}
			}
		}

		// Positive control: the walk must actually find lifecycles, or an
		// empty result would read as green.
		$this->assertGreaterThanOrEqual(5, $lifecycles, "$file: expected the five schema lifecycles");
		$this->assertSame(
			[],
			$outside,
			"$file: lifecycle states outside their schema's status enum (no row can hold them, so no transition is offered)"
		);
	}//end testEveryLifecycleStateIsAnEnumValue()

	/**
	 * List every state a lifecycle names, keyed by where it appears.
	 *
	 * @param array $lifecycle The x-openregister-lifecycle block.
	 *
	 * @return array<string, string>
	 */
	private function statesOf(array $lifecycle): array {
		$states = [];
		if (isset($lifecycle['initial']) === true) {
			$states['initial'] = (string)$lifecycle['initial'];
		}

		foreach ((array)($lifecycle['final'] ?? []) as $index => $state) {
			$states['final[' . $index . ']'] = (string)$state;
		}

		foreach (($lifecycle['transitions'] ?? []) as $name => $transition) {
			foreach ((array)($transition['from'] ?? []) as $index => $state) {
				$states[$name . '.from[' . $index . ']'] = (string)$state;
			}

			if (isset($transition['to']) === true) {
				$states[$name . '.to'] = (string)$transition['to'];
			}
		}

		return $states;
	}//end statesOf()
}//end class
