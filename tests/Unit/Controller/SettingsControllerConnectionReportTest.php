<?php

/**
 * SettingsController asks integriq to look again after an email settings save.
 *
 * Email settings reach storage through two routes: `POST /api/settings/email`
 * and the generic `PUT` or `POST /api/settings` with an `emailSettings` block,
 * which is the one the admin page's Save button uses. A report wired to only
 * one of them would leave the row stale after every save from the page.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-stackiq-conn-002-a-save-asks-integriq-to-look-again-and-a-run-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the email settings save paths and the connection report.
 *
 * @covers \OCA\Stackiq\Controller\SettingsController
 */
class SettingsControllerConnectionReportTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Build the controller as an admin request with the given params.
	 *
	 * @param array<string, mixed>         $params    The request params.
	 * @param ConnectionReportService|null $reports   The reporter, or none.
	 * @param bool                         $writeFails Whether the email settings write throws.
	 *
	 * @return SettingsController
	 */
	private function makeController(array $params, ?ConnectionReportService $reports, bool $writeFails = false): SettingsController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		if ($writeFails === true) {
			$this->settingsService->method('updateEmailSettings')->willThrowException(new RuntimeException('write failed'));
		} else {
			$this->settingsService->method('updateEmailSettings')->willReturn(['enabled' => false, 'transportType' => 'smtp']);
		}

		return new SettingsController(
			appName: 'stackiq',
			request: $request,
			config: $this->createMock(originalClassName: IAppConfig::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $groups,
			userSession: $session,
			settingsService: $this->settingsService,
			orgSyncSvc: $this->createMock(originalClassName: OrganizationSyncService::class),
			archiMateService: $this->createMock(originalClassName: ArchiMateService::class),
			progressTracker: $this->createMock(originalClassName: ProgressTracker::class),
			eolSyncService: $this->createMock(originalClassName: EolSyncService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			connectionReports: $reports
		);
	}//end makeController()

	/**
	 * The email endpoint asks for a report once, after the write.
	 *
	 * @return void
	 */
	public function testTheEmailEndpointAsksForAReport(): void {
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->once())->method('emailSettingsSaved')->willReturn(true);

		$response = $this->makeController(params: ['emailSettings' => ['enabled' => false]], reports: $reports)->updateEmailSettings();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testTheEmailEndpointAsksForAReport()

	/**
	 * The generic settings save asks for a report when it carries email settings, and only then.
	 *
	 * @return void
	 */
	public function testTheGenericSaveAsksOnlyWhenItWritesEmailSettings(): void {
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->once())->method('emailSettingsSaved')->willReturn(true);

		$this->makeController(params: ['emailSettings' => ['enabled' => true]], reports: $reports)->update();
		$this->makeController(params: ['userGroups' => []], reports: $reports)->update();
	}//end testTheGenericSaveAsksOnlyWhenItWritesEmailSettings()

	/**
	 * A save that fails asks for nothing: no setting changed.
	 *
	 * @return void
	 */
	public function testAFailedSaveAsksForNothing(): void {
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->never())->method('emailSettingsSaved');

		$controller = $this->makeController(params: ['emailSettings' => ['enabled' => true]], reports: $reports, writeFails: true);

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $controller->updateEmailSettings()->getStatus());
		$generic = $this->makeController(params: ['emailSettings' => []], reports: $reports, writeFails: true);
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $generic->update()->getStatus());
	}//end testAFailedSaveAsksForNothing()

	/**
	 * The response is the same with and without the reporter.
	 *
	 * @return void
	 */
	public function testTheResponseIsTheSameWithAndWithoutTheReporter(): void {
		$params = ['emailSettings' => ['enabled' => false]];

		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$with    = $this->makeController(params: $params, reports: $reports)->updateEmailSettings();
		$without = $this->makeController(params: $params, reports: null)->updateEmailSettings();

		$this->assertSame(expected: $without->getData(), actual: $with->getData());
		$this->assertSame(expected: $without->getStatus(), actual: $with->getStatus());
	}//end testTheResponseIsTheSameWithAndWithoutTheReporter()
}//end class
