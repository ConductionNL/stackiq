<?php

/**
 * The publication field rules, merged the way the app merges its register.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/publication-field-rules/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts the field rules, the usage and version read rules, and that no fragment lowers a version.
 */
class PublicationFieldRulesTest extends TestCase {

	/**
	 * Merge one fragment after another, as SettingsService::loadSettings does.
	 *
	 * @param array<string, mixed> $base      The register so far.
	 * @param array<string, mixed> $fragment  The fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function merge(array $base, array $fragment): array {
		$deep = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		return $keep->invoke(null, $base, $deep->invoke(null, $base, $fragment));
	}//end merge()

	/**
	 * The base register.
	 *
	 * @return array<string, mixed>
	 */
	private function base(): array {
		return json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
	}//end base()

	/**
	 * Every fragment merged in file name order.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$merged = $this->base();
		$files  = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$merged = self::merge(base: $merged, fragment: json_decode((string) file_get_contents($file), true));
		}

		return $merged;
	}//end register()

	/**
	 * Contacts, owners, service desk references and internal judgements read for signed-in users only.
	 *
	 * @return void
	 */
	public function testPrivateFieldsReadForSignedInUsersOnly(): void {
		$schemas = $this->register()['components']['schemas'];
		$private = [
			'module'         => [
				'contactPerson',
				'usages',
				'dpiaDocumentRef',
				'verwerkingsregisterRef',
				'externalId',
				'externalNumber',
				'externalKey',
				'externalCreatedAt',
				'externalModifiedAt',
			],
			'moduleVersion'  => ['usages'],
			'catalogService' => ['contactPerson'],
			'connection'     => ['provider', 'serviceDeskRecordId', 'serviceDeskUrl', 'longDescription'],
			'usage'          => [
				'businessOwner',
				'technicalOwner',
				'contactPerson',
				'timeClassification',
				'businessValue',
				'installedVersion',
				'serviceDeskUrl',
			],
			'organization'   => ['contactsUid', 'xml', 'pki', 'registrationStatus', 'mergedInto'],
		];
		foreach ($private as $schema => $fields) {
			foreach ($fields as $field) {
				$this->assertArrayHasKey(key: $field, array: $schemas[$schema]['properties'], message: $schema . '.' . $field . ' exists');
				// Other fragments may add write rules (module.externalKey: update by admin only); the read rule is this one.
				$this->assertSame(
					expected: ['authenticated'],
					actual: $schemas[$schema]['properties'][$field]['authorization']['read'] ?? null,
					message: $schema . '.' . $field
				);
				$this->assertArrayHasKey(
					key: 'type',
					array: $schemas[$schema]['properties'][$field],
					message: $schema . '.' . $field . ' is a real property, not a rule on nothing'
				);
			}
		}

		foreach (['module', 'catalogService', 'usage', 'connection'] as $schema) {
			$this->assertArrayNotHasKey(
				key: 'authorization',
				array: $schemas[$schema]['properties']['publicationDate'] ?? [],
				message: $schema . '.publicationDate stays readable: the read rules match on it'
			);
		}
	}//end testPrivateFieldsReadForSignedInUsersOnly()

	/**
	 * The two fields that already had rules keep exactly those rules.
	 *
	 * @return void
	 */
	public function testExistingFieldRulesAreNotWidened(): void {
		$base   = $this->base()['components']['schemas'];
		$merged = $this->register()['components']['schemas'];

		$this->assertSame(
			expected: $base['usage']['properties']['interneAnnotation']['authorization'],
			actual: $merged['usage']['properties']['interneAnnotation']['authorization']
		);
		$this->assertSame(
			expected: $base['organization']['properties']['contactpersonen']['authorization'],
			actual: $merged['organization']['properties']['contactpersonen']['authorization']
		);
	}//end testExistingFieldRulesAreNotWidened()

	/**
	 * A usage keeps every organisation read rule and becomes public from its publication date.
	 *
	 * @return void
	 */
	public function testAUsageIsPublicFromItsPublicationDateAndOrganisationsKeepReading(): void {
		$before = $this->base()['components']['schemas']['usage']['authorization']['read'];
		$after  = $this->register()['components']['schemas']['usage']['authorization']['read'];

		foreach ($before as $rule) {
			$this->assertContains(needle: $rule, haystack: $after, message: 'an organisation read rule survived: ' . json_encode($rule));
		}

		$this->assertContains(
			needle: ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']], 'crossOrganisation' => true],
			haystack: $after
		);
		$this->assertCount(expectedCount: count($before) + 1, haystack: $after);
	}//end testAUsageIsPublicFromItsPublicationDateAndOrganisationsKeepReading()

	/**
	 * A version is public under the same condition as its module, its publication date, and signed-in users read every version.
	 *
	 * @return void
	 */
	public function testAVersionFollowsItsApplication(): void {
		$schemas = $this->register()['components']['schemas'];
		$module  = $schemas['module']['authorization']['read'];
		$version = $schemas['moduleVersion']['authorization']['read'];

		$this->assertContains(
			needle: ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']], 'crossOrganisation' => true],
			haystack: $module
		);
		$this->assertNotContains(
			needle: ['group' => 'public', 'match' => ['registeredBy' => 'Supplier'], 'crossOrganisation' => true],
			haystack: $module,
			message: 'an unpublished supplier module is not public'
		);
		$this->assertSame(
			expected: [
				'authenticated',
				['group' => 'public', 'match' => ['modulePublicationDate' => ['$lte' => '$now']], 'crossOrganisation' => true],
			],
			actual: $version
		);
		$this->assertNotContains(needle: 'public', haystack: $version, message: 'no unconditional public read is left');
		$this->assertSame(
			expected: $schemas['module']['properties']['registeredBy']['enum'],
			actual: $schemas['moduleVersion']['properties']['moduleRegisteredBy']['enum']
		);
		$this->assertSame(expected: 'date-time', actual: $schemas['moduleVersion']['properties']['modulePublicationDate']['format']);
	}//end testAVersionFollowsItsApplication()

	/**
	 * The highest declared version wins, whatever the file names.
	 *
	 * @return void
	 */
	public function testAFragmentNeverLowersAVersion(): void {
		$base   = ['components' => ['schemas' => ['usage' => ['version' => '1.5.3', 'properties' => []]]]];
		$merged = self::merge(base: $base, fragment: ['components' => ['schemas' => ['usage' => ['version' => '1.5.5']]]]);
		$merged = self::merge(base: $merged, fragment: ['components' => ['schemas' => ['usage' => ['version' => '1.5.4']]]]);
		$this->assertSame(expected: '1.5.5', actual: $merged['components']['schemas']['usage']['version']);

		$schemas = $this->register()['components']['schemas'];
		$this->assertTrue(
			condition: version_compare($schemas['usage']['version'], '1.5.8', '>='),
			message: 'topdesk-cmdb-import.json bumps it past publication-field-rules.json; value-assessment.json, sorting last, does not lower it'
		);
		// Application-interfaces.json (connections-api-catalogue) adds connection.interface at 0.3.6; later fragments may raise it.
		$this->assertTrue(condition: version_compare($schemas['connection']['version'], '0.3.6', '>='));
		$this->assertSame(expected: '0.1.8', actual: $schemas['moduleVersion']['version']);
	}//end testAFragmentNeverLowersAVersion()
}//end class
