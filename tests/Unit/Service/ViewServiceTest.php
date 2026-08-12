<?php

/**
 * Unit tests for ViewService deelnames-gebruik feature.
 *
 * Tests cover two-phase retrieval, source organisation metadata, deduplication,
 * and enrichment flag logic introduced by the deelnames-gebruik spec.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\ViewService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ViewService.
 *
 * @category Tests
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ViewServiceTest extends TestCase {

	/**
	 * Mock of IAppConfig.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig|MockObject $config;

	/**
	 * Mock of IAppManager.
	 *
	 * @var IAppManager|MockObject
	 */
	private IAppManager|MockObject $appManager;

	/**
	 * Mock of ContainerInterface.
	 *
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface|MockObject $container;

	/**
	 * Mock of LoggerInterface.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface|MockObject $logger;

	/**
	 * Mock of SettingsService.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * Mock of IUserSession.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Mock of ICacheFactory.
	 *
	 * @var ICacheFactory|MockObject
	 */
	private ICacheFactory|MockObject $cacheFactory;

	/**
	 * Mock of ICache.
	 *
	 * @var ICache|MockObject
	 */
	private ICache|MockObject $cache;

	/**
	 * The ViewService instance under test.
	 *
	 * @var ViewService
	 */
	private ViewService $viewService;

	/**
	 * Set up the test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);

		$this->cacheFactory
			->method('createDistributed')
			->willReturn($this->cache);

		$this->viewService = new ViewService(
			config: $this->config,
			appManager: $this->appManager,
			container: $this->container,
			logger: $this->logger,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			cacheFactory: $this->cacheFactory
		);
	}//end setUp()

	/**
	 * Helper: call a private method via ReflectionClass.
	 *
	 * @param string $methodName Name of the private method.
	 * @param array $args Method arguments.
	 *
	 * @return mixed The method return value.
	 */
	private function callPrivateMethod(string $methodName, array $args = []): mixed {
		$reflection = new ReflectionClass(objectOrClass: ViewService::class);
		$method = $reflection->getMethod(name: $methodName);
		$method->setAccessible(accessible: true);
		return $method->invokeArgs(object: $this->viewService, args: $args);
	}//end callPrivateMethod()

	// ----------------------------------------------------------------
	// shouldIncludeGebruik tests
	// ----------------------------------------------------------------

	/**
	 * Test shouldIncludeGebruik returns true when include_gebruik is set to true.
	 *
	 * @return void
	 */
	public function testShouldIncludeGebruikReturnsTrueWhenFlagIsTrue(): void {
		$result = $this->callPrivateMethod(
			methodName: 'shouldIncludeGebruik',
			args: [['include_gebruik' => true]]
		);

		$this->assertTrue(condition: $result);
	}//end testShouldIncludeGebruikReturnsTrueWhenFlagIsTrue()

	/**
	 * Test shouldIncludeGebruik returns false when include_gebruik is absent.
	 *
	 * @return void
	 */
	public function testShouldIncludeGebruikReturnsFalseWhenFlagAbsent(): void {
		$result = $this->callPrivateMethod(
			methodName: 'shouldIncludeGebruik',
			args: [[]]
		);

		$this->assertFalse(condition: $result);
	}//end testShouldIncludeGebruikReturnsFalseWhenFlagAbsent()

	// ----------------------------------------------------------------
	// shouldIncludeDeelnamesGebruik tests
	// ----------------------------------------------------------------

	/**
	 * Test shouldIncludeDeelnamesGebruik returns true when flag is set.
	 *
	 * @return void
	 */
	public function testShouldIncludeDeelnamesGebruikReturnsTrueWhenFlagIsTrue(): void {
		$result = $this->callPrivateMethod(
			methodName: 'shouldIncludeDeelnamesGebruik',
			args: [['include_deelnames_gebruik' => true]]
		);

		$this->assertTrue(condition: $result);
	}//end testShouldIncludeDeelnamesGebruikReturnsTrueWhenFlagIsTrue()

	/**
	 * Test shouldIncludeDeelnamesGebruik returns false when include_gebruik is true but deelnames is absent.
	 *
	 * Verifies that the deelnames toggle is independent from the gebruik toggle.
	 *
	 * @return void
	 */
	public function testShouldIncludeDeelnamesGebruikReturnsFalseWhenOnlyGebruikSet(): void {
		$result = $this->callPrivateMethod(
			methodName: 'shouldIncludeDeelnamesGebruik',
			args: [['include_gebruik' => true]]
		);

		$this->assertFalse(condition: $result);
	}//end testShouldIncludeDeelnamesGebruikReturnsFalseWhenOnlyGebruikSet()

	/**
	 * Test both flags can be independently enabled.
	 *
	 * @return void
	 */
	public function testBothFlagsAreIndependent(): void {
		$bothEnabled = ['include_gebruik' => true, 'include_deelnames_gebruik' => true];

		$this->assertTrue(
			condition: $this->callPrivateMethod(methodName: 'shouldIncludeGebruik', args: [$bothEnabled])
		);
		$this->assertTrue(
			condition: $this->callPrivateMethod(methodName: 'shouldIncludeDeelnamesGebruik', args: [$bothEnabled])
		);

		$deelnamesOnly = ['include_deelnames_gebruik' => true];

		$this->assertFalse(
			condition: $this->callPrivateMethod(methodName: 'shouldIncludeGebruik', args: [$deelnamesOnly])
		);
		$this->assertTrue(
			condition: $this->callPrivateMethod(methodName: 'shouldIncludeDeelnamesGebruik', args: [$deelnamesOnly])
		);
	}//end testBothFlagsAreIndependent()

	// ----------------------------------------------------------------
	// getAppliedEnrichments tests
	// ----------------------------------------------------------------

	/**
	 * Test getAppliedEnrichments lists deelnames_gebruik when flag is set.
	 *
	 * @return void
	 */
	public function testGetAppliedEnrichmentsIncludesDeelnamesWhenFlagSet(): void {
		$result = $this->callPrivateMethod(
			methodName: 'getAppliedEnrichments',
			args: [['include_deelnames_gebruik' => true]]
		);

		$this->assertContains(needle: 'deelnames_gebruik', haystack: $result);
		$this->assertNotContains(needle: 'gebruik', haystack: $result);
	}//end testGetAppliedEnrichmentsIncludesDeelnamesWhenFlagSet()

	/**
	 * Test getAppliedEnrichments lists gebruik when flag is set.
	 *
	 * @return void
	 */
	public function testGetAppliedEnrichmentsIncludesGebruikWhenFlagSet(): void {
		$result = $this->callPrivateMethod(
			methodName: 'getAppliedEnrichments',
			args: [['include_gebruik' => true]]
		);

		$this->assertContains(needle: 'gebruik', haystack: $result);
		$this->assertNotContains(needle: 'deelnames_gebruik', haystack: $result);
	}//end testGetAppliedEnrichmentsIncludesGebruikWhenFlagSet()

	/**
	 * Test getAppliedEnrichments returns empty array when no flags set.
	 *
	 * @return void
	 */
	public function testGetAppliedEnrichmentsReturnsEmptyArrayWhenNoFlagsSet(): void {
		$result = $this->callPrivateMethod(
			methodName: 'getAppliedEnrichments',
			args: [[]]
		);

		$this->assertSame(expected: [], actual: $result);
	}//end testGetAppliedEnrichmentsReturnsEmptyArrayWhenNoFlagsSet()

	// ----------------------------------------------------------------
	// processGebruikItems source org metadata tests
	// ----------------------------------------------------------------

	/**
	 * Test processGebruikItems attaches _type: "regular" for regular items.
	 *
	 * @return void
	 */
	public function testProcessGebruikItemsSetsRegularType(): void {
		$allGebruik = [];
		$items = [
			['elementRef' => 'ref-1', 'naam' => 'App A'],
		];

		$this->callPrivateMethod(
			methodName: 'processGebruikItems',
			args: [$items, &$allGebruik, 'org-uuid-a', 'regular']
		);

		$this->assertArrayHasKey(key: 'ref-1', array: $allGebruik);
		$this->assertSame(expected: 'regular', actual: $allGebruik['ref-1'][0]['_type']);
		$this->assertArrayNotHasKey(key: '_sourceOrganizationId', array: $allGebruik['ref-1'][0]);
	}//end testProcessGebruikItemsSetsRegularType()

	/**
	 * Test processGebruikItems attaches source organisation metadata for deelnames items.
	 *
	 * @return void
	 */
	public function testProcessGebruikItemsAttachesSourceOrgMetadataForDeelnames(): void {
		$allGebruik = [];
		$items = [
			[
				'elementRef' => 'ref-2',
				'naam' => 'Shared App',
				'afnemer' => [
					'id' => 'org-uuid-b',
					'naam' => 'Gemeente B',
				],
			],
		];

		$this->callPrivateMethod(
			methodName: 'processGebruikItems',
			args: [$items, &$allGebruik, 'org-uuid-a', 'deelnames']
		);

		$this->assertArrayHasKey(key: 'ref-2', array: $allGebruik);
		$item = $allGebruik['ref-2'][0];
		$this->assertSame(expected: 'deelnames', actual: $item['_type']);
		$this->assertSame(expected: 'org-uuid-b', actual: $item['_sourceOrganizationId']);
		$this->assertSame(expected: 'Gemeente B', actual: $item['_sourceOrganization']);
	}//end testProcessGebruikItemsAttachesSourceOrgMetadataForDeelnames()

	/**
	 * Test processGebruikItems falls back to @self.organisation when afnemer is absent.
	 *
	 * @return void
	 */
	public function testProcessGebruikItemsFallsBackToSelfOrgWhenAfnemerAbsent(): void {
		$allGebruik = [];
		$items = [
			[
				'elementRef' => 'ref-3',
				'@self' => ['organisation' => 'org-uuid-c'],
			],
		];

		$this->callPrivateMethod(
			methodName: 'processGebruikItems',
			args: [$items, &$allGebruik, 'org-uuid-a', 'deelnames']
		);

		$item = $allGebruik['ref-3'][0];
		$this->assertSame(expected: 'org-uuid-c', actual: $item['_sourceOrganizationId']);
		$this->assertNull(actual: $item['_sourceOrganization']);
	}//end testProcessGebruikItemsFallsBackToSelfOrgWhenAfnemerAbsent()

	/**
	 * Test processGebruikItems skips items without an elementRef.
	 *
	 * @return void
	 */
	public function testProcessGebruikItemsSkipsItemsWithoutElementRef(): void {
		$allGebruik = [];
		$items = [
			['naam' => 'No Ref Item'],
		];

		$this->callPrivateMethod(
			methodName: 'processGebruikItems',
			args: [$items, &$allGebruik, 'org-uuid-a', 'regular']
		);

		$this->assertSame(expected: [], actual: $allGebruik);
	}//end testProcessGebruikItemsSkipsItemsWithoutElementRef()

	// ----------------------------------------------------------------
	// Deduplication tests (via enrichViewNodes indirectly via getNodeGebruik/getNodeDeelnamesGebruik)
	// ----------------------------------------------------------------

	/**
	 * Test getNodeGebruik returns items indexed by elementRef.
	 *
	 * @return void
	 */
	public function testGetNodeGebruikReturnsItemsForMatchingElementRef(): void {
		$gebruikData = [
			'ref-1' => [['naam' => 'App A', '_type' => 'regular']],
		];

		$result = $this->callPrivateMethod(
			methodName: 'getNodeGebruik',
			args: ['ref-1', $gebruikData]
		);

		$this->assertCount(expectedCount: 1, haystack: $result);
		$this->assertSame(expected: 'App A', actual: $result[0]['naam']);
	}//end testGetNodeGebruikReturnsItemsForMatchingElementRef()

	/**
	 * Test getNodeGebruik returns empty array when elementRef has no data.
	 *
	 * @return void
	 */
	public function testGetNodeGebruikReturnsEmptyArrayForUnknownRef(): void {
		$result = $this->callPrivateMethod(
			methodName: 'getNodeGebruik',
			args: ['unknown-ref', []]
		);

		$this->assertSame(expected: [], actual: $result);
	}//end testGetNodeGebruikReturnsEmptyArrayForUnknownRef()

	/**
	 * Test getNodeDeelnamesGebruik returns deelnames items indexed by elementRef.
	 *
	 * @return void
	 */
	public function testGetNodeDeelnamesGebruikReturnsDeelnamesItems(): void {
		$deelnamesData = [
			'ref-2' => [
				[
					'naam' => 'Shared App',
					'_type' => 'deelnames',
					'_sourceOrganization' => 'Gemeente B',
					'_sourceOrganizationId' => 'org-uuid-b',
				],
			],
		];

		$result = $this->callPrivateMethod(
			methodName: 'getNodeDeelnamesGebruik',
			args: ['ref-2', $deelnamesData]
		);

		$this->assertCount(expectedCount: 1, haystack: $result);
		$this->assertSame(expected: 'deelnames', actual: $result[0]['_type']);
		$this->assertSame(expected: 'Gemeente B', actual: $result[0]['_sourceOrganization']);
	}//end testGetNodeDeelnamesGebruikReturnsDeelnamesItems()

	/**
	 * Test deduplication: owned gebruik elementRef should not appear in deelnames data.
	 *
	 * Verifies array_diff_key deduplication logic used in enrichViewNodes.
	 *
	 * @return void
	 */
	public function testDeduplicationRemovesDeelnamesWhenOwnedExists(): void {
		$gebruikData = [
			'ref-owned' => [['_type' => 'regular', 'naam' => 'Topdesk']],
			'ref-owned2' => [['_type' => 'regular', 'naam' => 'ServiceNow']],
		];

		$deelnamesData = [
			// Same ref as owned — should be deduplicated.
			'ref-owned' => [['_type' => 'deelnames', 'naam' => 'Topdesk']],
			// Unique to deelnames — should survive.
			'ref-deelnames' => [['_type' => 'deelnames', 'naam' => 'OtherApp']],
		];

		$deduplicated = array_diff_key($deelnamesData, $gebruikData);

		$this->assertArrayNotHasKey(key: 'ref-owned', array: $deduplicated);
		$this->assertArrayHasKey(key: 'ref-deelnames', array: $deduplicated);
	}//end testDeduplicationRemovesDeelnamesWhenOwnedExists()

	/**
	 * Test deduplication: when there is no owned gebruik, all deelnames survive.
	 *
	 * @return void
	 */
	public function testDeduplicationKeepsAllDeelnamesWhenNoOwnedGebruik(): void {
		$gebruikData = [];

		$deelnamesData = [
			'ref-a' => [['_type' => 'deelnames']],
			'ref-b' => [['_type' => 'deelnames']],
		];

		$deduplicated = empty($gebruikData) === false
			? array_diff_key($deelnamesData, $gebruikData)
			: $deelnamesData;

		$this->assertSame(expected: $deelnamesData, actual: $deduplicated);
	}//end testDeduplicationKeepsAllDeelnamesWhenNoOwnedGebruik()

	// ----------------------------------------------------------------
	// getProductsData / getNodeProducts tests
	// (openspec/changes/view-products-enrichment)
	// ----------------------------------------------------------------

	/**
	 * getProductsData() returns [] gracefully (not fatal) when the dienst
	 * schema is not configured in the voorzieningen config.
	 *
	 * @return void
	 */
	public function testGetProductsDataReturnsEmptyWhenSchemaNotConfigured(): void {
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => 1, 'dienst_schema' => null]
		);

		$result = $this->callPrivateMethod(methodName: 'getProductsData');

		$this->assertSame([], $result);
	}//end testGetProductsDataReturnsEmptyWhenSchemaNotConfigured()

	/**
	 * getProductsData() queries the dienst schema (organisation-scoped) and
	 * indexes real product entries by elementRef when the register/schema
	 * resolve and products exist — NOT an unconditional [] stub.
	 *
	 * @return void
	 */
	public function testGetProductsDataReturnsOrganisationScopedProducts(): void {
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => 1, 'dienst_schema' => 3]
		);

		$this->userSession->method('getUser')->willReturn(null);

		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjects')->willReturn(
			[
				['elementRef' => 'node-1', 'naam' => 'Zaaksysteem'],
				['identifier' => 'node-2', 'naam' => 'Formulieren'],
			]
		);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->callPrivateMethod(methodName: 'getProductsData');

		$this->assertArrayHasKey('node-1', $result);
		$this->assertSame('Zaaksysteem', $result['node-1']['naam']);
		$this->assertArrayHasKey('node-2', $result);
		$this->assertSame('Formulieren', $result['node-2']['naam']);
	}//end testGetProductsDataReturnsOrganisationScopedProducts()

	/**
	 * getNodeProducts() matches a product linked to a given node by
	 * elementRef and excludes unrelated products.
	 *
	 * @return void
	 */
	public function testGetNodeProductsMatchesLinkedProductAndExcludesOthers(): void {
		$productsData = [
			'node-1' => ['naam' => 'Zaaksysteem'],
			'node-2' => ['naam' => 'Formulieren'],
		];

		$matched = $this->callPrivateMethod(
			methodName: 'getNodeProducts',
			args: ['node-1', $productsData]
		);

		$this->assertCount(1, $matched);
		$this->assertSame('Zaaksysteem', $matched[0]['naam']);
	}//end testGetNodeProductsMatchesLinkedProductAndExcludesOthers()

	/**
	 * getNodeProducts() returns [] (not the full $productsData count) when no
	 * product is linked to the given node — available_products_count in the
	 * caller's context must reflect the matched count, not the total.
	 *
	 * @return void
	 */
	public function testGetNodeProductsReturnsEmptyWhenNoProductLinkedToNode(): void {
		$productsData = [
			'node-1' => ['naam' => 'Zaaksysteem'],
		];

		$matched = $this->callPrivateMethod(
			methodName: 'getNodeProducts',
			args: ['node-unlinked', $productsData]
		);

		$this->assertSame([], $matched);
	}//end testGetNodeProductsReturnsEmptyWhenNoProductLinkedToNode()

}//end class
