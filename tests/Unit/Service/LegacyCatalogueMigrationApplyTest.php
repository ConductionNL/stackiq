<?php

/**
 * Tests for bringing over an export from the previous Softwarecatalogus.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/CmdbTestSupport.php';
require_once __DIR__ . '/../Support/BuildsLegacyCatalogueService.php';

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Service\LegacyCatalogueMigrationService;
use OCA\Stackiq\Tests\Unit\Support\BuildsLegacyCatalogueService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The first run creates, the second updates, the left-out and broken rows are skipped with a reason.
 */
class LegacyCatalogueMigrationApplyTest extends TestCase {
	use BuildsLegacyCatalogueService;

	/**
	 * The in-memory catalogue: objects by schema id and uuid.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * The saves, in order: schema id, uuid given (null = create) and whether RBAC was on.
	 *
	 * @var array<int, array{schema: int, uuid: string|null, rbac: bool}>
	 */
	private array $saves = [];

	/**
	 * The schema id whose saves fail, 0 for none.
	 *
	 * @var int
	 */
	private int $failingSchema = 0;

	/**
	 * Seed the catalogue: one supplier with one module and one version.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [
			11 => ['org-1' => ['name' => 'Voorbeeld Software B.V.', 'registrationNumber' => '12345678']],
			12 => ['mod-1' => ['name' => 'Zaaksysteem Voorbeeld', 'provider' => 'org-1']],
			13 => ['ver-1' => ['version' => '4.2', 'module' => 'mod-1']],
			14 => [],
		];
	}//end setUp()

	/**
	 * The first run: created for every row it may write; left-out, empty-supplier and repeated rows skipped with
	 * their reason; a new supplier named twice is created once, as a Draft registered by the municipality.
	 *
	 * @return void
	 */
	public function testTheFirstRunCreatesAndCounts(): void {
		$result = $this->apply(rows: self::export(newVersion: '1.0'), leftOut: ['swc-4']);

		$this->assertSame(expected: 3, actual: $result['created']);
		$this->assertSame(expected: 0, actual: $result['updated']);
		$this->assertSame(expected: 3, actual: $result['skipped']);
		$this->assertSame(
			expected: [['swc-4', 'left-out'], ['swc-5', 'mapping'], ['swc-1', 'repeated-id']],
			actual: array_map(static fn (array $row): array => [$row['legacyId'], $row['reason']], $result['skippedRows'])
		);

		$suppliers = array_values(array_filter($this->store[11], static fn (array $org): bool => $org['name'] === 'Nieuw Leverancier B.V.'));
		$this->assertSame(
			expected: [['name' => 'Nieuw Leverancier B.V.', 'type' => 'Supplier', 'status' => 'Draft', 'registeredBy' => 'Municipality']],
			actual: $suppliers
		);
		$this->assertCount(expectedCount: 3, haystack: $this->store[12], message: 'two new modules');
		$this->assertCount(expectedCount: 2, haystack: $this->store[13], message: 'one new version; the third row names none');

		$usages = array_column($this->store[14], null, 'legacyId');
		$this->assertSame(expected: ['swc-1', 'swc-2', 'swc-3'], actual: array_keys($usages));
		$this->assertSame(
			expected: ['status' => 'In production', 'consumer' => 'org-9', 'provider' => 'org-1', 'module' => 'mod-1', 'moduleVersion' => 'ver-1', 'legacyId' => 'swc-1'],
			actual: $usages['swc-1']
		);
		$this->assertArrayNotHasKey(key: 'moduleVersion', array: $usages['swc-3'], message: 'a row without a version links none');
		$this->assertSame(expected: [true], actual: array_values(array_unique(array_column($this->saves, 'rbac'))), message: 'every write uses the caller\'s rights');
	}//end testTheFirstRunCreatesAndCounts()

	/**
	 * The same export a week later with one changed version updates every usage and duplicates none.
	 *
	 * @return void
	 */
	public function testRunningItAgainUpdatesWithoutDuplicates(): void {
		$this->apply(rows: self::export(newVersion: '1.0'), leftOut: ['swc-4']);
		$suppliers = count($this->store[11]);

		$result = $this->apply(rows: self::export(newVersion: '1.1'), leftOut: ['swc-4']);

		$this->assertSame(expected: [0, 3], actual: [$result['created'], $result['updated']]);
		$this->assertCount(expectedCount: 3, haystack: $this->store[14], message: 'no usage duplicated');
		$this->assertSame(expected: $suppliers, actual: count($this->store[11]), message: 'the supplier created the first time is matched now');
		$updates = array_filter($this->saves, static fn (array $save): bool => $save['schema'] === 14 && $save['uuid'] !== null);
		$this->assertCount(expectedCount: 3, haystack: $updates);
		$this->assertSame(
			expected: ['1.0', '1.1', '4.2'],
			actual: (static function (array $versions): array {
				$numbers = array_column($versions, 'version');
				sort($numbers);
				return $numbers;
			})($this->store[13])
		);
	}//end testRunningItAgainUpdatesWithoutDuplicates()

	/**
	 * A save OpenRegister refuses skips that row with the step that failed; the other rows are written.
	 *
	 * @return void
	 */
	public function testARefusedSaveSkipsTheRow(): void {
		$this->failingSchema = 11;

		$result = $this->apply(rows: self::export(newVersion: '1.0'), leftOut: []);

		$this->assertSame(expected: 1, actual: $result['created']);
		$this->assertSame(
			expected: [['swc-2', 'supplier-not-saved'], ['swc-3', 'supplier-not-saved'], ['swc-4', 'supplier-not-saved'], ['swc-5', 'mapping'], ['swc-1', 'repeated-id']],
			actual: array_map(static fn (array $row): array => [$row['legacyId'], $row['reason']], $result['skippedRows'])
		);
	}//end testARefusedSaveSkipsTheRow()

	/**
	 * A caller who may not create usages is refused before the file is read, and nothing is written.
	 *
	 * @return void
	 */
	public function testACallerWithoutTheRightWritesNothing(): void {
		$reader = $this->createMock(originalClassName: CmdbWorkbookReader::class);
		$reader->expects($this->never())->method('read');

		try {
			$this->legacyService(options: ['mayCreate' => false, 'reader' => $reader, 'objectService' => $this->objectService()])
				->apply(path: '/tmp/export.xlsx', fileName: 'export.xlsx', leftOut: []);
			$this->fail(message: 'must be refused');
		} catch (CmdbImportException $exception) {
			$this->assertSame(expected: CmdbImportException::FORBIDDEN, actual: $exception->getErrorCode());
		}

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testACallerWithoutTheRightWritesNothing()

	/**
	 * Apply rows as if a reader read them from the uploaded file.
	 *
	 * @param array<int, array<string, mixed>> $rows The reader rows.
	 * @param array<int, string> $leftOut The ids left out.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(array $rows, array $leftOut): array {
		$reader = $this->createMock(originalClassName: CmdbWorkbookReader::class);
		$reader->method('read')->willReturn(['rows' => $rows, 'warnings' => []]);

		return $this->legacyService(options: ['reader' => $reader, 'objectService' => $this->objectService()])
			->apply(path: '/tmp/export.xlsx', fileName: 'export.xlsx', leftOut: $leftOut);
	}//end apply()

	/**
	 * The export: a fully matched row, a new supplier with two new products (one with a version), a left-out row,
	 * a row without a supplier and the first row again.
	 *
	 * @param string $newVersion The version of the new supplier's first product.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function export(string $newVersion): array {
		$cells = [
			['ID' => 'swc-1', 'Leverancier' => 'Voorbeeld Software B.V.', 'KvK-nummer leverancier' => '12345678', 'Applicatie' => 'Zaaksysteem Voorbeeld', 'Versie' => '4.2', 'Status' => 'In gebruik'],
			['ID' => 'swc-2', 'Leverancier' => 'Nieuw Leverancier B.V.', 'Applicatie' => 'Nieuw Pakket', 'Versie' => $newVersion],
			['ID' => 'swc-3', 'Leverancier' => 'Nieuw Leverancier B.V.', 'Applicatie' => 'Tweede Pakket'],
			['ID' => 'swc-4', 'Leverancier' => 'Nieuw Leverancier B.V.', 'Applicatie' => 'Niet nodig'],
			['ID' => 'swc-5', 'Leverancier' => '', 'Applicatie' => 'Zonder leverancier'],
			['ID' => 'swc-1', 'Leverancier' => 'Voorbeeld Software B.V.', 'Applicatie' => 'Zaaksysteem Voorbeeld'],
		];

		$rows = [];
		foreach ($cells as $index => $row) {
			$rows[] = ['sheet' => 'Applicaties', 'row' => ($index + 2), 'cells' => $row, 'uncached' => []];
		}

		return $rows;
	}//end export()

	/**
	 * An object service on the in-memory catalogue: exact-field search and save.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$service = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$service->method('searchObjects')->willReturnCallback(
			function (array $query = []): array {
				$schema = (int)$query['@self']['schema'];
				$found = [];
				foreach (($this->store[$schema] ?? []) as $uuid => $object) {
					if (self::holdsFilters(object: $object, query: $query) === true) {
						$found[] = $this->entity(uuid: (string)$uuid, object: $object);
					}
				}

				return $found;
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac = true): ObjectEntityInterface {
				$schema = (int)$options->target->schema;
				$this->saves[] = ['schema' => $schema, 'uuid' => $options->uuid, 'rbac' => $_rbac];
				if ($schema === $this->failingSchema) {
					throw new RuntimeException('refused');
				}

				$uuid = ($options->uuid ?? 'new-' . count($this->saves));
				$this->store[$schema][$uuid] = array_merge(($this->store[$schema][$uuid] ?? []), $object);
				return $this->entity(uuid: $uuid, object: $this->store[$schema][$uuid]);
			}
		);
		return $service;
	}//end objectService()

	/**
	 * Whether an object holds every field filter of a query.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array<string, mixed> $query The query.
	 *
	 * @return bool
	 */
	private static function holdsFilters(array $object, array $query): bool {
		foreach ($query as $field => $value) {
			if ($field[0] !== '@' && $field[0] !== '_' && ($object[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end holdsFilters()

	/**
	 * An entity double.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $object The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $uuid, array $object): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()
}//end class
