<?php

/**
 * Overlap in the portfolio rationalization report
 * (architecture-reference-component-coverage REQ-RCC-004): rows that share a
 * reference component with another application in use name it in
 * `overlapsWith`, the report counts them, and the CSV carries the column.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-004-the-portfolio-rationalization-report-shall-show-overlapping-applications
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\PortfolioReportService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for the overlap the portfolio report shows.
 */
class PortfolioReportServiceOverlapTest extends TestCase {

	/**
	 * Two applications sharing "Zaaksysteem", a third one alone on "DMS", a second
	 * usage of the first module (the same application), and a planned one that
	 * does not count.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function usages(): array {
		return [
			['id' => 'g-1', 'module' => 'm-1', 'status' => 'In use', 'usedForReferenceComponents' => ['rc-zaak']],
			['id' => 'g-2', 'module' => 'm-2', 'status' => 'in-gebruik', 'usedForReferenceComponents' => [['id' => 'rc-zaak'], 'rc-dms']],
			['id' => 'g-3', 'module' => 'm-3', 'usedForReferenceComponents' => ['rc-crm']],
			['id' => 'g-4', 'module' => 'm-1', 'status' => 'In use', 'usedForReferenceComponents' => ['rc-crm']],
			['id' => 'g-5', 'module' => 'm-4', 'status' => 'Planned', 'usedForReferenceComponents' => ['rc-zaak']],
		];
	}//end usages()

	/**
	 * A service over the fixture usages, with modules and components resolvable by id.
	 *
	 * @return PortfolioReportService
	 */
	private function service(): PortfolioReportService {
		$names = [
			'm-1' => 'Zaken Een',
			'm-2' => 'Zaken Twee',
			'm-3' => 'Relaties',
			'm-4' => 'Zaken Vier',
			'rc-zaak' => 'Zaaksysteem',
			'rc-dms' => 'DMS',
			'rc-crm' => 'CRM',
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			fn (array $query): array => $query['@self']['schema'] === 20
				? ['results' => $this->usages(), 'total' => 5]
				: ['results' => [], 'total' => 0]
		);
		$objectService->method('find')->willReturnCallback(
			function (...$args) use ($names) {
				if (isset($names[$args[0]]) === false) {
					throw new RuntimeException('not found');
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn(['name' => $names[$args[0]]]);
				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getVoorzieningenConfig')->willReturn(
			['register' => '1', 'gebruik_schema' => '20', 'contract_schema' => '21', 'module_schema' => '22', 'moduleVersie_schema' => '23']
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(100);

		$reflection = new ReflectionClass(PortfolioReportService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (
			[
				'catalogue' => $catalogue,
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

		return $service;
	}//end service()

	/**
	 * Two rows sharing a component name each other; the report counts every row with an overlap.
	 *
	 * @return void
	 */
	public function testRowsSharingAComponentNameEachOther(): void {
		$report = $this->service()->buildReport('org-a');
		$rows = array_column($report['rows'], null, 'uuid');

		$this->assertSame(
			[['componentId' => 'rc-zaak', 'component' => 'Zaaksysteem', 'modules' => ['Zaken Twee']]],
			$rows['g-1']['overlapsWith']
		);
		$this->assertSame(
			[['componentId' => 'rc-zaak', 'component' => 'Zaaksysteem', 'modules' => ['Zaken Een']]],
			$rows['g-2']['overlapsWith']
		);
		$this->assertSame(['Zaaksysteem', 'DMS'], $rows['g-2']['referenceComponents']);

		// A usage with no status counts, so Relaties and the second usage of Zaken Een overlap on CRM.
		$this->assertSame('CRM', $rows['g-3']['overlapsWith'][0]['component']);
		$this->assertSame(['Zaken Een'], $rows['g-3']['overlapsWith'][0]['modules']);

		// A planned usage does not count, is not marked, and does not mark others.
		$this->assertSame([], $rows['g-5']['overlapsWith']);
		$this->assertSame(['Zaaksysteem'], $rows['g-5']['referenceComponents']);

		$this->assertSame(4, $report['overlap']);
	}//end testRowsSharingAComponentNameEachOther()

	/**
	 * Two usages of the same module are one application: no overlap with itself.
	 *
	 * @return void
	 */
	public function testTheSameModuleTwiceIsNoOverlap(): void {
		$rows = (new PortfolioReportDerivation())->withOverlap(
			rows: [
				['uuid' => 'a', 'moduleId' => 'm-1', 'moduleName' => 'Een'],
				['uuid' => 'b', 'moduleId' => 'm-1', 'moduleName' => 'Een'],
			],
			usages: [
				['usedForReferenceComponents' => ['rc-1']],
				['usedForReferenceComponents' => ['rc-1']],
			],
			componentNames: ['rc-1' => 'Eén']
		);

		$this->assertSame([], $rows[0]['overlapsWith']);
		$this->assertSame([], $rows[1]['overlapsWith']);
		$this->assertSame(['Eén'], $rows[0]['referenceComponents']);
	}//end testTheSameModuleTwiceIsNoOverlap()

	/**
	 * The CSV names the component and the other module for an overlapping row and leaves it empty otherwise.
	 *
	 * @return void
	 */
	public function testTheCsvCarriesTheOverlap(): void {
		$lines = array_map('str_getcsv', explode("\n", trim($this->service()->buildCsv('org-a'))));
		$header = array_shift($lines);
		$column = array_search('overlapsWith', $header, true);

		$this->assertSame(count($header) - 1, $column);
		$this->assertSame('Zaaksysteem: Zaken Twee', $lines[0][$column]);
		$this->assertSame('Zaaksysteem: Zaken Een', $lines[1][$column]);
		$this->assertSame('', $lines[4][$column]);
	}//end testTheCsvCarriesTheOverlap()
}//end class
