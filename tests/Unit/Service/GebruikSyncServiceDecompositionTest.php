<?php

/**
 * Unit tests for the decomposed GebruikSyncService helpers.
 *
 * Covers method-decomposition task 8.7 — split updateStatusBasedOnDates()
 * into extractStatusDateMap() + resolveLatestEligibleStatus().
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-7
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\GebruikSyncService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the private helpers extracted from
 * GebruikSyncService::updateStatusBasedOnDates.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
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
			$this->createMock(ContainerInterface::class),
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
				'Verwerving' => '2026-01-01',
				'Gepland' => '2026-02-01',
				'In productie' => '2026-03-01',
				'Uit te faseren' => null,
				'Uitgefaseerd' => '',
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
			'Verwerving' => '2024-01-01',
			'Gepland' => '2025-01-01',
			'In productie' => '2026-01-01',
			'Uit te faseren' => null,
			'Uitgefaseerd' => '2099-01-01',
		];

		[$status, $date] = $reflection->invoke($service, $map, 'gebruik-uuid');

		$this->assertSame('In productie', $status);
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
			['Verwerving' => '2099-01-01', 'Gepland' => '2099-02-01'],
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
			['Verwerving' => '2024-01-01', 'Gepland' => 'not-a-date'],
			'gebruik-uuid'
		);

		$this->assertSame('Verwerving', $status);
		$this->assertSame('2024-01-01', $date->format('Y-m-d'));

	}//end testResolveLatestEligibleStatusSkipsUnparseableDates()

}//end class
