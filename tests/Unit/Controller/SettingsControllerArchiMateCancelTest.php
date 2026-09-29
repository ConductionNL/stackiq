<?php

/**
 * Tests the ArchiMate cancel endpoint's handling of the operation id.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The cancel endpoint passes a well-formed id on and refuses any other.
 */
class SettingsControllerArchiMateCancelTest extends TestCase {

	/**
	 * Build the controller for an admin posting the given operation id.
	 *
	 * @param mixed           $operationId     The posted id.
	 * @param SettingsService $settingsService The settings service.
	 *
	 * @return SettingsController The controller.
	 */
	private function controller(mixed $operationId, SettingsService $settingsService): SettingsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'operationId' ? $operationId : $default
		);

		return new SettingsController(
			'stackiq',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$groupManager,
			$userSession,
			$settingsService,
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(ArchiMateService::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A well-formed id reaches the settings service and the answer is 200.
	 *
	 * @return void
	 */
	public function testAWellFormedIdIsPassedOn(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('cancelArchiMateImport')
			->with('archimate_import_abc12345')
			->willReturn(['cancelled' => true]);

		$response = $this->controller('archimate_import_abc12345', $settings)->cancelArchiMateImport();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testAWellFormedIdIsPassedOn()

	/**
	 * Any other id is answered 400 and cancels nothing.
	 *
	 * @return void
	 */
	public function testAnotherIdIsRefused(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('cancelArchiMateImport');

		$response = $this->controller('sbom-import_abc', $settings)->cancelArchiMateImport();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnotherIdIsRefused()
}//end class
