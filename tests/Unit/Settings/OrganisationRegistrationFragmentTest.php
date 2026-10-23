<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-ORR-002: the organisation keeps what the registration form asks; the contact is for signed-in users.
 */
final class OrganisationRegistrationFragmentTest extends TestCase {
	/**
	 * @return array<string, mixed> The merged organisation schema.
	 */
	private function organisation(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $keep->invoke(null, $before, $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true)));
		}

		return $merged['components']['schemas']['organization'];
	}//end organisation()

	public function testTheFormsFieldsExistAndTheContactIsNotPublic(): void {
		$organisation = $this->organisation();

		foreach (['name', 'type', 'oin', 'website', 'registrationNumber', 'registrationContactName', 'registrationContactEmail', 'registrationStatus'] as $field) {
			self::assertArrayHasKey($field, $organisation['properties'], $field);
		}

		self::assertSame(['authenticated'], $organisation['properties']['registrationContactName']['authorization']['read']);
		self::assertSame(['authenticated'], $organisation['properties']['registrationContactEmail']['authorization']['read']);
		self::assertSame(['authenticated'], $organisation['properties']['registrationNumber']['authorization']['read']);
		self::assertSame(1, preg_match('/' . $organisation['properties']['registrationNumber']['pattern'] . '/', '91827364'));
		self::assertContains('pending', $organisation['properties']['registrationStatus']['enum']);
		self::assertTrue(version_compare($organisation['version'], '0.5.5', '>='));
	}//end testTheFormsFieldsExistAndTheContactIsNotPublic()
}//end class
