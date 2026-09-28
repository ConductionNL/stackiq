<?php

/**
 * Unit tests for reading back the organisation admin groups setting.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Settings\OrganizationSettingsHandler;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * THE DEFECT UNDER TEST (stackiq#1136).
 *
 * `setOrganizationAdminGroups()` stored the admin's choice under
 * `organization_admin_groups`, but `getOrganizationAdminGroups()` returned a
 * hard-coded empty list. The settings page reloaded empty after a save and
 * `SettingsController::verifyOrgExportPermission()` never saw the chosen
 * groups. These tests round-trip the setting through an in-memory IAppConfig
 * so the real getter and setter run end to end.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SettingsServiceOrganizationAdminGroupsTest extends TestCase {

	/**
	 * Build an IAppConfig double backed by an in-memory store.
	 *
	 * @param array $store Reference to the backing key/value store.
	 *
	 * @return IAppConfig
	 */
	private function makeConfig(array &$store): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end makeConfig()

	/**
	 * Build a SettingsService backed by an in-memory IAppConfig store.
	 *
	 * @param array $store Reference to the backing key/value store.
	 *
	 * @return SettingsService
	 */
	private function makeService(array &$store): SettingsService {
		return new SettingsService(
			config: $this->makeConfig($store),
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end makeService()

	/**
	 * A saved list comes back from the getter.
	 *
	 * @return void
	 */
	public function testSavedGroupsAreReadBack(): void {
		$store = [];
		$service = $this->makeService($store);

		$service->setOrganizationAdminGroups(['org-admins', 'inkoop']);

		$this->assertSame(
			['org-admins', 'inkoop'],
			$service->getOrganizationAdminGroups(),
			'The organisation admin groups an admin saved must be what the getter returns.'
		);
	}//end testSavedGroupsAreReadBack()

	/**
	 * A value already stored in app config is read, not discarded.
	 *
	 * @return void
	 */
	public function testStoredValueIsRead(): void {
		$store = ['organization_admin_groups' => '["beheer-gemeente-a"]'];
		$service = $this->makeService($store);

		$this->assertSame(['beheer-gemeente-a'], $service->getOrganizationAdminGroups());
	}//end testStoredValueIsRead()

	/**
	 * With nothing saved the list stays empty: no default groups come back.
	 *
	 * Commit bc4dc9ea removed the old default (organisaties-beheerder) on
	 * purpose, so restoring the read must not restore that default.
	 *
	 * @return void
	 */
	public function testNothingSavedMeansNoGroups(): void {
		$store = [];
		$service = $this->makeService($store);

		$this->assertSame([], $service->getOrganizationAdminGroups());
	}//end testNothingSavedMeansNoGroups()

	/**
	 * A stored value that is not a JSON list reads as no groups.
	 *
	 * @return void
	 */
	public function testMalformedStoredValueMeansNoGroups(): void {
		$store = ['organization_admin_groups' => 'not-json'];
		$service = $this->makeService($store);

		$this->assertSame([], $service->getOrganizationAdminGroups());
	}//end testMalformedStoredValueMeansNoGroups()

	/**
	 * The extracted settings handler reads the same key back.
	 *
	 * @return void
	 */
	public function testHandlerReadsSavedGroupsBack(): void {
		$store = [];
		$handler = new OrganizationSettingsHandler(
			config: $this->makeConfig($store),
			logger: $this->createMock(LoggerInterface::class)
		);

		$handler->setOrganizationAdminGroups(['org-admins']);

		$this->assertSame(['org-admins'], $handler->getOrganizationAdminGroups());
	}//end testHandlerReadsSavedGroupsBack()
}//end class
