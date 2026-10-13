<?php

/**
 * Tests for the import rules a CMDB mapping pack must follow.
 *
 * The rules per edit are exercised through the loader in
 * CmdbImportProfileTest::testAStoredPackThatBreaksAnImportRuleIsRefused();
 * this covers the rules on their own: the shipped packs follow them, the
 * register fragments count, and an unreadable register fails closed.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackRules;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * CmdbImportPackRules.
 */
class CmdbImportPackRulesTest extends TestCase {
	/**
	 * A shipped file, decoded.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed>
	 */
	private function shipped(string $file): array {
		return json_decode((string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/' . $file), true);
	}//end shipped()

	/**
	 * Every shipped pack follows the rules, including the module fields only a register fragment declares.
	 *
	 * @return void
	 */
	public function testTheShippedPacksFollowTheRules(): void {
		$rules = new CmdbImportPackRules();
		$profile = $this->shipped(file: 'topdesk-profile.json');

		foreach ($profile['packs'] as $target => $file) {
			$this->assertSame([], $rules->violations(target: $target, pack: $this->shipped(file: $file), profile: $profile), $target);
		}
	}//end testTheShippedPacksFollowTheRules()

	/**
	 * Several broken rules are all named, once each.
	 *
	 * @return void
	 */
	public function testEveryBrokenRuleIsNamedOnce(): void {
		$profile = $this->shipped(file: 'topdesk-profile.json');
		$pack = $this->shipped(file: 'topdesk-manufacturer.json');
		$pack['fieldMappings'][0]['required'] = false;
		$pack['fieldMappings'][] = ['source' => 'Leverancier', 'target' => 'provider'];
		$pack['fieldMappings'][] = ['source' => 'Hostingpartij', 'target' => 'provider'];
		unset($pack['defaults']['type']);

		$this->assertSame(
			[
				'"name" must be mapped as required',
				'"provider" is set by the import and cannot be mapped',
				'default "type" must stay "Supplier"',
			],
			(new CmdbImportPackRules())->violations(target: 'manufacturer', pack: $pack, profile: $profile)
		);
	}//end testEveryBrokenRuleIsNamedOnce()

	/**
	 * Fields an edited pack may not map: relations, the publication dates and the admin fields.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function refusedMappings(): array {
		return [
			'an organisation relation that writes back into others' => ['manufacturer', 'participants', '"participants" is a relation or a file and cannot be mapped'],
			'the organisation contacts' => ['manufacturer', 'contactpersonen', '"contactpersonen" is a relation or a file and cannot be mapped'],
			'a module relation with an inverse' => ['module', 'koppelingen', '"koppelingen" is a relation or a file and cannot be mapped'],
			'a module version' => ['module', 'moduleVersions', '"moduleVersions" is a relation or a file and cannot be mapped'],
			'the usage technical owner' => ['usage', 'technicalOwner', '"technicalOwner" is a relation or a file and cannot be mapped'],
			'the usage participants' => ['usage', 'participants', '"participants" is a relation or a file and cannot be mapped'],
			'the usage publication date' => ['usage', 'publicationDate', '"publicationDate" is set outside the import and cannot be mapped'],
			'the organisation publication date' => ['manufacturer', 'publicationDate', '"publicationDate" is set outside the import and cannot be mapped'],
			'the organisation depublication date' => ['municipality', 'depublicationDate', '"depublicationDate" is set outside the import and cannot be mapped'],
			'a merged organisation' => ['manufacturer', 'mergedInto', '"mergedInto" is set outside the import and cannot be mapped'],
			'the registration status' => ['manufacturer', 'registrationStatus', '"registrationStatus" is set outside the import and cannot be mapped'],
			'the organisation status' => ['manufacturer', 'status', '"status" is set outside the import and cannot be mapped'],
			'who registered it' => ['manufacturer', 'registeredBy', '"registeredBy" is set outside the import and cannot be mapped'],
			'the contacts uid' => ['municipality', 'contactsUid', '"contactsUid" is set outside the import and cannot be mapped'],
			'the certificate' => ['manufacturer', 'pki', '"pki" is set outside the import and cannot be mapped'],
			'the module type' => ['module', 'type', '"type" is set outside the import and cannot be mapped'],
			'who registered the module' => ['module', 'registeredBy', '"registeredBy" is set outside the import and cannot be mapped'],
			'the module logo, a file' => ['module', 'logo', '"logo" is a relation or a file and cannot be mapped'],
			'the organisation image, a file' => ['manufacturer', 'image', '"image" is a relation or a file and cannot be mapped'],
			'a field the module schema does not have' => ['module', 'noSuchField', '"noSuchField" is not a field the module pack may map'],
			'a field the usage schema does not have' => ['usage', 'noSuchField', '"noSuchField" is not a field the usage pack may map'],
			'a contact field the business owner does not have' => ['businessOwner', 'email', '"email" is not a field the businessOwner pack may map'],
		];
	}//end refusedMappings()

	/**
	 * A pack that maps a refused field names it, whatever the schema says about it.
	 *
	 * @param string $target The target.
	 * @param string $field The refused field.
	 * @param string $rule The broken rule.
	 *
	 * @return void
	 *
	 * @dataProvider refusedMappings
	 */
	public function testARefusedFieldCannotBeMapped(string $target, string $field, string $rule): void {
		$profile = $this->shipped(file: 'topdesk-profile.json');
		$pack = $this->shipped(file: $profile['packs'][$target]);
		$pack['fieldMappings'][] = ['source' => 'Opmerking', 'target' => $field];

		$this->assertSame([$rule], (new CmdbImportPackRules())->violations(target: $target, pack: $pack, profile: $profile));
	}//end testARefusedFieldCannotBeMapped()

	/**
	 * A refused field cannot be set as a default either, and the admin defaults keep their shipped value.
	 *
	 * @return void
	 */
	public function testARefusedFieldCannotBeADefault(): void {
		$profile = $this->shipped(file: 'topdesk-profile.json');
		$rules = new CmdbImportPackRules();

		$manufacturer = $this->shipped(file: 'topdesk-manufacturer.json');
		$manufacturer['defaults']['publicationDate'] = '2026-01-01T00:00:00+00:00';
		$manufacturer['defaults']['participants'] = ['00000000-0000-0000-0000-000000000000'];
		$manufacturer['defaults']['status'] = 'merged';
		$this->assertSame(
			[
				'default "publicationDate" is set outside the import and cannot be mapped',
				'default "participants" is a relation or a file and cannot be mapped',
				'default "status" must stay "Active"',
			],
			$rules->violations(target: 'manufacturer', pack: $manufacturer, profile: $profile)
		);

		$usage = $this->shipped(file: 'topdesk-usage.json');
		$usage['defaults'] = ['publicationDate' => '2026-01-01T00:00:00+00:00', 'status' => 'In production'];
		$this->assertSame(
			['default "publicationDate" is set outside the import and cannot be mapped'],
			$rules->violations(target: 'usage', pack: $usage, profile: $profile)
		);
	}//end testARefusedFieldCannotBeADefault()

	/**
	 * Any plain property of the target schema may be mapped, not only the fields the shipped packs map.
	 *
	 * @return void
	 */
	public function testAnyPlainSchemaFieldCanBeMapped(): void {
		$profile = $this->shipped(file: 'topdesk-profile.json');
		$rules = new CmdbImportPackRules();
		$fields = [
			'module' => ['licentietype', 'website', 'licence', 'hostingLocation', 'dpiaStatus'],
			'manufacturer' => ['name', 'oin', 'website', 'summary'],
			'municipality' => ['name', 'tooi', 'description'],
			'usage' => ['status', 'interneAnnotation', 'riskScore', 'startDatePlanned'],
			'businessOwner' => ['name', 'role'],
		];

		foreach ($fields as $target => $names) {
			foreach ($names as $field) {
				$pack = $this->shipped(file: $profile['packs'][$target]);
				$pack['fieldMappings'][] = ['source' => 'Opmerking', 'target' => $field];
				$violations = $rules->violations(target: $target, pack: $pack, profile: $profile);
				if ($target === 'manufacturer' && $field === 'website') {
					$this->assertSame(['"website" is not a field the manufacturer pack may map'], $violations, 'organization has no website');
					continue;
				}

				$this->assertSame([], $violations, $target . '.' . $field);
			}
		}
	}//end testAnyPlainSchemaFieldCanBeMapped()

	/**
	 * A field another subsystem owns is refused: the service desk exchange's keys and stamps, the calculated suggestion and the installed version.
	 *
	 * @return void
	 */
	public function testAFieldAnotherSubsystemOwnsIsRefused(): void {
		$profile = $this->shipped(file: 'topdesk-profile.json');
		$rules = new CmdbImportPackRules();
		$owned = ['serviceDeskSystem', 'serviceDeskRecordId', 'serviceDeskUrl', 'serviceDeskSyncedAt', 'installedVersion', 'suggestedTimeClassification'];

		foreach ($owned as $field) {
			$pack = $this->shipped(file: 'topdesk-usage.json');
			$pack['fieldMappings'][] = ['source' => 'Opmerking', 'target' => $field];
			$this->assertSame(['"' . $field . '" is set outside the import and cannot be mapped'], $rules->violations(target: 'usage', pack: $pack, profile: $profile), $field);

			$pack = $this->shipped(file: 'topdesk-usage.json');
			$pack['defaults'][$field] = 'x';
			$this->assertSame(['default "' . $field . '" is set outside the import and cannot be mapped'], $rules->violations(target: 'usage', pack: $pack, profile: $profile), 'default ' . $field);
		}
	}//end testAFieldAnotherSubsystemOwnsIsRefused()

	/**
	 * A property the schema hides from forms or calculates is refused, and a file property is refused as a file.
	 *
	 * @return void
	 */
	public function testAHiddenCalculatedOrFilePropertyIsRefused(): void {
		$directory = sys_get_temp_dir() . '/stackiq-pack-rules-' . bin2hex(random_bytes(4));
		mkdir($directory . '/register.d', 0700, true);
		$properties = [
			'status' => ['type' => 'string'],
			'hidden' => ['type' => 'string', 'hideOnForm' => true],
			'derived' => ['type' => 'string'],
			'attachment' => ['type' => 'file'],
		];
		$register = ['components' => ['schemas' => ['usage' => ['properties' => $properties, 'configuration' => ['x-openregister-calculations' => ['derived' => ['type' => 'string']]]]]]];
		file_put_contents($directory . '/softwarecatalogus_register.json', json_encode($register));

		$pack = ['fieldMappings' => [
			['source' => 'A', 'target' => 'status'],
			['source' => 'B', 'target' => 'hidden'],
			['source' => 'C', 'target' => 'derived'],
			['source' => 'D', 'target' => 'attachment'],
		]];

		try {
			$violations = (new CmdbImportPackRules(settingsDirectory: $directory))->violations(target: 'usage', pack: $pack, profile: []);
		} finally {
			rmdir($directory . '/register.d');
			unlink($directory . '/softwarecatalogus_register.json');
			rmdir($directory);
		}

		$this->assertSame(
			[
				'"hidden" is set outside the import and cannot be mapped',
				'"derived" is set outside the import and cannot be mapped',
				'"attachment" is a relation or a file and cannot be mapped',
			],
			$violations
		);
	}//end testAHiddenCalculatedOrFilePropertyIsRefused()

	/**
	 * A plain field that the register turns into a relation is refused: the schema has the last word.
	 *
	 * @return void
	 */
	public function testAPlainFieldThatBecameARelationIsRefused(): void {
		$directory = sys_get_temp_dir() . '/stackiq-pack-rules-' . bin2hex(random_bytes(4));
		mkdir($directory . '/register.d', 0700, true);
		$register = ['components' => ['schemas' => ['usage' => ['properties' => ['status' => ['type' => 'string'], 'interneAnnotation' => ['type' => 'string']]]]]];
		file_put_contents($directory . '/softwarecatalogus_register.json', json_encode($register));
		$fragment = ['components' => ['schemas' => ['usage' => ['properties' => ['interneAnnotation' => ['items' => ['$ref' => '#/components/schemas/organization']]]]]]];
		file_put_contents($directory . '/register.d/relation.json', json_encode($fragment));

		$pack = ['fieldMappings' => [['source' => 'Status', 'target' => 'status'], ['source' => 'Cluster', 'target' => 'interneAnnotation']]];

		try {
			$violations = (new CmdbImportPackRules(settingsDirectory: $directory))->violations(target: 'usage', pack: $pack, profile: []);
		} finally {
			unlink($directory . '/register.d/relation.json');
			rmdir($directory . '/register.d');
			unlink($directory . '/softwarecatalogus_register.json');
			rmdir($directory);
		}

		$this->assertSame(['"interneAnnotation" is a relation or a file and cannot be mapped'], $violations);
	}//end testAPlainFieldThatBecameARelationIsRefused()

	/**
	 * Without the register definition no pack can be checked, so the import fails closed.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsMappingUnavailable(): void {
		$rules = new CmdbImportPackRules(settingsDirectory: '/nonexistent');

		try {
			$rules->violations(target: 'usage', pack: $this->shipped(file: 'topdesk-usage.json'), profile: []);
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MAPPING_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame('The stackiq register definition cannot be read, so the CMDB import packs cannot be checked', $e->getMessage());
		}
	}//end testAnUnreadableRegisterIsMappingUnavailable()
}//end class
