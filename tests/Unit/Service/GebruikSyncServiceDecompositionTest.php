<?php

/**
 * Unit tests for the decomposed GebruikSyncService helpers.
 *
 * Covers method-decomposition task 8.7 — split updateStatusBasedOnDates()
 * into extractStatusDateMap() + resolveLatestEligibleStatus().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-7
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the private helpers extracted from
 * GebruikSyncService::updateStatusBasedOnDates.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-7
 */
class GebruikSyncServiceDecompositionTest extends TestCase {

	/**
	 * Build the service with stub collaborators.
	 *
	 * @return GebruikSyncService
	 */
	private function makeService(): GebruikSyncService {
		return new GebruikSyncService(
			new NullLogger(),
			$this->createMock(SettingsService::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end makeService()

	/**
	 * extractStatusDateMap projects the five canonical status-start fields.
	 *
	 * @return void
	 */
	public function testExtractStatusDateMapProjectsAllStatuses(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'extractStatusDateMap');
		$reflection->setAccessible(true);

		$map = $reflection->invoke(
			$service,
			[
				'startDateAcquisition' => '2026-01-01',
				'startDatePlanned' => '2026-02-01',
				'startDateInProduction' => '2026-03-01',
				'startDateOutPhasing' => null,
				'startDateOutPhased' => '',
			]
		);

		$this->assertSame(
			[
				'Acquisition' => '2026-01-01',
				'Planned' => '2026-02-01',
				'In production' => '2026-03-01',
				'To be phased out' => null,
				'Phased out' => '',
			],
			$map
		);

	}//end testExtractStatusDateMapProjectsAllStatuses()

	/**
	 * resolveLatestEligibleStatus returns the status with the latest
	 * non-future date.
	 *
	 * @return void
	 */
	public function testResolveLatestEligibleStatusPicksLatestPastDate(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'resolveLatestEligibleStatus');
		$reflection->setAccessible(true);

		$map = [
			'Acquisition' => '2024-01-01',
			'Planned' => '2025-01-01',
			'In production' => '2026-01-01',
			'To be phased out' => null,
			'Phased out' => '2099-01-01',
		];

		[$status, $date] = $reflection->invoke($service, $map, 'gebruik-uuid');

		$this->assertSame('In production', $status);
		$this->assertInstanceOf(\DateTime::class, $date);
		$this->assertSame('2026-01-01', $date->format('Y-m-d'));

	}//end testResolveLatestEligibleStatusPicksLatestPastDate()

	/**
	 * resolveLatestEligibleStatus returns [null, null] when no dates are
	 * non-future.
	 *
	 * @return void
	 */
	public function testResolveLatestEligibleStatusAllFutureReturnsNulls(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'resolveLatestEligibleStatus');
		$reflection->setAccessible(true);

		[$status, $date] = $reflection->invoke(
			$service,
			['Acquisition' => '2099-01-01', 'Planned' => '2099-02-01'],
			'gebruik-uuid'
		);

		$this->assertNull($status);
		$this->assertNull($date);

	}//end testResolveLatestEligibleStatusAllFutureReturnsNulls()

	/**
	 * resolveLatestEligibleStatus silently skips entries with unparseable
	 * date strings.
	 *
	 * @return void
	 */
	public function testResolveLatestEligibleStatusSkipsUnparseableDates(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'resolveLatestEligibleStatus');
		$reflection->setAccessible(true);

		[$status, $date] = $reflection->invoke(
			$service,
			['Acquisition' => '2024-01-01', 'Planned' => 'not-a-date'],
			'gebruik-uuid'
		);

		$this->assertSame('Acquisition', $status);
		$this->assertSame('2024-01-01', $date->format('Y-m-d'));

	}//end testResolveLatestEligibleStatusSkipsUnparseableDates()

}//end class
