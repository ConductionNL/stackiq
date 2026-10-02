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
use OCP\ICache;
use OCP\ICacheFactory;
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
	 * Build the tracker one request would get.
	 *
	 * The constructor's parameters are resolved by type, like the DI container
	 * does: a fresh session and user session for this request, and the one
	 * distributed cache factory all requests share.
	 *
	 * @param string|null $uid The signed-in user of this request, or null for cron.
	 *
	 * @return ProgressTracker The tracker of this request.
	 */
	private function trackerForRequest(?string $uid): ProgressTracker {
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
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$available = [
			ISession::class => $session,
			IUserSession::class => $userSession,
			ICacheFactory::class => $cacheFactory,
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
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

}//end class
