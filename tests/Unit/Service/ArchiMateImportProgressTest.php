<?php

/**
 * Tests that the ArchiMate import records its progress and honours a cancel between save batches.
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
 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateImportService;
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
 * The import runs with a real ProgressTracker on a shared cache; a second tracker
 * on the same cache stands for the progress poll and the cancel request.
 */
class ArchiMateImportProgressTest extends TestCase {

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
	 * The import service with only the collaborators the save loop uses.
	 *
	 * @param ProgressTracker $tracker The import request's tracker.
	 *
	 * @return ArchiMateImportService The service.
	 */
	private function importService(ProgressTracker $tracker): ArchiMateImportService {
		$service = (new \ReflectionClass(ArchiMateImportService::class))->newInstanceWithoutConstructor();
		$values  = [
			'logger'          => $this->createMock(LoggerInterface::class),
			'progressTracker' => $tracker,
		];
		foreach ($values as $prop => $value) {
			(new \ReflectionProperty(ArchiMateImportService::class, $prop))->setValue($service, $value);
		}

		return $service;
	}//end importService()

	/**
	 * Two schema groups of three and two objects.
	 *
	 * @return array<int|string, array<int, array<string, mixed>>> The groups.
	 */
	private function schemaGroups(): array {
		$object = fn (string $id, int $schema) => ['@self' => ['id' => $id, 'register' => 1, 'schema' => $schema]];
		return [
			11 => [$object('e1', 11), $object('e2', 11), $object('e3', 11)],
			12 => [$object('r1', 12), $object('r2', 12)],
		];
	}//end schemaGroups()

	/**
	 * After the first group is saved, a second request reads the objects saved so far.
	 *
	 * @return void
	 */
	public function testProgressAfterTheFirstGroupIsReadableFromAnotherRequest(): void {
		$importTracker = $this->tracker();
		$service       = $this->importService($importTracker);
		$service->startTracking(['operationId' => 'archimate_import_abc12345']);

		$seen = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects) use (&$seen): array {
				$seen[] = $this->tracker()->getProgress('archimate_import_abc12345')['processed_items'];
				return ['saved' => $objects, 'updated' => [], 'unchanged' => [], 'invalid' => []];
			}
		);

		$method = new \ReflectionMethod($service, 'saveSchemaGroups');
		$method->invoke($service, $this->schemaGroups(), $objectService, 1);

		// Before the first save nothing was saved, before the second the first group's three were.
		$this->assertSame([0, 3], $seen);
		$after = $this->tracker()->getProgress('archimate_import_abc12345');
		$this->assertSame(5, $after['processed_items']);
		$this->assertSame('processing_elements', $after['phase']);
	}//end testProgressAfterTheFirstGroupIsReadableFromAnotherRequest()

	/**
	 * A cancel that arrives during the first group stops the import before the second.
	 *
	 * @return void
	 */
	public function testACancelDuringTheFirstGroupStopsBeforeTheSecond(): void {
		$importTracker = $this->tracker();
		$service       = $this->importService($importTracker);
		$service->startTracking(['operationId' => 'archimate_import_abc12345']);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('saveObjects')->willReturnCallback(
			function (array $objects): array {
				$this->tracker()->setCancelRequested('archimate_import_abc12345');
				return ['saved' => $objects, 'updated' => [], 'unchanged' => [], 'invalid' => []];
			}
		);

		$method = new \ReflectionMethod($service, 'saveSchemaGroups');
		$result = $method->invoke($service, $this->schemaGroups(), $objectService, 1);

		$this->assertTrue($service->wasCancelled());
		$this->assertCount(3, $result['stats']['saved']);
		$this->assertSame('cancelled', $this->tracker()->getProgress('archimate_import_abc12345')['status']);
	}//end testACancelDuringTheFirstGroupStopsBeforeTheSecond()

	/**
	 * An import that throws is stored as failed at the percentage it reached, not completed at 100%.
	 *
	 * @return void
	 */
	public function testAFailedImportIsStoredAsFailed(): void {
		$service = $this->importService($this->tracker());
		(new \ReflectionProperty(ArchiMateImportService::class, 'cachedConfig'))->setValue($service, ['userId' => 'admin']);

		$result = $service->importArchiMateFileFromPathOptimized(
			['operationId' => 'archimate_import_abc12345', 'filePath' => '/does/not/exist.xml']
		);

		$this->assertFalse($result['success']);
		$stored = $this->tracker()->getProgress('archimate_import_abc12345');
		$this->assertSame('failed', $stored['status']);
		$this->assertLessThan(100, $stored['percentage']);
		$this->assertStringContainsString('File not found', $stored['errors'][0]['message']);
	}//end testAFailedImportIsStoredAsFailed()

	/**
	 * An id that does not match the pattern is ignored: no operation, no cancel checks.
	 *
	 * @return void
	 */
	public function testAnOperationIdOutsideThePatternIsIgnored(): void {
		$service = $this->importService($this->tracker());
		$service->startTracking(['operationId' => 'progress_someone_elses']);

		$this->assertSame([], $this->sharedCache);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->exactly(2))->method('saveObjects')->willReturn(
			['saved' => [], 'updated' => [], 'unchanged' => [], 'invalid' => []]
		);
		(new \ReflectionMethod($service, 'saveSchemaGroups'))->invoke($service, $this->schemaGroups(), $objectService, 1);
		$this->assertFalse($service->wasCancelled());
	}//end testAnOperationIdOutsideThePatternIsIgnored()
}//end class
