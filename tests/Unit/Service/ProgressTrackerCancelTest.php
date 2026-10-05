<?php

/**
 * Tests that an ArchiMate import can be named by the page and cancelled from another request.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
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

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ProgressTracker;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Two trackers on one shared cache stand for two requests: the import and the cancel.
 */
class ProgressTrackerCancelTest extends TestCase {

	/**
	 * The distributed cache every request shares.
	 *
	 * @var array<string, mixed>
	 */
	private array $sharedCache = [];

	/**
	 * Build the tracker one request gets, on the shared cache.
	 *
	 * @return ProgressTracker The tracker.
	 */
	private function tracker(): ProgressTracker {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => $this->sharedCache[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0): bool {
				$this->sharedCache[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function ($key): bool {
				unset($this->sharedCache[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$factory->method('isAvailable')->willReturn(true);

		return new ProgressTracker(
			cacheFactory: $factory,
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
			config: $this->createConfiguredMock(IConfig::class, ['getSystemValueString' => '\\OC\\Memcache\\Redis']),
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end tracker()

	/**
	 * An operation started under the page's id is readable under that id from another request.
	 *
	 * @return void
	 */
	public function testAnOperationStartedUnderAGivenIdIsReadableFromAnotherRequest(): void {
		$importRequest = $this->tracker();
		$returned = $importRequest->startOperation(
			operationType: 'archimate_import',
			operationId: 'archimate_import_abc12345'
		);

		$this->assertSame('archimate_import_abc12345', $returned);
		$progress = $this->tracker()->getProgress('archimate_import_abc12345');
		$this->assertNotNull($progress);
		$this->assertSame('admin', $progress['owner_uid']);
	}//end testAnOperationStartedUnderAGivenIdIsReadableFromAnotherRequest()

	/**
	 * A cancel written in one request is seen by the import's request, and only for that operation.
	 *
	 * @return void
	 */
	public function testACancelRequestedInOneRequestIsSeenByAnother(): void {
		$importRequest = $this->tracker();
		$importRequest->startOperation(operationType: 'archimate_import', operationId: 'archimate_import_abc12345');

		$this->assertFalse($importRequest->isCancelRequested('archimate_import_abc12345'));

		$this->tracker()->setCancelRequested('archimate_import_abc12345');

		$this->assertTrue($importRequest->isCancelRequested('archimate_import_abc12345'));
		$this->assertFalse($importRequest->isCancelRequested('archimate_import_other999'));
	}//end testACancelRequestedInOneRequestIsSeenByAnother()

	/**
	 * A cancelled operation is stored as cancelled with the counts it reached.
	 *
	 * @return void
	 */
	public function testACancelledOperationIsStoredAsCancelled(): void {
		$importRequest = $this->tracker();
		$importRequest->startOperation(operationType: 'archimate_import', operationId: 'archimate_import_abc12345');
		$importRequest->setPhase('processing_elements', ['total_items' => 10]);
		$importRequest->updateProgress(processedItems: 4);

		$importRequest->cancelOperation();

		$stored = $this->tracker()->getProgress('archimate_import_abc12345');
		$this->assertSame('cancelled', $stored['status']);
		$this->assertSame(4, $stored['processed_items']);
	}//end testACancelledOperationIsStoredAsCancelled()

	/**
	 * A failed operation is stored as failed with its reason and the counts it reached, and its cancel flag is cleared.
	 *
	 * @return void
	 */
	public function testAFailedOperationIsStoredAsFailed(): void {
		$importRequest = $this->tracker();
		$importRequest->startOperation(operationType: 'archimate_import', operationId: 'archimate_import_abc12345');
		$importRequest->setPhase('processing_elements', ['total_items' => 10]);
		$importRequest->updateProgress(processedItems: 4);
		$percentage = $importRequest->getProgress()['percentage'];
		$this->tracker()->setCancelRequested('archimate_import_abc12345');

		$importRequest->failOperation('Database went away');

		$stored = $this->tracker()->getProgress('archimate_import_abc12345');
		$this->assertSame('failed', $stored['status']);
		$this->assertSame(4, $stored['processed_items']);
		$this->assertSame($percentage, $stored['percentage']);
		$this->assertSame('Database went away', $stored['errors'][0]['message']);
		$this->assertFalse($importRequest->isCancelRequested('archimate_import_abc12345'));
	}//end testAFailedOperationIsStoredAsFailed()
}//end class
