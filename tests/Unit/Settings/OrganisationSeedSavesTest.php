<?php

/**
 * The seed organisations a fresh install creates can be saved.
 *
 * OpenRegister creates a column NOT NULL for every property a schema lists
 * as required, and the app import writes seed objects without validation, so
 * a required property a seed leaves out fails the insert. contactsUid is a
 * link the contacts sync fills in after the record exists, so it can never
 * be required.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts that the contacts link is optional and that every organisation a
 * seed object creates carries the organisation schema's required fields.
 */
class OrganisationSeedSavesTest extends TestCase {

	/**
	 * The register as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		return json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
	}//end register()

	/**
	 * No schema requires the contacts link, in its required list or on the property.
	 *
	 * @return void
	 */
	public function testTheContactsLinkIsNeverRequired(): void {
		$schemas = $this->register()['components']['schemas'];
		$checked = 0;
		foreach ($schemas as $key => $schema) {
			if (isset($schema['properties']['contactsUid']) === false) {
				continue;
			}

			$checked++;
			$this->assertNotContains('contactsUid', $schema['required'] ?? [], $key);
			$this->assertNotTrue($schema['properties']['contactsUid']['required'] ?? false, $key);
		}

		$this->assertSame(2, $checked, 'organization and contactPerson both carry contactsUid');
	}//end testTheContactsLinkIsNeverRequired()

	/**
	 * Every organisation nested in a seed object carries all required organisation fields.
	 *
	 * @return void
	 */
	public function testEverySeededOrganisationCarriesTheRequiredFields(): void {
		$register = $this->register();
		$schemas  = $register['components']['schemas'];
		$required = $schemas['organization']['required'];
		$typeEnum = $schemas['organization']['properties']['type']['enum'];
		$this->assertNotEmpty($required);

		$organisations = [];
		foreach ($register['components']['objects'] as $object) {
			$schema = $schemas[$object['@self']['schema']];
			foreach ($schema['properties'] as $name => $property) {
				$ref = $property['$ref'] ?? ($property['items']['$ref'] ?? null);
				if ($ref !== '#/components/schemas/organization' || isset($object[$name]) === false) {
					continue;
				}

				$values = $object[$name];
				if (isset($values['slug']) === true) {
					$values = [$values];
				}

				foreach ($values as $organisation) {
					$organisations[] = $organisation;
				}
			}
		}

		$this->assertGreaterThanOrEqual(5, count($organisations));
		foreach ($organisations as $organisation) {
			foreach ($required as $field) {
				$this->assertArrayHasKey($field, $organisation, $organisation['slug']);
			}

			$this->assertContains($organisation['type'], $typeEnum, $organisation['slug']);
		}
	}//end testEverySeededOrganisationCarriesTheRequiredFields()
}//end class
