<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageDerivation;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-RCC-001: bounded reads of the organisation's usages, their modules and the reference components.
 */
final class ReferenceComponentCoverageServiceTest extends TestCase {
	public function testEveryReadIsBoundedAndTheUsagesAreTheOrganisations(): void {
		$queries = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use (&$queries): array {
				$queries[] = $query;
				return match ($query['@self']['schema']) {
					20 => ['results' => [['id' => 'u1', 'module' => 'm1', 'status' => 'In production', 'usedForReferenceComponents' => ['zrc']]], 'total' => 3],
					22 => ['results' => [['id' => 'm1', 'name' => 'Mozard', 'referenceComponents' => ['zrc', 'doc']]], 'total' => 1],
					30 => ['results' => [['id' => 'zrc', 'name' => 'Zaakregistratiecomponent'], ['id' => 'doc', 'name' => 'Documentbeheercomponent']], 'total' => 2],
				};
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'gebruik_schema' => '20', 'module_schema' => '22']);
		$settings->method('getAmefConfig')->willReturn(['register' => '2', 'element_schema' => '30']);
		$relations = new PortfolioReportDerivation();

		$coverage = (new ReferenceComponentCoverageService(
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			derivation: new ReferenceComponentCoverageDerivation($relations),
			relations: $relations
		))->build(organisationUuid: 'zuiddrecht');

		foreach ($queries as $query) {
			self::assertArrayHasKey('_limit', $query);
		}

		self::assertSame('zuiddrecht', $queries[0]['consumer']);
		self::assertSame('referentiecomponent', $queries[2]['gemmaType']);
		self::assertTrue($coverage['truncated']);
		self::assertSame(['Documentbeheercomponent' => 'gap', 'Zaakregistratiecomponent' => 'covered'], array_column($coverage['components'], 'state', 'name'));
		self::assertTrue($coverage['components'][0]['fillable']);
		self::assertSame(['Mozard'], $coverage['components'][0]['fillableBy']);
	}//end testEveryReadIsBoundedAndTheUsagesAreTheOrganisations()

	public function testWithoutAGemmaModelNoComponentIsRead(): void {
		$queries = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use (&$queries): array {
				$queries[] = $query;
				return ['results' => [], 'total' => 0];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'gebruik_schema' => '20', 'module_schema' => '22']);
		$settings->method('getAmefConfig')->willReturn(['register' => '', 'element_schema' => '']);
		$relations = new PortfolioReportDerivation();

		$coverage = (new ReferenceComponentCoverageService(
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			derivation: new ReferenceComponentCoverageDerivation($relations),
			relations: $relations
		))->build(organisationUuid: 'zuiddrecht');

		self::assertSame([], $coverage['components']);
		self::assertSame([20], array_map(static fn (array $q): int => $q['@self']['schema'], $queries));
	}//end testWithoutAGemmaModelNoComponentIsRead()
}//end class
