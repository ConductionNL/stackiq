<?php

/**
 * Register-shape tests for open-data-publishing.
 *
 * Asserts the additive `registratiestatus` moderation field on the organisatie
 * schema is present, optional, and enumerates the moderation states — so an
 * import over existing data is non-destructive (existing organisations without
 * the field stay valid) and the pending/active/rejected lifecycle is well-formed.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Validates the organisatie schema gains the moderation field.
 */
class OpenDataRegisterShapeTest extends TestCase {
	/**
	 * @var array<string,mixed>
	 */
	private array $organisation;

	/**
	 * Load the organisatie schema once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);
		$this->organisation = $decoded['components']['schemas']['organization'];
	}//end setUp()

	/**
	 * The moderation field is present, optional, and enumerates the states.
	 *
	 * @return void
	 */
	public function testOrganisatieHasModerationField(): void {
		$props = $this->organisation['properties'] ?? [];
		$this->assertArrayHasKey('registrationStatus', $props);

		$field = $props['registrationStatus'];
		$this->assertSame('string', $field['type']);
		$this->assertSame(['pending', 'active', 'rejected'], $field['enum']);

		// Optional: not in `required` (import-over-existing is non-destructive).
		$required = $this->organisation['required'] ?? [];
		$this->assertNotContains('registrationStatus', $required);
	}//end testOrganisatieHasModerationField()
}//end class
