<?php

/**
 * Golden tests for the ViewService view and node enrichment steps.
 *
 * Pins enrichViewNodes() (which enrichments land on which node, in which
 * order, and the owned-over-deelnames deduplication), the module expansion
 * of view nodes, the referentiecomponent extraction and the view transform,
 * so splitting them into named steps keeps every outcome.
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden tests for ViewService node enrichment, module expansion and transform.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ViewServiceNodeEnrichmentGoldenTest extends TestCase {

	/**
	 * Every query passed to searchObjects(), in order.
	 *
	 * @var array<int, array>
	 */
	private array $queries = [];

	/**
	 * Rows returned per schema id.
	 *
	 * @var array<string, array>
	 */
	private array $rowsBySchema = [];

	/**
	 * The service under test.
	 *
	 * @var ViewService
	 */
	private ViewService $service;

	/**
	 * Build the service with an active organisation and configured registers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturn(
			['register' => 'voorz', 'dienst_schema' => 'dienst', 'gebruik_schema' => 'gebruik']
		);
		$settingsService->method('getAmefConfig')->willReturn(
			['register_id' => 'amef', 'module_schema' => 'module']
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$organisation = new Organisation();
		$organisation->setUuid('org-own');
		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getActiveOrganisation')->willReturn($organisation);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query;
				$rows = $this->rowsBySchema[(string) $query['@self']['schema']] ?? [];
				if (isset($query['participants']) === true) {
					return $this->rowsBySchema['deelnames'] ?? [];
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

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$this->service = new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settingsService,
			userSession: $userSession,
			cacheFactory: $cacheFactory
		);
	}//end setUp()

	/**
	 * Call a private method of the service.
	 *
	 * @param string $method The private method name.
	 * @param array $args The arguments.
	 *
	 * @return mixed The method's result.
	 */
	private function call(string $method, array $args): mixed {
		$reflection = new ReflectionClass(objectOrClass: ViewService::class);
		$target = $reflection->getMethod(name: $method);
		$target->setAccessible(accessible: true);
		return $target->invokeArgs($this->service, $args);
	}//end call()

	/**
	 * Seed every enrichment source with a few rows.
	 *
	 * @return void
	 */
	private function seedRows(): void {
		$this->rowsBySchema = [
			'dienst' => [['elementRef' => 'ref-a', 'name' => 'dienst a']],
			'module' => [
				['elementRef' => 'ref-a', 'name' => 'module a', 'referentiecomponenten' => ['node-model-b']],
				['elementRef' => 'ref-m', 'name' => 'module m', 'components' => [['id' => 'node-model-b'], ['identifier' => 'none']]],
			],
			'gebruik' => [['elementRef' => 'ref-a', 'id' => 'g1'], ['moduleRef' => 'ref-b', 'id' => 'g2']],
			'deelnames' => [
				['elementRef' => 'ref-a', 'id' => 'd1', 'consumer' => ['name' => 'Other', 'id' => 'org-x']],
				['componentRef' => 'ref-c', 'id' => 'd2'],
			],
		];
	}//end seedRows()

	/**
	 * Replace the random module node ids so the result can be compared.
	 *
	 * @param array $nodes The view nodes.
	 *
	 * @return array The nodes with stable module view node ids.
	 */
	private function stableIds(array $nodes): array {
		foreach ($nodes as $index => $node) {
			if (($node['_isModuleExpansion'] ?? false) === true) {
				$this->assertStringStartsWith('module-' . $node['modelNodeId'] . '-', $node['viewNodeId']);
				$nodes[$index]['viewNodeId'] = 'module-' . $node['modelNodeId'];
			}
		}

		return $nodes;
	}//end stableIds()

	/**
	 * Every enrichment on: each node with a model id gets all four, in order,
	 * and deelnames already owned as gebruik are left out.
	 *
	 * @return void
	 */
	public function testEveryEnrichmentLandsOnNodesWithAModelId(): void {
		$this->seedRows();
		$options = [
			'include_products' => true,
			'include_modules' => true,
			'include_gebruik' => true,
			'include_deelnames_gebruik' => true,
		];
		$nodes = [
			['viewNodeId' => 'v1', 'modelNodeId' => 'ref-a'],
			['viewNodeId' => 'v2'],
			['viewNodeId' => 'v3', 'modelNodeId' => 'ref-c'],
		];

		$result = $this->call(method: 'enrichViewNodes', args: [$nodes, $options]);

		$expected = [
			[
				'viewNodeId' => 'v1',
				'modelNodeId' => 'ref-a',
				'products' => [
					[
						'elementRef' => 'ref-a',
						'name' => 'dienst a',
					],
				],
				'modules' => [
					[
						'elementRef' => 'ref-a',
						'name' => 'module a',
						'referentiecomponenten' => [
							'node-model-b',
						],
					],
				],
				'usage' => [
					[
						'elementRef' => 'ref-a',
						'id' => 'g1',
						'_type' => 'regular',
						'_processed_for_org' => 'org-own',
						'_linked_module' => [
							'elementRef' => 'ref-a',
							'name' => 'module a',
							'referentiecomponenten' => [
								'node-model-b',
							],
						],
					],
				],
				'deelnamesGebruik' => [],
			],
			[
				'viewNodeId' => 'v2',
			],
			[
				'viewNodeId' => 'v3',
				'modelNodeId' => 'ref-c',
				'products' => [],
				'modules' => [],
				'usage' => [],
				'deelnamesGebruik' => [
					[
						'componentRef' => 'ref-c',
						'id' => 'd2',
						'_type' => 'deelnames',
						'_processed_for_org' => 'org-own',
						'_sourceOrganizationId' => null,
						'_sourceOrganization' => null,
					],
				],
			],
		];
		$this->assertSame($expected, $result);
	}//end testEveryEnrichmentLandsOnNodesWithAModelId()

	/**
	 * Only the requested enrichments are loaded and attached; nothing is
	 * attached when none is requested.
	 *
	 * @return void
	 */
	public function testOnlyRequestedEnrichmentsAreLoadedAndAttached(): void {
		$this->seedRows();
		$someNodes = [['modelNodeId' => 'ref-a'], ['modelNodeId' => 'ref-b']];
		$someOptions = ['include_gebruik' => true, 'include_deelnames_gebruik' => true];

		$some = $this->call(method: 'enrichViewNodes', args: [$someNodes, $someOptions]);
		$none = $this->call(
			method: 'enrichViewNodes',
			args: [[['modelNodeId' => 'ref-a'], ['x' => 1]], ['include_products' => false]]
		);

		$expectedSome = [
			[
				'modelNodeId' => 'ref-a',
				'usage' => [
					[
						'elementRef' => 'ref-a',
						'id' => 'g1',
						'_type' => 'regular',
						'_processed_for_org' => 'org-own',
						'_linked_module' => [
							'elementRef' => 'ref-a',
							'name' => 'module a',
							'referentiecomponenten' => [
								'node-model-b',
							],
						],
					],
				],
				'deelnamesGebruik' => [],
			],
			[
				'modelNodeId' => 'ref-b',
				'usage' => [
					[
						'moduleRef' => 'ref-b',
						'id' => 'g2',
						'_type' => 'regular',
						'_processed_for_org' => 'org-own',
					],
				],
				'deelnamesGebruik' => [],
			],
		];
		$this->assertSame($expectedSome, $some);
		$this->assertSame([
			[
				'modelNodeId' => 'ref-a',
			],
			[
				'x' => 1,
			],
		], $none);
		$expectedQueries = [
			[
				'@self' => [
					'register' => 'voorz',
					'schema' => 'gebruik',
					'organisation' => 'org-own',
				],
				'_limit' => 500,
			],
			[
				'@self' => [
					'register' => 'amef',
					'schema' => 'module',
					'organisation' => 'org-own',
				],
				'_limit' => 1000,
			],
			[
				'@self' => [
					'register' => 'voorz',
					'schema' => 'gebruik',
				],
				'participants' => 'org-own',
				'_limit' => 500,
			],
			[
				'@self' => [
					'register' => 'amef',
					'schema' => 'module',
					'organisation' => 'org-own',
				],
				'_limit' => 1000,
			],
		];
		$this->assertSame($expectedQueries, $this->queries);
	}//end testOnlyRequestedEnrichmentsAreLoadedAndAttached()

	/**
	 * Modules expand into child nodes of the referentiecomponent nodes of a view;
	 * views without a node list are left alone and the keys are kept.
	 *
	 * @return void
	 */
	public function testModulesExpandIntoChildNodesOfTheirReferenceComponents(): void {
		$this->seedRows();
		$this->rowsBySchema['module'][0]['id'] = 'mod-a';
		$this->rowsBySchema['module'][1]['id'] = 'mod-m';
		$views = [
			'k1' => [
				'id' => 'view-1',
				'viewNodes' => [
					['viewNodeId' => 'p', 'modelNodeId' => 'node-model-b', 'x' => 10, 'y' => 20, 'width' => 30],
					['viewNodeId' => 'q'],
				],
			],
			'k2' => ['id' => 'view-2'],
			'k3' => ['id' => 'view-3', 'viewNodes' => 'not-a-list'],
			'k4' => ['viewNodes' => [['viewNodeId' => 'r', 'modelNodeId' => 'unrelated']]],
		];

		$expanded = $this->call(method: 'expandModulesToViewNodes', args: [$views]);
		$expanded['k1']['viewNodes'] = $this->stableIds(nodes: $expanded['k1']['viewNodes']);
		$expanded['k4']['viewNodes'] = $this->stableIds(nodes: $expanded['k4']['viewNodes']);

		$expected = [
			'k1' => [
				'id' => 'view-1',
				'viewNodes' => [
					[
						'viewNodeId' => 'p',
						'modelNodeId' => 'node-model-b',
						'x' => 10,
						'y' => 20,
						'width' => 30,
					],
					[
						'viewNodeId' => 'q',
					],
					[
						'modelNodeId' => 'mod-a',
						'viewNodeId' => 'module-mod-a',
						'x' => 60,
						'y' => 20,
						'width' => 150,
						'height' => 50,
						'parent' => 'p',
						'name' => 'module a',
						'type' => 'module',
						'color' => 'rgb(200, 255, 200)',
						'borderColor' => 'rgb(0, 150, 0)',
						'font' => [
							'name' => 'Segoe UI, Arial',
							'size' => 11,
							'style' => 'normal',
							'color' => 'rgb(0, 0, 0)',
						],
						'description' => null,
						'elementRef' => 'mod-a',
						'_isModuleExpansion' => true,
						'_parentReferentieComponent' => 'node-model-b',
						'_moduleData' => [
							'elementRef' => 'ref-a',
							'name' => 'module a',
							'referentiecomponenten' => [
								'node-model-b',
							],
							'id' => 'mod-a',
						],
					],
					[
						'modelNodeId' => 'mod-m',
						'viewNodeId' => 'module-mod-m',
						'x' => 60,
						'y' => 20,
						'width' => 150,
						'height' => 50,
						'parent' => 'p',
						'name' => 'module m',
						'type' => 'module',
						'color' => 'rgb(200, 255, 200)',
						'borderColor' => 'rgb(0, 150, 0)',
						'font' => [
							'name' => 'Segoe UI, Arial',
							'size' => 11,
							'style' => 'normal',
							'color' => 'rgb(0, 0, 0)',
						],
						'description' => null,
						'elementRef' => 'mod-m',
						'_isModuleExpansion' => true,
						'_parentReferentieComponent' => 'node-model-b',
						'_moduleData' => [
							'elementRef' => 'ref-m',
							'name' => 'module m',
							'components' => [
								[
									'id' => 'node-model-b',
								],
								[
									'identifier' => 'none',
								],
							],
							'id' => 'mod-m',
						],
					],
				],
			],
			'k2' => [
				'id' => 'view-2',
			],
			'k3' => [
				'id' => 'view-3',
				'viewNodes' => 'not-a-list',
			],
			'k4' => [
				'viewNodes' => [
					[
						'viewNodeId' => 'r',
						'modelNodeId' => 'unrelated',
					],
				],
			],
		];
		$this->assertSame($expected, $expanded);
	}//end testModulesExpandIntoChildNodesOfTheirReferenceComponents()

	/**
	 * Referentiecomponent ids are read from every known field and shape, in
	 * field order, de-duplicated with their first keys.
	 *
	 * @return void
	 */
	public function testReferenceComponentsAreReadFromEveryFieldAndShape(): void {
		$modules = [
			[],
			[
				'referentiecomponenten' => ['a', 'b', 'a'],
				'referenceComponents' => 'c',
				'relatedComponents' => [['id' => 'd'], ['identifier' => 'e'], ['id' => 'f', 'identifier' => 'g'], ['name' => 'x'], 5, null],
				'components' => 7,
				'relationships' => ['b', ['id' => null, 'identifier' => 'h']],
			],
			['components' => null, 'relationships' => [['id' => 0]]],
		];

		$refs = [];
		foreach ($modules as $module) {
			$refs[] = $this->call(method: 'extractReferenceComponenten', args: [$module]);
		}

		$expected = [
			[],
			[
				0 => 'a',
				1 => 'b',
				3 => 'c',
				4 => 'd',
				5 => 'e',
				6 => 'f',
				8 => 'h',
			],
			[
				0,
			],
		];
		$this->assertSame($expected, $refs);
	}//end testReferenceComponentsAreReadFromEveryFieldAndShape()

	/**
	 * A view's XML documentation, properties, nodes and relationships are
	 * transformed only when present in the expected shape.
	 *
	 * @return void
	 */
	public function testViewTransformReadsOnlyWellFormedXmlSections(): void {
		$views = [
			['id' => 'plain'],
			['xml' => 'string'],
			[
				'xml' => [
					'documentation' => 'doc',
					'properties' => [
						'property' => [
							['_propertyDefinitionRef' => 'p1', 'value' => ['_value' => 'v1']],
							['___propertyDefinitionRef' => 'p2', 'value' => ['_value' => null]],
						],
					],
					'viewNodes' => [['viewNodeId' => 'n', 'description' => 'd', 'x' => 1, 'color' => 'rgb(1, 2, 3)']],
					'viewRelationships' => [['viewRelationshipId' => 'r', 'label' => 'L']],
				],
			],
			[
				'xml' => ['documentation' => null, 'properties' => ['property' => 'x'], 'viewNodes' => 'y', 'viewRelationships' => null],
				'documentation' => 'keep',
			],
		];

		$transformed = [];
		foreach ($views as $view) {
			$transformed[] = $this->call(method: 'transformView', args: [$view]);
		}

		$expected = [
			[
				'id' => 'plain',
			],
			[
				'xml' => 'string',
			],
			[
				'xml' => [
					'documentation' => 'doc',
					'properties' => [
						'property' => [
							[
								'_propertyDefinitionRef' => 'p1',
								'value' => [
									'_value' => 'v1',
								],
							],
							[
								'___propertyDefinitionRef' => 'p2',
								'value' => [
									'_value' => null,
								],
							],
						],
					],
					'viewNodes' => [
						[
							'viewNodeId' => 'n',
							'description' => 'd',
							'x' => 1,
							'color' => 'rgb(1, 2, 3)',
						],
					],
					'viewRelationships' => [
						[
							'viewRelationshipId' => 'r',
							'label' => 'L',
						],
					],
				],
				'documentation' => 'doc',
				'properties' => [
					[
						'propertyDefinitionRef' => 'p1',
						'value' => 'v1',
					],
				],
				'viewNodes' => [
					[
						'viewNodeId' => 'n',
						'description' => 'd',
						'x' => 1,
						'color' => 'rgb(1, 2, 3)',
						'identifier' => 'n',
						'documentation' => 'd',
						'position' => [
							'x' => 1,
							'y' => null,
							'w' => null,
							'h' => null,
						],
						'style' => [
							'fillColor' => [
								'r' => 1,
								'g' => 2,
								'b' => 3,
								'a' => 100,
							],
							'lineColor' => null,
							'font' => null,
						],
					],
				],
				'viewRelationships' => [
					[
						'viewRelationshipId' => 'r',
						'label' => 'L',
						'identifier' => 'r',
						'properties' => [
							[
								'propertyDefinitionRef' => 'propid-62',
								'value' => 'L',
							],
						],
						'bendpoints' => [],
					],
				],
			],
			[
				'xml' => [
					'documentation' => null,
					'properties' => [
						'property' => 'x',
					],
					'viewNodes' => 'y',
					'viewRelationships' => null,
				],
				'documentation' => 'keep',
			],
		];
		$this->assertSame($expected, $transformed);
	}//end testViewTransformReadsOnlyWellFormedXmlSections()

}//end class
