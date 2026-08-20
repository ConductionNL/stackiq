<?php

/**
 * Unit tests for the decomposed OrganizationSyncService helpers.
 *
 * Covers method-decomposition task 7.1 — extract `handleSyncError()` as a
 * centralised error-handling sink replacing the ad-hoc catch blocks across
 * the sync pipeline methods.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-1
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from OrganizationSyncService.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-1
 */
class OrganizationSyncServiceDecompositionTest extends TestCase {

	/**
	 * Build a service without invoking the constructor — the helper under
	 * test only reads the `logger` property, so wiring a full constructor
	 * (which requires Doctrine for IDBConnection) is unnecessary.
	 *
	 * @param LoggerInterface $logger A real or stub logger.
	 *
	 * @return OrganizationSyncService
	 */
	private function makeService(LoggerInterface $logger): OrganizationSyncService {
		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service = $reflection->newInstanceWithoutConstructor();

		$loggerProp = $reflection->getProperty('logger');
		$loggerProp->setAccessible(true);
		$loggerProp->setValue($service, $logger);

		return $service;
	}//end makeService()

	/**
	 * handleSyncError appends a uniformly-shaped entry to stats['errors']
	 * and emits a log line via the injected logger.
	 *
	 * @return void
	 */
	public function testHandleSyncErrorAppendsToStatsAndLogs(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('OrganizationSync'),
				$this->arrayHasKey('error')
			);

		$service = $this->makeService($logger);
		$reflection = new \ReflectionMethod($service, 'handleSyncError');
		$reflection->setAccessible(true);

		$stats = ['errors' => []];
		$reflection->invokeArgs(
			$service,
			['OrganizationSync', 'uuid-123', new \RuntimeException('boom'), &$stats]
		);

		$this->assertCount(1, $stats['errors']);
		$this->assertSame('uuid-123: boom', $stats['errors'][0]);

	}//end testHandleSyncErrorAppendsToStatsAndLogs()

	/**
	 * handleSyncError does not crash when stats has no errors[] key —
	 * it still logs the failure.
	 *
	 * @return void
	 */
	public function testHandleSyncErrorSurvivesMissingErrorsKey(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$service = $this->makeService($logger);
		$reflection = new \ReflectionMethod($service, 'handleSyncError');
		$reflection->setAccessible(true);

		$stats = [];
		$reflection->invokeArgs(
			$service,
			['ContactSync', 'user-7', new \LogicException('nope'), &$stats]
		);

		$this->assertArrayNotHasKey('errors', $stats);

	}//end testHandleSyncErrorSurvivesMissingErrorsKey()

	/**
	 * buildInitialSyncStats produces the canonical accumulator shape.
	 *
	 * @return void
	 */
	public function testBuildInitialSyncStatsShape(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$service = $this->makeService($logger);
		$ref = new \ReflectionMethod($service, 'buildInitialSyncStats');
		$ref->setAccessible(true);

		$stats = $ref->invokeArgs($service, [50, 45]);

		$this->assertSame(0, $stats['organizationsProcessed']);
		$this->assertSame(0, $stats['entitiesCreated']);
		$this->assertSame(0, $stats['entitiesUpdated']);
		$this->assertSame([], $stats['errors']);
		$this->assertSame(50, $stats['batchSize']);
		$this->assertSame(45, $stats['maxExecutionSeconds']);
		$this->assertFalse($stats['timeoutReached']);
		$this->assertSame(0, $stats['totalRemaining']);
		$this->assertNull($stats['endTime']);
		$this->assertNull($stats['duration']);

	}//end testBuildInitialSyncStatsShape()

	/**
	 * validateOrgSyncConfig returns the integer pair when both inputs are
	 * non-empty positive integers.
	 *
	 * @return void
	 */
	public function testValidateOrgSyncConfigHappyPath(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$service = $this->makeService($logger);
		$ref = new \ReflectionMethod($service, 'validateOrgSyncConfig');
		$ref->setAccessible(true);

		$this->assertSame([7, 12], $ref->invokeArgs($service, ['7', '12']));

	}//end testValidateOrgSyncConfigHappyPath()

	/**
	 * validateOrgSyncConfig returns [null, null] + warns when either side is empty.
	 *
	 * @return void
	 */
	public function testValidateOrgSyncConfigEmptyInput(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('missing register or organisatie_schema')
		);
		$service = $this->makeService($logger);
		$ref = new \ReflectionMethod($service, 'validateOrgSyncConfig');
		$ref->setAccessible(true);

		$this->assertSame([null, null], $ref->invokeArgs($service, ['', '7']));

	}//end testValidateOrgSyncConfigEmptyInput()

	/**
	 * validateOrgSyncConfig returns [null, null] + warns when input is non-positive.
	 *
	 * @return void
	 */
	public function testValidateOrgSyncConfigNonPositive(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('not a valid positive integer')
		);
		$service = $this->makeService($logger);
		$ref = new \ReflectionMethod($service, 'validateOrgSyncConfig');
		$ref->setAccessible(true);

		$this->assertSame([null, null], $ref->invokeArgs($service, ['abc', '7']));

	}//end testValidateOrgSyncConfigNonPositive()

}//end class
