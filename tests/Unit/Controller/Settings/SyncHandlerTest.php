<?php

/**
 * Unit tests for the settings SyncHandler.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Manual organisation sync trigger; the full/incremental choice is pinned here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller\Settings;

use OCA\Stackiq\Controller\Settings\SyncHandler;
use OCA\Stackiq\Service\OrganizationSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which sync runs for which request, and the response shape.
 *
 * @covers \OCA\Stackiq\Controller\Settings\SyncHandler
 *
 * @spec exclude Manual organisation sync trigger; the full/incremental choice is pinned here.
 */
class SyncHandlerTest extends TestCase {

	/**
	 * @var OrganizationSyncService&MockObject
	 */
	private OrganizationSyncService&MockObject $sync;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sync = $this->createMock(originalClassName: OrganizationSyncService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return SyncHandler
	 */
	private function handler(): SyncHandler {
		return new SyncHandler(orgSyncService: $this->sync, logger: $this->logger);
	}//end handler()

	/**
	 * Without minutesBack a full, batched sync runs.
	 *
	 * @return void
	 */
	public function testNoWindowRunsTheFullBatchedSync(): void {
		$this->sync->expects($this->once())->method('performOptimizedManualSync')->with(15, 75)
			->willReturn(['success' => true, 'processed' => 40]);
		$this->sync->expects($this->never())->method('performManualSync');

		$this->assertSame(
			expected: [
				'success' => true,
				'results' => ['success' => true, 'processed' => 40],
				'message' => 'Synchronization completed successfully',
				'isOptimized' => true,
			],
			actual: $this->handler()->handle([])
		);
	}//end testNoWindowRunsTheFullBatchedSync()

	/**
	 * A positive window runs the incremental sync over that many minutes.
	 *
	 * @return void
	 */
	public function testAWindowRunsTheIncrementalSync(): void {
		$this->sync->expects($this->never())->method('performOptimizedManualSync');
		$this->sync->expects($this->once())->method('performManualSync')->with(30)
			->willReturn(['success' => false, 'message' => 'OpenRegister unavailable']);

		$response = $this->handler()->handle(['minutesBack' => '30']);

		$this->assertFalse(condition: $response['success']);
		$this->assertSame(expected: 'OpenRegister unavailable', actual: $response['message']);
	}//end testAWindowRunsTheIncrementalSync()

	/**
	 * A negative window is logged and treated as a full sync.
	 *
	 * @return void
	 */
	public function testANegativeWindowFallsBackToAFullSync(): void {
		$this->logger->expects($this->once())->method('warning')->with('SyncHandler: minutesBack < 0, defaulting to 0.');
		$this->sync->expects($this->once())->method('performOptimizedManualSync')->willReturn([]);
		$this->sync->expects($this->never())->method('performManualSync');

		$response = $this->handler()->handle(['minutesBack' => -5]);

		$this->assertTrue(condition: $response['success']);
		$this->assertSame(expected: [], actual: $response['results']);
	}//end testANegativeWindowFallsBackToAFullSync()
}//end class
