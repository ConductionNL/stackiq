<?php

/**
 * Tests for the import profile of the previous Softwarecatalogus export.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * The profile names the export's sheet, columns, key, limits and one pack per target, and the fixture normalises
 * to supplier, module, version and usage records.
 */
class VngSwcProfileTest extends TestCase {
	/**
	 * The profile file.
	 */
	private const PROFILE = 'vng-swc-profile.json';

	/**
	 * The legacy profile, loaded and validated with OpenRegister's validator.
	 *
	 * @param string|null $directory A profile directory other than the shipped one.
	 * @param CmdbImportPackStore|null $store The pack store; null builds one on the empty container.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(?string $directory = null, ?CmdbImportPackStore $store = null): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(
			container: CmdbTestSupport::emptyContainer(test: $this),
			directory: $directory,
			profileFile: self::PROFILE,
			packStore: $store
		);
		$profile->load();
		return $profile;
	}//end profile()

	/**
	 * A copy of the shipped profile directory, to break on purpose.
	 *
	 * @return string The directory.
	 */
	private function copyOfShippedDirectory(): string {
		$directory = sys_get_temp_dir() . '/stackiq-vng-swc-profile-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		return $directory;
	}//end copyOfShippedDirectory()

	/**
	 * Remove a directory made by copyOfShippedDirectory().
	 *
	 * @param string $directory The directory.
	 *
	 * @return void
	 */
	private function remove(string $directory): void {
		array_map('unlink', glob($directory . '/*.json'));
		rmdir($directory);
	}//end remove()

	/**
	 * The profile names the sheet, the key, the required columns, the limits and one pack per target.
	 *
	 * @return void
	 */
	public function testTheProfileNamesTheExportAndOnePackPerTarget(): void {
		$profile = $this->profile();

		$this->assertSame(['manufacturer', 'module', 'version', 'usage'], $profile->targets());
		$this->assertSame(['Applicaties'], $profile->sheetNames());
		$this->assertSame('ID', $profile->keyColumn());
		$this->assertSame('Applicatie', $profile->nameColumn());
		$this->assertSame(['ID', 'Leverancier', 'Applicatie'], $profile->requiredColumns());
		$this->assertSame(['ID'], $profile->idColumns());
		$this->assertSame(10485760, $profile->maxFileBytes());
		$this->assertSame(10000, $profile->maxRowsPerSheet());
		$this->assertNull($profile->archiveSheetName());
		$this->assertSame(['keep'], $profile->missingRecordsModes());

		$validator = CmdbTestSupport::newPackClass(class: 'PackDefinitionValidator');
		foreach ($profile->targets() as $target) {
			$pack = $profile->pack(target: $target);
			$this->assertSame([], $validator->validate($pack), $target);
			$this->assertSame('excel', $pack['sourceFormat'], $target);
			$this->assertStringStartsWith('stackiq-vng-swc-', $pack['id'], $target);
		}

		$this->assertSame(
			['ID', 'Applicatie', 'Leverancier', 'KvK-nummer leverancier', 'Omschrijving', 'Versie', 'Status', 'Opmerking'],
			$profile->referencedColumns()
		);
	}//end testTheProfileNamesTheExportAndOnePackPerTarget()

	/**
	 * The TOPdesk profile, which names no targets of its own, keeps its five.
	 *
	 * @return void
	 */
	public function testTheTopdeskProfileKeepsItsFiveTargets(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this));
		$profile->load();

		$this->assertSame(CmdbImportProfile::TARGETS, $profile->targets());
		$this->assertSame(CmdbImportProfile::EDITABLE_TARGETS, array_keys($profile->shippedEditablePacks()));
	}//end testTheTopdeskProfileKeepsItsFiveTargets()

	/**
	 * A profile without editable targets never asks OpenRegister's pack store, and every pack is a shipped file.
	 *
	 * @return void
	 */
	public function testTheLegacyPacksNeverTouchThePackStore(): void {
		$store = $this->createMock(CmdbImportPackStore::class);
		$store->expects($this->never())->method('isAvailable');
		$store->expects($this->never())->method('resolve');

		$profile = $this->profile(store: $store);

		$this->assertTrue($profile->usesShippedFallback());
		$this->assertSame([], $profile->shippedEditablePacks());
		foreach ($profile->mappingOverview()['packs'] as $pack) {
			$this->assertFalse($pack['editable']);
		}
	}//end testTheLegacyPacksNeverTouchThePackStore()

	/**
	 * A pack that drops a mapping the profile requires is refused; the profile's own list replaces the shipped rule.
	 *
	 * @return void
	 */
	public function testAPackWithoutItsRequiredMappingIsRefused(): void {
		$directory = $this->copyOfShippedDirectory();
		$pack = json_decode((string)file_get_contents($directory . '/vng-swc-module.json'), true);
		$pack['fieldMappings'][0]['required'] = false;
		file_put_contents($directory . '/vng-swc-module.json', json_encode($pack));

		try {
			$this->profile(directory: $directory);
			$this->fail('a module pack whose name is not required must be refused');
		} catch (CmdbImportException $exception) {
			$this->assertSame(CmdbImportException::MAPPING_UNAVAILABLE, $exception->getErrorCode());
			$this->assertStringContainsString('"name" must be mapped as required', $exception->getMessage());
		} finally {
			$this->remove(directory: $directory);
		}
	}//end testAPackWithoutItsRequiredMappingIsRefused()

	/**
	 * The fixture reads to two rows that normalise to supplier, module, version and usage records.
	 *
	 * @return void
	 */
	public function testTheFixtureNormalisesToSupplierModuleVersionAndUsage(): void {
		$profile = $this->profile();
		$rows = $this->read(name: 'vng-swc-export.xlsx', profile: $profile)['rows'];
		$this->assertCount(2, $rows);
		$this->assertArrayNotHasKey('Koppelingen', $rows[0]['cells'], 'a column no pack names is never read');

		$records = array_map(fn (array $row): array => $this->records(profile: $profile, row: $row), $rows);

		$this->assertSame(
			[
				'manufacturer' => ['type' => 'Supplier', 'status' => 'Active', 'registeredBy' => 'Supplier', 'name' => 'Voorbeeld Software B.V.', 'registrationNumber' => '12345678'],
				'module' => ['name' => 'Zaaksysteem Voorbeeld', 'shortDescription' => 'Zaakgericht werken'],
				'version' => ['version' => '4.2'],
				'usage' => ['status' => 'In production', 'interneAnnotation' => 'Contract loopt tot 2027'],
			],
			$records[0]
		);
		$this->assertSame(
			[
				'manufacturer' => ['type' => 'Supplier', 'status' => 'Active', 'registeredBy' => 'Supplier', 'name' => 'Ander Leverancier B.V.'],
				'module' => ['name' => 'Burgerzaken Voorbeeld'],
				'version' => [],
				'usage' => ['status' => 'To be phased out'],
			],
			$records[1]
		);
		$this->assertSame('swc-usage-0002', $rows[1]['cells']['ID']);
	}//end testTheFixtureNormalisesToSupplierModuleVersionAndUsage()

	/**
	 * A file without a required column is refused with its name.
	 *
	 * @return void
	 */
	public function testAFileWithoutARequiredColumnIsRefusedWithItsName(): void {
		$profile = $this->profile();
		try {
			$this->read(name: 'vng-swc-export-without-supplier.xlsx', profile: $profile);
			$this->fail('a file without "Leverancier" must be refused');
		} catch (CmdbImportException $exception) {
			$this->assertSame(CmdbImportException::MISSING_COLUMN, $exception->getErrorCode());
			$this->assertSame(['sheet' => 'Applicaties', 'column' => 'Leverancier'], $exception->getDetails());
		}
	}//end testAFileWithoutARequiredColumnIsRefusedWithItsName()

	/**
	 * Read a fixture with the legacy profile; skipped where PhpSpreadsheet cannot be loaded.
	 *
	 * @param string $name The fixture file.
	 * @param CmdbImportProfile $profile The profile.
	 *
	 * @return array<string, mixed>
	 */
	private function read(string $name, CmdbImportProfile $profile): array {
		if (CmdbTestSupport::loadPhpSpreadsheet() === false) {
			$this->markTestSkipped('PhpSpreadsheet not found: set OPENREGISTER_DIR to an OpenRegister app with its vendor/ installed.');
		}

		$path = CmdbTestSupport::fixtures() . '/' . $name;
		$reader = new CmdbWorkbookReader();
		$reader->assertXlsx(path: $path, fileName: $name);
		return $reader->read(path: $path, profile: $profile);
	}//end read()

	/**
	 * The records one row maps to, per target.
	 *
	 * @param CmdbImportProfile $profile The profile.
	 * @param array<string, mixed> $row A row as the reader returns it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function records(CmdbImportProfile $profile, array $row): array {
		$cells = (new CmdbRowNormaliser())->normalise(
			cells: $row['cells'],
			dateColumns: $profile->dateColumns(),
			idColumns: $profile->idColumns(),
			emptyValues: $profile->emptyValues()
		);

		$engine = CmdbTestSupport::newPackClass(class: 'MappingEngine');
		$records = [];
		foreach ($profile->targets() as $target) {
			$mapped = $engine->mapRow($profile->pack(target: $target), $cells, (int)$row['row']);
			$this->assertSame([], $mapped['errors'], $target);
			$records[$target] = $mapped['data'];
		}

		return $records;
	}//end records()
}//end class
