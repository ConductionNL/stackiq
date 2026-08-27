<?php

/**
 * Register-shape tests for portfolio-rationalization-time.
 *
 * Asserts the three additive `gebruik` schema properties are well-formed
 * and OPTIONAL (so an import over existing data is non-destructive), and
 * that `cloudDienstverleningsmodel` (the existing Hosting field) is reused
 * for the cloud-transition metric rather than a new deployment-model field
 * being introduced (design.md Decision 1).
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-time-classification-fields-are-recorded-on-the-gebruik-schema
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the softwarecatalogus register file shape for the TIME change.
 */
class PortfolioTimeRegisterShapeTest extends TestCase {
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
	 * Fetch the gebruik schema definition.
	 *
	 * @return array<string,mixed>
	 */
	private function gebruikSchema(): array {
		$schemas = $this->register['components']['schemas'] ?? [];
		$this->assertArrayHasKey('usage', $schemas);
		return $schemas['usage'];
	}//end gebruikSchema()

	/**
	 * The gebruik schema gains exactly the three TIME properties, all
	 * optional (not in `required`).
	 *
	 * @return void
	 */
	public function testGebruikHasOptionalTimeFields(): void {
		$usage = $this->gebruikSchema();
		$props = $usage['properties'] ?? [];

		$this->assertArrayHasKey('timeClassification', $props);
		$this->assertArrayHasKey('timeRationale', $props);
		$this->assertArrayHasKey('timeReviewDate', $props);

		$required = $usage['required'] ?? [];
		$this->assertNotContains('timeClassification', $required);
		$this->assertNotContains('timeRationale', $required);
		$this->assertNotContains('timeReviewDate', $required);
	}//end testGebruikHasOptionalTimeFields()

	/**
	 * `timeClassification` matches the `status` field's enum-on-string
	 * convention (type: string, enum, title) with the four canonical
	 * Gartner TIME values.
	 *
	 * @return void
	 */
	public function testTimeClassificationIsEnumOnString(): void {
		$props = $this->gebruikSchema()['properties'];
		$field = $props['timeClassification'];

		$this->assertSame('string', $field['type']);
		$this->assertSame(
			['Tolerate', 'Invest', 'Migrate', 'Eliminate'],
			$field['enum']
		);
		$this->assertArrayHasKey('title', $field);
	}//end testTimeClassificationIsEnumOnString()

	/**
	 * `timeReviewDate` is a `date`-format string field, matching the
	 * existing phase-date fields' shape.
	 *
	 * @return void
	 */
	public function testTimeReviewDateIsDateField(): void {
		$props = $this->gebruikSchema()['properties'];

		$this->assertSame('string', $props['timeReviewDate']['type']);
		$this->assertSame('date', $props['timeReviewDate']['format']);
	}//end testTimeReviewDateIsDateField()

	/**
	 * `timeRationale` is a free-text string field.
	 *
	 * @return void
	 */
	public function testTimeRationaleIsStringField(): void {
		$props = $this->gebruikSchema()['properties'];

		$this->assertSame('string', $props['timeRationale']['type']);
	}//end testTimeRationaleIsStringField()

	/**
	 * design.md Decision 1: no competing `deploymentModel` field was added —
	 * the existing `cloudDienstverleningsmodel` (Hosting) field remains the
	 * sole deployment-model source, still facetable.
	 *
	 * @return void
	 */
	public function testNoCompetingDeploymentModelFieldWasAdded(): void {
		$props = $this->gebruikSchema()['properties'];

		$this->assertArrayNotHasKey('deploymentModel', $props);
		$this->assertArrayHasKey('cloudDienstverleningsmodel', $props);
		$this->assertTrue($props['cloudDienstverleningsmodel']['facetable']);
	}//end testNoCompetingDeploymentModelFieldWasAdded()
}//end class
