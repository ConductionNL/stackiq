<?php

/**
 * Unit tests for ArchiMateService export, status and object retrieval.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the ArchiMate facade: export orchestration, status counts and
 * the bounded per-type object search.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\ArchiMateService
 * @uses   \OCA\Stackiq\Service\ArchiMate\ObjectServiceLookup
 * @uses   \OCA\Stackiq\Service\ArchiMate\AmefRegisterId
 * @uses   \OCA\Stackiq\Service\ArchiMate\CamelCaseNames
 */
class ArchiMateServiceExportStatusTest extends TestCase {

	/**
	 * Schema ids per AMEF object type.
	 */
	private const SCHEMAS = [
		'model' => 31,
		'element' => 32,
		'relationship' => 33,
		'view' => 34,
		'organization' => 35,
		'property_definition' => 36,
		'property' => 37,
	];

	private IAppManager&MockObject $appManager;

	private RegisterConfigReaderInterface&MockObject $settings;

	private ObjectServiceInterface&MockObject $objectService;

	private ArchiMateImportService&MockObject $importService;

	private ArchiMateExportService&MockObject $exportService;

	private ArchiMateService $service;

	private array $amefConfig = ['register_id' => '8'];

	/**
	 * Set up mocks and the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($this->objectService);

		$this->settings = $this->createMock(RegisterConfigReaderInterface::class);
		$this->settings->method('getAmefConfig')->willReturnCallback(fn (): array => $this->amefConfig);
		$this->settings->method('getSchemaIdForObjectType')->willReturnCallback(
			fn (string $type): ?int => self::SCHEMAS[$type] ?? null
		);

		$this->importService = $this->createMock(ArchiMateImportService::class);
		$this->exportService = $this->createMock(ArchiMateExportService::class);

		$this->service = new ArchiMateService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$this->appManager,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->settings,
			$this->importService,
			$this->exportService,
			$this->createMock(ProgressTracker::class)
		);

	}//end setUp()

	/**
	 * The export hands the object service, AMEF register and schema map to the
	 * export service and returns its XML.
	 *
	 * @return void
	 */
	public function testExportDelegatesWithRegisterAndSchemaMap(): void {
		$this->exportService->expects($this->once())->method('exportArchiMateXml')
			->with(
				$this->objectService,
				8,
				[31 => 'model', 32 => 'element', 33 => 'relationship', 34 => 'view', 35 => 'organization', 36 => 'property_definition'],
				'org-1'
			)
			->willReturn('<model/>');

		$this->assertSame(
			['success' => true, 'xml' => '<model/>', 'exported_count' => 'calculated_in_export_service'],
			$this->service->exportToArchiMate('org-1')
		);

	}//end testExportDelegatesWithRegisterAndSchemaMap()

	/**
	 * Without an AMEF register the export reports the configuration error.
	 *
	 * @return void
	 */
	public function testExportWithoutRegisterFails(): void {
		$this->amefConfig = ['register_id' => '0'];
		$this->exportService->expects($this->never())->method('exportArchiMateXml');

		$this->assertSame(
			[
				'success' => false,
				'error' => 'AMEF register ID is not configured. Please configure the AMEF register via the admin interface.',
			],
			$this->service->exportToArchiMate()
		);

	}//end testExportWithoutRegisterFails()

	/**
	 * Without OpenRegister the export reports the missing object service.
	 *
	 * @return void
	 */
	public function testExportWithoutOpenRegisterFails(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$service = new ArchiMateService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->settings,
			$this->importService,
			$this->exportService,
			$this->createMock(ProgressTracker::class)
		);

		$this->assertSame(['success' => false, 'error' => 'ObjectService not available'], $service->exportToArchiMate());
		$this->assertSame(['success' => false, 'error' => 'ObjectService not available'], $service->getArchiMateStatus());
		$this->assertSame([], $service->getElementObjects());

	}//end testExportWithoutOpenRegisterFails()

	/**
	 * The organisation export looks the organisation up, searches its gebruik,
	 * modules and deelnames, and names the file after the organisation.
	 *
	 * @return void
	 */
	public function testOrganisationExportCollectsDataAndNamesFile(): void {
		$voorz = ['register' => 5, 'organisatie_schema' => 6, 'gebruik_schema' => 7, 'module_schema' => 9];
		$this->settings->method('getVoorzieningenConfig')->willReturn($voorz);

		$organisation = $this->createMock(ObjectEntity::class);
		$organisation->method('jsonSerialize')->willReturn(['name' => 'Gemeente Test']);
		$this->objectService->expects($this->once())->method('find')
			->with('org-uuid', [], false, 5, 6, false, false)
			->willReturn($organisation);

		$queries = [];
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$queries): array {
				$queries[] = $query;
				return [['n' => count($queries)]];
			}
		);

		$this->exportService->expects($this->once())->method('exportOrganizationArchiMateXml')
			->with(
				$this->objectService,
				8,
				$this->isType('array'),
				'Gemeente Test',
				'org-uuid',
				[['n' => 1]],
				[['n' => 2]],
				[['n' => 3]],
				['deelnames' => true]
			)
			->willReturn('<org/>');

		$result = $this->service->exportOrgArchiMate('org-uuid', ['deelnames' => true]);

		$this->assertSame(
			[
				'success' => true,
				'xml' => '<org/>',
				'file_name' => date('d-m-Y') . '_Stackiq_AMEFF_export_Gemeente_Test.xml',
			],
			$result
		);
		$this->assertSame(
			[
				['@self' => ['register' => 5, 'schema' => 7, 'organisation' => 'org-uuid'], '_limit' => 10000],
				['@self' => ['register' => 5, 'schema' => 9, 'organisation' => 'org-uuid'], '_limit' => 10000],
				['@self' => ['register' => 5, 'schema' => 7], 'participants' => 'org-uuid', '_limit' => 10000],
			],
			$queries
		);

	}//end testOrganisationExportCollectsDataAndNamesFile()

	/**
	 * An unknown organisation is reported without exporting; the register and
	 * schema fall back to the configured voorzieningen ids.
	 *
	 * @return void
	 */
	public function testOrganisationExportUnknownOrganisation(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(15);
		$this->objectService->expects($this->once())->method('find')
			->with('ghost', [], false, 15, 35)
			->willThrowException(new RuntimeException('not found'));
		$this->exportService->expects($this->never())->method('exportOrganizationArchiMateXml');

		$this->assertSame(
			['success' => false, 'error' => 'Organization not found: ghost'],
			$this->service->exportOrgArchiMate('ghost')
		);

	}//end testOrganisationExportUnknownOrganisation()

	/**
	 * Without organisation register the organisation export fails.
	 *
	 * @return void
	 */
	public function testOrganisationExportWithoutRegisterFails(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(null);

		$this->assertSame(
			['success' => false, 'error' => 'Organization register/schema not configured'],
			$this->service->exportOrgArchiMate('org-uuid')
		);

	}//end testOrganisationExportWithoutRegisterFails()

	/**
	 * The status counts every AMEF object type.
	 *
	 * @return void
	 */
	public function testStatusCountsEveryType(): void {
		$sizes = [31 => 1, 32 => 4, 33 => 3, 34 => 2, 35 => 5, 36 => 6, 37 => 7];
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($sizes): array {
				$this->assertSame(8, $query['@self']['register']);
				$this->assertSame(1000, $query['_limit']);
				return array_fill(0, $sizes[$query['@self']['schema']], []);
			}
		);

		$this->assertSame(
			[
				'success' => true,
				'status' => 'ready',
				'model_count' => 1,
				'total_objects' => 28,
				'element_count' => 4,
				'organization_count' => 5,
				'view_count' => 2,
				'relationship_count' => 3,
				'property_count' => 7,
				'property_definition_count' => 6,
			],
			$this->service->getArchiMateStatus()
		);

	}//end testStatusCountsEveryType()

	/**
	 * Pagination options become a bounded, offset query merged with the filters.
	 *
	 * @return void
	 */
	public function testPaginatedQueryIsBounded(): void {
		$this->objectService->expects($this->once())->method('searchObjects')
			->with(
				[
					'@self' => ['register' => 8, 'schema' => 34],
					'name' => 'X',
					'@pagination' => ['limit' => 50, 'offset' => 100],
					'_limit' => 50,
					'_offset' => 100,
				]
			)
			->willReturn([['id' => 1]]);

		$this->assertSame(
			[['id' => 1]],
			$this->service->getViewObjects(['name' => 'X', 'limit' => 50, 'offset' => 100, 'use_pagination' => true])
		);

	}//end testPaginatedQueryIsBounded()

	/**
	 * Without pagination the query is only bounded; a truthy flag with a zero
	 * limit offsets the query but adds no pagination block, and a caller's
	 * own @self keys are merged into the register and schema.
	 *
	 * @return void
	 */
	public function testQueryShapesWithoutOrWithEmptyPagination(): void {
		$queries = [];
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$queries): array {
				$queries[] = $query;
				return [];
			}
		);

		$this->service->getElementObjects();
		$this->service->getViewObjects(['@self' => ['owner' => 'x'], 'limit' => 0, 'offset' => '5', 'use_pagination' => 1]);

		$this->assertSame(
			[
				['@self' => ['register' => 8, 'schema' => 32], '_limit' => 1000],
				['@self' => ['register' => 8, 'schema' => 34, 'owner' => 'x'], '_limit' => 0, '_offset' => 5],
			],
			$queries
		);

	}//end testQueryShapesWithoutOrWithEmptyPagination()

	/**
	 * An unconfigured schema or a failing search returns no objects.
	 *
	 * @return void
	 */
	public function testUnconfiguredOrFailingSearchReturnsNothing(): void {
		$this->amefConfig = [];
		$this->assertSame([], $this->service->getModelObjects());

		$this->amefConfig = ['register' => 8];
		$this->objectService->method('searchObjects')->willThrowException(new RuntimeException('db'));
		$this->assertSame([], $this->service->getRelationshipObjects());
		$this->assertSame(['success' => true, 'status' => 'ready'], array_slice($this->service->getArchiMateStatus(), 0, 2));

	}//end testUnconfiguredOrFailingSearchReturnsNothing()

	/**
	 * The imports delegate to the import service.
	 *
	 * @return void
	 */
	public function testImportsDelegate(): void {
		$this->importService->expects($this->once())->method('importArchiMateFileFromPath')
			->with(['path' => 'a.xml'])->willReturn(['success' => true, 'kind' => 'plain']);
		$this->importService->expects($this->once())->method('importArchiMateFileFromPathOptimized')
			->with(['path' => 'b.xml'])->willReturn(['success' => true, 'kind' => 'optimized']);

		$this->assertSame(['success' => true, 'kind' => 'plain'], $this->service->importArchiMateFileFromPath(['path' => 'a.xml']));
		$this->assertSame(['success' => true, 'kind' => 'optimized'], $this->service->importArchiMateFileFromPathOptimized(['path' => 'b.xml']));
		$this->assertFalse($this->service->isImportInProgress());
		$this->assertFalse($this->service->isExportInProgress());

	}//end testImportsDelegate()

	/**
	 * Property names map from their original name to camelCase.
	 *
	 * @return void
	 */
	public function testPropertyNameMapping(): void {
		$this->assertSame(
			['Lifecycle status' => 'lifecycleStatus'],
			$this->service->getPropertyNameMapping(['ref-1' => 'Lifecycle status'])
		);

	}//end testPropertyNameMapping()

}//end class
