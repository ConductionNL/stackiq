<?php

/**
 * Tests that the connection schema matches its own data: lifecycle, picker, name and facets.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/connections-catalogue-pages/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-004-the-connection-schema-offers-transitions-and-a-picker-that-match-its-data
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped register file, the one the repair step imports.
 */
class ConnectionSchemaTest extends TestCase {

	/**
	 * The connection schema as shipped.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$register = json_decode(
			(string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'),
			true
		);
		return $register['components']['schemas']['connection'];
	}//end schema()

	/**
	 * Every lifecycle state is a value of the status enum, so a stored row can move.
	 *
	 * @return void
	 */
	public function testLifecycleStatesAreStatusValues(): void {
		$schema    = $this->schema();
		$enum      = $schema['properties']['status']['enum'];
		$lifecycle = $schema['configuration']['x-openregister-lifecycle'];

		$states = array_merge([$lifecycle['initial']], $lifecycle['final']);
		foreach ($lifecycle['transitions'] as $transition) {
			$states = array_merge($states, $transition['from'], [$transition['to']]);
		}

		$this->assertSame([], array_values(array_diff(array_unique($states), $enum)));
		$this->assertSame('in use', $lifecycle['transitions']['release']['to']);
	}//end testLifecycleStatesAreStatusValues()

	/**
	 * The national provision picker asks for the GEMMA type as the GEMMA model spells it.
	 *
	 * @return void
	 */
	public function testTheProvisionPickerUsesTheGemmaSpelling(): void {
		$query = $this->schema()['properties']['nonMunicipalProvision']['objectConfiguration']['queryParams'];
		$this->assertSame('gemmaType=Buitengemeentelijke voorziening', $query);

		$gemma = (string) file_get_contents(__DIR__ . '/../../../lib/Settings/GEMMA_release.xml');
		$this->assertStringContainsString('Buitengemeentelijke voorziening', $gemma);
	}//end testTheProvisionPickerUsesTheGemmaSpelling()

	/**
	 * The name template names keys the schema has and maps values its enum holds.
	 *
	 * @return void
	 */
	public function testTheNameTemplateUsesCurrentKeysAndValues(): void {
		$schema   = $this->schema();
		$template = $schema['configuration']['objectNameField'];

		preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $template, $blocks);
		$this->assertNotEmpty($blocks[1]);
		foreach ($blocks[1] as $block) {
			$parts = array_map('trim', explode('|', $block));
			$this->assertArrayHasKey($parts[0], $schema['properties'], 'template key ' . $parts[0]);
			foreach (array_slice($parts, 1) as $filter) {
				if (str_starts_with($filter, 'map:') === true) {
					foreach (explode(',', substr($filter, 4)) as $pair) {
						$from = trim(explode('=', $pair)[0]);
						$this->assertContains($from, $schema['properties'][$parts[0]]['enum'], 'mapped value ' . $from);
					}

					continue;
				}

				$this->assertArrayHasKey($filter, $schema['properties'], 'fallback key ' . $filter);
			}
		}
	}//end testTheNameTemplateUsesCurrentKeysAndValues()

	/**
	 * Type, status and direction can be counted and filtered on the Connections page.
	 *
	 * @return void
	 */
	public function testTheListFiltersAreFacetable(): void {
		$properties = $this->schema()['properties'];
		foreach (['type', 'status', 'dataExchangeDirection'] as $key) {
			$this->assertTrue($properties[$key]['facetable'], $key . ' is facetable');
		}
	}//end testTheListFiltersAreFacetable()
}//end class
