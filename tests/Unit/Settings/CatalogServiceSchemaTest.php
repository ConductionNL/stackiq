<?php

/**
 * CatalogServiceSchemaTest
 *
 * A supplier's service (REQ-CSV-001): the `catalogService` schema requires a
 * name, a provider and a type, the type holds the six service types and may
 * hold several, and the Services page and an organisation's services widget
 * cite the requirement.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/landscape-services-and-module-versions/specs/catalogue-services/spec.md#requirement-a-suppliers-service-is-registered-over-one-or-more-applications-req-csv-001
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the catalogService schema and its pages.
 */
class CatalogServiceSchemaTest extends TestCase {

	/**
	 * The six service types REQ-CSV-001 names.
	 *
	 * @var array<int, string>
	 */
	private const SERVICE_TYPES = [
		'Functional management',
		'Application management',
		'Technical management',
		'Implementation support',
		'Training',
		'Licence reseller',
	];

	/**
	 * Decode a JSON file of the app.
	 *
	 * @param string $path Path from the app root.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		return json_decode(json: (string) file_get_contents(__DIR__ . '/../../../' . $path), associative: true);
	}//end json()

	/**
	 * Name, provider and type are required.
	 *
	 * @return void
	 */
	public function testNameProviderAndTypeAreRequired(): void {
		$schema = $this->json(path: 'lib/Settings/softwarecatalogus_register.json')['components']['schemas']['catalogService'];

		foreach (['name', 'provider', 'type'] as $field) {
			$this->assertContains(needle: $field, haystack: $schema['required']);
			$this->assertArrayHasKey(key: $field, array: $schema['properties']);
		}
	}//end testNameProviderAndTypeAreRequired()

	/**
	 * A service carries one or more of the six service types.
	 *
	 * @return void
	 */
	public function testTypeHoldsTheSixServiceTypes(): void {
		$type = $this->json(path: 'lib/Settings/softwarecatalogus_register.json')['components']['schemas']['catalogService']['properties']['type'];

		$this->assertSame(expected: 'array', actual: $type['type']);
		$this->assertSame(expected: self::SERVICE_TYPES, actual: $type['items']['enum']);
	}//end testTypeHoldsTheSixServiceTypes()

	/**
	 * The Services page and the organisation's services widget cite REQ-CSV-001.
	 *
	 * @return void
	 */
	public function testThePagesCiteTheRequirement(): void {
		$manifest = $this->json(path: 'src/manifest.json');
		$notes = [];
		foreach ($manifest['pages'] as $page) {
			if ($page['id'] === 'Diensten') {
				$notes['Diensten'] = ($page['_note'] ?? '');
			}

			foreach (($page['config']['widgets'] ?? []) as $widget) {
				if (($widget['id'] ?? '') === 'org-diensten') {
					$notes['org-diensten'] = ($widget['_note'] ?? '');
				}
			}
		}

		$this->assertCount(expectedCount: 2, haystack: $notes);
		foreach ($notes as $note) {
			$this->assertStringContainsString(needle: 'REQ-CSV-001', haystack: $note);
		}
	}//end testThePagesCiteTheRequirement()
}//end class
