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
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('stackiq', $app);
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
			[
				'Aanbod-beheerder' => ['group' => 'aanbod-beheerder', 'sourceGroups' => []],
				'Gebruik-beheerder' => ['group' => 'gebruik-beheerder', 'sourceGroups' => []],
				'Gebruik-raadpleger' => ['group' => 'gebruik-raadpleger', 'sourceGroups' => []],
				'Functioneel-beheerder' => ['group' => 'functioneel-beheerder', 'sourceGroups' => []],
				'Organisatie-beheerder' => ['group' => 'organisatie-beheerder', 'sourceGroups' => []],
				'VNG-raadpleger' => ['group' => 'vng-raadpleger', 'sourceGroups' => []],
			],
			$answer['roleMapping']
		);
		$this->assertSame(
			[
				'Municipality' => 'Gebruik-beheerder',
				'Supplier' => 'Aanbod-beheerder',
				'Collaboration' => 'Gebruik-beheerder',
				'Community' => 'Aanbod-beheerder',
			],
			$answer['organisationTypeRoles']
		);
		$this->assertSame(365, $answer['accessReviewIntervalDays']);
	}//end testTheDefaultsWithoutASavedMapping()

	/**
	 * Every organisation type of the shipped register has a default role.
	 *
	 * @return void
	 */
	public function testEveryRegisterOrganisationTypeHasADefaultRole(): void {
		$register = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$types = $register['components']['schemas']['organization']['properties']['type']['enum'];
		$this->assertNotEmpty($types);

		foreach ($types as $type) {
			$this->assertNotNull($this->map()->defaultRoleForOrganisationType(organisationType: $type), $type);
		}
	}//end testEveryRegisterOrganisationTypeHasADefaultRole()

	/**
	 * Roles and groups are found without regard to case or surrounding whitespace.
	 *
	 * @return void
	 */
	public function testLookupsIgnoreCase(): void {
		$map = $this->map();

		$this->assertSame('Aanbod-beheerder', $map->canonicalRole(roleOrGroup: ' aanbod-BEHEERDER '));
		$this->assertSame('VNG-raadpleger', $map->canonicalRole(roleOrGroup: 'vng-raadpleger'));
		$this->assertNull($map->canonicalRole(roleOrGroup: 'Ambtenaar'));
		$this->assertNull($map->canonicalRole(roleOrGroup: ''));
		$this->assertSame('gebruik-beheerder', $map->roleGroup(role: 'Gebruik-Beheerder'));
		$this->assertNull($map->roleGroup(role: 'admin'));
		$this->assertTrue($map->isRoleGroup(groupId: 'Functioneel-beheerder'));
		$this->assertFalse($map->isRoleGroup(groupId: 'software-catalog-users'));
		$this->assertSame('Aanbod-beheerder', $map->defaultRoleForOrganisationType(organisationType: ' supplier '));
		$this->assertNull($map->defaultRoleForOrganisationType(organisationType: 'gemeente'));
		$this->assertSame(['aanbod-beheerder', 'gebruik-beheerder', 'gebruik-raadpleger', 'functioneel-beheerder', 'organisatie-beheerder', 'vng-raadpleger'], $map->roleGroups());
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

		$this->assertTrue($result['success'], json_encode($result));

		$fresh = $this->map();
		$this->assertSame(['Inkoop', 'Team Inkoop'], $fresh->sourceGroups()['Gebruik-beheerder']);
		$this->assertSame([], $fresh->sourceGroups()['Aanbod-beheerder']);
		$this->assertSame(180, $fresh->reviewIntervalDays());
		$this->assertSame(['group' => 'gebruik-beheerder', 'sourceGroups' => ['Inkoop', 'Team Inkoop']], $fresh->toArray()['roleMapping']['Gebruik-beheerder']);
		$this->assertArrayNotHasKey('organisation_type_roles', $this->stored, 'A part that was not submitted is not written.');
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

		$this->assertTrue($result['success']);
		$this->assertSame(['Leveranciers'], $this->map()->sourceGroups()['Aanbod-beheerder']);
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

		$this->assertTrue($result['success']);
		$map = $this->map();
		$this->assertNull($map->defaultRoleForOrganisationType(organisationType: 'Supplier'));
		$this->assertSame('Functioneel-beheerder', $map->defaultRoleForOrganisationType(organisationType: 'Municipality'));
		$this->assertSame('Aanbod-beheerder', $map->defaultRoleForOrganisationType(organisationType: 'Community'));
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

		$this->assertFalse($result['success']);
		$this->assertSame('The role mapping was not saved.', $result['message']);
		$this->assertSame(
			[
				'aanbod-beheerder is a role group and cannot grant Gebruik-beheerder.',
				'Ambtenaar is not a catalogue role.',
				'The groups of Aanbod-beheerder must be a list.',
				'A group of Gebruik-raadpleger has no valid name.',
				'Gemeente is not an organisation type.',
				'Ambtenaar is not a catalogue role.',
				'The review interval must be a whole number of days from 1 to 3650.',
			],
			$result['errors']
		);
		$this->assertSame([], $this->stored, 'A rejected save must not leave half a mapping behind.');
	}//end testAnInvalidSaveWritesNothing()

	/**
	 * Intervals outside one day to ten years are refused, and a bad stored value reads as the default.
	 *
	 * @return void
	 */
	public function testTheIntervalBounds(): void {
		foreach ([3651, -5, '12.5', 'a year', null, 1.5] as $bad) {
			$this->assertFalse($this->map()->update(config: ['accessReviewIntervalDays' => $bad])['success'], var_export($bad, true));
		}

		$this->assertTrue($this->map()->update(config: ['accessReviewIntervalDays' => 3650])['success']);
		$this->assertSame(3650, $this->map()->reviewIntervalDays());

		$this->stored['access_review_interval_days'] = '99999';
		$this->assertSame(365, $this->map()->reviewIntervalDays());
	}//end testTheIntervalBounds()

	/**
	 * A malformed stored mapping reads as the defaults, and unknown stored roles are ignored.
	 *
	 * @return void
	 */
	public function testAMalformedStoredMappingReadsAsTheDefaults(): void {
		$this->stored['role_mapping'] = '{not json';
		$this->stored['organisation_type_roles'] = '"Supplier"';
		$this->assertSame([], $this->map()->sourceGroups()['Gebruik-beheerder']);
		$this->assertSame('Aanbod-beheerder', $this->map()->defaultRoleForOrganisationType(organisationType: 'Supplier'));

		$this->stored['role_mapping'] = json_encode(['Ambtenaar' => ['x'], 'Gebruik-beheerder' => ['Inkoop', 7, '']]);
		$this->assertSame(['Inkoop'], $this->map()->sourceGroups()['Gebruik-beheerder']);
		$this->assertArrayNotHasKey('Ambtenaar', $this->map()->sourceGroups());
	}//end testAMalformedStoredMappingReadsAsTheDefaults()

	/**
	 * With a translator the messages are translated, with their placeholders filled.
	 *
	 * @return void
	 */
	public function testMessagesAreTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'NL:' . vsprintf($text, $parameters)
		);

		$result = $this->map(l10n: $l10n)->update(config: ['roleMapping' => ['Ambtenaar' => []]]);

		$this->assertSame('NL:The role mapping was not saved.', $result['message']);
		$this->assertSame(['NL:Ambtenaar is not a catalogue role.'], $result['errors']);
	}//end testMessagesAreTranslated()
}//end class
