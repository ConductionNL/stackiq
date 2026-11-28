<?php

/**
 * The catalogue role map: its defaults, its lookups and what a save accepts.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\RoleGroupMap;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the map over an in-memory app config.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
 */
final class RoleGroupMapTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The map over the in-memory config.
	 *
	 * @param IL10N|null $l10n The translator, or none.
	 *
	 * @return RoleGroupMap
	 */
	private function map(?IL10N $l10n = null): RoleGroupMap {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame(expected: 'stackiq', actual: $app);
				$this->stored[$key] = $value;
				return true;
			}
		);

		return new RoleGroupMap(appConfig: $config, l10n: $l10n);
	}//end map()

	/**
	 * Without a saved mapping every role has its lower-case group and no source groups,
	 * and the four organisation types have their default role on the English enum.
	 *
	 * @return void
	 */
	public function testTheDefaultsWithoutASavedMapping(): void {
		$answer = $this->map()->toArray();

		$this->assertSame(
			expected: [
				'Aanbod-beheerder' => ['group' => 'aanbod-beheerder', 'sourceGroups' => []],
				'Gebruik-beheerder' => ['group' => 'gebruik-beheerder', 'sourceGroups' => []],
				'Gebruik-raadpleger' => ['group' => 'gebruik-raadpleger', 'sourceGroups' => []],
				'Functioneel-beheerder' => ['group' => 'functioneel-beheerder', 'sourceGroups' => []],
				'Organisatie-beheerder' => ['group' => 'organisatie-beheerder', 'sourceGroups' => []],
				'VNG-raadpleger' => ['group' => 'vng-raadpleger', 'sourceGroups' => []],
			],
			actual: $answer['roleMapping']
		);
		$this->assertSame(
			expected: [
				'Municipality' => 'Gebruik-beheerder',
				'Supplier' => 'Aanbod-beheerder',
				'Collaboration' => 'Gebruik-beheerder',
				'Community' => 'Aanbod-beheerder',
			],
			actual: $answer['organisationTypeRoles']
		);
		$this->assertSame(expected: 365, actual: $answer['accessReviewIntervalDays']);
	}//end testTheDefaultsWithoutASavedMapping()

	/**
	 * Every organisation type of the shipped register has a default role.
	 *
	 * @return void
	 */
	public function testEveryRegisterOrganisationTypeHasADefaultRole(): void {
		$register = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$types = $register['components']['schemas']['organization']['properties']['type']['enum'];
		$this->assertNotEmpty(actual: $types);

		foreach ($types as $type) {
			$this->assertNotNull(actual: $this->map()->defaultRoleForOrganisationType(organisationType: $type), message: $type);
		}
	}//end testEveryRegisterOrganisationTypeHasADefaultRole()

	/**
	 * Roles and groups are found without regard to case or surrounding whitespace.
	 *
	 * @return void
	 */
	public function testLookupsIgnoreCase(): void {
		$map = $this->map();

		$this->assertSame(expected: 'Aanbod-beheerder', actual: $map->canonicalRole(roleOrGroup: ' aanbod-BEHEERDER '));
		$this->assertSame(expected: 'VNG-raadpleger', actual: $map->canonicalRole(roleOrGroup: 'vng-raadpleger'));
		$this->assertNull(actual: $map->canonicalRole(roleOrGroup: 'Ambtenaar'));
		$this->assertNull(actual: $map->canonicalRole(roleOrGroup: ''));
		$this->assertSame(expected: 'gebruik-beheerder', actual: $map->roleGroup(role: 'Gebruik-Beheerder'));
		$this->assertNull(actual: $map->roleGroup(role: 'admin'));
		$this->assertTrue(condition: $map->isRoleGroup(groupId: 'Functioneel-beheerder'));
		$this->assertFalse(condition: $map->isRoleGroup(groupId: 'software-catalog-users'));
		$this->assertSame(expected: 'Aanbod-beheerder', actual: $map->defaultRoleForOrganisationType(organisationType: ' supplier '));
		$this->assertNull(actual: $map->defaultRoleForOrganisationType(organisationType: 'gemeente'));
		$this->assertSame(
			expected: [
				'aanbod-beheerder',
				'gebruik-beheerder',
				'gebruik-raadpleger',
				'functioneel-beheerder',
				'organisatie-beheerder',
				'vng-raadpleger',
			],
			actual: $map->roleGroups()
		);
	}//end testLookupsIgnoreCase()

	/**
	 * An admin maps Gebruik-beheerder onto Inkoop with a 180 day interval, and the map answers both.
	 *
	 * @return void
	 */
	public function testASavedMappingAndIntervalAreAnswered(): void {
		$result = $this->map()->update(
			config: [
				'roleMapping' => ['gebruik-beheerder' => [' Inkoop ', 'Inkoop', 'Team Inkoop']],
				'accessReviewIntervalDays' => '180',
			]
		);

		$this->assertTrue(condition: $result['success'], message: json_encode($result));

		$fresh = $this->map();
		$this->assertSame(expected: ['Inkoop', 'Team Inkoop'], actual: $fresh->sourceGroups()['Gebruik-beheerder']);
		$this->assertSame(expected: [], actual: $fresh->sourceGroups()['Aanbod-beheerder']);
		$this->assertSame(expected: 180, actual: $fresh->reviewIntervalDays());
		$this->assertSame(
			expected: ['group' => 'gebruik-beheerder', 'sourceGroups' => ['Inkoop', 'Team Inkoop']],
			actual: $fresh->toArray()['roleMapping']['Gebruik-beheerder']
		);
		$this->assertArrayNotHasKey(key: 'organisation_type_roles', array: $this->stored, message: 'A part that was not submitted is not written.');
	}//end testASavedMappingAndIntervalAreAnswered()

	/**
	 * The `{sourceGroups: [...]}` shape the map reads back is accepted on save.
	 *
	 * @return void
	 */
	public function testTheReadShapeCanBeSavedBack(): void {
		$map = $this->map();
		$map->update(config: ['roleMapping' => ['Aanbod-beheerder' => ['Leveranciers']]]);

		$result = $this->map()->update(config: ['roleMapping' => $this->map()->toArray()['roleMapping']]);

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: ['Leveranciers'], actual: $this->map()->sourceGroups()['Aanbod-beheerder']);
	}//end testTheReadShapeCanBeSavedBack()

	/**
	 * The organisation type defaults can be changed and switched off.
	 *
	 * @return void
	 */
	public function testOrganisationTypeRolesCanBeChanged(): void {
		$result = $this->map()->update(
			config: ['organisationTypeRoles' => ['supplier' => null, 'Municipality' => 'functioneel-beheerder']]
		);

		$this->assertTrue(condition: $result['success']);
		$map = $this->map();
		$this->assertNull(actual: $map->defaultRoleForOrganisationType(organisationType: 'Supplier'));
		$this->assertSame(expected: 'Functioneel-beheerder', actual: $map->defaultRoleForOrganisationType(organisationType: 'Municipality'));
		$this->assertSame(expected: 'Aanbod-beheerder', actual: $map->defaultRoleForOrganisationType(organisationType: 'Community'));
	}//end testOrganisationTypeRolesCanBeChanged()

	/**
	 * A save with any invalid part writes nothing at all and says what was wrong.
	 *
	 * @return void
	 */
	public function testAnInvalidSaveWritesNothing(): void {
		$result = $this->map()->update(
			config: [
				'roleMapping' => [
					'Gebruik-beheerder' => ['Inkoop', 'aanbod-beheerder'],
					'Ambtenaar' => ['Medewerkers'],
					'Aanbod-beheerder' => 'Leveranciers',
					'Gebruik-raadpleger' => [''],
				],
				'organisationTypeRoles' => ['Gemeente' => 'Gebruik-beheerder', 'Supplier' => 'Ambtenaar'],
				'accessReviewIntervalDays' => 0,
			]
		);

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'The role mapping was not saved.', actual: $result['message']);
		$this->assertSame(
			expected: [
				'aanbod-beheerder is a role group and cannot grant Gebruik-beheerder.',
				'Ambtenaar is not a catalogue role.',
				'The groups of Aanbod-beheerder must be a list.',
				'A group of Gebruik-raadpleger has no valid name.',
				'Gemeente is not an organisation type.',
				'Ambtenaar is not a catalogue role.',
				'The review interval must be a whole number of days from 1 to 3650.',
			],
			actual: $result['errors']
		);
		$this->assertSame(expected: [], actual: $this->stored, message: 'A rejected save must not leave half a mapping behind.');
	}//end testAnInvalidSaveWritesNothing()

	/**
	 * Intervals outside one day to ten years are refused, and a bad stored value reads as the default.
	 *
	 * @return void
	 */
	public function testTheIntervalBounds(): void {
		foreach ([3651, -5, '12.5', 'a year', null, 1.5] as $bad) {
			$this->assertFalse(
				condition: $this->map()->update(config: ['accessReviewIntervalDays' => $bad])['success'],
				message: var_export($bad, true)
			);
		}

		$this->assertTrue(condition: $this->map()->update(config: ['accessReviewIntervalDays' => 3650])['success']);
		$this->assertSame(expected: 3650, actual: $this->map()->reviewIntervalDays());

		$this->stored['access_review_interval_days'] = '99999';
		$this->assertSame(expected: 365, actual: $this->map()->reviewIntervalDays());
	}//end testTheIntervalBounds()

	/**
	 * A malformed stored mapping reads as the defaults, and unknown stored roles are ignored.
	 *
	 * @return void
	 */
	public function testAMalformedStoredMappingReadsAsTheDefaults(): void {
		$this->stored['role_mapping'] = '{not json';
		$this->stored['organisation_type_roles'] = '"Supplier"';
		$this->assertSame(expected: [], actual: $this->map()->sourceGroups()['Gebruik-beheerder']);
		$this->assertSame(expected: 'Aanbod-beheerder', actual: $this->map()->defaultRoleForOrganisationType(organisationType: 'Supplier'));

		$this->stored['role_mapping'] = json_encode(['Ambtenaar' => ['x'], 'Gebruik-beheerder' => ['Inkoop', 7, '']]);
		$this->assertSame(expected: ['Inkoop'], actual: $this->map()->sourceGroups()['Gebruik-beheerder']);
		$this->assertArrayNotHasKey(key: 'Ambtenaar', array: $this->map()->sourceGroups());
	}//end testAMalformedStoredMappingReadsAsTheDefaults()

	/**
	 * With a translator the messages are translated, with their placeholders filled.
	 *
	 * @return void
	 */
	public function testMessagesAreTranslated(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'NL:' . vsprintf($text, $parameters)
		);

		$result = $this->map(l10n: $l10n)->update(config: ['roleMapping' => ['Ambtenaar' => []]]);

		$this->assertSame(expected: 'NL:The role mapping was not saved.', actual: $result['message']);
		$this->assertSame(expected: ['NL:Ambtenaar is not a catalogue role.'], actual: $result['errors']);
	}//end testMessagesAreTranslated()

	/**
	 * The role groups read as unseeded until the seeding is recorded.
	 *
	 * @return void
	 */
	public function testTheSeedingIsRecorded(): void {
		$map = $this->map();
		$this->assertFalse(condition: $map->grantsSeeded());

		$map->markGrantsSeeded();

		$this->assertSame(expected: '1', actual: $this->stored['role_grants_seeded']);
		$this->assertTrue(condition: $map->grantsSeeded());
	}//end testTheSeedingIsRecorded()
}//end class
