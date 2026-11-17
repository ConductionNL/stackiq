<?php

/**
 * SettingsService::autoConfigureAfterImport(): runs once, creates the role
 * groups first, and stops before marking itself done when the voorzieningen
 * register cannot be configured.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Auto-configuration after the catalogue import.
 */
final class SettingsServiceAutoConfigureAfterImportTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var RoleGroupSettings&MockObject
	 */
	private RoleGroupSettings $roleGroups;

	/**
	 * @var CatalogueLocator&MockObject
	 */
	private CatalogueLocator $catalogue;

	/**
	 * Create the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config       = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->roleGroups   = $this->createMock(RoleGroupSettings::class);
		$this->catalogue    = $this->createMock(CatalogueLocator::class);
	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		return new SettingsService(
			catalogue: $this->catalogue,
			config: $this->config,
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->roleGroups,
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->groupManager,
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);
	}//end service()

	/**
	 * Answer the completion flag with the given value.
	 *
	 * @param string $completed 'true' or 'false'.
	 *
	 * @return void
	 */
	private function completed(string $completed): void {
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'auto_config_completed' ? $completed : $default
		);
	}//end completed()

	/**
	 * Once completed, a second run does nothing at all and returns an empty result.
	 *
	 * @return void
	 */
	public function testACompletedRunIsNotRepeated(): void {
		$this->completed('true');
		$this->groupManager->expects($this->never())->method('groupExists');
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->catalogue->expects($this->never())->method('getObjectService');
		$this->config->expects($this->never())->method('setValueString');

		$this->assertSame([], $this->service()->autoConfigureAfterImport());
	}//end testACompletedRunIsNotRepeated()

	/**
	 * The role groups are created first (missing ones only) and the group
	 * defaults are set; when the voorzieningen register cannot be configured
	 * the run stops with an empty result and is not marked completed.
	 *
	 * @return void
	 */
	public function testGroupsAreCreatedAndAFailedVoorzieningenStepIsNotMarkedDone(): void {
		$this->completed('false');

		$existing = ['ambtenaar', 'admin-unrelated'];
		$this->groupManager->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => in_array($gid, $existing, true)
		);
		$created = [];
		$this->groupManager->method('createGroup')->willReturnCallback(
			function (string $gid) use (&$created): ?IGroup {
				$created[] = $gid;
				// One refused group is logged and does not stop the run.
				return $gid === 'vng-raadpleger' ? null : $this->createMock(IGroup::class);
			}
		);

		$this->roleGroups->expects($this->once())->method('setGenericUserGroups')->with(['software-catalog-users']);
		$this->roleGroups->expects($this->once())->method('setOrganizationAdminGroups')->with([]);
		$this->roleGroups->expects($this->once())->method('setSuperUserGroups')->with(['admin', 'software-catalog-admins']);

		// No OpenRegister object service: voorzieningen cannot be configured.
		$this->catalogue->method('getObjectService')->willReturn(null);
		$this->config->expects($this->never())->method('setValueString');

		$this->assertSame([], $this->service()->autoConfigureAfterImport());
		$this->assertSame(
			[
				'aanbod-beheerder',
				'gebruik-beheerder',
				'gebruik-raadpleger',
				'functioneel-beheerder',
				'vng-raadpleger',
				'organisatie-beheerder',
				'organisaties-beheerder',
				'software-catalog-users',
				'software-catalog-admins',
			],
			$created
		);
	}//end testGroupsAreCreatedAndAFailedVoorzieningenStepIsNotMarkedDone()

	/**
	 * A group manager failure aborts the run with both causes in the message,
	 * before the voorzieningen step and without marking it done.
	 *
	 * @return void
	 */
	public function testAGroupManagerFailureAbortsTheRun(): void {
		$this->completed('false');
		$this->groupManager->method('groupExists')->willThrowException(new \Exception('LDAP unavailable'));
		$this->catalogue->expects($this->never())->method('getObjectService');
		$this->roleGroups->expects($this->never())->method('setGenericUserGroups');
		$this->config->expects($this->never())->method('setValueString');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to auto-configure after import: Failed to create required user groups: LDAP unavailable');

		$this->service()->autoConfigureAfterImport();
	}//end testAGroupManagerFailureAbortsTheRun()
}//end class
