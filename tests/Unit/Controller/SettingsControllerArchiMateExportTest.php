<?php

/**
 * Unit tests for SettingsController's ArchiMate exports: the authentication
 * and admin gate, the download on success, and the HTTP status each failure
 * maps to.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
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
 * ArchiMate export endpoints.
 */
final class SettingsControllerArchiMateExportTest extends TestCase {

	/**
	 * Mock export service.
	 *
	 * @var ArchiMateService&MockObject
	 */
	private ArchiMateService&MockObject $archiMate;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->archiMate = $this->createMock(ArchiMateService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->params = [];

	}//end setUp()

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null $uid The logged-in user, null for anonymous
	 * @param bool $admin Whether the caller is a Nextcloud admin
	 *
	 * @return SettingsController
	 */
	private function controller(?string $uid = 'admin', bool $admin = true): SettingsController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);
		$roleGroups = $this->createMock(RoleGroupSettings::class);
		$roleGroups->method('getOrganizationAdminGroups')->willReturn([]);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groupManager,
			$userSession,
			$this->createMock(SettingsService::class),
			$this->createMock(CronjobSettings::class),
			$this->createMock(OrganizationSyncService::class),
			$this->archiMate,
			$roleGroups,
			$this->createMock(ProgressTracker::class),
			$this->createMock(ArchiMateImportControl::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(EmailSettings::class),
			$this->logger
		);

	}//end controller()

	/**
	 * An anonymous caller gets 401 and a non-admin 403 on the full export; nothing is exported.
	 *
	 * @return void
	 */
	public function testFullExportNeedsAnAdmin(): void {
		$this->archiMate->expects($this->never())->method('exportToArchiMate');

		$anonymous = $this->controller(uid: null)->exportArchiMate();
		$this->assertSame(401, $anonymous->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $anonymous->getData());

		$member = $this->controller(uid: 'bob', admin: false)->exportArchiMate();
		$this->assertSame(403, $member->getStatus());
		$this->assertSame(['message' => 'Admin or organisation-admin privileges required'], $member->getData());

	}//end testFullExportNeedsAnAdmin()

	/**
	 * A successful full export downloads the XML, for the organisation given as a parameter, and is logged.
	 *
	 * @return void
	 */
	public function testFullExportDownloadsTheXml(): void {
		$this->params = ['organization' => 'org-1'];
		$this->archiMate->expects($this->once())
			->method('exportToArchiMate')
			->with('org-1')
			->willReturn(['success' => true, 'xml' => '<model id="m"/>', 'file_name' => 'register.xml', 'statistics' => ['objects_exported' => 12]]);
		$this->logger->expects($this->once())
			->method('info')
			->with('ArchiMate export completed', ['fileName' => 'register.xml', 'size' => 15, 'objects_exported' => 12]);

		$response = $this->controller()->exportArchiMate();

		$this->assertNotInstanceOf(JSONResponse::class, $response);
		$this->assertSame('<model id="m"/>', $response->render());

	}//end testFullExportDownloadsTheXml()

	/**
	 * A failed full export answers JSON with the status its error maps to.
	 *
	 * @return void
	 */
	public function testFullExportFailuresMapToStatuses(): void {
		$results = [
			[['success' => false, 'error' => 'Register not configured'], 400, 'Register not configured', 'Register not configured'],
			[['success' => false, 'error' => 'Schema not found'], 404, 'Schema not found', 'Schema not found'],
			[['success' => false], 500, 'Export failed', 'EXPORT_FAILED'],
		];
		foreach ($results as [$result, $status, $message, $error]) {
			$this->setUp();
			$this->archiMate->method('exportToArchiMate')->willReturn($result);

			$response = $this->controller()->exportArchiMate();

			$this->assertSame($status, $response->getStatus(), $message);
			$this->assertSame(['success' => false, 'message' => $message, 'error' => $error], $response->getData());
		}

	}//end testFullExportFailuresMapToStatuses()

	/**
	 * A throwing full export is logged and answered by the exception's status.
	 *
	 * @return void
	 */
	public function testFullExportExceptionIsLoggedAndMapped(): void {
		$this->archiMate->method('exportToArchiMate')->willThrowException(new \InvalidArgumentException('bad organisation'));
		$this->logger->expects($this->once())->method('error')->with('ArchiMate export failed', $this->arrayHasKey('trace'));

		$response = $this->controller()->exportArchiMate();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Export failed: bad organisation', 'error' => 'bad organisation'], $response->getData());

	}//end testFullExportExceptionIsLoggedAndMapped()

	/**
	 * The organisation export passes the view options from the request.
	 *
	 * @return void
	 */
	public function testOrgExportPassesTheOptions(): void {
		$this->params = ['modules' => 'false', 'deelnames' => 'true'];
		$this->archiMate->expects($this->once())
			->method('exportOrgArchiMate')
			->with('org-1', ['modules' => false, 'deelnames' => true, 'usage' => false])
			->willReturn(['success' => true, 'xml' => '<model/>']);

		$response = $this->controller()->exportOrgArchiMate('org-1');

		$this->assertSame('<model/>', $response->render());

	}//end testOrgExportPassesTheOptions()

	/**
	 * A failed organisation export is 404 when the organisation is not found, else 500; an exception maps by its message.
	 *
	 * @return void
	 */
	public function testOrgExportFailures(): void {
		$this->archiMate->method('exportOrgArchiMate')->willReturnOnConsecutiveCalls(
			['success' => false, 'error' => 'Organisation not found'],
			['success' => false],
			$this->throwException(new RuntimeException('Validation failed for org'))
		);
		$controller = $this->controller();

		$notFound = $controller->exportOrgArchiMate('org-x');
		$this->assertSame(404, $notFound->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Organisation not found', 'error' => 'Organisation not found'], $notFound->getData());

		$failed = $controller->exportOrgArchiMate('org-x');
		$this->assertSame(500, $failed->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Export failed', 'error' => 'EXPORT_FAILED'], $failed->getData());

		$thrown = $controller->exportOrgArchiMate('org-x');
		$this->assertSame(422, $thrown->getStatus());
		$this->assertSame('Export failed: Validation failed for org', $thrown->getData()['message']);

	}//end testOrgExportFailures()
}//end class
