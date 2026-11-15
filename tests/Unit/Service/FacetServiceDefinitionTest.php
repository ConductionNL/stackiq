<?php

/**
 * Unit tests for the GEMMA definitions on the referenceComponent facet.
 *
 * Each referenceComponent bucket carries the element's `documentation` and
 * `gemmaUrl`, trimmed, or '' when the element has none or is not resolved;
 * no other dimension's buckets change.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\DimensionResolution;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The definitions FacetService adds to the referenceComponent buckets.
 *
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
 */
#[CoversClass(FacetService::class)]
#[UsesClass(DimensionResolution::class)]
#[UsesClass(ViewQueryBuilder::class)]
class FacetServiceDefinitionTest extends TestCase {

	/**
	 * Every element lookup the service made.
	 *
	 * @var array<int, array>
	 */
	private array $elementQueries = [];

	/**
	 * A FacetService over these modules and elements.
	 *
	 * @param array<int, array> $modules  The module objects the base query returns.
	 * @param array<int, array> $elements The element objects the lookup returns.
	 *
	 * @return FacetService
	 */
	private function service(array $modules, array $elements): FacetService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturn(
			['results' => $modules, 'total' => count($modules), 'page' => 1, 'pages' => 1]
		);
		$objectService->method('searchObjects')->willReturn([]);

		$organisationService = new class {
			/**
			 * No active organisation.
			 *
			 * @return null
			 */
			public function getActiveOrganisation() {
				return null;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === ObjectService::class ? $objectService : $organisationService)
		);

		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getVoorzieningenConfig')->willReturn(['register' => '10', 'module_schema' => '20', 'dienst_schema' => '21']);

		$archiMate = $this->createMock(ArchiMateService::class);
		$archiMate->method('getElementObjects')->willReturnCallback(
			function (array $query) use ($elements): array {
				$this->elementQueries[] = $query;
				return $elements;
			}
		);
		$archiMate->method('getRelationshipObjects')->willReturn([]);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FacetService(
			container: $container,
			catalogue: $catalogue,
			archiMateService: $archiMate,
			queryBuilder: new ViewQueryBuilder(),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			cacheFactory: $cacheFactory
		);
	}//end service()

	/**
	 * Each referenceComponent bucket carries the trimmed documentation and
	 * GEMMA Online link of its element, from the one element lookup.
	 *
	 * @return void
	 */
	public function testEachReferenceComponentBucketCarriesItsDefinition(): void {
		$service = $this->service(
			modules: [
				['id' => 'm1', 'referenceComponents' => ['rc-zs', 'rc-kb'], 'standardVersions' => [['name' => 'StUF']]],
				['id' => 'm2', 'referenceComponents' => ['rc-zs'], 'standardVersions' => []],
			],
			elements: [
				['identifier' => 'rc-zs', 'name' => 'Zaaksysteem', 'documentation' => "  Ondersteunt het zaakgericht werken.\n", 'gemmaUrl' => 'https://www.gemmaonline.nl/zaaksysteem'],
				['identifier' => 'rc-kb', 'name' => 'Kennisbank', 'documentation' => ['not', 'a', 'string'], 'gemmaUrl' => null],
			]
		);

		$result = $service->getFacets(schema: 'module');

		$this->assertSame(
			[
				['value' => 'Zaaksysteem', 'label' => 'Zaaksysteem', 'count' => 2, 'documentation' => 'Ondersteunt het zaakgericht werken.', 'gemmaUrl' => 'https://www.gemmaonline.nl/zaaksysteem'],
				['value' => 'Kennisbank', 'label' => 'Kennisbank', 'count' => 1, 'documentation' => '', 'gemmaUrl' => ''],
			],
			$result['referenceComponent']
		);
		$this->assertCount(1, $this->elementQueries);
		$this->assertSame([['value' => 'StUF', 'label' => 'StUF', 'count' => 1]], $result['standard']);
	}//end testEachReferenceComponentBucketCarriesItsDefinition()

	/**
	 * A component whose element is not found keeps its identifier as value and
	 * gets an empty definition and link.
	 *
	 * @return void
	 */
	public function testAnUnresolvedComponentHasAnEmptyDefinition(): void {
		$service = $this->service(
			modules: [['id' => 'm1', 'referenceComponents' => ['rc-gone'], 'standardVersions' => []]],
			elements: []
		);

		$this->assertSame(
			[['value' => 'rc-gone', 'label' => 'rc-gone', 'count' => 1, 'documentation' => '', 'gemmaUrl' => '']],
			$service->getFacets(schema: 'module')['referenceComponent']
		);
	}//end testAnUnresolvedComponentHasAnEmptyDefinition()

	/**
	 * Two elements with the same name share one bucket; the first keeps its definition.
	 *
	 * @return void
	 */
	public function testTheFirstElementOfANameKeepsTheDefinition(): void {
		$service = $this->service(
			modules: [['id' => 'm1', 'referenceComponents' => ['rc-a', 'rc-b'], 'standardVersions' => []]],
			elements: [
				['identifier' => 'rc-a', 'name' => 'Portaal', 'documentation' => 'Eerste', 'gemmaUrl' => 'https://a'],
				['identifier' => 'rc-b', 'name' => 'Portaal', 'documentation' => 'Tweede', 'gemmaUrl' => 'https://b'],
			]
		);

		$this->assertSame(
			[['value' => 'Portaal', 'label' => 'Portaal', 'count' => 1, 'documentation' => 'Eerste', 'gemmaUrl' => 'https://a']],
			$service->getFacets(schema: 'module')['referenceComponent']
		);
	}//end testTheFirstElementOfANameKeepsTheDefinition()
}//end class
