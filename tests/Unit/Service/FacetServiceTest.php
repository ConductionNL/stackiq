<?php

/**
 * Unit tests for FacetService.
 *
 * Covers gemma-faceted-search tasks 1–6: direct-field aggregation, linked
 * `element` resolution (domein/applicatieservice), narrowing (facet-on-facet
 * AND/OR + disjunctive self-count), free-text search combination, RBAC/tenant
 * scoping parity, and distributed caching + invalidation-key isolation.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\FacetService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\ViewQueryBuilder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FacetService.
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-8
 */
class FacetServiceTest extends TestCase
{

    /**
     * Build a FacetService with the given (mocked) collaborators.
     *
     * @param ObjectService|null       $objectService    Mocked ObjectService, or null to omit from container.
     * @param SettingsService|null     $settingsService  Mocked SettingsService (defaults to a working voorzieningen config).
     * @param ArchiMateService|null    $archiMateService Mocked ArchiMateService (defaults to empty lookups).
     * @param ICache|null              $cache            Mocked ICache (defaults to always-miss/no-op).
     * @param string|null              $userId           Simulated current user id, or null for "not logged in".
     *
     * @return FacetService
     */
    private function makeService(
        ?ObjectService $objectService=null,
        ?SettingsService $settingsService=null,
        ?ArchiMateService $archiMateService=null,
        ?ICache $cache=null,
        ?string $userId='alice'
    ): FacetService {
        // OrganisationService lookup — mocked as a plain object exposing
        // `getActiveOrganisation(): null` (no active organisation by default),
        // since the real interface lives in OpenRegister and isn't a test dependency here.
        $organisationService = new class {
            public function getActiveOrganisation()
            {
                return null;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($objectService, $organisationService) {
                if ($id === ObjectService::class) {
                    return $objectService;
                }

                return $organisationService;
            }
        );

        if ($settingsService === null) {
            $settingsService = $this->createMock(SettingsService::class);
            $settingsService->method('getVoorzieningenConfig')->willReturn(
                [
                    'register'      => '10',
                    'module_schema' => '20',
                    'dienst_schema' => '21',
                ]
            );
        }

        if ($archiMateService === null) {
            $archiMateService = $this->createMock(ArchiMateService::class);
            $archiMateService->method('getElementObjects')->willReturn([]);
            $archiMateService->method('getRelationshipObjects')->willReturn([]);
        }

        if ($cache === null) {
            $cache = $this->createMock(ICache::class);
            $cache->method('get')->willReturn(null);
        }

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $userSession = $this->createMock(IUserSession::class);
        if ($userId !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($userId);
            $userSession->method('getUser')->willReturn($user);
        } else {
            $userSession->method('getUser')->willReturn(null);
        }

        return new FacetService(
            container: $container,
            settingsService: $settingsService,
            archiMateService: $archiMateService,
            queryBuilder: new ViewQueryBuilder(),
            userSession: $userSession,
            logger: $this->createMock(LoggerInterface::class),
            cacheFactory: $cacheFactory
        );

    }//end makeService()

    /**
     * Build an ObjectService mock whose `searchObjectsPaginated()` returns the
     * given results (single page) and records every captured query.
     *
     * @param array $results     Objects to return.
     * @param array $capturedRef Reference array; every captured query is appended.
     *
     * @return ObjectService
     */
    private function makePaginatedObjectService(array $results, array &$capturedRef): ObjectService
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturnCallback(
            function (array $query) use ($results, &$capturedRef): array {
                $capturedRef[] = $query;
                return [
                    'results' => $results,
                    'total'   => count($results),
                    'page'    => 1,
                    'pages'   => 1,
                ];
            }
        );
        $objectService->method('searchObjects')->willReturn([]);

        return $objectService;

    }//end makePaginatedObjectService()

    /**
     * Unsupported schema throws InvalidArgumentException naming the supported set.
     *
     * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
     *
     * @return void
     */
    public function testGetFacetsThrowsForUnsupportedSchema(): void
    {
        $service = $this->makeService(objectService: $this->createMock(ObjectService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/module.*dienst|dienst.*module/');
        $service->getFacets(schema: 'contract');

    }//end testGetFacetsThrowsForUnsupportedSchema()

    /**
     * ObjectService unavailable throws RuntimeException (mapped to 503 by the controller).
     *
     * @return void
     */
    public function testGetFacetsThrowsWhenObjectServiceUnavailable(): void
    {
        $service = $this->makeService(objectService: null);

        $this->expectException(\RuntimeException::class);
        $service->getFacets(schema: 'module');

    }//end testGetFacetsThrowsWhenObjectServiceUnavailable()

    /**
     * All four dimensions are always present, even when empty — never omitted.
     *
     * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
     *
     * @return void
     */
    public function testGetFacetsReturnsAllFourDimensionsEvenWhenEmpty(): void
    {
        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
        $service       = $this->makeService(objectService: $objectService);

        $result = $service->getFacets(schema: 'module');

        foreach (['referentiecomponent', 'standaard', 'applicatieservice', 'domein'] as $dimension) {
            $this->assertArrayHasKey($dimension, $result);
            $this->assertSame([], $result[$dimension]);
        }

        $this->assertSame(0, $result['_meta']['totalMatched']);
        $this->assertFalse($result['_meta']['cached']);

    }//end testGetFacetsReturnsAllFourDimensionsEvenWhenEmpty()

    /**
     * Direct module fields (referentieComponenten / standaardVersies) aggregate
     * into referentiecomponent/standaard counts.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-1
     *
     * @return void
     */
    public function testGetFacetsAggregatesDirectModuleFields(): void
    {
        $modules = [
            [
                'id'                    => 'm1',
                'referentieComponenten' => [['identifier' => 'rc-1']],
                'standaardVersies'      => [['name' => 'StUF-ZKN']],
            ],
            [
                'id'                    => 'm2',
                'referentieComponenten' => [['identifier' => 'rc-1']],
                'standaardVersies'      => [['name' => 'StUF-ZKN']],
            ],
            [
                'id'                    => 'm3',
                'referentieComponenten' => ['rc-2'],
                'standaardVersies'      => [],
            ],
        ];

        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

        $archiMateService = $this->createMock(ArchiMateService::class);
        $archiMateService->method('getElementObjects')->willReturn(
            [
                ['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent', 'domein' => 'Bedrijfsvoering'],
                ['identifier' => 'rc-2', 'name' => 'Klantcontactcomponent', 'domein' => 'Dienstverlening'],
            ]
        );
        $archiMateService->method('getRelationshipObjects')->willReturn([]);

        $service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

        $result = $service->getFacets(schema: 'module');

        $refCompByValue = array_column($result['referentiecomponent'], 'count', 'value');
        $this->assertSame(2, $refCompByValue['Zaakregistratiecomponent']);
        $this->assertSame(1, $refCompByValue['Klantcontactcomponent']);

        $standaardByValue = array_column($result['standaard'], 'count', 'value');
        $this->assertSame(2, $standaardByValue['StUF-ZKN']);

        $domeinByValue = array_column($result['domein'], 'count', 'value');
        $this->assertSame(2, $domeinByValue['Bedrijfsvoering']);
        $this->assertSame(1, $domeinByValue['Dienstverlening']);

        $this->assertSame(3, $result['_meta']['totalMatched']);

    }//end testGetFacetsAggregatesDirectModuleFields()

    /**
     * `applicatieservice` is resolved via a `relation` connecting a referentiecomponent
     * element to an element with gemmaType === 'Applicatieservice'.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-2
     *
     * @return void
     */
    public function testGetFacetsResolvesApplicatieserviceViaRelationship(): void
    {
        $modules = [
            ['id' => 'm1', 'referentieComponenten' => [['identifier' => 'rc-1']], 'standaardVersies' => []],
        ];

        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

        $archiMateService = $this->createMock(ArchiMateService::class);
        $archiMateService->method('getElementObjects')->willReturnCallback(
            function (array $query) {
                $ids = $query['identifier'] ?? [];
                $all = [
                    'rc-1' => ['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent', 'domein' => 'Bedrijfsvoering'],
                    'as-1' => ['identifier' => 'as-1', 'name' => 'Zaakservice', 'gemmaType' => 'Applicatieservice'],
                ];
                return array_values(array_intersect_key($all, array_flip($ids)));
            }
        );
        $archiMateService->method('getRelationshipObjects')->willReturn(
            [
                ['source' => 'as-1', 'target' => 'rc-1', 'type' => 'Serving'],
            ]
        );

        $service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

        $result = $service->getFacets(schema: 'module');

        $applicatieserviceByValue = array_column($result['applicatieservice'], 'count', 'value');
        $this->assertArrayHasKey('Zaakservice', $applicatieserviceByValue);
        $this->assertSame(1, $applicatieserviceByValue['Zaakservice']);

    }//end testGetFacetsResolvesApplicatieserviceViaRelationship()

    /**
     * Selecting one facet value narrows counts for OTHER dimensions, but a
     * dimension's own count is NOT narrowed by its own selection
     * (disjunctive faceting).
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-3
     *
     * @return void
     */
    public function testGetFacetsNarrowingIsDisjunctive(): void
    {
        $modules = [
            ['id' => 'm1', 'referentieComponenten' => [['identifier' => 'rc-1']], 'standaardVersies' => [['name' => 'StUF-ZKN']]],
            ['id' => 'm2', 'referentieComponenten' => [['identifier' => 'rc-1']], 'standaardVersies' => []],
            ['id' => 'm3', 'referentieComponenten' => [['identifier' => 'rc-2']], 'standaardVersies' => [['name' => 'StUF-ZKN']]],
        ];

        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

        $archiMateService = $this->createMock(ArchiMateService::class);
        $archiMateService->method('getElementObjects')->willReturn(
            [
                ['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent'],
                ['identifier' => 'rc-2', 'name' => 'Klantcontactcomponent'],
            ]
        );
        $archiMateService->method('getRelationshipObjects')->willReturn([]);

        $service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

        $result = $service->getFacets(
            schema: 'module',
            filters: ['referentiecomponent' => ['Zaakregistratiecomponent']]
        );

        // `standaard` is narrowed to the 2 modules carrying "Zaakregistratiecomponent" —
        // only m1 of those also carries "StUF-ZKN".
        $standaardByValue = array_column($result['standaard'], 'count', 'value');
        $this->assertSame(1, $standaardByValue['StUF-ZKN']);

        // `referentiecomponent`'s OWN count is NOT narrowed by its own selection —
        // it still reflects the full 2-object set carrying "Zaakregistratiecomponent".
        $refCompByValue = array_column($result['referentiecomponent'], 'count', 'value');
        $this->assertSame(2, $refCompByValue['Zaakregistratiecomponent']);

        $this->assertSame(2, $result['_meta']['totalMatched']);

    }//end testGetFacetsNarrowingIsDisjunctive()

    /**
     * Multiple values within one dimension combine with OR; across dimensions with AND.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-3
     *
     * @return void
     */
    public function testGetFacetsOrWithinDimensionAndAcrossDimensions(): void
    {
        $modules = [
            ['id' => 'm1', 'referentieComponenten' => [['identifier' => 'rc-1']], 'standaardVersies' => [['name' => 'StUF-ZKN']]],
            ['id' => 'm2', 'referentieComponenten' => [['identifier' => 'rc-2']], 'standaardVersies' => []],
            ['id' => 'm3', 'referentieComponenten' => [['identifier' => 'rc-3']], 'standaardVersies' => []],
        ];

        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: $modules, capturedRef: $captured);

        $archiMateService = $this->createMock(ArchiMateService::class);
        $archiMateService->method('getElementObjects')->willReturn(
            [
                ['identifier' => 'rc-1', 'name' => 'A'],
                ['identifier' => 'rc-2', 'name' => 'B'],
                ['identifier' => 'rc-3', 'name' => 'C'],
            ]
        );
        $archiMateService->method('getRelationshipObjects')->willReturn([]);

        $service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

        // OR within a dimension: A or B -> m1 + m2.
        $orResult = $service->getFacets(schema: 'module', filters: ['referentiecomponent' => ['A', 'B']]);
        $this->assertSame(2, $orResult['_meta']['totalMatched']);

        // AND across dimensions: A (referentiecomponent) AND StUF-ZKN (standaard) -> only m1.
        $andResult = $service->getFacets(
            schema: 'module',
            filters: [
                'referentiecomponent' => ['A'],
                'standaard'           => ['StUF-ZKN'],
            ]
        );
        $this->assertSame(1, $andResult['_meta']['totalMatched']);

    }//end testGetFacetsOrWithinDimensionAndAcrossDimensions()

    /**
     * A `search` query parameter is forwarded as `_search` on the base
     * object query — combining free-text search with facet aggregation.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-4
     *
     * @return void
     */
    public function testGetFacetsForwardsSearchAsUnderscoreSearchParam(): void
    {
        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
        $service       = $this->makeService(objectService: $objectService);

        $service->getFacets(schema: 'module', search: 'zaak');

        $this->assertNotEmpty($captured);
        $this->assertSame('zaak', $captured[0]['_search'] ?? null);

    }//end testGetFacetsForwardsSearchAsUnderscoreSearchParam()

    /**
     * No search parameter means no `_search` key is added — facets cover the
     * full RBAC-scoped set.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-4
     *
     * @return void
     */
    public function testGetFacetsOmitsSearchParamWhenNotProvided(): void
    {
        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
        $service       = $this->makeService(objectService: $objectService);

        $service->getFacets(schema: 'module');

        $this->assertArrayNotHasKey('_search', $captured[0]);

    }//end testGetFacetsOmitsSearchParamWhenNotProvided()

    /**
     * Every base-object query carries an explicit `_limit` — the
     * bound-unbounded-searchobjects-scans pattern.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-1
     * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded
     *
     * @return void
     */
    public function testGetFacetsQueriesCarryExplicitLimit(): void
    {
        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
        $service       = $this->makeService(objectService: $objectService);

        $service->getFacets(schema: 'module');

        $this->assertNotEmpty($captured);
        foreach ($captured as $query) {
            $this->assertArrayHasKey('_limit', $query);
            $this->assertGreaterThan(0, $query['_limit']);
        }

    }//end testGetFacetsQueriesCarryExplicitLimit()

    /**
     * An explicit `organization` override is applied as `@self.organisation`
     * on the base object query — the same RBAC/tenant-scoping convention
     * `ViewService` already uses for its own module/gebruik queries.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-5
     * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
     *
     * @return void
     */
    public function testGetFacetsAppliesOrganisationScoping(): void
    {
        $captured      = [];
        $objectService = $this->makePaginatedObjectService(results: [], capturedRef: $captured);
        $service       = $this->makeService(objectService: $objectService);

        $service->getFacets(schema: 'module', organization: 'org-uuid-123');

        $this->assertSame('org-uuid-123', $captured[0]['@self']['organisation'] ?? null);

    }//end testGetFacetsAppliesOrganisationScoping()

    /**
     * Two different users produce different cache keys — no cross-user/tenant
     * cache bleed.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-6
     * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
     *
     * @return void
     */
    public function testCacheKeyDiffersPerUser(): void
    {
        $serviceAlice = $this->makeService(objectService: $this->createMock(ObjectService::class), userId: 'alice');
        $serviceBob   = $this->makeService(objectService: $this->createMock(ObjectService::class), userId: 'bob');

        $reflectionAlice = new \ReflectionMethod($serviceAlice, 'buildCacheKey');
        $reflectionAlice->setAccessible(true);
        $keyAlice = $reflectionAlice->invoke($serviceAlice, 'module', [], null, null);

        $reflectionBob = new \ReflectionMethod($serviceBob, 'buildCacheKey');
        $reflectionBob->setAccessible(true);
        $keyBob = $reflectionBob->invoke($serviceBob, 'module', [], null, null);

        $this->assertNotSame($keyAlice, $keyBob);

    }//end testCacheKeyDiffersPerUser()

    /**
     * A cache hit is returned with `_meta.cached: true` and skips recomputation
     * (the mocked ObjectService is never invoked).
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-6
     * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached
     *
     * @return void
     */
    public function testGetFacetsServesFromCacheOnHit(): void
    {
        $cachedPayload = [
            'referentiecomponent' => [],
            'standaard'           => [],
            'applicatieservice'   => [],
            'domein'              => [],
            '_meta'               => ['totalMatched' => 0, 'processingTimeMs' => 5.0, 'cached' => false],
        ];

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn($cachedPayload);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $service = $this->makeService(objectService: $objectService, cache: $cache);

        $result = $service->getFacets(schema: 'module');

        $this->assertTrue($result['_meta']['cached']);

    }//end testGetFacetsServesFromCacheOnHit()

    /**
     * A dienst's facet values resolve transitively via its linked modules.
     *
     * @spec openspec/changes/gemma-faceted-search/tasks.md#task-1
     *
     * @return void
     */
    public function testGetFacetsResolvesDienstFacetsTransitivelyViaModules(): void
    {
        $objectService = $this->createMock(ObjectService::class);

        $capturedPaginated = [];
        $objectService->method('searchObjectsPaginated')->willReturnCallback(
            function (array $query) use (&$capturedPaginated): array {
                $capturedPaginated[] = $query;
                return [
                    'results' => [
                        ['id' => 'd1', 'modules' => [['id' => 'm1']]],
                    ],
                    'total' => 1,
                    'page'  => 1,
                    'pages' => 1,
                ];
            }
        );
        $objectService->method('searchObjects')->willReturn(
            [
                ['id' => 'm1', 'referentieComponenten' => [['identifier' => 'rc-1']], 'standaardVersies' => []],
            ]
        );

        $archiMateService = $this->createMock(ArchiMateService::class);
        $archiMateService->method('getElementObjects')->willReturn(
            [['identifier' => 'rc-1', 'name' => 'Zaakregistratiecomponent']]
        );
        $archiMateService->method('getRelationshipObjects')->willReturn([]);

        $service = $this->makeService(objectService: $objectService, archiMateService: $archiMateService);

        $result = $service->getFacets(schema: 'dienst');

        $refCompByValue = array_column($result['referentiecomponent'], 'count', 'value');
        $this->assertSame(1, $refCompByValue['Zaakregistratiecomponent']);

    }//end testGetFacetsResolvesDienstFacetsTransitivelyViaModules()
}//end class
