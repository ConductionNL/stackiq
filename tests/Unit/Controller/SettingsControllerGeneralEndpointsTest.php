<?php

/**
 * Unit tests for the general, sync, EOL, cache and maintenance endpoints of
 * SettingsController.
 *
 * Asserts the status, body, delegated service calls and log lines of
 * index, load, initialize, stats, debug, clearCache, resetAutoConfig, the
 * general/sync/EOL config endpoints, the deprecated cronjob-users endpoint
 * and the exception-to-status mapping used by bulkSyncStandards.
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
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\CronjobSettings;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * General settings endpoints.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
#[CoversClass(SettingsController::class)]
class SettingsControllerGeneralEndpointsTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * App config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

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
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * EOL sync service mock.
	 *
	 * @var EolSyncService&MockObject
	 */
	private EolSyncService&MockObject $eolSyncService;

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
		$this->config          = $this->createMock(IAppConfig::class);
		$this->container       = $this->createMock(ContainerInterface::class);
		$this->appManager      = $this->createMock(IAppManager::class);
		$this->groupManager    = $this->createMock(IGroupManager::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->eolSyncService  = $this->createMock(EolSyncService::class);
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
			config: $this->config,
			container: $this->container,
			appManager: $this->appManager,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			settingsService: $this->settingsService,
			cronjobSettings: $this->createMock(CronjobSettings::class),
			orgSyncSvc: $this->createMock(OrganizationSyncService::class),
			archiMateService: $this->createMock(ArchiMateService::class),
			roleGroupSettings: $this->createMock(RoleGroupSettings::class),
			progressTracker: $this->createMock(ProgressTracker::class),
			eolSyncService: $this->eolSyncService,
			emailSettings: $this->createMock(EmailSettings::class),
			logger: $this->logger
		);
	}//end controller()

	/**
	 * Sign a user in, optionally as administrator.
	 *
	 * @param string $uid     The user id.
	 * @param bool   $isAdmin Whether the group manager reports an admin.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}//end signIn()

	/**
	 * index refuses an anonymous caller without touching the service.
	 *
	 * @return void
	 */
	public function testIndexRefusesAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getAllSettings');

		$response = $this->controller()->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testIndexRefusesAnonymousCaller()

	/**
	 * index merges the OpenRegister flag and the admin flag into the settings.
	 *
	 * @return void
	 */
	public function testIndexAddsOpenRegisterAndAdminFlags(): void {
		$this->signIn(uid: 'alice', isAdmin: true);
		$this->settingsService->expects($this->once())->method('getAllSettings')->willReturn(['a' => 1]);
		$this->appManager->method('getInstalledApps')->willReturn(['files', 'openregister']);

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['a' => 1, 'openRegisters' => true, 'isAdmin' => true], $response->getData());
	}//end testIndexAddsOpenRegisterAndAdminFlags()

	/**
	 * index reports a missing OpenRegister and a non-admin caller.
	 *
	 * @return void
	 */
	public function testIndexReportsMissingOpenRegisterForNonAdmin(): void {
		$this->signIn(uid: 'bob', isAdmin: false);
		$this->settingsService->method('getAllSettings')->willReturn([]);
		$this->appManager->method('getInstalledApps')->willReturn(['files']);

		$data = $this->controller()->index()->getData();

		$this->assertSame(['openRegisters' => false, 'isAdmin' => false], $data);
	}//end testIndexReportsMissingOpenRegisterForNonAdmin()

	/**
	 * index answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testIndexReturns500WhenServiceThrows(): void {
		$this->signIn(uid: 'alice', isAdmin: true);
		$this->settingsService->method('getAllSettings')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to retrieve settings', ['exception' => 'boom']);

		$response = $this->controller()->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'boom'], $response->getData());
	}//end testIndexReturns500WhenServiceThrows()

	/**
	 * load and initialize pass the service result through unchanged.
	 *
	 * @return void
	 */
	public function testLoadAndInitializeReturnServiceResult(): void {
		$this->settingsService->expects($this->once())->method('loadSettings')->willReturn(['loaded' => true]);
		$this->settingsService->expects($this->once())->method('initialize')->willReturn(['initialized' => 3]);

		$controller = $this->controller();

		$this->assertSame(['loaded' => true], $controller->load()->getData());
		$this->assertSame(['initialized' => 3], $controller->initialize()->getData());
	}//end testLoadAndInitializeReturnServiceResult()

	/**
	 * load answers 500 with the message when the service throws.
	 *
	 * @return void
	 */
	public function testLoadReturns500WhenServiceThrows(): void {
		$this->settingsService->method('loadSettings')->willThrowException(new \RuntimeException('no file'));

		$response = $this->controller()->load();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'no file'], $response->getData());
	}//end testLoadReturns500WhenServiceThrows()

	/**
	 * initialize answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testInitializeReturns500AndLogsWhenServiceThrows(): void {
		$this->settingsService->method('initialize')->willThrowException(new \RuntimeException('init failed'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to initialize settings', ['exception' => 'init failed']);

		$response = $this->controller()->initialize();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'init failed'], $response->getData());
	}//end testInitializeReturns500AndLogsWhenServiceThrows()

	/**
	 * stats refuses anonymous callers and wraps the statistics otherwise.
	 *
	 * @return void
	 */
	public function testStatsRefusesAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getObjectCountsStatistics');

		$response = $this->controller()->stats();

		$this->assertSame(401, $response->getStatus());
	}//end testStatsRefusesAnonymousCaller()

	/**
	 * stats wraps the service statistics for a signed-in caller.
	 *
	 * @return void
	 */
	public function testStatsWrapsStatistics(): void {
		$this->signIn(uid: 'bob', isAdmin: false);
		$this->settingsService->method('getObjectCountsStatistics')->willReturn(['apps' => 4]);

		$response = $this->controller()->stats();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'statistics' => ['apps' => 4]], $response->getData());
	}//end testStatsWrapsStatistics()

	/**
	 * stats answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testStatsReturns500WhenServiceThrows(): void {
		$this->signIn(uid: 'bob', isAdmin: false);
		$this->settingsService->method('getObjectCountsStatistics')->willThrowException(new \RuntimeException('db'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to get object counts statistics', ['exception' => 'db']);

		$response = $this->controller()->stats();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'db'], $response->getData());
	}//end testStatsReturns500WhenServiceThrows()

	/**
	 * debug answers 401 to anonymous and 403 to non-admin callers.
	 *
	 * @return void
	 */
	public function testDebugRefusesAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getDebugInfo');

		$response = $this->controller()->debug();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testDebugRefusesAnonymousCaller()

	/**
	 * debug refuses a signed-in non-admin with 403.
	 *
	 * @return void
	 */
	public function testDebugRefusesNonAdmin(): void {
		$this->signIn(uid: 'bob', isAdmin: false);
		$this->settingsService->expects($this->never())->method('getDebugInfo');

		$response = $this->controller()->debug();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'Admin privileges required'], $response->getData());
	}//end testDebugRefusesNonAdmin()

	/**
	 * debug returns the debug info to an admin, and 500 when it throws.
	 *
	 * @return void
	 */
	public function testDebugReturnsInfoForAdmin(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->settingsService->method('getDebugInfo')->willReturn(['version' => '1.0']);

		$response = $this->controller()->debug();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['version' => '1.0'], $response->getData());
	}//end testDebugReturnsInfoForAdmin()

	/**
	 * debug answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testDebugReturns500WhenServiceThrows(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->settingsService->method('getDebugInfo')->willThrowException(new \RuntimeException('nope'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to get debug information', ['exception' => 'nope']);

		$response = $this->controller()->debug();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'nope'], $response->getData());
	}//end testDebugReturns500WhenServiceThrows()

	/**
	 * clearCache clears the configuration cache and reports success.
	 *
	 * @return void
	 */
	public function testClearCacheClearsAndReportsSuccess(): void {
		$this->settingsService->expects($this->once())->method('clearConfigurationCache');
		$this->logger->expects($this->once())->method('info')
			->with('SettingsController: Clearing configuration cache');

		$response = $this->controller()->clearCache();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'message' => 'Configuration cache cleared successfully'],
			$response->getData()
		);
	}//end testClearCacheClearsAndReportsSuccess()

	/**
	 * clearCache answers 500 with message and error when clearing throws.
	 *
	 * @return void
	 */
	public function testClearCacheReturns500WhenClearingThrows(): void {
		$this->settingsService->method('clearConfigurationCache')->willThrowException(new \RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')
			->with('SettingsController: Cache clear failed', ['exception' => 'locked']);

		$response = $this->controller()->clearCache();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Cache clear failed: locked', 'error' => 'locked'],
			$response->getData()
		);
	}//end testClearCacheReturns500WhenClearingThrows()

	/**
	 * resetAutoConfig passes a strict-true resetConfiguration flag through.
	 *
	 * @return void
	 */
	public function testResetAutoConfigPassesStrictTrueFlag(): void {
		$this->settingsService->expects($this->once())->method('resetAutoConfiguration')
			->with(true)->willReturn(['success' => true]);

		$response = $this->controller(params: ['resetConfiguration' => true])->resetAutoConfig();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testResetAutoConfigPassesStrictTrueFlag()

	/**
	 * resetAutoConfig treats a non-boolean flag as false and maps failure to 400.
	 *
	 * @return void
	 */
	public function testResetAutoConfigTreatsStringFlagAsFalseAndFailureAs400(): void {
		$this->settingsService->expects($this->once())->method('resetAutoConfiguration')
			->with(false)->willReturn(['success' => false, 'message' => 'not reset']);

		$response = $this->controller(params: ['resetConfiguration' => 'true'])->resetAutoConfig();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'not reset'], $response->getData());
	}//end testResetAutoConfigTreatsStringFlagAsFalseAndFailureAs400()

	/**
	 * resetAutoConfig answers 500 when the service throws.
	 *
	 * @return void
	 */
	public function testResetAutoConfigReturns500WhenServiceThrows(): void {
		$this->settingsService->method('resetAutoConfiguration')->willThrowException(new \RuntimeException('x'));

		$response = $this->controller()->resetAutoConfig();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Reset failed: x', 'error' => 'x'], $response->getData());
	}//end testResetAutoConfigReturns500WhenServiceThrows()

	/**
	 * getGeneralConfig returns the catalog location.
	 *
	 * @return void
	 */
	public function testGetGeneralConfigReturnsCatalogLocation(): void {
		$this->settingsService->method('getCatalogLocation')->willReturn('/catalog');

		$response = $this->controller()->getGeneralConfig();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'config' => ['catalogLocation' => '/catalog']], $response->getData());
	}//end testGetGeneralConfigReturnsCatalogLocation()

	/**
	 * getGeneralConfig answers 500 and logs without request data on failure.
	 *
	 * @return void
	 */
	public function testGetGeneralConfigReturns500WhenServiceThrows(): void {
		$this->settingsService->method('getCatalogLocation')->willThrowException(new \RuntimeException('gone'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to get general config', ['exception' => 'gone']);

		$response = $this->controller()->getGeneralConfig();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Failed to get general config: gone'], $response->getData());
	}//end testGetGeneralConfigReturns500WhenServiceThrows()

	/**
	 * updateGeneralConfig stores a given catalog location and echoes the stored value.
	 *
	 * @return void
	 */
	public function testUpdateGeneralConfigStoresCatalogLocation(): void {
		$this->settingsService->expects($this->once())->method('setCatalogLocation')->with('/new');
		$this->settingsService->method('getCatalogLocation')->willReturn('/new');

		$response = $this->controller(params: ['catalogLocation' => '/new'])->updateGeneralConfig();

		$this->assertSame(
			[
				'success' => true,
				'message' => 'General configuration updated successfully',
				'config' => ['catalogLocation' => '/new'],
			],
			$response->getData()
		);
	}//end testUpdateGeneralConfigStoresCatalogLocation()

	/**
	 * updateGeneralConfig without a catalog location stores nothing.
	 *
	 * @return void
	 */
	public function testUpdateGeneralConfigWithoutLocationStoresNothing(): void {
		$this->settingsService->expects($this->never())->method('setCatalogLocation');
		$this->settingsService->method('getCatalogLocation')->willReturn('/old');

		$data = $this->controller(params: ['other' => 'x'])->updateGeneralConfig()->getData();

		$this->assertSame(['catalogLocation' => '/old'], $data['config']);
	}//end testUpdateGeneralConfigWithoutLocationStoresNothing()

	/**
	 * updateGeneralConfig logs redacted request data when storing throws.
	 *
	 * @return void
	 */
	public function testUpdateGeneralConfigLogsRedactedParamsOnFailure(): void {
		$this->settingsService->method('setCatalogLocation')->willThrowException(new \RuntimeException('ro'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to update general config',
			[
				'exception' => 'ro',
				'requestData' => ['catalogLocation' => '/x', 'apiKey' => '***', 'password' => '***'],
			]
		);

		$response = $this->controller(params: ['catalogLocation' => '/x', 'apiKey' => 'k', 'password' => 'p'])
			->updateGeneralConfig();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Failed to update general config: ro'],
			$response->getData()
		);
	}//end testUpdateGeneralConfigLogsRedactedParamsOnFailure()

	/**
	 * getSyncConfig reads syncTimeWindow with default "10".
	 *
	 * @return void
	 */
	public function testGetSyncConfigReadsTimeWindow(): void {
		$this->config->expects($this->once())->method('getValueString')
			->with('stackiq', 'syncTimeWindow', '10')->willReturn('15');

		$response = $this->controller()->getSyncConfig();

		$this->assertSame(['success' => true, 'config' => ['syncTimeWindow' => '15']], $response->getData());
	}//end testGetSyncConfigReadsTimeWindow()

	/**
	 * getSyncConfig answers 500 when the config read throws.
	 *
	 * @return void
	 */
	public function testGetSyncConfigReturns500WhenConfigThrows(): void {
		$this->config->method('getValueString')->willThrowException(new \RuntimeException('cfg'));

		$response = $this->controller()->getSyncConfig();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Failed to get sync config: cfg'], $response->getData());
	}//end testGetSyncConfigReturns500WhenConfigThrows()

	/**
	 * updateSyncConfig stores the time window as a string.
	 *
	 * @return void
	 */
	public function testUpdateSyncConfigStoresTimeWindowAsString(): void {
		$this->config->expects($this->once())->method('setValueString')->with('stackiq', 'syncTimeWindow', '30');
		$this->config->method('getValueString')->willReturn('30');

		$response = $this->controller(params: ['syncTimeWindow' => 30])->updateSyncConfig();

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Sync configuration updated successfully',
				'config' => ['syncTimeWindow' => '30'],
			],
			$response->getData()
		);
	}//end testUpdateSyncConfigStoresTimeWindowAsString()

	/**
	 * updateSyncConfig without a time window writes nothing; a write failure answers 500.
	 *
	 * @return void
	 */
	public function testUpdateSyncConfigWithoutTimeWindowWritesNothing(): void {
		$this->config->expects($this->never())->method('setValueString');
		$this->config->method('getValueString')->willReturn('10');

		$data = $this->controller()->updateSyncConfig()->getData();

		$this->assertSame(['syncTimeWindow' => '10'], $data['config']);
	}//end testUpdateSyncConfigWithoutTimeWindowWritesNothing()

	/**
	 * updateSyncConfig answers 500 when the write throws.
	 *
	 * @return void
	 */
	public function testUpdateSyncConfigReturns500WhenWriteThrows(): void {
		$this->config->method('setValueString')->willThrowException(new \RuntimeException('w'));

		$response = $this->controller(params: ['syncTimeWindow' => '5'])->updateSyncConfig();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Failed to update sync config: w'], $response->getData());
	}//end testUpdateSyncConfigReturns500WhenWriteThrows()

	/**
	 * The EOL endpoints delegate to the EOL sync service.
	 *
	 * @return void
	 */
	public function testEolEndpointsDelegateToService(): void {
		$this->eolSyncService->method('getConfig')->willReturn(['interval' => 'daily']);
		$this->eolSyncService->method('getStatus')->willReturn(['state' => 'idle']);
		$this->eolSyncService->expects($this->once())->method('run')->willReturn(['state' => 'done']);
		$this->eolSyncService->expects($this->once())->method('updateConfig')
			->with(['interval' => 'weekly'])->willReturn(['saved' => true]);

		$controller = $this->controller(params: ['interval' => 'weekly']);

		$this->assertSame(['success' => true, 'config' => ['interval' => 'daily']], $controller->getEolSyncConfig()->getData());
		$this->assertSame(['success' => true, 'status' => ['state' => 'idle']], $controller->getEolSyncStatus()->getData());
		$this->assertSame(['success' => true, 'status' => ['state' => 'done']], $controller->triggerEolSync()->getData());
		$this->assertSame(['saved' => true], $controller->updateEolSyncConfig()->getData());
	}//end testEolEndpointsDelegateToService()

	/**
	 * The EOL endpoints answer 500 with their own message when the service throws.
	 *
	 * @return void
	 */
	public function testEolEndpointsReturn500WhenServiceThrows(): void {
		$error = new \RuntimeException('feed');
		$this->eolSyncService->method('getConfig')->willThrowException($error);
		$this->eolSyncService->method('getStatus')->willThrowException($error);
		$this->eolSyncService->method('run')->willThrowException($error);
		$this->eolSyncService->method('updateConfig')->willThrowException($error);

		$controller = $this->controller();
		$responses  = [
			$controller->getEolSyncConfig(),
			$controller->getEolSyncStatus(),
			$controller->triggerEolSync(),
			$controller->updateEolSyncConfig(),
		];

		$this->assertSame([500, 500, 500, 500], array_map(fn (JSONResponse $r) => $r->getStatus(), $responses));
		$this->assertSame(
			[
				'Failed to get EOL sync config: feed',
				'Failed to get EOL sync status: feed',
				'Failed to trigger EOL sync: feed',
				'Failed to update EOL sync config: feed',
			],
			array_map(fn (JSONResponse $r) => $r->getData()['message'], $responses)
		);
	}//end testEolEndpointsReturn500WhenServiceThrows()

	/**
	 * getCronjobUsers refuses anonymous callers and is gone for everyone else.
	 *
	 * @return void
	 */
	public function testGetCronjobUsersIsGoneForSignedInCaller(): void {
		$this->signIn(uid: 'bob', isAdmin: false);

		$response = $this->controller()->getCronjobUsers();

		$this->assertSame(410, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('deprecated', $response->getData()['message']);
	}//end testGetCronjobUsersIsGoneForSignedInCaller()

	/**
	 * getCronjobUsers refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testGetCronjobUsersRefusesAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(401, $this->controller()->getCronjobUsers()->getStatus());
	}//end testGetCronjobUsersRefusesAnonymousCaller()

	/**
	 * bulkSyncStandards maps the failure to a status by type and message.
	 *
	 * @return array<string, array{0: \Exception, 1: int}>
	 */
	public static function bulkSyncFailureProvider(): array {
		return [
			'invalid argument'      => [new \InvalidArgumentException('anything'), 400],
			'not configured'        => [new \RuntimeException('Register NOT configured'), 400],
			'missing configuration' => [new \RuntimeException('missing configuration key'), 400],
			'invalid configuration' => [new \RuntimeException('invalid configuration'), 400],
			'file not found'        => [new \RuntimeException('File not found'), 404],
			'not found'             => [new \RuntimeException('schema not found'), 404],
			'missing file'          => [new \RuntimeException('missing file x'), 404],
			'validation'            => [new \RuntimeException('Validation broke'), 422],
			'invalid xml'           => [new \RuntimeException('invalid XML'), 422],
			'parsing error'         => [new \RuntimeException('parsing error at 3'), 422],
			'malformed'             => [new \RuntimeException('malformed input'), 422],
			'could not be parsed'   => [new \RuntimeException('it could not be parsed'), 422],
			'unknown'               => [new \RuntimeException('database exploded'), 500],
		];
	}//end bulkSyncFailureProvider()

	/**
	 * bulkSyncStandards answers the mapped status with the failure message.
	 *
	 * @param \Exception $error    The exception the container throws.
	 * @param int        $expected The expected HTTP status.
	 *
	 * @return void
	 */
	#[DataProvider('bulkSyncFailureProvider')]
	public function testBulkSyncStandardsMapsFailureToStatus(\Exception $error, int $expected): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$this->container->method('get')->with(ModuleComplianceService::class)->willThrowException($error);

		$response = $this->controller()->bulkSyncStandards();

		$this->assertSame($expected, $response->getStatus());
		$this->assertSame(
			[
				'success' => false,
				'message' => 'Bulk sync failed: ' . $error->getMessage(),
				'error' => $error->getMessage(),
			],
			$response->getData()
		);
	}//end testBulkSyncStandardsMapsFailureToStatus()

	/**
	 * bulkSyncStandards returns the compliance service results to an admin.
	 *
	 * @return void
	 */
	public function testBulkSyncStandardsReturnsResults(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		$compliance = $this->createMock(ModuleComplianceService::class);
		$compliance->expects($this->once())->method('bulkSyncModuleStandards')->willReturn(['synced' => 7]);
		$this->container->method('get')->with(ModuleComplianceService::class)->willReturn($compliance);

		$response = $this->controller()->bulkSyncStandards();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'message' => 'Bulk sync completed successfully', 'data' => ['synced' => 7]],
			$response->getData()
		);
	}//end testBulkSyncStandardsReturnsResults()

	/**
	 * bulkSyncStandards refuses a non-admin before reaching the container.
	 *
	 * @return void
	 */
	public function testBulkSyncStandardsRefusesNonAdmin(): void {
		$this->signIn(uid: 'bob', isAdmin: false);
		$this->container->expects($this->never())->method('get');

		$this->assertSame(403, $this->controller()->bulkSyncStandards()->getStatus());
	}//end testBulkSyncStandardsRefusesNonAdmin()
}//end class
