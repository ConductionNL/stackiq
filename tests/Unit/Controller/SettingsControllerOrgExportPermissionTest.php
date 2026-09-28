<?php

/**
 * Permission tests for the organisation ArchiMate export.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
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
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * THE DEFECT UNDER TEST (stackiq#1136).
 *
 * `GET /api/archimate/export/organization/{organizationUuid}` lets a
 * Nextcloud admin through, or a member of a configured organisation admin
 * group. The group list came from `SettingsService::getOrganizationAdminGroups()`,
 * which always returned an empty list, so a member of a chosen group got 403
 * even for their own organisation.
 *
 * The controller runs against the REAL SettingsService over an in-memory
 * IAppConfig, so the saved setting travels the same path it does in
 * production. With the read restored, a group member may export only their
 * own active organisation (user value core/organisation, the rule
 * PortfolioReportController::isAuthorisedForOrganisation() applies); another
 * organisation's uuid is refused before the export runs.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SettingsControllerOrgExportPermissionTest extends TestCase {

	/**
	 * Uuid of the caller's own organisation.
	 *
	 * @var string
	 */
	private const OWN_ORG = '11111111-1111-4111-8111-111111111111';

	/**
	 * Uuid of an organisation the caller does not belong to.
	 *
	 * @var string
	 */
	private const OTHER_ORG = '22222222-2222-4222-8222-222222222222';

	/**
	 * How often the export service was reached.
	 *
	 * @var integer
	 */
	private int $exportCalls = 0;

	/**
	 * Build a SettingsController for a caller.
	 *
	 * @param string $uid The caller's UID.
	 * @param boolean $isAdmin Whether the caller is a Nextcloud admin.
	 * @param string[] $callerGroups The groups the caller is a member of.
	 * @param string[] $savedAdminGroups The organisation admin groups saved in settings.
	 * @param string $activeOrg The caller's active organisation uuid ('' for none).
	 *
	 * @return SettingsController
	 */
	private function makeController(
		string $uid,
		bool $isAdmin,
		array $callerGroups,
		array $savedAdminGroups,
		string $activeOrg
	): SettingsController {
		$store = ['organization_admin_groups' => json_encode($savedAdminGroups)];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static function (string $userId, string $group) use ($uid, $callerGroups): bool {
				return $userId === $uid && in_array($group, $callerGroups, true);
			}
		);

		$settingsService = new SettingsService(
			config: $appConfig,
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $groupManager,
			l10n: $this->createMock(IL10N::class)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userConfig = $this->createMock(IConfig::class);
		$userConfig->method('getUserValue')->willReturnCallback(
			static function (string $userId, string $appName, string $key, $default = '') use ($uid, $activeOrg) {
				if ($userId === $uid && $appName === 'core' && $key === 'organisation') {
					return $activeOrg;
				}

				return $default;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($userConfig) {
				if ($id === IConfig::class) {
					return $userConfig;
				}

				throw new \RuntimeException('Unexpected container lookup: ' . $id);
			}
		);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('exportOrgArchiMate')->willReturnCallback(
			function (): array {
				$this->exportCalls++;
				return ['success' => true, 'xml' => '<model/>', 'file_name' => 'org.xml'];
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return $default;
			}
		);

		return new SettingsController(
			'stackiq',
			$request,
			$appConfig,
			$container,
			$this->createMock(IAppManager::class),
			$groupManager,
			$userSession,
			$settingsService,
			$this->createMock(OrganizationSyncService::class),
			$archiMateService,
			$this->createMock(ProgressTracker::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end makeController()

	/**
	 * A member of a saved organisation admin group exports their own organisation.
	 *
	 * @return void
	 */
	public function testMemberOfASavedGroupExportsTheirOwnOrganisation(): void {
		$controller = $this->makeController(
			uid: 'beheerder-a',
			isAdmin: false,
			callerGroups: ['org-admins'],
			savedAdminGroups: ['org-admins'],
			activeOrg: self::OWN_ORG
		);

		$response = $controller->exportOrgArchiMate(self::OWN_ORG);

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'A member of a saved organisation admin group must be able to export their own organisation.'
		);
		$this->assertSame(1, $this->exportCalls);
	}//end testMemberOfASavedGroupExportsTheirOwnOrganisation()

	/**
	 * A member of a saved group cannot export another organisation.
	 *
	 * @return void
	 */
	public function testMemberOfASavedGroupCannotExportAnotherOrganisation(): void {
		$controller = $this->makeController(
			uid: 'beheerder-a',
			isAdmin: false,
			callerGroups: ['org-admins'],
			savedAdminGroups: ['org-admins'],
			activeOrg: self::OWN_ORG
		);

		$response = $controller->exportOrgArchiMate(self::OTHER_ORG);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $this->exportCalls, 'The export must not run for a refused caller.');
	}//end testMemberOfASavedGroupCannotExportAnotherOrganisation()

	/**
	 * A member of a saved group without an active organisation is refused.
	 *
	 * @return void
	 */
	public function testMemberWithoutAnActiveOrganisationIsRefused(): void {
		$controller = $this->makeController(
			uid: 'beheerder-a',
			isAdmin: false,
			callerGroups: ['org-admins'],
			savedAdminGroups: ['org-admins'],
			activeOrg: ''
		);

		$response = $controller->exportOrgArchiMate(self::OWN_ORG);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $this->exportCalls);
	}//end testMemberWithoutAnActiveOrganisationIsRefused()

	/**
	 * A user outside every saved group is refused, even for their own organisation.
	 *
	 * @return void
	 */
	public function testUserOutsideTheSavedGroupsIsRefused(): void {
		$controller = $this->makeController(
			uid: 'plain-user',
			isAdmin: false,
			callerGroups: ['software-catalog-users'],
			savedAdminGroups: ['org-admins'],
			activeOrg: self::OWN_ORG
		);

		$response = $controller->exportOrgArchiMate(self::OWN_ORG);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $this->exportCalls);
	}//end testUserOutsideTheSavedGroupsIsRefused()

	/**
	 * A member of a saved group cannot export the whole register.
	 *
	 * The whole-register route passes no organisation, so restoring the read
	 * must not widen it beyond Nextcloud admins.
	 *
	 * @return void
	 */
	public function testMemberOfASavedGroupCannotExportTheWholeRegister(): void {
		$controller = $this->makeController(
			uid: 'beheerder-a',
			isAdmin: false,
			callerGroups: ['org-admins'],
			savedAdminGroups: ['org-admins'],
			activeOrg: self::OWN_ORG
		);

		$response = $controller->exportArchiMate();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $this->exportCalls);
	}//end testMemberOfASavedGroupCannotExportTheWholeRegister()

	/**
	 * A Nextcloud admin still exports any organisation.
	 *
	 * @return void
	 */
	public function testAdminExportsAnyOrganisation(): void {
		$controller = $this->makeController(
			uid: 'an-admin',
			isAdmin: true,
			callerGroups: ['admin'],
			savedAdminGroups: [],
			activeOrg: ''
		);

		$response = $controller->exportOrgArchiMate(self::OTHER_ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $this->exportCalls);
	}//end testAdminExportsAnyOrganisation()
}//end class
