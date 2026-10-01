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
 * Asserts that the contacts link is optional and that the seeds create each
 * organisation once, with the organisation schema's required fields.
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
	 * Seeds name an organisation by reference, never inline, and each one is seeded exactly once with the required fields.
	 *
	 * OpenRegister creates a NEW object for every inline occurrence of a related
	 * object (SaveObject::cascadeSingleObject), so an organisation nested in two
	 * seed usages became two rows on every import. A `@ref:organization:<slug>`
	 * token resolves to the one seeded organisation (ImportHandler
	 * resolveSeedReferenceTokens), and a re-import reuses its uuid.
	 *
	 * @return void
	 */
	public function testSeedsReferenceEachOrganisationOnce(): void {
		$register = $this->register();
		$schemas  = $register['components']['schemas'];
		$required = $schemas['organization']['required'];
		$typeEnum = $schemas['organization']['properties']['type']['enum'];

		$seeded = [];
		foreach ($register['components']['objects'] as $object) {
			if ($object['@self']['schema'] !== 'organization') {
				continue;
			}

			$slug = $object['@self']['slug'];
			$this->assertArrayNotHasKey($slug, $seeded, $slug . ' is seeded once');
			foreach ($required as $field) {
				$this->assertArrayHasKey($field, $object, $slug);
			}

			$this->assertContains($object['type'], $typeEnum, $slug);
			$seeded[$slug] = true;
		}

		$references = 0;
		foreach ($register['components']['objects'] as $object) {
			$schema = $schemas[$object['@self']['schema']];
			foreach ($schema['properties'] as $name => $property) {
				$ref = $property['$ref'] ?? ($property['items']['$ref'] ?? null);
				if ($ref !== '#/components/schemas/organization' || isset($object[$name]) === false) {
					continue;
				}

				$values = $object[$name];
				if (is_array($values) === false || array_is_list($values) === false) {
					$values = [$values];
				}

				foreach ($values as $value) {
					$this->assertIsString($value, $object['@self']['slug'] . '.' . $name . ' names an organisation by reference, not inline');
					$this->assertStringStartsWith('@ref:organization:', $value);
					$this->assertArrayHasKey(substr($value, strlen('@ref:organization:')), $seeded, $value . ' is a seeded organisation');
					$references++;
				}
			}
		}

		$this->assertGreaterThanOrEqual(5, $references);
		$this->assertCount(5, $seeded);
	}//end testSeedsReferenceEachOrganisationOnce()
}//end class
