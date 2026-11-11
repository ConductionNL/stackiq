<?php

/**
 * Golden tests for the ViewService products and modules enrichment loaders.
 *
 * Pins the queries sent to OpenRegister, the organisation filter on the
 * returned rows (own and unowned rows kept, foreign rows dropped), the
 * elementRef index and the fail-closed paths of getProductsData() and
 * getModulesData(), so splitting them into named steps keeps every outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden tests for ViewService::getProductsData() and getModulesData().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ViewServiceEnrichmentDataGoldenTest extends TestCase {

	/**
	 * The active organisation uuid, or null for no active organisation.
	 *
	 * @var string|null
	 */
	private ?string $activeOrganisation = 'org-own';

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $openRegisterInstalled = true;

	/**
	 * Every query passed to searchObjects(), in order.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * Rows returned per schema id; a Throwable value is thrown instead.
	 *
	 * @var array<string, mixed>
	 */
	private array $rowsBySchema = [];

	/**
	 * The voorzieningen config returned by the settings service.
	 *
	 * @var array
	 */
	private array $voorzieningenConfig = [];

	/**
	 * The AMEF config returned by the settings service.
	 *
	 * @var array
	 */
	private array $amefConfig = [];

	/**
	 * Logger double that records every call.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface|MockObject $logger;

	/**
	 * Every logger call as [level, message, context].
	 *
	 * @var array<int, array>
	 */
	private array $logs = [];

	/**
	 * Build the service under test.
	 *
	 * @return ViewService
	 */
	private function service(): ViewService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (): bool => $this->openRegisterInstalled);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturnCallback(fn (): array => $this->voorzieningenConfig);
		$settingsService->method('getAmefConfig')->willReturnCallback(fn (): array => $this->amefConfig);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturnCallback(
			function (): ?Organisation {
				if ($this->activeOrganisation === null) {
					return null;
				}

				$organisation = new Organisation();
				$organisation->setUuid($this->activeOrganisation);
				return $organisation;
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query;
				$rows = $this->rowsBySchema[(string) $query['@self']['schema']] ?? [];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $organisationService) {
				if ($id === ObjectServiceInterface::class) {
					return $objectService;
				}

				return $organisationService;
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $this->logger,
			settingsService: $settingsService,
			userSession: $userSession,
			cacheFactory: $cacheFactory
		);
	}//end service()

	/**
	 * Call a private loader on a fresh service.
	 *
	 * @param string $method The private method name.
	 *
	 * @return array The loader's result.
	 */
	private function load(string $method): array {
		$service = $this->service();
		$reflection = new ReflectionClass(objectOrClass: ViewService::class);
		$loader = $reflection->getMethod(name: $method);
		$loader->setAccessible(accessible: true);
		return $loader->invoke($service);
	}//end load()

	/**
	 * Rows of one schema: own, foreign, unowned and unreferenced.
	 *
	 * @return array<int, array>
	 */
	private function mixedRows(): array {
		return [
			['elementRef' => 'ref-own', 'name' => 'own', '@self' => ['organisation' => 'org-own']],
			['elementRef' => 'ref-foreign', 'name' => 'foreign', '@self' => ['organisation' => 'org-other']],
			['identifier' => 'ref-unowned', 'name' => 'unowned'],
			['name' => 'no reference', '@self' => ['organisation' => 'org-own']],
			['elementRef' => 'ref-null-org', 'name' => 'null org', '@self' => ['organisation' => null]],
		];
	}//end mixedRows()

	/**
	 * Products: the organisation-scoped query and the row filter, allowed and refused.
	 *
	 * @return void
	 */
	public function testProductsKeepOwnAndUnownedRowsAndDropForeignRows(): void {
		$this->voorzieningenConfig = ['register' => 'voorz', 'dienst_schema' => 'dienst'];
		$this->rowsBySchema = ['dienst' => $this->mixedRows()];

		$result = $this->load(method: 'getProductsData');

		$this->assertSame(
			[
				[
					'@self' => [
						'register' => 'voorz',
						'schema' => 'dienst',
						'organisation' => 'org-own',
					],
					'_limit' => 500,
				],
			],
			$this->queries
		);
		$this->assertSame(['ref-own', 'ref-unowned', 'ref-null-org'], array_keys($result));
		$this->assertSame('own', $result['ref-own']['name']);
		$this->assertSame(
			['debug', 'Total products retrieved for enrichment', ['total_products' => 3]],
			$this->logs[1]
		);
	}//end testProductsKeepOwnAndUnownedRowsAndDropForeignRows()

	/**
	 * Products without an active organisation: no organisation filter at all.
	 *
	 * @return void
	 */
	public function testProductsWithoutOrganisationAreNotFiltered(): void {
		$this->activeOrganisation = null;
		$this->voorzieningenConfig = ['register' => 'voorz', 'dienst_schema' => 'dienst'];
		$this->rowsBySchema = ['dienst' => $this->mixedRows()];

		$result = $this->load(method: 'getProductsData');

		$this->assertSame([['@self' => ['register' => 'voorz', 'schema' => 'dienst'], '_limit' => 500]], $this->queries);
		$this->assertSame(['ref-own', 'ref-foreign', 'ref-unowned', 'ref-null-org'], array_keys($result));
	}//end testProductsWithoutOrganisationAreNotFiltered()

	/**
	 * Products fail closed on every missing piece of configuration.
	 *
	 * @return void
	 */
	public function testProductsFailClosedWhenUnconfigured(): void {
		$configs = [
			['dienst_schema' => 'dienst'],
			['register' => 'voorz'],
			['register' => 'voorz', 'dienst_schema' => ''],
		];
		foreach ($configs as $config) {
			$this->voorzieningenConfig = $config;
			$this->logs = [];

			$this->assertSame([], $this->load(method: 'getProductsData'));
			$this->assertSame(
				['warning', 'Voorzieningen register or dienst schema not configured for products', []],
				$this->logs[1]
			);
		}

		$this->assertSame([], $this->queries);
	}//end testProductsFailClosedWhenUnconfigured()

	/**
	 * Products: a failing search is logged and yields no products.
	 *
	 * @return void
	 */
	public function testProductsSearchFailureIsLoggedAndEmpty(): void {
		$this->voorzieningenConfig = ['register' => 'voorz', 'dienst_schema' => 'dienst'];
		$this->rowsBySchema = ['dienst' => new \RuntimeException('down')];

		$this->assertSame([], $this->load(method: 'getProductsData'));
		$this->assertSame(['error', 'Failed to get products data', ['error' => 'down']], $this->logs[1]);
	}//end testProductsSearchFailureIsLoggedAndEmpty()

	/**
	 * Products and modules: no OpenRegister means no query and no rows.
	 *
	 * @return void
	 */
	public function testLoadersReturnEmptyWithoutOpenRegister(): void {
		$this->openRegisterInstalled = false;
		$this->voorzieningenConfig = ['register' => 'voorz', 'dienst_schema' => 'dienst'];
		$this->amefConfig = ['register_id' => 'amef', 'module_schema' => 'module'];

		$this->assertSame([], $this->load(method: 'getProductsData'));
		$this->assertSame([], $this->load(method: 'getModulesData'));
		$this->assertSame([], $this->queries);
	}//end testLoadersReturnEmptyWithoutOpenRegister()

	/**
	 * Modules: one scoped query per configured schema, merged in schema order.
	 *
	 * @return void
	 */
	public function testModulesQueryEachConfiguredSchemaAndFilterRows(): void {
		$this->amefConfig = [
			'register_id' => 'amef',
			'module_schema' => 'module',
			'component_schema' => '',
			'element_schema' => 'element',
		];
		$this->rowsBySchema = [
			'module' => $this->mixedRows(),
			'element' => [
				['elementRef' => 'ref-own', 'name' => 'element own'],
				['elementRef' => 'ref-element', 'name' => 'element', '@self' => ['organisation' => 'org-own']],
				['elementRef' => 'ref-element-foreign', '@self' => ['organisation' => 'org-x']],
			],
		];

		$result = $this->load(method: 'getModulesData');

		$expectedQuery = fn (string $schema): array => [
			'@self' => [
				'register' => 'amef',
				'schema' => $schema,
				'organisation' => 'org-own',
			],
			'_limit' => 1000,
		];
		$this->assertSame([$expectedQuery('module'), $expectedQuery('element')], $this->queries);
		$this->assertSame(['ref-own', 'ref-unowned', 'ref-null-org', 'ref-element'], array_keys($result));
		$this->assertSame('element own', $result['ref-own']['name']);
		$this->assertSame(
			[
				['debug', 'Getting modules data for enrichment', []],
				['debug', 'Retrieved modules from schema', ['schema_id' => 'module', 'modules_count' => 5]],
				['debug', 'Retrieved modules from schema', ['schema_id' => 'element', 'modules_count' => 3]],
				['debug', 'Total modules retrieved for enrichment', ['total_modules' => 4]],
			],
			$this->logs
		);
	}//end testModulesQueryEachConfiguredSchemaAndFilterRows()

	/**
	 * Modules without an active organisation are not filtered.
	 *
	 * @return void
	 */
	public function testModulesWithoutOrganisationAreNotFiltered(): void {
		$this->activeOrganisation = null;
		$this->amefConfig = ['register_id' => 'amef', 'component_schema' => 'component'];
		$this->rowsBySchema = ['component' => $this->mixedRows()];

		$result = $this->load(method: 'getModulesData');

		$this->assertSame([['@self' => ['register' => 'amef', 'schema' => 'component'], '_limit' => 1000]], $this->queries);
		$this->assertSame(['ref-own', 'ref-foreign', 'ref-unowned', 'ref-null-org'], array_keys($result));
	}//end testModulesWithoutOrganisationAreNotFiltered()

	/**
	 * Modules fail closed on an unconfigured register: no unpinned query.
	 *
	 * @return void
	 */
	public function testModulesFailClosedWithoutRegister(): void {
		foreach ([[], ['register_id' => ''], ['register_id' => null]] as $config) {
			$this->amefConfig = $config + ['module_schema' => 'module'];
			$this->logs = [];

			$this->assertSame([], $this->load(method: 'getModulesData'));
			$this->assertSame('warning', $this->logs[1][0]);
			$this->assertCount(2, $this->logs);
		}

		$this->assertSame([], $this->queries);
	}//end testModulesFailClosedWithoutRegister()

	/**
	 * Modules: a failing schema is logged and the other schemas still load.
	 *
	 * @return void
	 */
	public function testModulesSchemaFailureIsSkipped(): void {
		$this->amefConfig = ['register_id' => 'amef', 'module_schema' => 'module', 'element_schema' => 'element'];
		$this->rowsBySchema = [
			'module' => new \RuntimeException('schema down'),
			'element' => [['elementRef' => 'ref-element']],
		];

		$result = $this->load(method: 'getModulesData');

		$this->assertSame(['ref-element'], array_keys($result));
		$this->assertSame(
			['warning', 'Failed to get modules from schema', ['schema_id' => 'module', 'error' => 'schema down']],
			$this->logs[1]
		);
		$this->assertSame(['debug', 'Total modules retrieved for enrichment', ['total_modules' => 1]], $this->logs[3]);
	}//end testModulesSchemaFailureIsSkipped()

}//end class
