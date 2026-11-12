<?php

/**
 * Golden test of the base object set the facet service counts over.
 *
 * Pins, through getFacets, the paged queries sent to OpenRegister (register,
 * schema, organisation scope, search, page and limit), where paging stops,
 * the warnings for a missing configuration and for the paging ceiling, and
 * the number of objects matched.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test of the facet base object set.
 */
class FacetServiceBaseObjectsGoldenTest extends TestCase {
	/**
	 * Run getFacets over a paged register and record the queries, the warnings and the match count.
	 *
	 * @param array $config The voorzieningen config.
	 * @param array $call   schema, search, organization and the result count per page.
	 *
	 * @return array
	 */
	private function record(array $config, array $call): array {
		$queries = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$queries, $call): array {
				$queries[] = $query;
				$count = $call['pages'][$query['_page'] - 1] ?? 0;
				$results = [];
				for ($i = 0; $i < $count; $i++) {
					$results[] = ['id' => 'o' . $query['_page'] . '-' . $i];
				}

				return ['results' => $results, 'pages' => $call['totalPages']];
			}
		);
		$objectService->method('searchObjects')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $id === ObjectService::class ? $objectService : null);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getVoorzieningenConfig')->willReturn($config);
		$archiMate = $this->createMock(ArchiMateService::class);
		$archiMate->method('getElementObjects')->willReturn([]);
		$archiMate->method('getRelationshipObjects')->willReturn([]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});

		$service = new FacetService($container, $settings, $archiMate, new ViewQueryBuilder(), $this->createMock(IUserSession::class), $logger, $cacheFactory);
		$result = $service->getFacets($call['schema'], [], $call['search'], $call['organization']);

		return ['queries' => $queries, 'warnings' => $warnings, 'totalMatched' => $result['_meta']['totalMatched']];
	}//end record()

	/**
	 * The base object queries match the golden.
	 *
	 * @return void
	 */
	public function testBaseObjectsMatchTheGolden(): void {
		$config = ['register' => '1', 'module_schema' => '2', 'dienst_schema' => '3', 'service_schema' => '4', 'catalogService_schema' => '5'];
		$base = ['schema' => 'module', 'search' => null, 'organization' => null, 'pages' => [3], 'totalPages' => 1];
		$out = [
			'not configured' => $this->record(['register' => '1'], $base),
			'no register' => $this->record(['module_schema' => '2'], $base),
			'one page' => $this->record($config, $base),
			'organisation and search' => $this->record($config, ['organization' => 'org-1', 'search' => 'word'] + $base),
			'full pages until the last' => $this->record($config, ['pages' => [1000, 1000, 7], 'totalPages' => 3] + $base),
			'full page that is the last' => $this->record($config, ['pages' => [1000], 'totalPages' => 1] + $base),
			'past the ceiling' => $this->record($config, ['pages' => [1000, 1000, 1000, 1000, 1000, 1000], 'totalPages' => 9] + $base),
		];
		foreach (['catalogService'] as $schema) {
			$out['schema ' . $schema] = $this->record($config, ['schema' => $schema] + $base);
		}

		$path = dirname(__DIR__, 2) . '/fixtures/facets/base-objects.json';
		$this->assertSame(
			json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
			json_decode((string)json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
		);
	}//end testBaseObjectsMatchTheGolden()
}//end class
