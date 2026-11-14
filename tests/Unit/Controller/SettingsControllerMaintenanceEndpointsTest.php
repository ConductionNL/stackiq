<?php

/**
 * Unit tests for the maintenance endpoints of SettingsController.
 *
 * Asserts the status, body, delegated service calls and log lines of
 * autoConfigure, consolidatedAutoConfigure, manualImport, forceUpdate,
 * performSync and sendTestEmail, plus the OpenRegister service lookups.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\Stackiq\Controller\SettingsController;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Maintenance settings endpoints.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
#[CoversClass(SettingsController::class)]
class SettingsControllerMaintenanceEndpointsTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Organisation sync service mock.
	 *
	 * @var OrganizationSyncService&MockObject
	 */
	private OrganizationSyncService&MockObject $orgSyncSvc;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * App manager mock.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->orgSyncSvc      = $this->createMock(OrganizationSyncService::class);
		$this->container       = $this->createMock(ContainerInterface::class);
		$this->appManager      = $this->createMock(IAppManager::class);
		$this->request         = $this->createMock(IRequest::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with the current mocks.
	 *
	 * @param array $params Request params returned by getParams().
	 *
	 * @return SettingsController
	 */
	private function controller(array $params=[]): SettingsController {
		$this->request->method('getParams')->willReturn($params);

		return new SettingsController(
			appName: 'stackiq',
			request: $this->request,
			config: $this->createMock(IAppConfig::class),
			container: $this->container,
			appManager: $this->appManager,
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			settingsService: $this->settingsService,
			cronjobSettings: $this->createMock(CronjobSettings::class),
			orgSyncSvc: $this->orgSyncSvc,
			archiMateService: $this->createMock(ArchiMateService::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			progressTracker: $this->createMock(ProgressTracker::class),
			archiMateImportControl: $this->createMock(ArchiMateImportControl::class),
			eolSyncService: $this->createMock(EolSyncService::class),
			emailSettings: $this->createMock(EmailSettings::class),
			logger: $this->logger
		);
	}//end controller()

	/**
	 * getObjectService fetches the OpenRegister object service when the app is installed.
	 *
	 * @return void
	 */
	public function testGetObjectServiceReturnsContainerServiceWhenInstalled(): void {
		$service = $this->createMock(ObjectServiceInterface::class);
		$this->appManager->method('getInstalledApps')->willReturn(['files', 'openregister']);
		$this->container->expects($this->once())->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($service);

		$this->assertSame($service, $this->controller()->getObjectService());
	}//end testGetObjectServiceReturnsContainerServiceWhenInstalled()

	/**
	 * getObjectService throws when OpenRegister is not installed.
	 *
	 * @return void
	 */
	public function testGetObjectServiceThrowsWithoutOpenRegister(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['files']);
		$this->container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister service is not available.');

		$this->controller()->getObjectService();
	}//end testGetObjectServiceThrowsWithoutOpenRegister()

	/**
	 * getConfigurationService fetches the configuration service when OpenRegister is installed.
	 *
	 * @return void
	 */
	public function testGetConfigurationServiceReturnsContainerServiceWhenInstalled(): void {
		$service = $this->createMock(ConfigurationService::class);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->expects($this->once())->method('get')
			->with('OCA\OpenRegister\Service\ConfigurationService')
			->willReturn($service);

		$this->assertSame($service, $this->controller()->getConfigurationService());
	}//end testGetConfigurationServiceReturnsContainerServiceWhenInstalled()

	/**
	 * getConfigurationService throws when OpenRegister is not installed.
	 *
	 * @return void
	 */
	public function testGetConfigurationServiceThrowsWithoutOpenRegister(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Configuration service is not available.');

		$this->controller()->getConfigurationService();
	}//end testGetConfigurationServiceThrowsWithoutOpenRegister()

	/**
	 * autoConfigure saves a found configuration and returns the saved result.
	 *
	 * @return void
	 */
	public function testAutoConfigureSavesFoundConfiguration(): void {
		$this->settingsService->expects($this->once())->method('autoConfigure')->willReturn(['register' => 'r1']);
		$this->settingsService->expects($this->once())->method('updateSettings')
			->with(['register' => 'r1'])
			->willReturn(['register' => 'r1', 'saved' => true]);

		$response = $this->controller()->autoConfigure();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'configuration' => ['register' => 'r1', 'saved' => true]],
			$response->getData()
		);
	}//end testAutoConfigureSavesFoundConfiguration()

	/**
	 * autoConfigure reports nothing found and saves nothing on an empty configuration.
	 *
	 * @return void
	 */
	public function testAutoConfigureReportsNothingFound(): void {
		$this->settingsService->method('autoConfigure')->willReturn([]);
		$this->settingsService->expects($this->never())->method('updateSettings');

		$response = $this->controller()->autoConfigure();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'No matching registers or schemas found for auto-configuration'],
			$response->getData()
		);
	}//end testAutoConfigureReportsNothingFound()

	/**
	 * autoConfigure answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testAutoConfigureReturns500WhenServiceThrows(): void {
		$this->settingsService->method('autoConfigure')->willThrowException(new RuntimeException('no db'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to auto-configure settings', ['exception' => 'no db']);

		$response = $this->controller()->autoConfigure();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'no db'], $response->getData());
	}//end testAutoConfigureReturns500WhenServiceThrows()

	/**
	 * sendTestEmail passes the address and settings to the service and echoes its outcome.
	 *
	 * @return void
	 */
	public function testSendTestEmailDelegatesToService(): void {
		$this->settingsService->expects($this->once())->method('sendTestEmail')
			->with('a@example.org', ['provider' => 'smtp'])
			->willReturn(['success' => true, 'message' => 'Sent', 'extra' => 'dropped']);

		$response = $this->controller(['email' => 'a@example.org', 'emailSettings' => ['provider' => 'smtp']])
			->sendTestEmail();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'message' => 'Sent'], $response->getData());
	}//end testSendTestEmailDelegatesToService()

	/**
	 * sendTestEmail defaults a missing address and settings to empty values.
	 *
	 * @return void
	 */
	public function testSendTestEmailDefaultsMissingParams(): void {
		$this->settingsService->expects($this->once())->method('sendTestEmail')
			->with('', [])
			->willReturn(['success' => false, 'message' => 'Email address required']);

		$response = $this->controller()->sendTestEmail();

		$this->assertSame(['success' => false, 'message' => 'Email address required'], $response->getData());
	}//end testSendTestEmailDefaultsMissingParams()

	/**
	 * sendTestEmail answers 500 and logs the request with credentials redacted.
	 *
	 * @return void
	 */
	public function testSendTestEmailRedactsCredentialsInErrorLog(): void {
		$params = ['email' => 'a@example.org', 'smtpPassword' => 'hunter2', 'apiKey' => 'k', 'host' => 'mail'];
		$this->settingsService->method('sendTestEmail')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('error')
			->with(
				'Stackiq: Failed to send test email in controller',
				[
					'exception_class' => RuntimeException::class,
					'exception_message' => 'smtp down',
					'requestData' => ['email' => 'a@example.org', 'smtpPassword' => '***', 'apiKey' => '***', 'host' => 'mail'],
				]
			);

		$response = $this->controller($params)->sendTestEmail();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Failed to send test email: smtp down'], $response->getData());
	}//end testSendTestEmailRedactsCredentialsInErrorLog()

	/**
	 * performSync runs the optimised batch sync for a full sync.
	 *
	 * @return void
	 */
	public function testPerformSyncRunsOptimisedFullSync(): void {
		$this->orgSyncSvc->expects($this->once())->method('performOptimizedManualSync')
			->with(15, 75)
			->willReturn(['processed' => 10]);
		$this->orgSyncSvc->expects($this->never())->method('performManualSync');

		$response = $this->controller()->performSync(0);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'results' => ['processed' => 10],
				'message' => 'Optimized synchronization completed successfully',
				'isOptimized' => true,
			],
			$response->getData()
		);
	}//end testPerformSyncRunsOptimisedFullSync()

	/**
	 * performSync runs an incremental sync and answers 200 on success.
	 *
	 * @return void
	 */
	public function testPerformSyncIncrementalSuccess(): void {
		$this->orgSyncSvc->expects($this->never())->method('performOptimizedManualSync');
		$this->orgSyncSvc->expects($this->once())->method('performManualSync')
			->with(30)
			->willReturn(['success' => true, 'synced' => 3]);

		$response = $this->controller()->performSync(30);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'synced' => 3], $response->getData());
	}//end testPerformSyncIncrementalSuccess()

	/**
	 * performSync answers 500 with the service body when an incremental sync fails.
	 *
	 * @return void
	 */
	public function testPerformSyncIncrementalFailureAnswers500(): void {
		$this->orgSyncSvc->method('performManualSync')->willReturn(['success' => false, 'message' => 'partial']);

		$response = $this->controller()->performSync(5);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'partial'], $response->getData());
	}//end testPerformSyncIncrementalFailureAnswers500()

	/**
	 * performSync answers 500 and logs the window when the service throws.
	 *
	 * @return void
	 */
	public function testPerformSyncReturns500WhenServiceThrows(): void {
		$this->orgSyncSvc->method('performManualSync')->willThrowException(new RuntimeException('timeout'));
		$this->logger->expects($this->once())->method('error')
			->with('Manual sync failed', ['minutesBack' => 7, 'exception' => 'timeout']);

		$response = $this->controller()->performSync(7);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Synchronization failed: timeout', 'error' => 'timeout'],
			$response->getData()
		);
	}//end testPerformSyncReturns500WhenServiceThrows()

	/**
	 * manualImport forces only on a boolean true and answers 200 on success with a timestamp.
	 *
	 * @return void
	 */
	public function testManualImportForcesOnBooleanTrue(): void {
		$this->settingsService->expects($this->once())->method('manualImport')
			->with(true)
			->willReturn(['success' => true, 'message' => 'Imported']);

		$before   = time();
		$response = $this->controller(['force' => true])->manualImport();
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertSame('Imported', $data['message']);
		$this->assertGreaterThanOrEqual($before, $data['timestamp']);
	}//end testManualImportForcesOnBooleanTrue()

	/**
	 * manualImport does not force on a string flag and answers 400 when the import fails.
	 *
	 * @return void
	 */
	public function testManualImportFailureAnswers400AndIgnoresStringFlag(): void {
		$this->settingsService->expects($this->once())->method('manualImport')
			->with(false)
			->willReturn(['success' => false]);

		$response = $this->controller(['force' => 'true'])->manualImport();

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}//end testManualImportFailureAnswers400AndIgnoresStringFlag()

	/**
	 * manualImport answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testManualImportReturns500WhenServiceThrows(): void {
		$exception = new RuntimeException('bad file');
		$this->settingsService->method('manualImport')->willThrowException($exception);
		$this->logger->expects($this->once())->method('error')
			->with(
				'SettingsController: Manual import failed',
				['exception_message' => 'bad file', 'exception' => $exception]
			);

		$response = $this->controller()->manualImport();
		$data     = $response->getData();

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('Import failed: bad file', $data['message']);
		$this->assertSame('bad file', $data['error']);
		$this->assertArrayHasKey('timestamp', $data);
	}//end testManualImportReturns500WhenServiceThrows()

	/**
	 * forceUpdate always answers 200 with the service result plus a timestamp.
	 *
	 * @return void
	 */
	public function testForceUpdateReturnsServiceResult(): void {
		$this->settingsService->expects($this->once())->method('forceUpdate')
			->willReturn(['success' => false, 'message' => 'Needs attention', 'details' => ['a' => 1]]);

		$response = $this->controller()->forceUpdate();
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('Needs attention', $data['message']);
		$this->assertSame(['a' => 1], $data['details']);
		$this->assertIsInt($data['timestamp']);
	}//end testForceUpdateReturnsServiceResult()

	/**
	 * forceUpdate falls back to a simplified body when the result cannot be JSON-encoded.
	 *
	 * @return void
	 */
	public function testForceUpdateSimplifiesUnserializableResult(): void {
		$this->settingsService->method('forceUpdate')
			->willReturn(['success' => true, 'binary' => "\xB1\x31"]);
		$this->logger->expects($this->once())->method('error')
			->with(
				'SettingsController: JSON serialization error',
				$this->callback(
					static fn (array $context): bool => $context['result_keys'] === ['success', 'binary', 'timestamp']
						&& is_string($context['json_error'])
				)
			);

		$response = $this->controller()->forceUpdate();
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success', 'message', 'timestamp'], array_keys($data));
		$this->assertTrue($data['success']);
		$this->assertSame('Force update completed but response serialization failed', $data['message']);
	}//end testForceUpdateSimplifiesUnserializableResult()

	/**
	 * forceUpdate answers 500 when the service throws an Error, not only an Exception.
	 *
	 * @return void
	 */
	public function testForceUpdateReturns500OnError(): void {
		$this->settingsService->method('forceUpdate')->willThrowException(new \TypeError('wrong type'));
		$this->logger->expects($this->once())->method('error')
			->with(
				'SettingsController: Force update failed',
				$this->callback(
					static fn (array $context): bool => $context['exception_message'] === 'wrong type'
						&& $context['exception_class'] === \TypeError::class
				)
			);

		$response = $this->controller()->forceUpdate();
		$data     = $response->getData();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('Force update failed: wrong type', $data['message']);
		$this->assertSame('wrong type', $data['error']);
	}//end testForceUpdateReturns500OnError()

	/**
	 * consolidatedAutoConfigure passes the force flag and answers 200 on success.
	 *
	 * @return void
	 */
	public function testConsolidatedAutoConfigureSuccess(): void {
		$this->request->method('getParam')->with('force', false)->willReturn(true);
		$this->settingsService->expects($this->once())->method('performConsolidatedAutoConfiguration')
			->with(true)
			->willReturn(['success' => true, 'steps' => 4]);

		$response = $this->controller()->consolidatedAutoConfigure();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'steps' => 4], $response->getData());
	}//end testConsolidatedAutoConfigureSuccess()

	/**
	 * consolidatedAutoConfigure answers 207 when it failed with listed errors.
	 *
	 * @return void
	 */
	public function testConsolidatedAutoConfigureWithErrorsAnswers207(): void {
		$this->request->method('getParam')->willReturn(false);
		$this->settingsService->method('performConsolidatedAutoConfiguration')
			->willReturn(['success' => false, 'errors' => ['schema missing']]);

		$response = $this->controller()->consolidatedAutoConfigure();

		$this->assertSame(207, $response->getStatus());
	}//end testConsolidatedAutoConfigureWithErrorsAnswers207()

	/**
	 * consolidatedAutoConfigure answers 500 when it failed without listed errors.
	 *
	 * @return void
	 */
	public function testConsolidatedAutoConfigureWithoutErrorsAnswers500(): void {
		$this->request->method('getParam')->willReturn(false);
		$this->settingsService->method('performConsolidatedAutoConfiguration')
			->willReturn(['success' => false, 'errors' => []]);

		$response = $this->controller()->consolidatedAutoConfigure();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'errors' => []], $response->getData());
	}//end testConsolidatedAutoConfigureWithoutErrorsAnswers500()

	/**
	 * consolidatedAutoConfigure answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testConsolidatedAutoConfigureReturns500WhenServiceThrows(): void {
		$exception = new RuntimeException('locked');
		$this->request->method('getParam')->willReturn(false);
		$this->settingsService->method('performConsolidatedAutoConfiguration')->willThrowException($exception);
		$this->logger->expects($this->once())->method('error')
			->with(
				'SettingsController: Consolidated auto-configuration failed',
				['exception_message' => 'locked', 'exception' => $exception]
			);

		$response = $this->controller()->consolidatedAutoConfigure();
		$data     = $response->getData();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('Auto-configuration failed: locked', $data['message']);
		$this->assertSame('locked', $data['error']);
	}//end testConsolidatedAutoConfigureReturns500WhenServiceThrows()
}//end class
