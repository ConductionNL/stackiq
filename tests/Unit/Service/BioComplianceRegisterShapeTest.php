<?php

/**
 * Register-shape tests for bio-compliance-assessment.
 *
 * Asserts the additive schema changes are well-formed and OPTIONAL (so an
 * import over existing data is non-destructive — existing compliancy and
 * module objects without the new fields stay valid), that the new
 * bioMaatregel reference catalog is wired into the voorzieningen register
 * and seeded, and that the dpia-review-overdue notification rule is
 * declared in the canonical x-openregister-notifications dialect.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the softwarecatalogus register file shape for the BIO compliance change.
 */
class BioComplianceRegisterShapeTest extends TestCase {

	/**
	 * The decoded register file, loaded once in setUp().
	 *
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
		$this->assertFileExists(filename: $path);
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray(actual: $decoded, message: 'register file must be valid JSON');
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
		$this->assertArrayHasKey(key: $slug, array: $schemas, message: "schema $slug must exist");
		return $schemas[$slug];
	}//end schema()

	/**
	 * The bioMaatregel reference catalog schema exists with the required fields.
	 *
	 * @return void
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog
	 */
	public function testBioMaatregelSchemaExists(): void {
		$bioMeasure = $this->schema(slug: 'bioMaatregel');
		$props = $bioMeasure['properties'] ?? [];

		foreach (['code', 'name', 'omschrijving', 'thema', 'bioVersion', 'bbnNiveau', 'bron'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $props, message: "bioMaatregel must declare $field");
		}

		$this->assertSame(expected: ['BBN1', 'BBN2', 'BBN3'], actual: $props['bbnNiveau']['items']['enum'] ?? null);
		$this->assertContains(needle: 'public', haystack: $bioMeasure['authorization']['read'] ?? []);
	}//end testBioMaatregelSchemaExists()

	/**
	 * The bioMaatregel schema is wired into the voorzieningen register (schemas list +
	 * magicMapping configuration), not just declared in components.schemas.
	 *
	 * @return void
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog
	 */
	public function testBioMaatregelIsRegisteredInVoorzieningen(): void {
		$voorzieningen = $this->register['components']['registers']['voorzieningen'] ?? [];
		$this->assertContains(needle: 'bioMaatregel', haystack: $voorzieningen['schemas'] ?? []);
		$this->assertArrayHasKey(key: 'bioMaatregel', array: $voorzieningen['configuration']['schemas'] ?? []);
		$this->assertTrue(condition: $voorzieningen['configuration']['schemas']['bioMaatregel']['autoCreateTable'] ?? false);
	}//end testBioMaatregelIsRegisteredInVoorzieningen()

	/**
	 * The bioMaatregel catalog is seeded with BIO 2.0 measures carrying a slug
	 * (required for the importer's idempotency-by-slug check).
	 *
	 * @return void
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog
	 */
	public function testBioMaatregelSeedDataExists(): void {
		$seedObjects = $this->register['x-openregister']['seedData']['objects']['bioMaatregel'] ?? [];
		$this->assertNotEmpty(actual: $seedObjects, message: 'bioMaatregel seed data must not be empty');

		foreach ($seedObjects as $object) {
			$this->assertArrayHasKey(key: 'slug', array: $object, message: 'each seed object needs a slug for idempotent re-import');
			$this->assertArrayHasKey(key: 'code', array: $object);
			$this->assertArrayHasKey(key: 'name', array: $object);
		}
	}//end testBioMaatregelSeedDataExists()

	/**
	 * The compliancy schema gains an optional bioMaatregel relation, parallel to
	 * standaardversie, without adding it to `required`.
	 *
	 * @return void
	 * @spec   openspec/specs/module-compliance-assessment/spec.md#requirement-compliance-records-link-modules-to-standard-versions-with-evidence
	 */
	public function testCompliancyHasOptionalBioMaatregelRelation(): void {
		$compliancy = $this->schema(slug: 'compliancy');
		$props = $compliancy['properties'] ?? [];

		$this->assertArrayHasKey(key: 'bioMaatregel', array: $props);
		$this->assertSame(expected: 'related-object', actual: $props['bioMaatregel']['objectConfiguration']['handling'] ?? null);
		$this->assertStringContainsString(needle: 'bioMaatregel', haystack: $props['bioMaatregel']['$ref'] ?? '');

		$required = $compliancy['required'] ?? [];
		$this->assertNotContains(needle: 'bioMaatregel', haystack: $required);
		$this->assertNotContains(needle: 'standardVersion', haystack: $required);
	}//end testCompliancyHasOptionalBioMaatregelRelation()

	/**
	 * The module schema gains the six optional BBN/DPIA/verwerkingsregister fields.
	 *
	 * @return void
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-each-application-records-a-bbn-level
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-each-application-tracks-dpia-status-and-review-dates
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-application-references-its-register-van-verwerkingen-entry
	 */
	public function testModuleHasOptionalBioFields(): void {
		$module = $this->schema(slug: 'module');
		$props = $module['properties'] ?? [];

		$newFields = [
			'bbnLevel',
			'dpiaStatus',
			'dpiaDate',
			'dpiaNextAssessment',
			'dpiaDocumentRef',
			'verwerkingsregisterRef',
		];

		$required = $module['required'] ?? [];
		foreach ($newFields as $field) {
			$this->assertArrayHasKey(key: $field, array: $props, message: "module must declare $field");
			$this->assertNotContains(needle: $field, haystack: $required, message: "$field must stay optional");
		}

		$this->assertSame(expected: ['BBN1', 'BBN2', 'BBN3'], actual: $props['bbnLevel']['enum'] ?? null);
		$this->assertTrue(condition: $props['bbnLevel']['facetable'] ?? false);
		$this->assertSame(expected: ['not required', 'required', 'executed'], actual: $props['dpiaStatus']['enum'] ?? null);
		$this->assertSame(expected: 'date', actual: $props['dpiaDate']['format'] ?? null);
		$this->assertSame(expected: 'date', actual: $props['dpiaNextAssessment']['format'] ?? null);
	}//end testModuleHasOptionalBioFields()

	/**
	 * The dpia-review-overdue rule is declared on module in the canonical dialect:
	 * a scheduled trigger, `equals`/`withinNext` filter operators, plural
	 * channels/recipients arrays, and nl/en subjects.
	 *
	 * @return void
	 * @spec   openspec/specs/bio-compliance-assessment/spec.md#requirement-overdue-dpia-reviews-trigger-a-notification
	 */
	public function testModuleDeclaresDpiaOverdueRule(): void {
		$rules = $this->schema(slug: 'module')['x-openregister-notifications'] ?? [];
		$this->assertArrayHasKey(key: 'dpia-review-overdue', array: $rules);

		$rule = $rules['dpia-review-overdue'];
		$this->assertSame(expected: 'scheduled', actual: $rule['trigger']['type']);

		$statusFilter = $rule['trigger']['filter']['dpiaStatus'] ?? [];
		$this->assertSame(expected: 'equals', actual: $statusFilter['operator'] ?? null);
		$this->assertSame(expected: 'executed', actual: $statusFilter['value'] ?? null);

		$dateFilter = $rule['trigger']['filter']['dpiaNextAssessment'] ?? [];
		$this->assertSame(expected: 'withinNext', actual: $dateFilter['operator'] ?? null);
		$this->assertSame(expected: 'P0D', actual: $dateFilter['value'] ?? null);

		$this->assertIsArray(actual: $rule['channels']);
		$this->assertContains(needle: 'nc-notification', haystack: $rule['channels']);
		$this->assertContains(needle: 'email', haystack: $rule['channels']);

		$this->assertIsArray(actual: $rule['recipients']);
		$kinds = array_column($rule['recipients'], 'kind');
		$this->assertContains(needle: 'groups', haystack: $kinds);
		$this->assertContains(needle: 'object-acl', haystack: $kinds);

		$this->assertArrayHasKey(key: 'nl', array: $rule['subject']);
		$this->assertArrayHasKey(key: 'en', array: $rule['subject']);
	}//end testModuleDeclaresDpiaOverdueRule()
}//end class
