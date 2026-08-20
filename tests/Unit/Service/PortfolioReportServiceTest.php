<?php

/**
 * Unit tests for PortfolioReportService.
 *
 * Covers the pure derivation helpers (lifecycle phase, end-of-support state,
 * annualised cost, quadrant aggregation — mirrored 1:1 from
 * `src/utils/lifecyclePhase.js` / `src/utils/contractCost.js` per design.md
 * Decision 3) and the bounded-query invariant on the gebruik and contract
 * OpenRegister searches.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\SoftwareCatalog\Service\PortfolioReportDerivation;
use OCA\SoftwareCatalog\Service\PortfolioReportService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for PortfolioReportService.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */
class PortfolioReportServiceTest extends TestCase {
	/**
	 * Build a service instance without invoking the constructor — sufficient
	 * for the pure private derivation methods under test.
	 *
	 * @return PortfolioReportService
	 */
	private function makeService(): PortfolioReportService {
		$reflection = new ReflectionClass(PortfolioReportService::class);
		return $reflection->newInstanceWithoutConstructor();
	}//end makeService()

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object $object The instance.
	 * @param string $method The method name.
	 * @param array $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function invokePrivate(object $object, string $method, array $args) {
		$reflection = new ReflectionMethod($object, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($object, $args);
	}//end invokePrivate()

	/**
	 * Build a fresh derivation helper — every method under it is pure
	 * (no I/O), so a plain `new` is sufficient (no reflection needed).
	 *
	 * @return PortfolioReportDerivation
	 */
	private function makeDerivation(): PortfolioReportDerivation {
		return new PortfolioReportDerivation();
	}//end makeDerivation()

	// -- deriveLifecyclePhase() -------------------------------------------

	/**
	 * The most-advanced past phase date wins.
	 *
	 * @return void
	 */
	public function testDeriveLifecyclePhaseUsesMostAdvancedPastDate(): void {
		$derivation = $this->makeDerivation();
		$now = new \DateTimeImmutable('2026-06-01');

		$result = $derivation->deriveLifecyclePhase(
			[
				'startDateInProduction' => '2025-01-01',
				'startDateOutPhasing' => '2027-01-01',
			],
			$now
		);

		$this->assertSame('In production', $result);
	}//end testDeriveLifecyclePhaseUsesMostAdvancedPastDate()

	/**
	 * No phase dates at all → Onbekend, never hidden.
	 *
	 * @return void
	 */
	public function testDeriveLifecyclePhaseReturnsOnbekendWhenNoDates(): void {
		$derivation = $this->makeDerivation();
		$result = $derivation->deriveLifecyclePhase([], new \DateTimeImmutable());

		$this->assertSame('Onbekend', $result);
	}//end testDeriveLifecyclePhaseReturnsOnbekendWhenNoDates()

	// -- deriveEolState() ---------------------------------------------------

	/**
	 * A past `datumEindeOndersteuning` flags `passed`.
	 *
	 * @return void
	 */
	public function testDeriveEolStateFlagsPassedDate(): void {
		$derivation = $this->makeDerivation();
		$now = new \DateTimeImmutable('2026-06-01');

		$result = $derivation->deriveEolState(['dateEndSupport' => '2025-01-01'], $now);

		$this->assertTrue($result['passed']);
		$this->assertFalse($result['withdrawn']);
		$this->assertSame('2025-01-01', $result['endDate']);
	}//end testDeriveEolStateFlagsPassedDate()

	/**
	 * A future end-of-support date does not flag `passed`.
	 *
	 * @return void
	 */
	public function testDeriveEolStateDoesNotFlagFutureDate(): void {
		$derivation = $this->makeDerivation();
		$now = new \DateTimeImmutable('2026-06-01');

		$result = $derivation->deriveEolState(['dateEndSupport' => '2030-01-01'], $now);

		$this->assertFalse($result['passed']);
	}//end testDeriveEolStateDoesNotFlagFutureDate()

	/**
	 * A null moduleVersie (unresolved relation) fails soft — no exception,
	 * flags stay false.
	 *
	 * @return void
	 */
	public function testDeriveEolStateHandlesNullModuleVersie(): void {
		$derivation = $this->makeDerivation();
		$result = $derivation->deriveEolState(null, new \DateTimeImmutable());

		$this->assertFalse($result['passed']);
		$this->assertFalse($result['withdrawn']);
		$this->assertNull($result['endDate']);
	}//end testDeriveEolStateHandlesNullModuleVersie()

	// -- isEolApproaching() --------------------------------------------------

	/**
	 * A date within the 180-day window is "approaching".
	 *
	 * @return void
	 */
	public function testIsEolApproachingWithinWindow(): void {
		$derivation = $this->makeDerivation();
		$now = new \DateTimeImmutable('2026-06-01');

		$result = $derivation->isEolApproaching(['dateEndSupport' => '2026-09-01'], $now);

		$this->assertTrue($result);
	}//end testIsEolApproachingWithinWindow()

	/**
	 * An already-passed date is NOT "approaching" (it is already past).
	 *
	 * @return void
	 */
	public function testIsEolApproachingExcludesPassedDate(): void {
		$derivation = $this->makeDerivation();
		$now = new \DateTimeImmutable('2026-06-01');

		$result = $derivation->isEolApproaching(['dateEndSupport' => '2025-01-01'], $now);

		$this->assertFalse($result);
	}//end testIsEolApproachingExcludesPassedDate()

	// -- annualisedCost() -----------------------------------------------------

	/**
	 * Monthly cost annualises ×12.
	 *
	 * @return void
	 */
	public function testAnnualisedCostMonthly(): void {
		$derivation = $this->makeDerivation();
		$result = $derivation->annualisedCost(['cost' => 1000, 'costPeriod' => 'Monthly']);

		$this->assertSame(12000.0, $result['annual']);
		$this->assertSame(0.0, $result['oneOff']);
	}//end testAnnualisedCostMonthly()

	/**
	 * Yearly cost passes through ×1.
	 *
	 * @return void
	 */
	public function testAnnualisedCostYearly(): void {
		$derivation = $this->makeDerivation();
		$result = $derivation->annualisedCost(['cost' => 6000, 'costPeriod' => 'Annually']);

		$this->assertSame(6000.0, $result['annual']);
		$this->assertSame(0.0, $result['oneOff']);
	}//end testAnnualisedCostYearly()

	/**
	 * A one-off contract is excluded from the annual figure.
	 *
	 * @return void
	 */
	public function testAnnualisedCostOneOff(): void {
		$derivation = $this->makeDerivation();
		$result = $derivation->annualisedCost(['cost' => 5000, 'costPeriod' => 'One-off']);

		$this->assertSame(0.0, $result['annual']);
		$this->assertSame(5000.0, $result['oneOff']);
	}//end testAnnualisedCostOneOff()

	/**
	 * An unknown period or unparseable amount yields zeros, never throws.
	 *
	 * @return void
	 */
	public function testAnnualisedCostUnknownYieldsZeros(): void {
		$derivation = $this->makeDerivation();

		$result = $derivation->annualisedCost(['cost' => 100, 'costPeriod' => 'Wekelijks']);
		$this->assertSame(0.0, $result['annual']);
		$this->assertSame(0.0, $result['oneOff']);

		$result = $derivation->annualisedCost([]);
		$this->assertSame(0.0, $result['annual']);
		$this->assertSame(0.0, $result['oneOff']);
	}//end testAnnualisedCostUnknownYieldsZeros()

	// -- resolveRelationId() -------------------------------------------------

	/**
	 * A plain string relation value resolves to itself (trimmed).
	 *
	 * @return void
	 */
	public function testResolveRelationIdFromString(): void {
		$derivation = $this->makeDerivation();
		$result = $derivation->resolveRelationId(' uuid-1 ');

		$this->assertSame('uuid-1', $result);
	}//end testResolveRelationIdFromString()

	/**
	 * A nested object relation value resolves via its `id`/`uuid` key.
	 *
	 * @return void
	 */
	public function testResolveRelationIdFromNestedObject(): void {
		$derivation = $this->makeDerivation();

		$this->assertSame('uuid-2', $derivation->resolveRelationId(['uuid' => 'uuid-2']));
		$this->assertSame('uuid-3', $derivation->resolveRelationId(['id' => 'uuid-3']));
		$this->assertSame('', $derivation->resolveRelationId(null));
	}//end testResolveRelationIdFromNestedObject()

	// -- aggregateQuadrants() -------------------------------------------------

	/**
	 * Rows aggregate into quadrant counts, EOL exposure, cloud share, and
	 * summed cost — Unclassified always present, even with zero rows.
	 *
	 * @return void
	 */
	public function testAggregateQuadrantsCountsAndSums(): void {
		$service = $this->makeService();
		$rows = [
			[
				'quadrant' => 'Migrate',
				'eol' => ['passed' => true],
				'eolApproaching' => false,
				'hostingModel' => ['SaaS'],
				'annualisedCost' => 1000.0,
				'oneOffCost' => 0.0,
			],
			[
				'quadrant' => 'Migrate',
				'eol' => ['passed' => false],
				'eolApproaching' => false,
				'hostingModel' => ['SaaS'],
				'annualisedCost' => 500.0,
				'oneOffCost' => 0.0,
			],
			[
				'quadrant' => 'Unclassified',
				'eol' => ['passed' => false],
				'eolApproaching' => false,
				'hostingModel' => [],
				'annualisedCost' => 0.0,
				'oneOffCost' => 200.0,
			],
		];

		$result = $this->invokePrivate($service, 'aggregateQuadrants', [$rows]);

		$this->assertSame(2, $result['Migrate']['count']);
		$this->assertSame(1, $result['Migrate']['eolExposedCount']);
		$this->assertSame(2, $result['Migrate']['cloudTransition']['SaaS']);
		$this->assertSame(1500.0, $result['Migrate']['annualisedCost']);

		$this->assertSame(1, $result['Unclassified']['count']);
		$this->assertSame(200.0, $result['Unclassified']['oneOffCost']);

		// Quadrants with zero rows still appear (Tolerate/Invest/Eliminate here).
		$this->assertSame(0, $result['Tolerate']['count']);
		$this->assertSame(0, $result['Invest']['count']);
		$this->assertSame(0, $result['Eliminate']['count']);
	}//end testAggregateQuadrantsCountsAndSums()

	// -- Bounded-query invariant (bound-unbounded-searchobjects-scans) ------

	/**
	 * `buildReport()`'s gebruik query and its contract lookup both carry an
	 * explicit `_limit` — never an unbounded `searchObjectsPaginated()` call.
	 *
	 * @return void
	 */
	public function testBuildReportGebruikAndContractQueriesCarryLimit(): void {
		$capturedQueries = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$capturedQueries): array {
				$capturedQueries[] = $query;
				$schema = $query['@self']['schema'] ?? null;
				if ($schema === 20) {
					// gebruik schema — one row, no relations set (keeps the
					// rest of buildRow()'s relation-resolution a no-op).
					return [
						'results' => [
							[
								'id' => 'gebruik-1',
								'consumer' => 'org-a',
							],
						],
						'total' => 1,
					];
				}
				return ['results' => [], 'total' => 0];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturn(
			[
				'register' => '1',
				'gebruik_schema' => '20',
				'contract_schema' => '21',
				'module_schema' => '22',
				'moduleVersie_schema' => '23',
			]
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(500);

		$reflection = new ReflectionClass(PortfolioReportService::class);
		$service = $reflection->newInstanceWithoutConstructor();

		foreach (
			[
				'settingsService' => $settingsService,
				'appManager' => $appManager,
				'container' => $container,
				'logger' => $this->createMock(LoggerInterface::class),
				'config' => $config,
				'derivation' => new PortfolioReportDerivation(),
			] as $propertyName => $value
		) {
			$property = $reflection->getProperty($propertyName);
			$property->setAccessible(true);
			$property->setValue($service, $value);
		}

		$service->buildReport('org-a');

		$this->assertNotEmpty($capturedQueries);
		foreach ($capturedQueries as $query) {
			$this->assertArrayHasKey('_limit', $query, 'every searchObjectsPaginated() call must carry an explicit _limit');
			$this->assertGreaterThan(0, $query['_limit']);
		}
	}//end testBuildReportGebruikAndContractQueriesCarryLimit()

	/**
	 * The report discloses truncation when the organisation's gebruik total
	 * exceeds the page-size ceiling, rather than presenting a silently
	 * incomplete total.
	 *
	 * @return void
	 */
	public function testBuildReportDisclosesTruncation(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query): array {
				$schema = $query['@self']['schema'] ?? null;
				if ($schema === 20) {
					return [
						'results' => [['id' => 'gebruik-1', 'consumer' => 'org-a']],
						// Total exceeds the returned page — the ceiling truncated it.
						'total' => 5,
					];
				}
				return ['results' => [], 'total' => 0];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturn(
			[
				'register' => '1',
				'gebruik_schema' => '20',
				'contract_schema' => '21',
				'module_schema' => '22',
				'moduleVersie_schema' => '23',
			]
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(1);

		$reflection = new ReflectionClass(PortfolioReportService::class);
		$service = $reflection->newInstanceWithoutConstructor();

		foreach (
			[
				'settingsService' => $settingsService,
				'appManager' => $appManager,
				'container' => $container,
				'logger' => $this->createMock(LoggerInterface::class),
				'config' => $config,
				'derivation' => new PortfolioReportDerivation(),
			] as $propertyName => $value
		) {
			$property = $reflection->getProperty($propertyName);
			$property->setAccessible(true);
			$property->setValue($service, $value);
		}

		$report = $service->buildReport('org-a');

		$this->assertTrue($report['truncated']);
		$this->assertSame(5, $report['totalGebruiken']);
		$this->assertSame(1, $report['includedGebruiken']);
	}//end testBuildReportDisclosesTruncation()
}//end class
