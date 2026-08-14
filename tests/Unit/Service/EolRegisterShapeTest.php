<?php

/**
 * Register-shape tests for eol-feed-integration.
 *
 * Asserts the additive schema changes (`module.eolProductSlug`,
 * `moduleVersie.eolBron`, `moduleVersie.eolBijgewerktOp`) are well-formed
 * and OPTIONAL (so an import over existing data is non-destructive —
 * existing module/moduleVersie objects without the new fields stay valid).
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the softwarecatalogus register file shape for the EOL feed change.
 */
class EolRegisterShapeTest extends TestCase {

	/**
	 * @var array<string,mixed>
	 */
	private array $register;

	/**
	 * Load and decode the register file once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$this->assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, 'register file must be valid JSON');
		$this->register = $decoded;
	}//end setUp()

	/**
	 * Fetch a schema definition by slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string,mixed> The schema definition.
	 */
	private function schema(string $slug): array {
		$schemas = $this->register['components']['schemas'] ?? [];
		$this->assertArrayHasKey($slug, $schemas, "schema $slug must exist");
		return $schemas[$slug];
	}//end schema()

	/**
	 * The module schema gains the optional eolProductSlug mapping field.
	 *
	 * @return void
	 */
	public function testModuleHasOptionalEolProductSlugField(): void {
		$module = $this->schema('module');
		$props = $module['properties'] ?? [];

		$this->assertArrayHasKey('eolProductSlug', $props);
		$this->assertSame('string', $props['eolProductSlug']['type'] ?? null);

		// Optional: not listed in `required` (import-over-existing is non-destructive).
		$required = $module['required'] ?? [];
		$this->assertNotContains('eolProductSlug', $required);
	}//end testModuleHasOptionalEolProductSlugField()

	/**
	 * The moduleVersie schema gains the two optional provenance fields.
	 *
	 * @return void
	 */
	public function testModuleVersieHasOptionalProvenanceFields(): void {
		$moduleVersion = $this->schema('moduleVersie');
		$props = $moduleVersion['properties'] ?? [];

		$this->assertArrayHasKey('eolSource', $props);
		$this->assertArrayHasKey('eolUpdatedOn', $props);
		$this->assertSame('string', $props['eolSource']['type'] ?? null);
		$this->assertSame('date-time', $props['eolUpdatedOn']['format'] ?? null);

		// Optional: not listed in `required`.
		$required = $moduleVersion['required'] ?? [];
		$this->assertNotContains('eolSource', $required);
		$this->assertNotContains('eolUpdatedOn', $required);
	}//end testModuleVersieHasOptionalProvenanceFields()

	/**
	 * The moduleVersie schema still declares datumEindeOndersteuning — the
	 * field the matcher stamps and application-lifecycle-tracking already
	 * reads for EOL indicators/filters/roadmap/notification.
	 *
	 * @return void
	 */
	public function testModuleVersieStillDeclaresDatumEindeOndersteuning(): void {
		$props = $this->schema('moduleVersie')['properties'] ?? [];
		$this->assertArrayHasKey('dateEndSupport', $props);
		$this->assertSame('date', $props['dateEndSupport']['format'] ?? null);
	}//end testModuleVersieStillDeclaresDatumEindeOndersteuning()
}//end class
