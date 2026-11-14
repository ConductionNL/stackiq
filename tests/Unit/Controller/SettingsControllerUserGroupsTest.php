<?php

/**
 * The role mapping through the admin user groups endpoints.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Controller
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

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives /api/user-groups/config with the real role group settings and role map over an in-memory app config.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
 */
final class SettingsControllerUserGroupsTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The controller for a caller posting the given parameters.
	 *
	 * @param bool                 $isAdmin Whether the caller is a Nextcloud admin.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return SettingsController
	 */
	private function controller(bool $isAdmin, array $params = []): SettingsController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('caller')->willReturn($isAdmin);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$logger = $this->createMock(LoggerInterface::class);
		$roleGroupSettings = new RoleGroupSettings(
			config: $config,
			appManager: $this->createMock(IAppManager::class),
			groupManager: $groupManager,
			logger: $logger,
			roleGroupMap: new RoleGroupMap(appConfig: $config)
		);

		return new SettingsController(
			appName: 'stackiq',
			request: $request,
			config: $config,
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			groupManager: $groupManager,
			userSession: $session,
			settingsService: $this->createMock(SettingsService::class),
			cronjobSettings: $this->createMock(CronjobSettings::class),
			orgSyncSvc: $this->createMock(OrganizationSyncService::class),
			archiMateService: $this->createMock(ArchiMateService::class),
			roleGroupSettings: $roleGroupSettings,
			progressTracker: $this->createMock(ProgressTracker::class),
			archiMateImportControl: $this->createMock(ArchiMateImportControl::class),
			eolSyncService: $this->createMock(EolSyncService::class),
			emailSettings: $this->createMock(EmailSettings::class),
			logger: $logger
		);
	}//end controller()

	/**
	 * An admin maps Gebruik-beheerder onto Inkoop with a 180 day interval; the config returns both.
	 *
	 * @return void
	 */
	public function testAnAdminSavesTheMappingAndReadsItBack(): void {
		$save = $this->controller(
			isAdmin: true,
			params: [
				'roleMapping' => ['Gebruik-beheerder' => ['Inkoop']],
				'accessReviewIntervalDays' => 180,
				'_route' => 'stackiq.settings.updateUserGroupsConfig',
			]
		)->updateUserGroupsConfig();

		$this->assertSame(Http::STATUS_OK, $save->getStatus());
		$this->assertTrue($save->getData()['success'], json_encode($save->getData()));

		$read = $this->controller(isAdmin: true)->getUserGroupsConfig();
		$this->assertSame(Http::STATUS_OK, $read->getStatus());
		$config = $read->getData()['config'];
		$this->assertSame(['group' => 'gebruik-beheerder', 'sourceGroups' => ['Inkoop']], $config['roleMapping']['Gebruik-beheerder']);
		$this->assertSame(180, $config['accessReviewIntervalDays']);
		$this->assertSame('Aanbod-beheerder', $config['organisationTypeRoles']['Supplier']);
	}//end testAnAdminSavesTheMappingAndReadsItBack()

	/**
	 * An invalid mapping is refused as a failed update and stores nothing.
	 *
	 * @return void
	 */
	public function testAnInvalidMappingIsNotStored(): void {
		$save = $this->controller(isAdmin: true, params: ['roleMapping' => ['Gebruik-beheerder' => ['gebruik-beheerder']]])->updateUserGroupsConfig();

		$this->assertFalse($save->getData()['success']);
		$this->assertFalse($save->getData()['results']['roleMapping']['success']);
		$this->assertSame([], $this->stored);
	}//end testAnInvalidMappingIsNotStored()

	/**
	 * A non-admin gets 403 on read and write, and the mapping is kept.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefusedAndTheMappingIsKept(): void {
		$this->controller(isAdmin: true, params: ['roleMapping' => ['Gebruik-beheerder' => ['Inkoop']]])->updateUserGroupsConfig();
		$before = $this->stored;

		$write = $this->controller(
			isAdmin: false,
			params: ['roleMapping' => ['Gebruik-beheerder' => ['Iedereen']], 'accessReviewIntervalDays' => 1]
		)->updateUserGroupsConfig();
		$read = $this->controller(isAdmin: false)->getUserGroupsConfig();

		$this->assertSame(Http::STATUS_FORBIDDEN, $write->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $read->getStatus());
		$this->assertArrayNotHasKey('config', $read->getData());
		$this->assertSame($before, $this->stored, 'A refused write must keep the mapping as it was.');
	}//end testANonAdminIsRefusedAndTheMappingIsKept()
}//end class
