<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Stackiq\Service\LandscapeAtDateDerivation;
use OCA\Stackiq\Service\LandscapeComparisonService;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-FSS-002 and REQ-FSS-003: today's landscape against the plan, or a scenario, on a date.
 */
final class LandscapeComparisonServiceTest extends TestCase {
	/**
	 * The usage, module and element schema ids the configuration names.
	 */
	private const USAGE = 20;
	private const MODULE = 22;
	private const ELEMENT = 30;

	/**
	 * The searchObjectsPaginated queries the service sent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * The object service boundary.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	protected function setUp(): void {
		$this->queries = [];
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(function (
			array $query,
			bool $_rbac=true,
			bool $_multitenancy=true,
			bool $deleted=false,
			?array $ids=null
		): array {
			// The id selection travels beside the query (SearchSelection::fromQuery); fold it back for the assertions.
			if ($ids !== null) {
				$query['id'] = $ids;
			}

			$this->queries[] = $query;
			return match ($query['@self']['schema']) {
				self::USAGE => ['results' => self::usages(), 'total' => 5],
				self::MODULE => ['results' => array_values(array_filter(self::modules(), static fn (array $m): bool => in_array($m['id'], $query['id'], true))), 'total' => 0],
				self::ELEMENT => ['results' => self::components(), 'total' => 3],
			};
		});
	}//end setUp()

	public function testThePlanReplacesRemovesAndAddsApplicationsByTheDate(): void {
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('searchObjectsBySlug');

		$comparison = $this->service()->compare(organisationUuid: 'zuiddrecht', date: '2027-06-30');

		self::assertSame('2026-11-14', $comparison['today']);
		self::assertSame('2027-06-30', $comparison['date']);
		self::assertNull($comparison['scenario']);
		self::assertSame(1, $comparison['notDated']);
		self::assertSame(5, $comparison['total']);
		self::assertFalse($comparison['truncated']);
		self::assertSame(
			[
				['Planned App', 'added', 'plan', '2027-05-01', null],
				['Gone App', 'removed', 'plan', '2027-01-01', null],
				['Old App', 'replaced', 'plan', '2027-03-01', 'New App'],
				['Kept App', 'unchanged', null, null, null],
			],
			array_map(static fn (array $row): array => [$row['moduleName'], $row['change'], $row['source'], $row['date'], $row['replacedBy'] ?? null], $comparison['applications'])
		);
		self::assertSame(['Gone App', 'Kept App', 'Old App'], array_column($comparison['landscapes']['today'], 'moduleName'));
		self::assertSame(['Kept App', 'New App', 'Planned App'], array_column($comparison['landscapes']['future'], 'moduleName'));
		self::assertSame(
			[
				['Baliecomponent', 'gap', 'covered', 'gap closed'],
				['Zaakregistratiecomponent', 'overlap', 'covered', 'overlap resolved'],
			],
			array_map(static fn (array $row): array => [$row['component'], $row['today'], $row['future'], $row['change']], $comparison['coverage'])
		);
	}//end testThePlanReplacesRemovesAndAddsApplicationsByTheDate()

	public function testEveryReadIsBoundedAndScopedToTheOrganisation(): void {
		$this->service()->compare(organisationUuid: 'zuiddrecht', date: '2027-06-30');

		foreach ($this->queries as $query) {
			self::assertArrayHasKey('_limit', $query);
		}

		self::assertSame('zuiddrecht', $this->queries[0]['consumer']);
		self::assertSame(self::USAGE, $this->queries[0]['@self']['schema']);
		$moduleQuery = array_values(array_filter($this->queries, static fn (array $q): bool => $q['@self']['schema'] === self::MODULE))[0];
		self::assertEqualsCanonicalizing(['m-old', 'm-new', 'm-keep', 'm-gone', 'm-planned'], $moduleQuery['id']);
		self::assertSame('referentiecomponent', end($this->queries)['gemmaType']);
	}//end testEveryReadIsBoundedAndScopedToTheOrganisation()

	public function testAScenarioUsesItsTargetDateAndItsOpenChangesReadWithRbacOn(): void {
		$this->objects->expects($this->once())->method('find')
			->with(
				's1',
				$this->callback(static fn (mixed $options): bool => $options instanceof ObjectFindOptions && $options->register === 'stackiq' && $options->schema === 'scenario'),
				true,
				true
			)
			->willReturn($this->entity(uuid: 's1', data: ['name' => 'Servicedesk 2027', 'organisation' => ['id' => 'zuiddrecht'], 'targetDate' => '2027-06-30', 'status' => 'proposed']));
		$this->objects->expects($this->once())->method('searchObjectsBySlug')
			->with('stackiq', 'scenarioChange', ['scenario' => 's1', '_limit' => LandscapeComparisonService::CHANGE_LIMIT], true, true)
			->willReturn([
				$this->entity(uuid: 'c1', data: ['action' => 'phase out', 'usage' => 'u-keep', 'effectiveDate' => '2027-02-01']),
				['action' => 'add', 'module' => 'm-new', 'referenceComponents' => ['doc']],
				['action' => 'add', 'module' => 'm-gone', 'appliedTo' => 'u-written'],
			]);

		$comparison = $this->service()->compare(organisationUuid: 'zuiddrecht', date: '2030-01-01', scenarioId: 's1');

		self::assertSame('2027-06-30', $comparison['date']);
		self::assertSame(['id' => 's1', 'name' => 'Servicedesk 2027', 'status' => 'proposed', 'targetDate' => '2027-06-30', 'appliedAt' => null], $comparison['scenario']);
		$rows = array_map(static fn (array $row): array => [$row['moduleName'], $row['change'], $row['source'], $row['date']], $comparison['applications']);
		self::assertContains(['New App', 'added', 'scenario', '2027-06-30'], $rows);
		self::assertContains(['Kept App', 'removed', 'scenario', '2027-02-01'], $rows);
		self::assertNotContains(['Gone App', 'added', 'scenario', '2027-06-30'], $rows, 'An applied change is in the usages already and is not applied twice.');
		self::assertSame(['Baliecomponent', 'Zaakregistratiecomponent'], array_column($comparison['coverage'], 'component'), 'The added module keeps the phased-out usage\'s component covered.');
	}//end testAScenarioUsesItsTargetDateAndItsOpenChangesReadWithRbacOn()

	public function testAScenarioChangeOnAUsageThePlanReplacesWinsAndNamesBothSources(): void {
		$this->objects->method('find')->willReturn($this->entity(uuid: 's1', data: ['organisation' => 'zuiddrecht', 'targetDate' => '2027-06-30']));
		$this->objects->method('searchObjectsBySlug')->willReturn(['results' => [['action' => 'phase out', 'usage' => 'u-old']]]);

		$comparison = $this->service()->compare(organisationUuid: 'zuiddrecht', scenarioId: 's1');

		$old = array_values(array_filter($comparison['applications'], static fn (array $row): bool => $row['usage'] === 'u-old'))[0];
		self::assertSame('removed', $old['change']);
		self::assertSame('scenario', $old['source']);
		self::assertSame(['scenario', 'plan'], $old['sources']);
		self::assertSame('Old App', $old['moduleName']);
	}//end testAScenarioChangeOnAUsageThePlanReplacesWinsAndNamesBothSources()

	public function testAScenarioOfAnotherOrganisationIsRefusedBeforeTheUsagesAreRead(): void {
		$this->objects->method('find')->willReturn($this->entity(uuid: 's2', data: ['organisation' => 'alblasserdam', 'targetDate' => '2027-06-30']));
		$this->objects->expects($this->never())->method('searchObjectsBySlug');

		try {
			$this->service()->compare(organisationUuid: 'zuiddrecht', scenarioId: 's2');
			self::fail('A scenario of another organisation must be refused.');
		} catch (DomainException $e) {
			self::assertSame('The scenario belongs to another organisation', $e->getMessage());
		}

		self::assertSame([], $this->queries);
	}//end testAScenarioOfAnotherOrganisationIsRefusedBeforeTheUsagesAreRead()

	public function testAnUnknownOrUnreadableScenarioIsNotFound(): void {
		$this->objects->method('find')->willThrowException(new \RuntimeException('Object not found'));

		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('Scenario not found');

		$this->service()->compare(organisationUuid: 'zuiddrecht', scenarioId: 'nope');
	}//end testAnUnknownOrUnreadableScenarioIsNotFound()

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function badDates(): array {
		return ['empty' => [''], 'impossible month' => ['2027-13-01'], 'relative' => ['next year'], 'with a time' => ['2027-06-30T10:00:00']];
	}//end badDates()

	#[DataProvider('badDates')]
	public function testWithoutAValidDateNothingIsRead(string $date): void {
		try {
			$this->service()->compare(organisationUuid: 'zuiddrecht', date: $date);
			self::fail('An invalid date must be refused.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('date must be a date as YYYY-MM-DD', $e->getMessage());
		}

		self::assertSame([], $this->queries);
	}//end testWithoutAValidDateNothingIsRead()

	private function service(): LandscapeComparisonService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(RegisterConfigReaderInterface::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'gebruik_schema' => (string)self::USAGE, 'module_schema' => (string)self::MODULE]);
		$settings->method('getAmefConfig')->willReturn(['register' => '2', 'element_schema' => (string)self::ELEMENT]);
		$settings->method('getObjectService')->willReturn($this->objects);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-14 15:30:00'));
		$relations = new PortfolioReportDerivation();
		$coverage = new ReferenceComponentCoverageDerivation($relations);

		return new LandscapeComparisonService(
			reads: new ReferenceComponentCoverageService(
				settingsService: $settings,
				appManager: $appManager,
				container: $container,
				logger: new NullLogger(),
				derivation: $coverage,
				relations: $relations
			),
			landscapes: new LandscapeAtDateDerivation(phases: $relations),
			coverage: $coverage,
			relations: $relations,
			registers: $settings,
			time: $time
		);
	}//end service()

	/**
	 * @param array<string,mixed> $data
	 */
	private function entity(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Five usages of zuiddrecht: one the plan replaces, one kept on its status alone,
	 * one phased out, one planned to start and one with neither a date nor a current status.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function usages(): array {
		return [
			['id' => 'u-old', 'module' => 'm-old', 'startDateInProduction' => '2020-01-01', 'plannedReplacement' => ['id' => 'm-new'], 'plannedReplacementDate' => '2027-03-01', 'usedForReferenceComponents' => ['zrc']],
			['@self' => ['id' => 'u-keep'], 'module' => 'm-keep', 'status' => 'In production', 'usedForReferenceComponents' => ['doc']],
			['id' => 'u-gone', 'module' => 'm-gone', 'startDateInProduction' => '2020-01-01', 'startDateOutPhased' => '2027-01-01', 'usedForReferenceComponents' => ['zrc']],
			['id' => 'u-planned', 'module' => 'm-planned', 'startDatePlanned' => '2026-01-01', 'startDateInProduction' => '2027-05-01', 'usedForReferenceComponents' => ['bal']],
			['id' => 'u-unknown', 'module' => 'm-keep', 'status' => 'in-gebruik'],
		];
	}//end usages()

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function modules(): array {
		return [
			['id' => 'm-old', 'name' => 'Old App'],
			['id' => 'm-new', 'name' => 'New App'],
			['id' => 'm-keep', 'name' => 'Kept App'],
			['id' => 'm-gone', 'name' => 'Gone App'],
			['id' => 'm-planned', 'name' => 'Planned App'],
		];
	}//end modules()

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function components(): array {
		return [
			['id' => 'zrc', 'name' => 'Zaakregistratiecomponent', 'grouping' => 'Zaak'],
			['id' => 'doc', 'name' => 'Documentbeheercomponent', 'grouping' => 'Zaak'],
			['id' => 'bal', 'name' => 'Baliecomponent', 'grouping' => 'Kanaal'],
		];
	}//end components()
}//end class
