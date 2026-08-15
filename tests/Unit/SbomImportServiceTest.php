<?php

/**
 * Unit tests for SbomImportService.
 *
 * Covers the soft-delete-aware replace-on-reimport core: the previous live
 * component set is soft-deleted and only the new set is live afterwards,
 * bounded-batch bulk save/delete, progress-tracking activation above the
 * 50-component threshold (and its absence below it), import provenance
 * recorded on the moduleVersie with existing fields carried forward
 * (PUT-semantic save), the not-found guard, and that an unsupported-format
 * parse failure writes nothing.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-re-import-replaces-the-previous-component-set-and-is-soft-delete-aware
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Exception\UnsupportedSbomFormatException;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use OCA\SoftwareCatalog\Service\SbomImportService;
use OCA\SoftwareCatalog\Service\SbomParserService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for SbomImportService.
 */
class SbomImportServiceTest extends TestCase {
	/**
	 * @var string
	 */
	private string $fixturesDir;

	/**
	 * @var ObjectService|MockObject
	 */
	private ObjectService|MockObject $objectService;

	/**
	 * @var ProgressTracker|MockObject
	 */
	private ProgressTracker|MockObject $progressTracker;

	/**
	 * @var array<int,array<string,mixed>> Objects saved via saveObjects().
	 */
	private array $savedBatches = [];

	/**
	 * @var array<int,array<int,string>> UUID batches passed to deleteObjects().
	 */
	private array $deletedBatches = [];

	/**
	 * @var array<string,mixed>|null The moduleVersie data bag last saved via saveObject().
	 */
	private ?array $savedModuleVersion = null;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->fixturesDir = __DIR__ . '/../fixtures/sbom';
		$this->savedBatches = [];
		$this->deletedBatches = [];
		$this->savedModuleVersion = null;
	}//end setUp()

	/**
	 * Read a fixture file's raw contents.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string The raw contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents($this->fixturesDir . '/' . $name);
	}//end fixture()

	/**
	 * Build a moduleVersie entity stub.
	 *
	 * @param array<string,mixed> $data Existing moduleVersie data.
	 *
	 * @return ObjectEntity|MockObject
	 */
	private function moduleVersionEntity(array $data): ObjectEntity|MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn('mv-uuid-1');

		return $entity;
	}//end moduleVersieEntity()

	/**
	 * Build a previously-imported sbomComponent entity stub exposing only
	 * getUuid() (the replace path only needs the uuid to delete).
	 *
	 * @param string $uuid The component uuid.
	 *
	 * @return ObjectEntity|MockObject
	 */
	private function previousComponentEntity(string $uuid): ObjectEntity|MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);

		return $entity;
	}//end previousComponentEntity()

	/**
	 * Build a fully-wired SbomImportService whose ObjectService is a mock
	 * pre-configured with a moduleVersie find() result and a previous
	 * component set for searchObjects().
	 *
	 * @param array<string,mixed> $moduleVersionData Existing moduleVersie data bag.
	 * @param array<int,string> $previousUuids Uuids of the previous live component set.
	 *
	 * @return SbomImportService
	 */
	private function makeService(array $moduleVersionData = ['version' => '1.0.0'], array $previousUuids = []): SbomImportService {
		$container = $this->createMock(ContainerInterface::class);
		$settings = $this->createMock(SettingsService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->progressTracker = $this->createMock(ProgressTracker::class);
		$logger = $this->createMock(LoggerInterface::class);

		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 1]);
		$settings->method('getSchemaIdForObjectType')->willReturnMap(
			[
				['moduleVersion', 10],
				['sbomComponent', 20],
				['module', 30],
			]
		);

		$entity = $this->moduleVersionEntity($moduleVersionData);
		$this->objectService->method('find')->willReturn($entity);

		$previousEntities = array_map([$this, 'previousComponentEntity'], $previousUuids);
		$this->objectService->method('searchObjects')->willReturn($previousEntities);

		$this->objectService->method('deleteObjects')->willReturnCallback(
			function (array $uuids) {
				$this->deletedBatches[] = $uuids;
				return ['deleted_uuids' => $uuids, 'skipped_uuids' => [], 'cascade_count' => 0];
			}
		);

		$this->objectService->method('saveObjects')->willReturnCallback(
			function (array $objects) {
				$this->savedBatches[] = $objects;
				return ['statistics' => ['objectsCreated' => count($objects)]];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity) {
				$this->savedModuleVersion = $object;
				return $entity;
			}
		);

		$container->method('get')->willReturn($this->objectService);

		return new SbomImportService(
			$container,
			$settings,
			new SbomParserService(),
			$this->progressTracker,
			$logger
		);
	}//end makeService()

	/**
	 * A first import creates one sbomComponent per parsed component, linked
	 * to the target moduleVersie.
	 *
	 * @return void
	 */
	public function testImportCreatesOneComponentPerParsedEntry(): void {
		$service = $this->makeService();

		$result = $service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-1.6-valid.json'),
			'cyclonedx-json',
			'sbom.json'
		);

		$this->assertTrue($result['success']);
		$this->assertSame(3, $result['componentCount']);
		$this->assertCount(1, $this->savedBatches);
		$this->assertCount(3, $this->savedBatches[0]);

		foreach ($this->savedBatches[0] as $componentData) {
			$this->assertSame('mv-uuid-1', $componentData['moduleVersion']);
		}

		$this->assertSame('lodash', $this->savedBatches[0][0]['name']);
		$this->assertSame(['MIT'], $this->savedBatches[0][0]['licenses']);
	}//end testImportCreatesOneComponentPerParsedEntry()

	/**
	 * A VEX vulnerabilities[] block's cveId is attached to the matching
	 * component's `vexCveIds` (raw fact, keyed by bom-ref) — a component
	 * with no VEX entry gets an empty array, never null/undefined.
	 *
	 * @return void
	 */
	public function testVexCveIdsAreAttachedToTheMatchingComponentByBomRef(): void {
		$service = $this->makeService();

		$service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-with-vex.json'),
			'cyclonedx-json',
			'sbom.json'
		);

		$this->assertCount(1, $this->savedBatches[0]);
		$this->assertSame(['CVE-2021-44228'], $this->savedBatches[0][0]['vexCveIds']);
	}//end testVexCveIdsAreAttachedToTheMatchingComponentByBomRef()

	/**
	 * A component with no VEX entry gets an empty vexCveIds array.
	 *
	 * @return void
	 */
	public function testComponentsWithoutVexEntryGetEmptyVexCveIds(): void {
		$service = $this->makeService();

		$service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-1.6-valid.json'),
			'cyclonedx-json',
			'sbom.json'
		);

		foreach ($this->savedBatches[0] as $componentData) {
			$this->assertSame([], $componentData['vexCveIds']);
		}
	}//end testComponentsWithoutVexEntryGetEmptyVexCveIds()

	/**
	 * A second import soft-deletes the previous live set; only the new set
	 * is created.
	 *
	 * @return void
	 */
	public function testReimportReplacesPreviousLiveSet(): void {
		$service = $this->makeService(['version' => '1.0.0'], ['prev-1', 'prev-2']);

		$result = $service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-1.5-valid.json'),
			'cyclonedx-json',
			'sbom-v2.json'
		);

		$this->assertSame(2, $result['previousComponentCount']);
		$this->assertCount(1, $this->deletedBatches);
		$this->assertSame(['prev-1', 'prev-2'], $this->deletedBatches[0]);
		// Only the newly parsed set is created — no mixing with the old uuids.
		$this->assertCount(2, $this->savedBatches[0]);
	}//end testReimportReplacesPreviousLiveSet()

	/**
	 * When the previous live set is empty (e.g. already-trashed rows from an
	 * earlier replace, which OR's default search excludes), no delete batch
	 * is issued and the count is zero.
	 *
	 * @return void
	 */
	public function testNoPreviousLiveSetMeansNoDeleteBatch(): void {
		$service = $this->makeService(['version' => '1.0.0'], []);

		$result = $service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-1.5-valid.json'),
			'cyclonedx-json',
			'sbom.json'
		);

		$this->assertSame(0, $result['previousComponentCount']);
		$this->assertSame([], $this->deletedBatches);
	}//end testNoPreviousLiveSetMeansNoDeleteBatch()

	/**
	 * A successful import records sbomLastImportedAt/sbomFormat/sbomFileName
	 * on the moduleVersie, carrying every pre-existing field forward
	 * (PUT-semantic saveObject — an omitted field would be nulled).
	 *
	 * @return void
	 */
	public function testImportRecordsProvenanceAndCarriesExistingFieldsForward(): void {
		$service = $this->makeService(['version' => '2.3.1', 'shortDescription' => 'Keep me']);

		$service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-1.6-valid.json'),
			'cyclonedx-json',
			'my-sbom.json'
		);

		$this->assertNotNull($this->savedModuleVersion);
		$this->assertSame('2.3.1', $this->savedModuleVersion['version']);
		$this->assertSame('Keep me', $this->savedModuleVersion['shortDescription']);
		$this->assertSame('cyclonedx-json', $this->savedModuleVersion['sbomFormat']);
		$this->assertSame('my-sbom.json', $this->savedModuleVersion['sbomFileName']);
		$this->assertNotEmpty($this->savedModuleVersion['sbomLastImportedAt']);
	}//end testImportRecordsProvenanceAndCarriesExistingFieldsForward()

	/**
	 * A parsed set of more than 50 components starts a progress-tracking
	 * operation, updates it, and completes it, with the operation id
	 * returned in the response.
	 *
	 * @return void
	 */
	public function testLargeImportTracksProgressAndReturnsOperationId(): void {
		$service = $this->makeService();

		$this->progressTracker->expects($this->once())
			->method('startOperation')
			->with('sbom-import', ['total_items' => 60])
			->willReturn('sbom-import_abc123');
		$this->progressTracker->expects($this->atLeastOnce())->method('updateProgress');
		$this->progressTracker->expects($this->once())->method('completeOperation');

		$largeDocument = [
			'bomFormat' => 'CycloneDX',
			'specVersion' => '1.6',
			'components' => array_fill(
				0,
				60,
				['name' => 'pkg', 'version' => '1.0.0', 'purl' => 'pkg:generic/pkg@1.0.0', 'licenses' => []]
			),
		];

		$result = $service->importForModuleVersie(
			'mv-uuid-1',
			json_encode($largeDocument),
			'cyclonedx-json',
			'large.json'
		);

		$this->assertSame('sbom-import_abc123', $result['operationId']);
		$this->assertSame(60, $result['componentCount']);
		// Two batches of 100-max — 60 components is exactly one batch.
		$this->assertCount(1, $this->savedBatches);
	}//end testLargeImportTracksProgressAndReturnsOperationId()

	/**
	 * A parsed set of 50 or fewer components completes without starting a
	 * progress-tracking operation; operationId is null.
	 *
	 * @return void
	 */
	public function testSmallImportDoesNotTrackProgress(): void {
		$service = $this->makeService();

		$this->progressTracker->expects($this->never())->method('startOperation');
		$this->progressTracker->expects($this->never())->method('completeOperation');

		$result = $service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-1.6-valid.json'),
			'cyclonedx-json',
			'sbom.json'
		);

		$this->assertNull($result['operationId']);
		$this->assertSame(3, $result['componentCount']);
	}//end testSmallImportDoesNotTrackProgress()

	/**
	 * An unsupported SBOM format throws before any OR write — no delete, no
	 * save is issued.
	 *
	 * @return void
	 */
	public function testUnsupportedFormatWritesNothing(): void {
		$service = $this->makeService();

		$this->objectService->expects($this->never())->method('deleteObjects');
		$this->objectService->expects($this->never())->method('saveObjects');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(UnsupportedSbomFormatException::class);

		$service->importForModuleVersie(
			'mv-uuid-1',
			$this->fixture('cyclonedx-invalid-format.json'),
			'cyclonedx-json',
			'bad.json'
		);
	}//end testUnsupportedFormatWritesNothing()

	/**
	 * A moduleVersie that cannot be resolved throws a RuntimeException.
	 *
	 * @return void
	 */
	public function testModuleVersieNotFoundThrows(): void {
		$container = $this->createMock(ContainerInterface::class);
		$settings = $this->createMock(SettingsService::class);
		$objectService = $this->createMock(ObjectService::class);
		$progressTracker = $this->createMock(ProgressTracker::class);
		$logger = $this->createMock(LoggerInterface::class);

		$settings->method('getVoorzieningenConfig')->willReturn(['register' => 1]);
		$settings->method('getSchemaIdForObjectType')->willReturnMap(
			[
				['moduleVersion', 10],
				['sbomComponent', 20],
			]
		);
		$objectService->method('find')->willReturn(null);
		$container->method('get')->willReturn($objectService);

		$service = new SbomImportService($container, $settings, new SbomParserService(), $progressTracker, $logger);

		$this->expectException(\RuntimeException::class);

		$service->importForModuleVersie(
			'missing-uuid',
			$this->fixture('cyclonedx-1.6-valid.json'),
			'cyclonedx-json',
			'sbom.json'
		);
	}//end testModuleVersieNotFoundThrows()

	/**
	 * resolveParentModuleUuid() reads the moduleVersie's `module` relation
	 * and resolves a plain-string uuid.
	 *
	 * @return void
	 */
	public function testResolveParentModuleUuidReadsModuleRelation(): void {
		$service = $this->makeService(['module' => 'module-uuid-1']);

		$this->assertSame('module-uuid-1', $service->resolveParentModuleUuid('mv-uuid-1'));
	}//end testResolveParentModuleUuidReadsModuleRelation()

	/**
	 * resolveParentModuleUuid() also resolves an array-shaped relation
	 * (`{uuid: ...}`), matching the lenient relation shapes used elsewhere
	 * in this codebase.
	 *
	 * @return void
	 */
	public function testResolveParentModuleUuidReadsArrayShapedRelation(): void {
		$service = $this->makeService(['module' => ['uuid' => 'module-uuid-2']]);

		$this->assertSame('module-uuid-2', $service->resolveParentModuleUuid('mv-uuid-1'));
	}//end testResolveParentModuleUuidReadsArrayShapedRelation()
}//end class
