<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\DataQualityDerivation;
use OCA\Stackiq\Service\DataQualityReportService;
use OCA\Stackiq\Service\PortfolioReportDerivation;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-CDQ-003: the report reads the declared rules and one bounded, organisation-scoped query.
 */
final class DataQualityReportServiceTest extends TestCase {
	public function testReportCountsTheDeclaredRulesOverTheOrganisationsUsages(): void {
		$queries = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use (&$queries): array {
				$queries[] = $query;
				return [
					'results' => [
						['id' => 'u-1', 'module' => 'm-1', 'businessOwner' => 'marloes'],
						['id' => 'u-2', 'module' => 'm-2', 'businessOwner' => ''],
					],
					'total' => 2,
				];
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (string|int $id): ObjectEntityInterface {
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn(['name' => ['m-1' => 'Mozard', 'm-2' => 'Djuma'][$id]]);
				return $entity;
			}
		);

		$schemaMapper = new class {
			public function find(int $id, bool $_rbac = true, bool $_multitenancy = true): object {
				return new class {
					public function getConfiguration(): array {
						return ['x-openregister-quality' => ['rules' => [['type' => 'required', 'field' => 'businessOwner']]]];
					}

					public function getProperties(): array {
						return ['businessOwner' => ['title' => 'Business owner']];
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $name) => str_ends_with($name, 'SchemaMapper') ? $schemaMapper : $objectService
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn(['register' => '1', 'gebruik_schema' => '20']);

		$report = (new DataQualityReportService(
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: new NullLogger(),
			derivation: new DataQualityDerivation(),
			relations: new PortfolioReportDerivation()
		))->build(organisationUuid: 'zuiddrecht');

		self::assertSame('zuiddrecht', $queries[0]['consumer']);
		self::assertSame(['register' => 1, 'schema' => 20], $queries[0]['@self']);
		self::assertArrayHasKey('_limit', $queries[0]);
		self::assertSame([['type' => 'required', 'field' => 'businessOwner', 'weight' => 1.0, 'met' => 1, 'total' => 2, 'title' => 'Business owner']], $report['rules']);
		self::assertSame('Djuma', $report['lowest'][0]['name']);
		self::assertSame(['Business owner'], $report['lowest'][0]['missing']);
		self::assertSame(50, $report['summary']['completeness']);
		self::assertFalse($report['truncated']);
	}//end testReportCountsTheDeclaredRulesOverTheOrganisationsUsages()
}//end class
