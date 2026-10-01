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
 * @spec openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md
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
			'module'         => ['contactPerson', 'usages', 'dpiaDocumentRef', 'verwerkingsregisterRef'],
			'moduleVersion'  => ['usages'],
			'catalogService' => ['contactPerson'],
			'connection'     => ['provider', 'serviceDeskRecordId', 'serviceDeskUrl', 'longDescription'],
			'usage'          => ['businessOwner', 'technicalOwner', 'contactPerson', 'timeClassification', 'businessValue', 'installedVersion', 'serviceDeskUrl'],
			'organization'   => ['contactsUid', 'xml', 'pki', 'registrationStatus', 'mergedInto'],
		];
		foreach ($private as $schema => $fields) {
			foreach ($fields as $field) {
				$this->assertArrayHasKey($field, $schemas[$schema]['properties'], $schema . '.' . $field . ' exists');
				$this->assertSame(['read' => ['authenticated']], $schemas[$schema]['properties'][$field]['authorization'] ?? null, $schema . '.' . $field);
				$this->assertArrayHasKey('type', $schemas[$schema]['properties'][$field], $schema . '.' . $field . ' is a real property, not a rule on nothing');
			}
		}

		foreach (['module', 'catalogService', 'usage', 'connection'] as $schema) {
			$this->assertArrayNotHasKey('authorization', $schemas[$schema]['properties']['publicationDate'] ?? [], $schema . '.publicationDate stays readable: the read rules match on it');
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

		$this->assertSame($base['usage']['properties']['interneAnnotation']['authorization'], $merged['usage']['properties']['interneAnnotation']['authorization']);
		$this->assertSame($base['organization']['properties']['contactpersonen']['authorization'], $merged['organization']['properties']['contactpersonen']['authorization']);
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
			$this->assertContains($rule, $after, 'an organisation read rule survived: ' . json_encode($rule));
		}

		$this->assertContains(['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], $after);
		$this->assertCount(count($before) + 1, $after);
	}//end testAUsageIsPublicFromItsPublicationDateAndOrganisationsKeepReading()

	/**
	 * A version is public under the same two conditions as its module, and signed-in users read every version.
	 *
	 * @return void
	 */
	public function testAVersionFollowsItsApplication(): void {
		$schemas = $this->register()['components']['schemas'];
		$module  = $schemas['module']['authorization']['read'];
		$version = $schemas['moduleVersion']['authorization']['read'];

		$this->assertContains(['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], $module);
		$this->assertContains(['group' => 'public', 'match' => ['registeredBy' => 'Supplier']], $module);
		$this->assertSame(
			[
				'authenticated',
				['group' => 'public', 'match' => ['modulePublicationDate' => ['$lte' => '$now']]],
				['group' => 'public', 'match' => ['moduleRegisteredBy' => 'Supplier']],
			],
			$version
		);
		$this->assertNotContains('public', $version, 'no unconditional public read is left');
		$this->assertSame($schemas['module']['properties']['registeredBy']['enum'], $schemas['moduleVersion']['properties']['moduleRegisteredBy']['enum']);
		$this->assertSame('date-time', $schemas['moduleVersion']['properties']['modulePublicationDate']['format']);
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
		$this->assertSame('1.5.5', $merged['components']['schemas']['usage']['version']);

		$schemas = $this->register()['components']['schemas'];
		$this->assertSame('1.5.5', $schemas['usage']['version'], 'publication-field-rules.json sorts before sharing-itsm-exchange.json and value-assessment.json');
		$this->assertSame('0.3.5', $schemas['connection']['version']);
		$this->assertSame('0.1.6', $schemas['moduleVersion']['version']);
	}//end testAFragmentNeverLowersAVersion()
}//end class
