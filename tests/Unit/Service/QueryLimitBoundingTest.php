<?php

/**
 * Unit tests verifying representative `searchObjects()` call sites carry an
 * explicit `_limit`.
 *
 * ObjectService::searchObjects() builds `setMaxResults($query['_limit'] ??
 * null)` — an unset `_limit` means Doctrine's setMaxResults(null), i.e. no
 * LIMIT clause at all (a full-table scan). A repo-wide audit found 25 of 29
 * call sites omitting `_limit`; this test covers one representative call
 * site per named service from that audit (ViewService,
 * OrganizationSyncService) as a regression backstop so the omission cannot
 * silently reappear.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/bound-unbounded-searchobjects-scans/tasks.md#task-5-1
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCA\Stackiq\Service\ViewService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Regression backstop for the unbounded-`searchObjects()` fix.
 *
 * @spec openspec/changes/bound-unbounded-searchobjects-scans/tasks.md#task-5-1
 */
class QueryLimitBoundingTest extends TestCase {

	/**
	 * ViewService::getViewsFromRegister() (invoked via the public
	 * getAllViews()) passes `_limit` on the "all view objects" query — the
	 * view-index load call site from the proposal (ViewService.php:270).
	 *
	 * @return void
	 */
	public function testViewServiceViewIndexQueryCarriesLimit(): void {
		$capturedQuery = null;

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$capturedQuery): array {
				$capturedQuery = $query;
				return [];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getAmefConfig')->willReturn(
			['register_id' => 1, 'view_schema' => 3]
		);

		$service = new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $this->createMock(IAppManager::class),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settingsService,
			userSession: $this->createMock(IUserSession::class),
			cacheFactory: $this->createMock(ICacheFactory::class)
		);

		$reflection = new ReflectionClass(ViewService::class);
		$method = $reflection->getMethod('getViewsFromRegister');
		$method->setAccessible(true);
		$method->invoke($service);

		$this->assertIsArray($capturedQuery);
		$this->assertArrayHasKey('_limit', $capturedQuery);
		$this->assertGreaterThan(0, $capturedQuery['_limit']);
	}//end testViewServiceViewIndexQueryCarriesLimit()

	/**
	 * OrganizationSyncService::getOrganisatieObjectsByTimeWindow() passes
	 * `_limit` on the organisatie sync query — the org-sync call site from
	 * the proposal (OrganizationSyncService.php:830).
	 *
	 * @return void
	 */
	public function testOrganizationSyncServiceTimeWindowQueryCarriesLimit(): void {
		$capturedQuery = null;

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$capturedQuery): array {
				$capturedQuery = $query;
				return [];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$reflection = new ReflectionClass(OrganizationSyncService::class);
		$service = $reflection->newInstanceWithoutConstructor();

		$containerProp = new ReflectionProperty(OrganizationSyncService::class, 'container');
		$containerProp->setAccessible(true);
		$containerProp->setValue($service, $container);

		// ADR-084: the subject reads OpenRegister through the INJECTED contract,
		// not through the container. `newInstanceWithoutConstructor()` leaves a
		// typed property uninitialised, and touching it is an Error — not a
		// null — so seeding only `container` made this test die before it could
		// observe the query it exists to observe.
		$objectServiceProp = new ReflectionProperty(OrganizationSyncService::class, 'objectService');
		$objectServiceProp->setAccessible(true);
		$objectServiceProp->setValue($service, $objectService);

		$loggerProp = new ReflectionProperty(OrganizationSyncService::class, 'logger');
		$loggerProp->setAccessible(true);
		$loggerProp->setValue($service, $this->createMock(LoggerInterface::class));

		$method = new ReflectionMethod($service, 'getOrganisationObjectsByTimeWindow');
		$method->setAccessible(true);
		$method->invoke($service, '1', '3', 0);

		$this->assertIsArray($capturedQuery);
		$this->assertArrayHasKey('_limit', $capturedQuery);
		$this->assertGreaterThan(0, $capturedQuery['_limit']);
	}//end testOrganizationSyncServiceTimeWindowQueryCarriesLimit()

	/**
	 * FacetService::getFacets() (gemma-faceted-search) pages the base
	 * module/dienst object set via `searchObjectsPaginated()` with an
	 * explicit `_limit` on every page request — never an unbounded
	 * `searchObjects()` scan.
	 *
	 * @spec openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
	 *
	 * @return void
	 */
	public function testFacetServiceBaseObjectQueryCarriesLimit(): void {
		$capturedQuery = null;

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$capturedQuery): array {
				$capturedQuery = $query;
				return ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				return $id === ObjectService::class ? $objectService : null;
			}
		);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => '1', 'module_schema' => '2', 'dienst_schema' => '3']
		);

		$archiMateService = $this->createMock(ArchiMateService::class);
		$archiMateService->method('getElementObjects')->willReturn([]);
		$archiMateService->method('getRelationshipObjects')->willReturn([]);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$service = new FacetService(
			container: $container,
			settingsService: $settingsService,
			archiMateService: $archiMateService,
			queryBuilder: new ViewQueryBuilder(),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			cacheFactory: $cacheFactory
		);

		$service->getFacets('module');

		$this->assertIsArray($capturedQuery);
		$this->assertArrayHasKey('_limit', $capturedQuery);
		$this->assertGreaterThan(0, $capturedQuery['_limit']);
	}//end testFacetServiceBaseObjectQueryCarriesLimit()
}//end class
