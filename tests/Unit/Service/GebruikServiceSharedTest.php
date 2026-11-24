<?php

/**
 * GebruikService's shared-usage reads: the scope it forces on the search and
 * the records it keeps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\GebruikService;
use OCA\Stackiq\Service\RelationRefs;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\SharedUsageScope;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for GebruikService::getSharedGebruiken() and getSharedConnections().
 */
#[CoversClass(GebruikService::class)]
#[UsesClass(SharedUsageScope::class)]
#[UsesClass(RelationRefs::class)]
class GebruikServiceSharedTest extends TestCase {

	/**
	 * Object Service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Service.
	 *
	 * @var GebruikService
	 */
	private GebruikService $service;

	/**
	 * Wire an installed OpenRegister and a complete configuration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$catalogue  = $this->createMock(originalClassName: CatalogueLocator::class);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$container  = $this->createMock(originalClassName: ContainerInterface::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objectService);
		$catalogue->method('getVoorzieningenConfig')->willReturn(
			['register' => 'reg-1', 'gebruik_schema' => 'gebruik-schema', 'module_schema' => 'module-schema']
		);

		$this->service = new GebruikService(
			catalogue: $catalogue,
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The search keeps the request's filters but forces participants, drops consumer and paging,
	 * and only records that really list the organisation come back, marked.
	 *
	 * @return void
	 */
	public function testSharedSearchForcesTheScopeAndRechecksEveryRecord(): void {
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(
				[
					'module' => 'app-1',
					'participants' => 'org-alb',
					'_limit' => 500,
					'@self' => ['register' => 'reg-1', 'schema' => 'gebruik-schema'],
					'_extend' => [],
				],
				false,
				false
			)
			->willReturn(
				[
					'results' => [
						['id' => 'u-shared', 'consumer' => 'org-zd', 'participants' => ['org-alb']],
						['id' => 'u-foreign', 'consumer' => 'org-zd', 'participants' => ['org-pap']],
						['id' => 'u-own', 'consumer' => 'org-alb', 'participants' => ['org-alb']],
					],
					'total' => 3,
				]
			);

		$shared = $this->service->getSharedGebruiken(
			[
				'module' => 'app-1',
				'consumer' => 'org-alb',
				'participants' => ['org-elsewhere'],
				'_page' => 1,
				'_offset' => 0,
				'_limit' => 20,
			],
			'org-alb'
		);

		$this->assertSame(expected: ['u-shared'], actual: array_column($shared, 'id'));
		$this->assertSame(expected: ['cooperation' => 'org-zd', 'participantCount' => 2], actual: $shared[0]['_shared']);
	}//end testSharedSearchForcesTheScopeAndRechecksEveryRecord()

	/**
	 * No organisation: no query at all.
	 *
	 * @return void
	 */
	public function testNoOrganisationIssuesNoQuery(): void {
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$this->assertSame(expected: [], actual: $this->service->getSharedGebruiken([], ''));
		$this->assertSame(expected: [], actual: $this->service->getSharedConnections(''));
	}//end testNoOrganisationIssuesNoQuery()

	/**
	 * The connections read the owned and the shared usages and combine them.
	 *
	 * @return void
	 */
	public function testSharedConnectionsCombineOwnedAndSharedUsages(): void {
		$queries = [];
		$this->objectService->expects($this->exactly(count: 2))->method('searchObjectsPaginated')
			->willReturnCallback(
				static function (array $query) use (&$queries): array {
					$queries[] = array_intersect_key($query, ['consumer' => 1, 'participants' => 1, '_limit' => 1]);
					if (isset($query['consumer']) === true) {
						return ['results' => [['id' => 'u-1', 'consumer' => 'org-pap', 'participants' => ['org-alb'], 'koppelingen' => ['c-1']]]];
					}

					return ['results' => [['id' => 'u-2', 'consumer' => 'org-zd', 'participants' => ['org-pap'], 'koppelingen' => ['c-1', 'c-2']]]];
				}
			);

		$connections = $this->service->getSharedConnections('org-pap');

		$this->assertSame(
			expected: [
				['consumer' => 'org-pap', '_limit' => 500],
				['participants' => 'org-pap', '_limit' => 500],
			],
			actual: $queries
		);
		$this->assertSame(
			expected: [
				'c-1' => ['sharedWith' => ['org-alb', 'org-zd'], 'usages' => ['u-1', 'u-2']],
				'c-2' => ['sharedWith' => ['org-zd'], 'usages' => ['u-2']],
			],
			actual: $connections
		);
	}//end testSharedConnectionsCombineOwnedAndSharedUsages()
}//end class
