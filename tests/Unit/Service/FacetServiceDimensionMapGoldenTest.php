<?php

/**
 * Golden test of the per-object GEMMA dimension values of the facet service.
 *
 * Pins buildDimensionValueMap for objects whose modules carry repeated,
 * unresolved and nameless referenceComponents, padded and empty domains,
 * standards with empty names, and application services linked by relation.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test of the facet dimension map.
 */
class FacetServiceDimensionMapGoldenTest extends TestCase {
	/**
	 * The dimension map of the given modules per object.
	 *
	 * @param array $modulesByObjectId The modules per object id.
	 * @param array $relations         The ArchiMate relations.
	 *
	 * @return array
	 */
	private function map(array $modulesByObjectId, array $relations): array {
		$elements = [
			'rc-1' => ['identifier' => 'rc-1', 'name' => '  Zaakregistratie  ', 'domein' => '  Bedrijfsvoering '],
			'rc-2' => ['identifier' => 'rc-2', 'name' => '', 'domein' => ''],
			'rc-3' => ['identifier' => 'rc-3', 'name' => 'Klantcontact', 'domein' => 'Dienstverlening'],
			'as-1' => ['identifier' => 'as-1', 'name' => 'Zaakservice', 'gemmaType' => 'Applicatieservice'],
			'as-2' => ['identifier' => 'as-2', 'name' => 'Klantservice', 'gemmaType' => 'Applicatieservice'],
		];
		$archiMate = $this->createMock(ArchiMateService::class);
		$archiMate->method('getElementObjects')->willReturnCallback(
			static fn (array $query): array => array_values(array_intersect_key($elements, array_flip($query['identifier'] ?? [])))
		);
		$archiMate->method('getRelationshipObjects')->willReturn($relations);
		$cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$service = new FacetService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(CatalogueLocator::class),
			$archiMate,
			new ViewQueryBuilder(),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$cacheFactory
		);

		$method = new \ReflectionMethod(FacetService::class, 'buildDimensionValueMap');
		$method->setAccessible(true);
		return $method->invoke($service, $modulesByObjectId);
	}//end map()

	/**
	 * The dimension map matches the golden.
	 *
	 * @return void
	 */
	public function testDimensionMapMatchesTheGolden(): void {
		$modules = [
			'o1' => [
				['referenceComponents' => [['identifier' => 'rc-1'], 'rc-2'], 'standardVersions' => [['name' => 'StUF'], ['name' => '']]],
				['referenceComponents' => ['rc-1', 'rc-9'], 'standardVersions' => [['name' => 'StUF'], ['name' => 'ZGW']]],
			],
			'o2' => [
				['referenceComponents' => ['rc-3'], 'standardVersions' => []],
			],
			'o3' => [],
			7 => [['referenceComponents' => [], 'standardVersions' => [['name' => 'NEN']]]],
		];
		$relations = [
			['source' => 'as-1', 'target' => 'rc-1', 'type' => 'Serving'],
			['source' => 'rc-3', 'target' => 'as-2', 'type' => 'Serving'],
			['source' => 'as-2', 'target' => 'rc-1', 'type' => 'Serving'],
		];
		$out = [
			'with relations' => $this->map(modulesByObjectId: $modules, relations: $relations),
			'without relations' => $this->map(modulesByObjectId: $modules, relations: []),
			'no objects' => $this->map(modulesByObjectId: [], relations: $relations),
		];

		$path = dirname(__DIR__, 2) . '/fixtures/facets/dimension-map.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testDimensionMapMatchesTheGolden()
}//end class
