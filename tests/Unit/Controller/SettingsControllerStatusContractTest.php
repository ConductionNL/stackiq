<?php

/**
 * Wire-contract tests for the status / statistics / progress / sync half of
 * SettingsController's registered public endpoints.
 *
 * Gate-25 (contract-coverage) requires an automated proof of the wire contract
 * for every registered, publicly-reachable controller method. Each endpoint in
 * this file is `@NoAdminRequired`, so the contract that matters is:
 *
 *   * the deny-before-grant guard — an anonymous caller gets 401 and the
 *     backing service is NEVER invoked;
 *   * the ownership guard on `/api/progress/{operationId}` — a progress record
 *     belonging to another user reads as 404, not as somebody else's data
 *     (IDOR, OWASP A01:2021). Both the JSON and the SSE variant carry it;
 *   * the status mapping and the response keys a caller parses.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\SettingsController;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\EolSyncService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
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

/**
 * Contract tests for the settings status / statistics / progress endpoints.
 */
class SettingsControllerStatusContractTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * The mocked organisation sync service.
	 *
	 * @var OrganizationSyncService|MockObject
	 */
	private OrganizationSyncService|MockObject $orgSyncService;

	/**
	 * The mocked progress tracker.
	 *
	 * @var ProgressTracker|MockObject
	 */
	private ProgressTracker|MockObject $progressTracker;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * The mocked app config.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig|MockObject $appConfig;

	/**
	 * Build the controller under test with fresh mocks.
	 *
	 * @param array<string,mixed> $params Query/body params the request reports.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function makeController(array $params = []): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		$this->settingsService = $this->createMock(SettingsService::class);
		$this->orgSyncService = $this->createMock(OrganizationSyncService::class);
		$this->progressTracker = $this->createMock(ProgressTracker::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		return new SettingsController(
			'softwarecatalog',
			$request,
			$this->appConfig,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IGroupManager::class),
			$this->userSession,
			$this->settingsService,
			$this->orgSyncService,
			$this->createMock(ArchiMateService::class),
			$this->progressTracker,
			$this->createMock(EolSyncService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end makeController()

	/**
	 * Authenticate the session as the given uid.
	 *
	 * @param string $uid The uid to report.
	 *
	 * @return void
	 */
	private function withUser(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end withUser()

	/**
	 * Every endpoint in this file answers an anonymous caller with the SAME
	 * 401 envelope. Asserting them as one table makes a newly-added endpoint
	 * that forgets the guard visible as a missing row rather than as a silent
	 * omission.
	 *
	 * @return array<string, array{0: string, 1: array<int,mixed>}>
	 */
	public static function anonymousEndpointProvider(): array {
		return [
			'status' => ['status', []],
			'heartbeat' => ['heartbeat', []],
			'getVersionInfo' => ['getVersionInfo', []],
			'getObjectCounts' => ['getObjectCounts', []],
			'getObjectsCounts' => ['getObjectsCounts', []],
			'getObjectsStatistics' => ['getObjectsStatistics', []],
			'getSyncStatus' => ['getSyncStatus', [10]],
			'syncOrganisations' => ['syncOrganisations', []],
			'getCronjobOrganisations' => ['getCronjobOrganisations', []],
			'getProgress' => ['getProgress', ['op-1']],
			'streamProgress' => ['streamProgress', ['op-1']],
		];

	}//end anonymousEndpointProvider()

	/**
	 * An anonymous caller is rejected with the 401 envelope on every
	 * @NoAdminRequired endpoint in this half of the controller.
	 *
	 * @param string $method The controller method name.
	 * @param array<int,mixed> $args Positional arguments for the call.
	 *
	 * @return void
	 *
	 * @dataProvider anonymousEndpointProvider
	 */
	public function testAnonymousCallerIsRejectedWith401(string $method, array $args): void {
		$controller = $this->makeController();
		$this->userSession->method('getUser')->willReturn(null);

		// No backing service may be consulted before the guard runs.
		$this->settingsService->expects($this->never())->method($this->anything());
		$this->orgSyncService->expects($this->never())->method($this->anything());
		$this->progressTracker->expects($this->never())->method($this->anything());

		$response = $controller->$method(...$args);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());

	}//end testAnonymousCallerIsRejectedWith401()

	/**
	 * GET /api/settings/status composes the three service reads the settings
	 * UI depends on and reports the auto-config flag as a boolean, not the
	 * raw 'true'/'false' string it is stored as.
	 *
	 * @return void
	 */
	public function testStatusComposesTheConfigurationSnapshot(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->settingsService->method('getConfigurationStatus')->willReturn(['registers' => 'ok']);
		$this->settingsService->method('isFullyConfigured')->willReturn(true);
		$this->settingsService->method('getVersionInfo')->willReturn(['needsUpdate' => false]);
		$this->appConfig->method('getValueString')->willReturn('true');

		$response = $controller->status();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['registers' => 'ok'], $data['status']);
		$this->assertTrue($data['fullyConfigured']);
		$this->assertSame(['needsUpdate' => false], $data['versionInfo']);
		$this->assertTrue($data['autoConfigCompleted']);
		$this->assertIsInt($data['timestamp']);

	}//end testStatusComposesTheConfigurationSnapshot()

	/**
	 * The auto-config flag is only true for the literal stored 'true'; any
	 * other stored string reads false.
	 *
	 * @return void
	 */
	public function testStatusReportsAutoConfigFalseForAnyNonTrueStoredValue(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->settingsService->method('getConfigurationStatus')->willReturn([]);
		$this->settingsService->method('isFullyConfigured')->willReturn(false);
		$this->settingsService->method('getVersionInfo')->willReturn([]);
		$this->appConfig->method('getValueString')->willReturn('false');

		$this->assertFalse($controller->status()->getData()['autoConfigCompleted']);

	}//end testStatusReportsAutoConfigFalseForAnyNonTrueStoredValue()

	/**
	 * The heartbeat echoes the client timestamp back and stamps its own, which
	 * is what lets the frontend detect clock skew during long operations.
	 *
	 * @return void
	 */
	public function testHeartbeatEchoesTheClientTimestampAndStampsTheServer(): void {
		$controller = $this->makeController(['timestamp' => 1234567890]);
		$this->withUser();

		$response = $controller->heartbeat();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertSame(1234567890, $data['timestamp']);
		$this->assertIsInt($data['server_time']);

	}//end testHeartbeatEchoesTheClientTimestampAndStampsTheServer()

	/**
	 * GET /api/settings/version returns the service's version info plus a
	 * cache-busting timestamp.
	 *
	 * @return void
	 */
	public function testGetVersionInfoReturnsTheServiceDataWithACacheBustingTimestamp(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('getVersionInfo')->willReturn(['appVersion' => '0.2.22']);

		$data = $controller->getVersionInfo()->getData();

		$this->assertSame('0.2.22', $data['appVersion']);
		$this->assertIsInt($data['timestamp']);

	}//end testGetVersionInfoReturnsTheServiceDataWithACacheBustingTimestamp()

	/**
	 * A service failure on the version endpoint is a 500 with the error, not
	 * an uncaught exception.
	 *
	 * @return void
	 */
	public function testGetVersionInfoReportsAServiceFailureAs500(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('getVersionInfo')
			->willThrowException(new \Exception('registry unreachable'));

		$response = $controller->getVersionInfo();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('registry unreachable', $response->getData()['error']);

	}//end testGetVersionInfoReportsAServiceFailureAs500()

	/**
	 * The three statistics endpoints are distinct routes backed by distinct
	 * service reads — a copy-paste that pointed two of them at the same
	 * service call would silently serve the wrong payload on one route.
	 *
	 * @return void
	 */
	public function testTheThreeStatisticsEndpointsCallTheirOwnServiceReads(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->settingsService->expects($this->once())
			->method('getObjectCountsStatistics')->willReturn(['modules' => 3]);
		$this->settingsService->expects($this->once())
			->method('getObjectsCounts')->willReturn(['total' => 7]);
		$this->settingsService->expects($this->once())
			->method('getObjectsStatistics')->willReturn(['byRegister' => []]);

		$counts = $controller->getObjectCounts()->getData();
		$this->assertTrue($counts['success']);
		$this->assertSame(['modules' => 3], $counts['objectCounts']);

		$this->assertSame(['total' => 7], $controller->getObjectsCounts()->getData());
		$this->assertSame(['byRegister' => []], $controller->getObjectsStatistics()->getData());

	}//end testTheThreeStatisticsEndpointsCallTheirOwnServiceReads()

	/**
	 * GET /api/settings/sync-status forwards the look-back window to the sync
	 * service unchanged.
	 *
	 * @return void
	 */
	public function testGetSyncStatusForwardsTheLookBackWindow(): void {
		$controller = $this->makeController();
		$this->withUser();

		$this->orgSyncService->expects($this->once())
			->method('getSyncStatusWithErrorHandling')
			->with(45)
			->willReturn(['running' => false]);

		$response = $controller->getSyncStatus(45);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['running' => false], $response->getData());

	}//end testGetSyncStatusForwardsTheLookBackWindow()

	/**
	 * POST /api/settings/sync/organisations coerces `batch_size` to an int and
	 * `dry_run` to a bool before handing them to the service — a string
	 * "false" from a form post must not enable a live write.
	 *
	 * @return void
	 */
	public function testSyncOrganisationsCoercesItsRequestOptions(): void {
		$controller = $this->makeController(['batch_size' => '250', 'dry_run' => 'false']);
		$this->withUser();

		$this->settingsService->expects($this->once())
			->method('syncOrganisationsToVoorzieningenOptimized')
			->with(['batch_size' => 250, 'dry_run' => false])
			->willReturn(['success' => true, 'message' => 'done']);

		$response = $controller->syncOrganisations();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testSyncOrganisationsCoercesItsRequestOptions()

	/**
	 * A `dry_run=true` request is passed through as a real boolean true.
	 *
	 * @return void
	 */
	public function testSyncOrganisationsPassesDryRunThroughAsTrue(): void {
		$controller = $this->makeController(['dry_run' => 'true']);
		$this->withUser();

		$this->settingsService->expects($this->once())
			->method('syncOrganisationsToVoorzieningenOptimized')
			->with(['batch_size' => 500, 'dry_run' => true])
			->willReturn(['success' => true]);

		$controller->syncOrganisations();

	}//end testSyncOrganisationsPassesDryRunThroughAsTrue()

	/**
	 * A failed sync envelope maps to 500 while still carrying the envelope.
	 *
	 * @return void
	 */
	public function testSyncOrganisationsMapsAFailedEnvelopeTo500(): void {
		$controller = $this->makeController();
		$this->withUser();
		$this->settingsService->method('syncOrganisationsToVoorzieningenOptimized')
			->willReturn(['success' => false, 'message' => 'register missing']);

		$response = $controller->syncOrganisations();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testSyncOrganisationsMapsAFailedEnvelopeTo500()

	/**
	 * The deprecated cronjob-organisations route answers 410 Gone for an
	 * authenticated caller — a tombstone, not a 404 and not a silent 200.
	 *
	 * @return void
	 */
	public function testGetCronjobOrganisationsIsATombstoneReturning410(): void {
		$controller = $this->makeController();
		$this->withUser();

		$response = $controller->getCronjobOrganisations();

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertFalse($response->getData()['success']);

	}//end testGetCronjobOrganisationsIsATombstoneReturning410()

	/**
	 * GET /api/progress/{operationId} returns the tracked progress to its
	 * owner.
	 *
	 * @return void
	 */
	public function testGetProgressReturnsTheOperationToItsOwner(): void {
		$controller = $this->makeController();
		$this->withUser('alice');
		$this->progressTracker->method('getProgress')
			->willReturn(['owner_uid' => 'alice', 'percent' => 40]);

		$response = $controller->getProgress('op-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertSame(40, $data['progress']['percent']);

	}//end testGetProgressReturnsTheOperationToItsOwner()

	/**
	 * IDOR: another user's operation is indistinguishable from a missing one —
	 * 404 with the same body, so the endpoint does not confirm the operation
	 * exists.
	 *
	 * @return void
	 */
	public function testGetProgressHidesAnotherUsersOperationBehindA404(): void {
		$controller = $this->makeController();
		$this->withUser('alice');
		$this->progressTracker->method('getProgress')
			->willReturn(['owner_uid' => 'bob', 'percent' => 90]);

		$response = $controller->getProgress('op-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('OPERATION_NOT_FOUND', $data['error']);
		$this->assertArrayNotHasKey('progress', $data);

	}//end testGetProgressHidesAnotherUsersOperationBehindA404()

	/**
	 * An unknown operation id is the same 404 envelope.
	 *
	 * @return void
	 */
	public function testGetProgressReturns404ForAnUnknownOperation(): void {
		$controller = $this->makeController();
		$this->withUser('alice');
		$this->progressTracker->method('getProgress')->willReturn(null);

		$response = $controller->getProgress('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('OPERATION_NOT_FOUND', $response->getData()['error']);

	}//end testGetProgressReturns404ForAnUnknownOperation()

	/**
	 * The SSE variant carries the SAME ownership guard: streaming another
	 * user's operation is refused with a JSON 404 before any stream is opened.
	 *
	 * @return void
	 */
	public function testStreamProgressRefusesToStreamAnotherUsersOperation(): void {
		$controller = $this->makeController();
		$this->withUser('alice');
		$this->progressTracker->method('getProgress')
			->willReturn(['owner_uid' => 'bob']);

		$response = $controller->streamProgress('op-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testStreamProgressRefusesToStreamAnotherUsersOperation()

	/**
	 * For its owner, the SSE endpoint returns a streaming response with the
	 * event-stream headers a browser EventSource requires.
	 *
	 * @return void
	 */
	public function testStreamProgressOpensAnEventStreamForTheOwner(): void {
		$controller = $this->makeController();
		$this->withUser('alice');
		$this->progressTracker->method('getProgress')
			->willReturn(['owner_uid' => 'alice']);

		$response = $controller->streamProgress('op-1');

		$this->assertNotInstanceOf(JSONResponse::class, $response);

		// `Response::getHeaders()` merges in framework headers via
		// `\OC::$server`, which does not exist in a unit context. Read the
		// headers the controller itself set, which is what is under test.
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);
		$headers = $property->getValue($response);

		$this->assertSame('text/event-stream', $headers['Content-Type']);
		$this->assertSame('no-cache', $headers['Cache-Control']);
		$this->assertSame('keep-alive', $headers['Connection']);

	}//end testStreamProgressOpensAnEventStreamForTheOwner()
}//end class
