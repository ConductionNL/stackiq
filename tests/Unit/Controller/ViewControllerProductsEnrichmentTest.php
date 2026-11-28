<?php

/**
 * Controller-level test of the products enrichment of GET /api/views/{viewId}.
 *
 * Runs the real ViewController over the real ViewService, with only the
 * OpenRegister object service, the organisation service, the settings, the
 * session and the cache doubled, and pins that `include_products=true` puts the
 * linked products of the current organisation on the nodes that reference them.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Controller\ViewController;
use OCA\Stackiq\Service\SearchBounds;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewOrigin;
use OCA\Stackiq\Service\ViewService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/views/{viewId}?include_products=true, controller to service.
 *
 * @spec openspec/changes/archive/2026-11-24-view-products-enrichment/specs/view-enrichment-api/spec.md
 */
#[CoversClass(ViewController::class)]
#[UsesClass(ViewService::class)]
#[UsesClass(SearchBounds::class)]
#[UsesClass(ViewOrigin::class)]
class ViewControllerProductsEnrichmentTest extends TestCase {

	/**
	 * The product rows OpenRegister answers the dienst search with.
	 *
	 * @var array<int, array>
	 */
	private array $productRows = [];

	/**
	 * Every query the dienst search was called with.
	 *
	 * @var array<int, array>
	 */
	private array $searchQueries = [];

	/**
	 * Whether the dienst schema is configured.
	 *
	 * @var boolean
	 */
	private bool $dienstConfigured = true;

	/**
	 * Call GET /api/views/view-1 with the given query parameters.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return array{status: int, data: array}
	 */
	private function getView(array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getAmefConfig')->willReturn(['register_id' => 'amef', 'view_schema' => 'view']);
		$settingsService->method('getVoorzieningenConfig')->willReturnCallback(
			fn (): array => ['register' => 'voorzieningen', 'dienst_schema' => ($this->dienstConfigured === true ? 'dienst' : '')]
		);

		$viewEntity = $this->createMock(ObjectEntityInterface::class);
		$viewEntity->method('jsonSerialize')->willReturn(
			[
				'id' => 'view-1',
				'name' => 'Applicatielandschap',
				'xml' => [
					'viewNodes' => [
						['viewNodeId' => 'vn-1', 'modelNodeId' => 'el-1'],
						['viewNodeId' => 'vn-2', 'modelNodeId' => 'el-2'],
						['viewNodeId' => 'vn-3', 'modelNodeId' => 'el-3'],
						['viewNodeId' => 'vn-4'],
					],
				],
			]
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($viewEntity);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = []): array {
				$this->searchQueries[] = $query;
				return $this->productRows;
			}
		);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturnCallback(
			static function (): Organisation {
				$organisation = new Organisation();
				$organisation->setUuid('org-a');
				return $organisation;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $organisationService) {
				if ($id === ObjectServiceInterface::class) {
					return $objectService;
				}

				if ($id === OrganisationService::class) {
					return $organisationService;
				}

				throw new \RuntimeException('Unexpected container id ' . $id);
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$logger = $this->createMock(LoggerInterface::class);
		$viewService = new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $logger,
			settingsService: $settingsService,
			userSession: $userSession,
			cacheFactory: $cacheFactory
		);

		$controller = new ViewController('stackiq', $request, $viewService, $logger, $userSession);
		$response = $controller->getView('view-1');

		return ['status' => $response->getStatus(), 'data' => $response->getData()];
	}//end getView()

	/**
	 * The nodes of the answered view, keyed by view node id.
	 *
	 * @param array $data The response body.
	 *
	 * @return array<string, array>
	 */
	private function nodesById(array $data): array {
		$nodes = [];
		foreach ($data['view']['viewNodes'] as $node) {
			$nodes[$node['viewNodeId']] = $node;
		}

		return $nodes;
	}//end nodesById()

	/**
	 * Nodes with a linked product of the current organisation carry it in
	 * `products`; other nodes carry an empty list, a product of another
	 * organisation is left out, and a node without a model element is untouched.
	 *
	 * @return void
	 */
	public function testIncludeProductsPutsTheLinkedProductsOnTheirNodes(): void {
		$this->productRows = [
			['id' => 'p-1', 'elementRef' => 'el-1', 'name' => 'Zaaksysteem', '@self' => ['organisation' => 'org-a']],
			['id' => 'p-2', 'identifier' => 'el-2', 'name' => 'Formulieren'],
			['id' => 'p-3', 'elementRef' => 'el-3', 'name' => 'Ander', '@self' => ['organisation' => 'org-b']],
		];

		$result = $this->getView(['include_products' => 'true']);

		$this->assertSame(200, $result['status']);
		$this->assertTrue($result['data']['success']);
		$this->assertContains('products', $result['data']['enrichments_applied']);

		$nodes = $this->nodesById($result['data']);
		$this->assertSame(['p-1'], array_column($nodes['vn-1']['products'], 'id'));
		$this->assertSame('Zaaksysteem', $nodes['vn-1']['products'][0]['name']);
		$this->assertSame(['p-2'], array_column($nodes['vn-2']['products'], 'id'));
		$this->assertSame([], $nodes['vn-3']['products']);
		$this->assertArrayNotHasKey('products', $nodes['vn-4']);

		$this->assertCount(1, $this->searchQueries);
		$this->assertSame(
			['register' => 'voorzieningen', 'schema' => 'dienst', 'organisation' => 'org-a'],
			$this->searchQueries[0]['@self']
		);
	}//end testIncludeProductsPutsTheLinkedProductsOnTheirNodes()

	/**
	 * Without `include_products` the products are neither searched nor set.
	 *
	 * @return void
	 */
	public function testWithoutIncludeProductsNoProductsAreRead(): void {
		$this->productRows = [
			['id' => 'p-1', 'elementRef' => 'el-1', 'name' => 'Zaaksysteem'],
		];

		$result = $this->getView(['include_products' => 'false']);

		$this->assertSame(200, $result['status']);
		$this->assertNotContains('products', $result['data']['enrichments_applied']);
		$this->assertSame([], $this->searchQueries);
		foreach ($this->nodesById($result['data']) as $node) {
			$this->assertArrayNotHasKey('products', $node);
		}
	}//end testWithoutIncludeProductsNoProductsAreRead()

	/**
	 * With the dienst schema unconfigured the view is still answered, every
	 * referencing node with an empty products list.
	 *
	 * @return void
	 */
	public function testUnconfiguredDienstSchemaAnswersEmptyProducts(): void {
		$this->dienstConfigured = false;
		$this->productRows = [
			['id' => 'p-1', 'elementRef' => 'el-1', 'name' => 'Zaaksysteem'],
		];

		$result = $this->getView(['include_products' => 'true']);

		$this->assertSame(200, $result['status']);
		$this->assertSame([], $this->searchQueries);
		$nodes = $this->nodesById($result['data']);
		$this->assertSame([], $nodes['vn-1']['products']);
		$this->assertSame([], $nodes['vn-2']['products']);
	}//end testUnconfiguredDienstSchemaAnswersEmptyProducts()
}//end class
