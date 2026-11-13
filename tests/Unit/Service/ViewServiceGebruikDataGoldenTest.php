<?php

/**
 * Golden tests for the ViewService owned and deelnames gebruik loaders.
 *
 * Pins the queries (organisation scoped for owned gebruik; deelnemers with
 * RBAC and multitenancy off for deelnames), the grouped result with its
 * module links, every log call and the fail-closed paths of getGebruikData()
 * and getDeelnamesGebruikData(), so splitting them keeps every outcome.
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
 * Golden tests for ViewService::getGebruikData() and getDeelnamesGebruikData().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ViewServiceGebruikDataGoldenTest extends TestCase {

	/**
	 * The active organisation uuid, or null for none.
	 *
	 * @var string|null
	 */
	private ?string $activeOrganisation = 'org-own';

	/**
	 * Every search as [query, rbac, multitenancy].
	 *
	 * @var array<int, array>
	 */
	private array $searches = [];

	/**
	 * Rows per kind ('owned', 'deelnames', 'module'); a Throwable is thrown.
	 *
	 * @var array<string, mixed>
	 */
	private array $rows = [];

	/**
	 * The voorzieningen config.
	 *
	 * @var array
	 */
	private array $voorzieningenConfig = ['register' => 'voorz', 'gebruik_schema' => 'gebruik'];

	/**
	 * Every logger call as [level, message, context].
	 *
	 * @var array<int, array>
	 */
	private array $logs = [];

	/**
	 * Call a private loader on a fresh service.
	 *
	 * @param string $method The loader.
	 *
	 * @return array The loader's result.
	 */
	private function load(string $method): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getVoorzieningenConfig')->willReturnCallback(fn (): array => $this->voorzieningenConfig);
		$settingsService->method('getAmefConfig')->willReturn(['register_id' => 'amef', 'module_schema' => 'module']);

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
			function (array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = [$query, $_rbac, $_multitenancy];
				$kind = 'owned';
				if (isset($query['participants']) === true) {
					$kind = 'deelnames';
				}

				if ($query['@self']['schema'] === 'module') {
					$kind = 'module';
				}

				$rows = $this->rows[$kind] ?? [];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === ObjectServiceInterface::class ? $objectService : $organisationService
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$service = new ViewService(
			config: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			logger: $logger,
			settingsService: $settingsService,
			userSession: $userSession,
			cacheFactory: $cacheFactory
		);

		$target = (new ReflectionClass(objectOrClass: ViewService::class))->getMethod(name: $method);
		$target->setAccessible(accessible: true);
		return $target->invoke($service);
	}//end load()

	/**
	 * Seed owned, deelnames and module rows.
	 *
	 * @return void
	 */
	private function seed(): void {
		$this->rows = [
			'owned' => [
				['elementRef' => 'ref-a', 'id' => 'g1'],
				['moduleRef' => 'ref-b', 'id' => 'g2'],
				['name' => 'unreferenced'],
			],
			'deelnames' => [
				['componentRef' => 'ref-a', 'id' => 'd1', 'consumer' => ['name' => 'Other', '@self' => ['id' => 'org-x']]],
			],
			'module' => [['elementRef' => 'ref-a', 'id' => 'mod-a', 'name' => 'Module A']],
		];
	}//end seed()

	/**
	 * Owned gebruik: the scoped query, the grouped result, the logs.
	 *
	 * @return void
	 */
	public function testOwnedGebruik(): void {
		$this->seed();

		$result = $this->load(method: 'getGebruikData');

		$expected = [
			[
				'ref-a' => [
					[
						'elementRef' => 'ref-a',
						'id' => 'g1',
						'_type' => 'regular',
						'_processed_for_org' => 'org-own',
						'_linked_module' => [
							'elementRef' => 'ref-a',
							'id' => 'mod-a',
							'name' => 'Module A',
						],
					],
				],
				'ref-b' => [
					[
						'moduleRef' => 'ref-b',
						'id' => 'g2',
						'_type' => 'regular',
						'_processed_for_org' => 'org-own',
					],
				],
			],
			[
				[
					[
						'@self' => [
							'register' => 'voorz',
							'schema' => 'gebruik',
							'organisation' => 'org-own',
						],
						'_limit' => 500,
					],
					true,
					true,
				],
				[
					[
						'@self' => [
							'register' => 'amef',
							'schema' => 'module',
							'organisation' => 'org-own',
						],
						'_limit' => 1000,
					],
					true,
					true,
				],
			],
			[
				[
					'debug',
					'Getting regular gebruik data for view enrichment',
					[],
				],
				[
					'debug',
					'Retrieved regular gebruik from voorzieningen register',
					[
						'schema_id' => 'gebruik',
						'gebruik_count' => 3,
						'organisation' => 'org-own',
					],
				],
				[
					'debug',
					'Extending gebruik data with modules information',
					[],
				],
				[
					'debug',
					'Getting modules data for enrichment',
					[],
				],
				[
					'debug',
					'Retrieved modules from schema',
					[
						'schema_id' => 'module',
						'modules_count' => 1,
					],
				],
				[
					'debug',
					'Total modules retrieved for enrichment',
					[
						'total_modules' => 1,
					],
				],
				[
					'debug',
					'Linked module to gebruik',
					[
						'element_ref' => 'ref-a',
						'gebruik_id' => 'g1',
						'module_id' => 'mod-a',
						'module_name' => 'Module A',
					],
				],
				[
					'debug',
					'Total regular gebruik retrieved',
					[
						'total_element_refs' => 2,
						'current_org' => 'org-own',
					],
				],
			],
		];
		$this->assertSame($expected, [$result, $this->searches, $this->logs]);
	}//end testOwnedGebruik()

	/**
	 * Deelnames gebruik: the unscoped deelnemers query, the source organisation, the logs.
	 *
	 * @return void
	 */
	public function testDeelnamesGebruik(): void {
		$this->seed();

		$result = $this->load(method: 'getDeelnamesGebruikData');

		$expected = [
			[
				'ref-a' => [
					[
						'componentRef' => 'ref-a',
						'id' => 'd1',
						'consumer' => [
							'name' => 'Other',
							'@self' => [
								'id' => 'org-x',
							],
						],
						'_type' => 'deelnames',
						'_processed_for_org' => 'org-own',
						'_sourceOrganizationId' => 'org-x',
						'_sourceOrganization' => 'Other',
						'_linked_module' => [
							'elementRef' => 'ref-a',
							'id' => 'mod-a',
							'name' => 'Module A',
						],
					],
				],
			],
			[
				[
					[
						'@self' => [
							'register' => 'voorz',
							'schema' => 'gebruik',
						],
						'participants' => 'org-own',
						'_limit' => 500,
					],
					false,
					false,
				],
				[
					[
						'@self' => [
							'register' => 'amef',
							'schema' => 'module',
							'organisation' => 'org-own',
						],
						'_limit' => 1000,
					],
					true,
					true,
				],
			],
			[
				[
					'debug',
					'Getting deelnames gebruik data for view enrichment',
					[],
				],
				[
					'debug',
					'Retrieved deelnames gebruik data',
					[
						'schema_id' => 'gebruik',
						'deelnames_count' => 1,
						'organisation_in_deelnemers' => 'org-own',
					],
				],
				[
					'debug',
					'Extending gebruik data with modules information',
					[],
				],
				[
					'debug',
					'Getting modules data for enrichment',
					[],
				],
				[
					'debug',
					'Retrieved modules from schema',
					[
						'schema_id' => 'module',
						'modules_count' => 1,
					],
				],
				[
					'debug',
					'Total modules retrieved for enrichment',
					[
						'total_modules' => 1,
					],
				],
				[
					'debug',
					'Linked module to gebruik',
					[
						'element_ref' => 'ref-a',
						'gebruik_id' => 'd1',
						'module_id' => 'mod-a',
						'module_name' => 'Module A',
					],
				],
				[
					'debug',
					'Total deelnames gebruik retrieved',
					[
						'total_element_refs' => 1,
					],
				],
			],
		];
		$this->assertSame($expected, [$result, $this->searches, $this->logs]);
	}//end testDeelnamesGebruik()

	/**
	 * Every fail-closed path of both loaders.
	 *
	 * @return void
	 */
	public function testFailClosedPaths(): void {
		$out = [];
		$cases = [
			'owned-no-org' => ['getGebruikData', fn () => $this->activeOrganisation = null],
			'deelnames-no-org' => ['getDeelnamesGebruikData', fn () => $this->activeOrganisation = null],
			'owned-no-schema' => ['getGebruikData', fn () => $this->voorzieningenConfig = ['register' => 'voorz']],
			'deelnames-no-register' => ['getDeelnamesGebruikData', fn () => $this->voorzieningenConfig = ['gebruik_schema' => 'gebruik']],
			'owned-search-fails' => ['getGebruikData', fn () => $this->rows['owned'] = new \RuntimeException('owned down')],
			'deelnames-search-fails' => ['getDeelnamesGebruikData', fn () => $this->rows['deelnames'] = new \RuntimeException('deelnames down')],
		];
		foreach ($cases as $name => [$method, $arrange]) {
			$this->seed();
			$this->activeOrganisation = 'org-own';
			$this->voorzieningenConfig = ['register' => 'voorz', 'gebruik_schema' => 'gebruik'];
			$this->searches = [];
			$this->logs = [];
			$arrange();
			$out[$name] = [$this->load(method: $method), $this->searches, $this->logs];
		}

		$expected = [
			'owned-no-org' => [
				[
					'ref-a' => [
						[
							'elementRef' => 'ref-a',
							'id' => 'g1',
							'_type' => 'regular',
							'_processed_for_org' => '',
							'_linked_module' => [
								'elementRef' => 'ref-a',
								'id' => 'mod-a',
								'name' => 'Module A',
							],
						],
					],
					'ref-b' => [
						[
							'moduleRef' => 'ref-b',
							'id' => 'g2',
							'_type' => 'regular',
							'_processed_for_org' => '',
						],
					],
				],
				[
					[
						[
							'@self' => [
								'register' => 'voorz',
								'schema' => 'gebruik',
							],
							'_limit' => 500,
						],
						true,
						true,
					],
					[
						[
							'@self' => [
								'register' => 'amef',
								'schema' => 'module',
							],
							'_limit' => 1000,
						],
						true,
						true,
					],
				],
				[
					[
						'debug',
						'Getting regular gebruik data for view enrichment',
						[],
					],
					[
						'debug',
						'Retrieved regular gebruik from voorzieningen register',
						[
							'schema_id' => 'gebruik',
							'gebruik_count' => 3,
							'organisation' => null,
						],
					],
					[
						'debug',
						'Extending gebruik data with modules information',
						[],
					],
					[
						'debug',
						'Getting modules data for enrichment',
						[],
					],
					[
						'debug',
						'Retrieved modules from schema',
						[
							'schema_id' => 'module',
							'modules_count' => 1,
						],
					],
					[
						'debug',
						'Total modules retrieved for enrichment',
						[
							'total_modules' => 1,
						],
					],
					[
						'debug',
						'Linked module to gebruik',
						[
							'element_ref' => 'ref-a',
							'gebruik_id' => 'g1',
							'module_id' => 'mod-a',
							'module_name' => 'Module A',
						],
					],
					[
						'debug',
						'Total regular gebruik retrieved',
						[
							'total_element_refs' => 2,
							'current_org' => null,
						],
					],
				],
			],
			'deelnames-no-org' => [
				[],
				[],
				[
					[
						'debug',
						'Getting deelnames gebruik data for view enrichment',
						[],
					],
				],
			],
			'owned-no-schema' => [
				[],
				[],
				[
					[
						'debug',
						'Getting regular gebruik data for view enrichment',
						[],
					],
					[
						'warning',
						'Voorzieningen register or gebruik schema not configured',
						[],
					],
				],
			],
			'deelnames-no-register' => [
				[],
				[],
				[
					[
						'debug',
						'Getting deelnames gebruik data for view enrichment',
						[],
					],
					[
						'warning',
						'Voorzieningen register or gebruik schema not configured for deelnames',
						[],
					],
				],
			],
			'owned-search-fails' => [
				[],
				[
					[
						[
							'@self' => [
								'register' => 'voorz',
								'schema' => 'gebruik',
								'organisation' => 'org-own',
							],
							'_limit' => 500,
						],
						true,
						true,
					],
					[
						[
							'@self' => [
								'register' => 'amef',
								'schema' => 'module',
								'organisation' => 'org-own',
							],
							'_limit' => 1000,
						],
						true,
						true,
					],
				],
				[
					[
						'debug',
						'Getting regular gebruik data for view enrichment',
						[],
					],
					[
						'warning',
						'Failed to get regular gebruik',
						[
							'schema_id' => 'gebruik',
							'error' => 'owned down',
						],
					],
					[
						'debug',
						'Extending gebruik data with modules information',
						[],
					],
					[
						'debug',
						'Getting modules data for enrichment',
						[],
					],
					[
						'debug',
						'Retrieved modules from schema',
						[
							'schema_id' => 'module',
							'modules_count' => 1,
						],
					],
					[
						'debug',
						'Total modules retrieved for enrichment',
						[
							'total_modules' => 1,
						],
					],
					[
						'debug',
						'Total regular gebruik retrieved',
						[
							'total_element_refs' => 0,
							'current_org' => 'org-own',
						],
					],
				],
			],
			'deelnames-search-fails' => [
				[],
				[
					[
						[
							'@self' => [
								'register' => 'voorz',
								'schema' => 'gebruik',
							],
							'participants' => 'org-own',
							'_limit' => 500,
						],
						false,
						false,
					],
					[
						[
							'@self' => [
								'register' => 'amef',
								'schema' => 'module',
								'organisation' => 'org-own',
							],
							'_limit' => 1000,
						],
						true,
						true,
					],
				],
				[
					[
						'debug',
						'Getting deelnames gebruik data for view enrichment',
						[],
					],
					[
						'warning',
						'Failed to get deelnames gebruik data; view will render without deelnames',
						[
							'schema_id' => 'gebruik',
							'error' => 'deelnames down',
						],
					],
					[
						'debug',
						'Extending gebruik data with modules information',
						[],
					],
					[
						'debug',
						'Getting modules data for enrichment',
						[],
					],
					[
						'debug',
						'Retrieved modules from schema',
						[
							'schema_id' => 'module',
							'modules_count' => 1,
						],
					],
					[
						'debug',
						'Total modules retrieved for enrichment',
						[
							'total_modules' => 1,
						],
					],
					[
						'debug',
						'Total deelnames gebruik retrieved',
						[
							'total_element_refs' => 0,
						],
					],
				],
			],
		];
		$this->assertSame($expected, $out);
	}//end testFailClosedPaths()

}//end class
