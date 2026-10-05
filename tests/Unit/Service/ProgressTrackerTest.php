<?php

/**
 * Unit tests for where ProgressTracker keeps its progress.
 *
 * Progress of a long operation must be readable from another request: a
 * second login, another user's page or the request after a cron run. Each
 * test therefore builds one tracker per request, the way Nextcloud does:
 * every request gets its own session and user, and all requests share the
 * distributed cache.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ProgressTracker;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ProgressTracker storage across requests.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ProgressTrackerTest extends TestCase {

	/**
	 * The distributed cache every request shares, as a plain array.
	 *
	 * @var array<string, mixed>
	 */
	private array $sharedCache = [];

	/**
	 * The TTL of the last write to the shared cache.
	 *
	 * @var int|null
	 */
	private ?int $lastTtl = null;

	/**
	 * The app config table every request shares, as a plain array.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $appConfigRows = [];

	/**
	 * The number of app config writes.
	 *
	 * @var int
	 */
	private int $appConfigWrites = 0;

	/**
	 * The number of times a request dropped its app config cache.
	 *
	 * @var int
	 */
	private int $appConfigCacheClears = 0;

	/**
	 * Whether a tracker asked the factory for the distributed cache.
	 *
	 * @var bool
	 */
	private bool $distributedCacheUsed = false;

	/**
	 * Build the tracker one request would get.
	 *
	 * The constructor's parameters are resolved by type, like the DI container
	 * does: a fresh session and user session for this request, and the one
	 * distributed cache factory all requests share.
	 *
	 * @param string|null $uid The signed-in user of this request, or null for cron.
	 * @param string|null $distributedCache The `memcache.distributed` class, or null when no memcache is configured.
	 *
	 * @return ProgressTracker The tracker of this request.
	 */
	private function trackerForRequest(?string $uid, ?string $distributedCache = '\\OC\\Memcache\\Redis'): ProgressTracker {
		$sessionStore = [];
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(
			static function (string $key) use (&$sessionStore) {
				return $sessionStore[$key] ?? null;
			}
		);
		$session->method('set')->willReturnCallback(
			static function (string $key, $value) use (&$sessionStore): void {
				$sessionStore[$key] = $value;
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession->method('getUser')->willReturn($user);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			fn ($key) => $this->sharedCache[$key] ?? null
		);
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0): bool {
				$this->sharedCache[$key] = $value;
				$this->lastTtl = $ttl;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function ($key): bool {
				unset($this->sharedCache[$key]);
				return true;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn($distributedCache !== null);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function () use ($cache): ICache {
				$this->distributedCacheUsed = true;
				return $cache;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'memcache.distributed' ? ($distributedCache ?? '') : $default)
		);

		$available = [
			ISession::class => $session,
			IUserSession::class => $userSession,
			ICacheFactory::class => $cacheFactory,
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
			IConfig::class => $config,
			IAppConfig::class => $this->appConfigForRequest(),
		];

		$args = [];
		$constructor = (new \ReflectionClass(ProgressTracker::class))->getConstructor();
		foreach ($constructor->getParameters() as $parameter) {
			$type = (string) $parameter->getType();
			$this->assertArrayHasKey($type, $available, 'No test collaborator for ' . $type);
			$args[$parameter->getName()] = $available[$type];
		}

		return new ProgressTracker(...$args);
	}//end trackerForRequest()

	/**
	 * The app config of one request: its own cache in front of the shared table.
	 *
	 * A value another request wrote is seen only after this request dropped its cache.
	 *
	 * @return IAppConfig The app config double.
	 */
	private function appConfigForRequest(): IAppConfig {
		$cached = null;
		$load = function () use (&$cached): array {
			if ($cached === null) {
				$cached = $this->appConfigRows;
			}

			return $cached;
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('clearCache')->willReturnCallback(
			function () use (&$cached): void {
				$cached = null;
				$this->appConfigCacheClears++;
			}
		);
		$appConfig->method('getValueArray')->willReturnCallback(
			static fn (string $app, string $key, array $default = []): array => ($load()[$app . '/' . $key] ?? $default)
		);
		$appConfig->method('setValueArray')->willReturnCallback(
			function (string $app, string $key, array $value) use (&$cached): bool {
				$this->appConfigRows[$app . '/' . $key] = $value;
				$cached = $this->appConfigRows;
				$this->appConfigWrites++;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key) use (&$cached): void {
				unset($this->appConfigRows[$app . '/' . $key]);
				$cached = $this->appConfigRows;
			}
		);
		$appConfig->method('searchKeys')->willReturnCallback(
			static function (string $app, string $prefix = '') use ($load): array {
				$keys = [];
				foreach (array_keys($load()) as $row) {
					if (str_starts_with($row, $app . '/' . $prefix) === true) {
						$keys[] = substr($row, strlen($app) + 1);
					}
				}

				return $keys;
			}
		);

		return $appConfig;
	}//end appConfigForRequest()

	/**
	 * Progress written in one request is readable from another request, for
	 * example an admin in a second login.
	 *
	 * @return void
	 */
	public function testAnotherRequestReadsTheProgressOfARunningOperation(): void {
		$writer = $this->trackerForRequest('admin');
		$operationId = $writer->startOperation(
			operationType: 'org_merge',
			options: ['total_items' => 4],
			ownerUid: 'admin'
		);
		$writer->setPhase('processing_organizations');
		$writer->updateProgress(processedItems: 2);

		$reader = $this->trackerForRequest('admin');
		$progress = $reader->getProgress($operationId);

		$this->assertNotNull($progress, 'a second request must read the running operation');
		$this->assertSame($operationId, $progress['operation_id']);
		$this->assertSame('processing_organizations', $progress['phase']);
		$this->assertSame(2, $progress['processed_items']);
		$this->assertSame(4, $progress['total_items']);
	}//end testAnotherRequestReadsTheProgressOfARunningOperation()

	/**
	 * Progress written by a background job, which has no session and no
	 * user, is readable from a web request.
	 *
	 * @return void
	 */
	public function testProgressOfABackgroundJobIsReadableFromAWebRequest(): void {
		$job = $this->trackerForRequest(null);
		$operationId = $job->startOperation(operationType: 'org_merge', options: ['total_items' => 1]);
		$job->completeOperation(['merged' => 1]);

		$progress = $this->trackerForRequest('admin')->getProgress($operationId);

		$this->assertNotNull($progress);
		$this->assertSame('completed', $progress['phase']);
		$this->assertSame(100, $progress['percentage']);
		$this->assertNull($progress['owner_uid'], 'an operation started without a user has no owner');
	}//end testProgressOfABackgroundJobIsReadableFromAWebRequest()

	/**
	 * A stored entry expires, so the shared cache does not keep every
	 * operation forever.
	 *
	 * @return void
	 */
	public function testStoredProgressExpires(): void {
		$this->trackerForRequest('admin')->startOperation(operationType: 'org_merge');

		$this->assertNotNull($this->lastTtl);
		$this->assertGreaterThan(0, $this->lastTtl);
	}//end testStoredProgressExpires()

	/**
	 * An operation started in a request with a signed-in user and no explicit
	 * owner belongs to that user, so the owner check can find its owner.
	 *
	 * @return void
	 */
	public function testTheOwnerDefaultsToTheSignedInUser(): void {
		$tracker = $this->trackerForRequest('alice');
		$operationId = $tracker->startOperation(operationType: 'sbom-import', options: ['total_items' => 200]);

		$progress = $this->trackerForRequest('admin')->getProgress($operationId);

		$this->assertNotNull($progress);
		$this->assertSame('alice', $progress['owner_uid']);
	}//end testTheOwnerDefaultsToTheSignedInUser()

	/**
	 * An explicit owner wins over the signed-in user.
	 *
	 * @return void
	 */
	public function testAnExplicitOwnerIsKept(): void {
		$tracker = $this->trackerForRequest('alice');
		$tracker->startOperation(operationType: 'org_merge', ownerUid: 'bob');

		$this->assertSame('bob', $tracker->getProgress()['owner_uid']);
	}//end testAnExplicitOwnerIsKept()

	/**
	 * An unknown operation id reads as null.
	 *
	 * @return void
	 */
	public function testAnUnknownOperationIsNull(): void {
		$this->assertNull($this->trackerForRequest('admin')->getProgress('org_merge_unknown'));
	}//end testAnUnknownOperationIsNull()

	/**
	 * Without any memcache, progress and cancel go through the app config and
	 * still reach another request.
	 *
	 * @return void
	 */
	public function testWithoutAMemcacheProgressAndCancelReachAnotherRequest(): void {
		$writer = $this->trackerForRequest('admin', null);
		$operationId = $writer->startOperation(operationType: 'cmdb_import', options: ['total_items' => 3], operationId: 'cmdb-' . str_repeat('a', 64));
		$writer->setPhase('processing_elements');

		$reader = $this->trackerForRequest('admin', null);
		$progress = $reader->getProgress($operationId);

		$this->assertFalse($this->distributedCacheUsed, 'a cache that keeps nothing is not used');
		$this->assertNotNull($progress, 'a second request must read the running operation');
		$this->assertSame('processing_elements', $progress['phase']);

		$reader->setCancelRequested($operationId);
		$this->assertTrue($writer->isCancelRequested($operationId), 'the running request sees a cancel another request asked for');

		foreach (array_keys($this->appConfigRows) as $row) {
			$this->assertLessThanOrEqual(64, strlen(substr($row, strlen('stackiq/'))), 'an app config key holds at most 64 characters');
		}
	}//end testWithoutAMemcacheProgressAndCancelReachAnotherRequest()

	/**
	 * APCu is kept per server and per process, so it does not carry progress either.
	 *
	 * @return void
	 */
	public function testApcuAloneIsNotTrustedAsASharedCache(): void {
		$writer = $this->trackerForRequest('admin', '\\OC\\Memcache\\APCu');
		$operationId = $writer->startOperation(operationType: 'archimate_import');

		$this->assertFalse($this->distributedCacheUsed);
		$this->assertSame([], $this->sharedCache);
		$this->assertNotNull($this->trackerForRequest('admin', '\\OC\\Memcache\\APCu')->getProgress($operationId));
	}//end testApcuAloneIsNotTrustedAsASharedCache()

	/**
	 * A reader drops its own config cache before it reads, so a snapshot
	 * written after the reader's first read is still seen.
	 *
	 * @return void
	 */
	public function testAReaderSeesALaterWriteInTheAppConfig(): void {
		$writer = $this->trackerForRequest('admin', null);
		$operationId = $writer->startOperation(operationType: 'archimate_import');

		$reader = $this->trackerForRequest('admin', null);
		$this->assertSame('running', $reader->getProgress($operationId)['status']);

		$writer->completeOperation();

		$this->assertSame('completed', $reader->getProgress($operationId)['status']);
		$this->assertGreaterThan(0, $this->appConfigCacheClears);
	}//end testAReaderSeesALaterWriteInTheAppConfig()

	/**
	 * A running operation writes the app config at most once a second in one
	 * phase, and its final state is always written.
	 *
	 * @return void
	 */
	public function testAppConfigWritesOfARunningOperationAreSpacedOut(): void {
		$tracker = $this->trackerForRequest('admin', null);
		$operationId = $tracker->startOperation(operationType: 'archimate_import', options: ['total_items' => 500]);
		$writesAfterStart = $this->appConfigWrites;
		for ($i = 0; $i < 500; $i++) {
			$tracker->incrementProgress();
		}

		$this->assertLessThanOrEqual($writesAfterStart + 2, $this->appConfigWrites, '500 rows are not 500 writes');

		$tracker->completeOperation();

		$progress = $this->trackerForRequest('admin', null)->getProgress($operationId);
		$this->assertSame('completed', $progress['status']);
		$this->assertSame(500, $progress['processed_items']);
	}//end testAppConfigWritesOfARunningOperationAreSpacedOut()

	/**
	 * App config entries whose time is up are removed when the next operation starts.
	 *
	 * @return void
	 */
	public function testExpiredAppConfigEntriesAreRemovedWhenAnOperationStarts(): void {
		$this->appConfigRows['stackiq/op_progress_old'] = ['expires' => time() - 1, 'value' => ['status' => 'running']];
		$this->appConfigRows['stackiq/op_cancel_old']   = ['expires' => time() - 1, 'value' => true];
		$this->appConfigRows['stackiq/other_setting']   = ['kept' => true];

		$this->trackerForRequest('admin', null)->startOperation(operationType: 'archimate_import');

		$this->assertArrayNotHasKey('stackiq/op_progress_old', $this->appConfigRows);
		$this->assertArrayNotHasKey('stackiq/op_cancel_old', $this->appConfigRows);
		$this->assertArrayHasKey('stackiq/other_setting', $this->appConfigRows);
	}//end testExpiredAppConfigEntriesAreRemovedWhenAnOperationStarts()

}//end class
