<?php

/**
 * Unit tests for SettingsController endpoints called without a session.
 *
 * Asserts that the organisation ArchiMate export and the cronjob
 * configuration answer 401 when nobody is logged in, without reaching the
 * export or the cronjob settings.
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
use OCA\Stackiq\Kernel\Contracts\ArchiMateImportControlInterface;
use OCA\Stackiq\Kernel\Contracts\ArchiMateServiceInterface;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\EmailSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\EolSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\OrganizationSyncServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ProgressTrackerInterface;
use OCA\Stackiq\Kernel\Contracts\RoleGroupSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(SettingsController::class)]
class SettingsControllerUnauthenticatedTest extends TestCase {

	/**
	 * The ArchiMate export; never reached without a session.
	 *
	 * @var ArchiMateServiceInterface&MockObject
	 */
	private ArchiMateServiceInterface&MockObject $archiMate;

	/**
	 * The cronjob settings; never reached without a session.
	 *
	 * @var CronjobSettingsInterface&MockObject
	 */
	private CronjobSettingsInterface&MockObject $cronjobSettings;

	/**
	 * The group manager; no role is looked up without a session.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The controller under test, with no user in the session.
	 *
	 * @var SettingsController
	 */
	private SettingsController $controller;

	/**
	 * Build the controller with an empty session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->archiMate       = $this->createMock(ArchiMateServiceInterface::class);
		$this->cronjobSettings = $this->createMock(CronjobSettingsInterface::class);
		$this->groupManager    = $this->createMock(IGroupManager::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->controller = new SettingsController(
			'stackiq',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->groupManager,
			$session,
			$this->createMock(SettingsServiceInterface::class),
			$this->cronjobSettings,
			$this->createMock(OrganizationSyncServiceInterface::class),
			$this->archiMate,
			$this->createMock(RoleGroupSettingsInterface::class),
			$this->createMock(ProgressTrackerInterface::class),
			$this->createMock(ArchiMateImportControlInterface::class),
			$this->createMock(EolSyncServiceInterface::class),
			$this->createMock(EmailSettingsInterface::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The organisation export is 401 without a session and exports nothing.
	 *
	 * @return void
	 */
	public function testTheOrganisationExportNeedsASession(): void {
		$this->archiMate->expects(self::never())->method(self::anything());
		$this->groupManager->expects(self::never())->method(self::anything());

		$response = $this->controller->exportOrgArchiMate(organizationUuid: 'org-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testTheOrganisationExportNeedsASession()

	/**
	 * The cronjob configuration is 401 without a session and reads nothing.
	 *
	 * @return void
	 */
	public function testTheCronjobConfigNeedsASession(): void {
		$this->cronjobSettings->expects(self::never())->method(self::anything());

		$response = $this->controller->getCronjobConfig();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testTheCronjobConfigNeedsASession()
}//end class
