<?php

/**
 * The contract schema after every register.d fragment is merged in: the
 * Expiring status, its lifecycle, the responsible user and the expiry
 * warning's recipients (contracts-expiry-and-owner).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
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
 * REQ-CEO-001, REQ-CEO-002, REQ-CEO-004 and REQ-CEO-005, as the merged register declares them.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 */
class ContractLifecycleDeclarationTest extends TestCase {

	/**
	 * The register as SettingsService merges it: the monolith, then every fragment in name order, keeping the highest schema versions.
	 *
	 * @return array<string, mixed> The merged register.
	 */
	private function mergedRegister(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$register = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$highest = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$register = $highest->invoke(null, $register, $merge->invoke(null, $register, $fragment));
		}

		return $register;
	}//end mergedRegister()

	/**
	 * The merged contract schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function contract(): array {
		return $this->mergedRegister()['components']['schemas']['catalogContract'];
	}//end contract()

	/**
	 * The status enum is Active, Expiring, Expired, In negotiation.
	 *
	 * @return void
	 */
	public function testTheStatusEnumHoldsExpiring(): void {
		$this->assertSame(['Active', 'Expiring', 'Expired', 'In negotiation'], $this->contract()['properties']['status']['enum']);
	}//end testTheStatusEnumHoldsExpiring()

	/**
	 * Every lifecycle state is an enum value, and approach, extend and expire move through Expiring.
	 *
	 * @return void
	 */
	public function testTheLifecycleMovesThroughExpiring(): void {
		$contract = $this->contract();
		$enum = $contract['properties']['status']['enum'];
		$lifecycle = $contract['configuration']['x-openregister-lifecycle'];

		$states = [$lifecycle['initial'], ...$lifecycle['final']];
		foreach ($lifecycle['transitions'] as $transition) {
			$states = [...$states, ...$transition['from'], $transition['to']];
		}

		$this->assertSame([], array_values(array_diff(array_unique($states), $enum)), 'every lifecycle state is an enum value');
		$this->assertSame(['from' => ['Active'], 'to' => 'Expiring'], array_intersect_key($lifecycle['transitions']['approach'], ['from' => 1, 'to' => 1]));
		$this->assertSame(['from' => ['Expiring'], 'to' => 'Active'], array_intersect_key($lifecycle['transitions']['extend'], ['from' => 1, 'to' => 1]));
		$this->assertSame(['Active', 'Expiring'], $lifecycle['transitions']['expire']['from']);
		$this->assertSame('Expired', $lifecycle['transitions']['expire']['to']);
		$this->assertSame(['Expired'], $lifecycle['final']);
	}//end testTheLifecycleMovesThroughExpiring()

	/**
	 * The responsible user is a Nextcloud user id.
	 *
	 * @return void
	 */
	public function testTheResponsibleUserIsANextcloudUser(): void {
		$field = $this->contract()['properties']['responsibleUser'];

		$this->assertSame('string', $field['type']);
		$this->assertSame('nextcloud-user', $field['referenceType']);
		$this->assertArrayNotHasKey('responsibleUser', array_flip($this->contract()['required'] ?? []));
	}//end testTheResponsibleUserIsANextcloudUser()

	/**
	 * The expiry warning goes to the admins group, the manage rights holders and the responsible user.
	 *
	 * @return void
	 */
	public function testTheExpiryWarningReachesTheResponsibleUser(): void {
		$recipients = $this->contract()['x-openregister-notifications']['contract-expiry']['recipients'];

		$this->assertContains(['kind' => 'groups', 'groups' => ['software-catalog-admins']], $recipients);
		$this->assertContains(['kind' => 'object-acl', 'permission' => 'manage'], $recipients);
		$this->assertContains(['kind' => 'field', 'field' => 'responsibleUser'], $recipients);
		$this->assertCount(3, $recipients);
	}//end testTheExpiryWarningReachesTheResponsibleUser()

	/**
	 * The contract schema and the register both moved up, with a changelog line, so the lifecycle edit deploys.
	 *
	 * @return void
	 */
	public function testTheVersionsMovedUp(): void {
		$register = $this->mergedRegister();

		$this->assertTrue(version_compare($register['components']['schemas']['catalogContract']['version'], '0.1.5', '>'));
		$this->assertTrue(version_compare($register['info']['version'], '2.5.8', '>'));
		$this->assertStringStartsWith($register['info']['version'] . ': catalogContract gains the Expiring status', $register['info']['changelog']);
	}//end testTheVersionsMovedUp()
}//end class
