<?php

/**
 * Characterisation tests for the rows and CSV of PortfolioReportService.
 *
 * Pins the exact row payload (keys, order, values), the exact CSV document,
 * the relation lookups and the contract-fetch fallback, so the row and CSV
 * builders can be split into named steps without changing their output.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
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
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Characterisation tests for the portfolio report rows and CSV export.
 */
class PortfolioReportRowsTest extends TestCase {

	/**
	 * Ids passed to ObjectService::find, in call order.
	 *
	 * @var array<int,string>
	 */
	private array $foundIds = [];

	/**
	 * Queries passed to ObjectService::searchObjectsPaginated, in call order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * The gebruiken the report is built over.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function usages(): array {
		return [
			[
				'id' => 'g-1',
				'module' => 'm-1',
				'moduleVersion' => 'v-1',
				'cloudDienstverleningsmodel' => 'SaaS',
				'timeClassification' => 'Invest',
				'timeRationale' => 'Kernsysteem',
				'timeReviewDate' => '2027-01-01',
			],
			[
				'@self' => ['id' => 'g-2'],
				'module' => ['id' => 'm-2'],
				'cloudDienstverleningsmodel' => ['IaaS', '', 5],
				'timeClassification' => 'bogus',
			],
			[
				'id' => 'g-3',
				'module' => 'm-1',
			],
		];
	}//end usages()

	/**
	 * The contracts the contract search returns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function contracts(): array {
		return [
			['usage' => 'g-1', 'cost' => 10, 'costPeriod' => 'Monthly'],
			['usage' => 'g-1', 'cost' => '99', 'costPeriod' => 'One-off'],
			['usage' => ['id' => 'g-2'], 'cost' => 5, 'costPeriod' => 'Annually'],
			['usage' => null, 'cost' => 1, 'costPeriod' => 'Annually'],
		];
	}//end contracts()

	/**
	 * A found relation whose data bag is the given array.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A service over the fixture usages, relations and contracts.
	 *
	 * @param bool            $contractsFail Whether the contract search throws.
	 * @param LoggerInterface $logger        The logger double.
	 *
	 * @return PortfolioReportService
	 */
	private function service(bool $contractsFail, LoggerInterface $logger): PortfolioReportService {
		$entities = [
			'm-1' => $this->entity(['name' => 'Module Een']),
			'v-1' => $this->entity(['dateEndSupport' => '2000-01-01']),
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($contractsFail): array {
				$this->queries[] = $query;
				if ($query['@self']['schema'] === 20) {
					return ['results' => $this->usages(), 'total' => 7];
				}

				if ($contractsFail === true) {
					throw new RuntimeException('boom');
				}

				return ['results' => $this->contracts(), 'total' => 4];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (...$args) use ($entities) {
				$this->foundIds[] = $args[0];
				if (isset($entities[$args[0]]) === false) {
					throw new RuntimeException('not found');
				}

				return $entities[$args[0]];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => '1', 'gebruik_schema' => '20', 'contract_schema' => '21', 'module_schema' => '22', 'moduleVersie_schema' => '23']
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(2);

		$reflection = new ReflectionClass(PortfolioReportService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (
			[
				'settingsService' => $settingsService,
				'appManager' => $appManager,
				'container' => $container,
				'logger' => $logger,
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
	 * The report rows carry exactly these keys, in this order, with these values.
	 *
	 * @return void
	 */
	public function testRowsArePinnedKeyForKey(): void {
		$report = $this->service(contractsFail: false, logger: $this->createMock(LoggerInterface::class))->buildReport('org-a');

		$this->assertSame(7, $report['totalGebruiken']);
		$this->assertSame(3, $report['includedGebruiken']);
		$this->assertTrue($report['truncated']);
		$this->assertSame(2, $report['pageSizeCeiling']);
		$this->assertSame($this->expectedRows(), $report['rows']);
		$this->assertSame(['m-1', 'v-1', 'm-2'], $this->foundIds);
		$this->assertSame(
			[
				'@self' => ['register' => 1, 'schema' => 20],
				'consumer' => 'org-a',
				'_limit' => 2,
			],
			$this->queries[0]
		);
		$this->assertSame(
			[
				'@self' => ['register' => 1, 'schema' => 21],
				'usage' => ['g-1', 'g-2', 'g-3'],
				'_limit' => 10,
			],
			$this->queries[1]
		);
	}//end testRowsArePinnedKeyForKey()

	/**
	 * A failing contract search is logged and leaves every cost at zero.
	 *
	 * @return void
	 */
	public function testAFailingContractSearchIsLoggedAndCostsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('PortfolioReportService: contract fetch failed', ['error' => 'boom']);

		$rows = $this->service(contractsFail: true, logger: $logger)->buildReport('org-a')['rows'];

		$this->assertSame([0.0, 0.0, 0.0], array_column($rows, 'annualisedCost'));
		$this->assertSame([0.0, 0.0, 0.0], array_column($rows, 'oneOffCost'));
	}//end testAFailingContractSearchIsLoggedAndCostsNothing()

	/**
	 * The CSV export is pinned byte for byte.
	 *
	 * @return void
	 */
	public function testTheCsvIsPinnedByteForByte(): void {
		$csv = $this->service(contractsFail: false, logger: $this->createMock(LoggerInterface::class))->buildCsv('org-a');

		$this->assertSame($this->expectedCsv(), $csv);
	}//end testTheCsvIsPinnedByteForByte()

	/**
	 * The rows the fixture yields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function expectedRows(): array {
		return [
			[
				'businessValue' => null,
				'technicalFit' => null,
				'riskScore' => null,
				'scoredOn' => null,
				'suggestedTimeClassification' => null,
				'timeMismatch' => false,
				'uuid' => 'g-1',
				'moduleId' => 'm-1',
				'moduleName' => 'Module Een',
				'timeClassification' => 'Invest',
				'quadrant' => 'Invest',
				'timeRationale' => 'Kernsysteem',
				'timeReviewDate' => '2027-01-01',
				'lifecyclePhase' => 'Onbekend',
				'eol' => ['passed' => true, 'withdrawn' => false, 'endDate' => '2000-01-01', 'withdrawnDate' => null],
				'eolApproaching' => false,
				'hostingModel' => ['SaaS'],
				'annualisedCost' => 120.0,
				'oneOffCost' => 99.0,
			],
			[
				'businessValue' => null,
				'technicalFit' => null,
				'riskScore' => null,
				'scoredOn' => null,
				'suggestedTimeClassification' => null,
				'timeMismatch' => false,
				'uuid' => 'g-2',
				'moduleId' => 'm-2',
				'moduleName' => 'm-2',
				'timeClassification' => null,
				'quadrant' => 'Unclassified',
				'timeRationale' => null,
				'timeReviewDate' => null,
				'lifecyclePhase' => 'Onbekend',
				'eol' => ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null],
				'eolApproaching' => false,
				'hostingModel' => ['IaaS'],
				'annualisedCost' => 5.0,
				'oneOffCost' => 0.0,
			],
			[
				'businessValue' => null,
				'technicalFit' => null,
				'riskScore' => null,
				'scoredOn' => null,
				'suggestedTimeClassification' => null,
				'timeMismatch' => false,
				'uuid' => 'g-3',
				'moduleId' => 'm-1',
				'moduleName' => 'Module Een',
				'timeClassification' => null,
				'quadrant' => 'Unclassified',
				'timeRationale' => null,
				'timeReviewDate' => null,
				'lifecyclePhase' => 'Onbekend',
				'eol' => ['passed' => false, 'withdrawn' => false, 'endDate' => null, 'withdrawnDate' => null],
				'eolApproaching' => false,
				'hostingModel' => [],
				'annualisedCost' => 0.0,
				'oneOffCost' => 0.0,
			],
		];
	}//end expectedRows()

	/**
	 * The CSV the fixture yields.
	 *
	 * @return string
	 */
	private function expectedCsv(): string {
		return 'organisation,module,timeClassification,timeRationale,timeReviewDate,lifecyclePhase,eolStatus,hostingModel,annualisedCost,oneOffCost,'
			. "businessValue,technicalFit,riskScore,scoredOn,suggestedTimeClassification,timeMismatch\n"
			. "org-a,\"Module Een\",Invest,Kernsysteem,2027-01-01,Onbekend,passed,SaaS,120,99,,,,,,no\n"
			. "org-a,m-2,,,,Onbekend,ok,IaaS,5,0,,,,,,no\n"
			. "org-a,\"Module Een\",,,,Onbekend,ok,,0,0,,,,,,no\n";
	}//end expectedCsv()
}//end class
