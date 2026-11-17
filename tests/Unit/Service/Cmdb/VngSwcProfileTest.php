<?php

/**
 * Tests for the import profile of the previous VNG Softwarecatalogus export and its four packs.
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
use OCA\Stackiq\Service\Cmdb\CmdbCsvReader;
use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * The profile names the export's sheet and columns, and the fixture normalises to the four records.
 */
class VngSwcProfileTest extends TestCase {
	/**
	 * The profile file name.
	 */
	private const PROFILE = 'vng-swc-profile.json';

	/**
	 * The shipped profile, loaded with a pack store that must never be asked.
	 *
	 * @param string|null $directory The profile directory; null is the shipped one.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(?string $directory = null): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		$store = $this->createMock(CmdbImportPackStore::class);
		$store->expects($this->never())->method('isAvailable');
		$store->expects($this->never())->method('resolve');
		$store->expects($this->never())->method('find');

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
	 * The fixture directory of this change.
	 *
	 * @return string
	 */
	private static function fixtures(): string {
		return CmdbTestSupport::appRoot() . '/tests/fixtures';
	}//end fixtures()

	/**
	 * The profile names the sheet, the key, the required columns, the limits and one pack per target.
	 *
	 * @return void
	 */
	public function testTheProfileNamesTheExportsSheetColumnsAndLimits(): void {
		$profile = $this->profile();

		$this->assertSame(['supplier', 'module', 'version', 'usage'], $profile->targets());
		$this->assertSame([], $profile->editableTargets());
		$this->assertSame(['Applicaties'], $profile->sheetNames());
		$this->assertSame('Applicaties', $profile->csvSheetName());
		$this->assertNull($profile->archiveSheetName());
		$this->assertSame('Gebruik ID', $profile->keyColumn());
		$this->assertSame(['Gebruik ID', 'Leverancier', 'Pakket'], $profile->requiredColumns());
		$this->assertSame(10485760, $profile->maxFileBytes());
		$this->assertSame(5000, $profile->maxRowsPerSheet());
		$this->assertSame(['supplier' => 'Leverancier ID', 'module' => 'Pakket ID', 'usage' => 'Gebruik ID'], $profile->legacyIdColumns());
		$this->assertSame(['referenceComponents' => 'Referentiecomponenten', 'connections' => 'Koppelingen'], $profile->linkColumns());
		$this->assertSame(';', $profile->linkSeparator());
		$this->assertSame(['keep'], $profile->missingRecordsModes());
	}//end testTheProfileNamesTheExportsSheetColumnsAndLimits()

	/**
	 * Every pack is a valid OpenRegister pack, and maps what the matching needs.
	 *
	 * @return void
	 */
	public function testEveryPackIsValidAndMapsTheMatchFields(): void {
		$profile = $this->profile();
		$validator = CmdbTestSupport::newPackClass(class: 'PackDefinitionValidator');

		$targets = [];
		foreach ($profile->targets() as $target) {
			$pack = $profile->pack(target: $target);
			$this->assertSame([], $validator->validate($pack), $target);
			$targets[$target] = array_column($pack['fieldMappings'], 'target', 'source');
		}

		$this->assertSame(['Leverancier' => 'name', 'Leverancier KvK' => 'registrationNumber'], $targets['supplier']);
		$this->assertSame(['Pakket' => 'name', 'Pakket omschrijving' => 'shortDescription'], $targets['module']);
		$this->assertSame(['Versie' => 'version'], $targets['version']);
		$this->assertSame(['Status' => 'status', 'Toelichting' => 'interneAnnotation'], $targets['usage']);
		$this->assertSame(['type' => 'Supplier', 'status' => 'Draft'], $profile->pack(target: 'supplier')['defaults']);
	}//end testEveryPackIsValidAndMapsTheMatchFields()

	/**
	 * The allowlist holds the profile's and the packs' columns, never another column of the export.
	 *
	 * @return void
	 */
	public function testTheAllowlistLeavesOtherColumnsUnread(): void {
		$columns = $this->profile()->referencedColumns();
		sort($columns);

		$this->assertSame(
			[
				'Gebruik ID', 'Koppelingen', 'Leverancier', 'Leverancier ID', 'Leverancier KvK', 'Pakket', 'Pakket ID',
				'Pakket omschrijving', 'Referentiecomponenten', 'Status', 'Toelichting', 'Versie',
			],
			$columns
		);
		$this->assertNotContains('Contactpersoon', $columns);
	}//end testTheAllowlistLeavesOtherColumnsUnread()

	/**
	 * The CSV fixture's rows normalise and map to a supplier, a module, a version and a usage.
	 *
	 * @return void
	 */
	public function testTheFixtureNormalisesToSupplierModuleVersionAndUsage(): void {
		$profile = $this->profile();
		$read = (new CmdbCsvReader())->read(path: self::fixtures() . '/vng-swc-export.csv', profile: $profile);

		$this->assertCount(3, $read['rows']);
		$this->assertSame([2, 3, 4], array_column($read['rows'], 'row'));
		$this->assertArrayNotHasKey('Contactpersoon', $read['rows'][0]['cells']);

		$normaliser = new CmdbRowNormaliser();
		$engine = CmdbTestSupport::newPackClass(class: 'MappingEngine');
		$values = $normaliser->normalise(cells: $read['rows'][1]['cells'], dateColumns: $profile->dateColumns(), idColumns: $profile->idColumns());

		$mapped = [];
		foreach ($profile->targets() as $target) {
			$mapped[$target] = $engine->mapRow($profile->pack(target: $target), $values, 3)['data'];
		}

		$this->assertSame('Voorbeeld Leverancier B.V.', $mapped['supplier']['name']);
		$this->assertSame('12345678', $mapped['supplier']['registrationNumber']);
		$this->assertSame('Supplier', $mapped['supplier']['type']);
		$this->assertSame('Draft', $mapped['supplier']['status']);
		$this->assertSame('Voorbeeld Zaken', $mapped['module']['name']);
		$this->assertSame('3.4', $mapped['version']['version']);
		$this->assertSame('Planned', $mapped['usage']['status']);
		$this->assertSame('Na de aanbesteding', $mapped['usage']['interneAnnotation']);
		$this->assertSame('Zaaksysteem; Documentbeheersysteem', $values['Referentiecomponenten']);
		$this->assertSame('1002', $values['Gebruik ID']);
	}//end testTheFixtureNormalisesToSupplierModuleVersionAndUsage()

	/**
	 * A file without the required columns is refused with every missing column named.
	 *
	 * @return void
	 */
	public function testAFileWithoutTheRequiredColumnsIsRefusedWithTheirNames(): void {
		$path = tempnam(sys_get_temp_dir(), 'vng-swc-') . '.csv';
		file_put_contents($path, "Gebruik ID;Naam;Versie\n1;Iets;1.0\n");

		try {
			(new CmdbCsvReader())->read(path: $path, profile: $this->profile());
			$this->fail('MISSING_COLUMN expected');
		} catch (CmdbImportException $e) {
			$this->assertSame(CmdbImportException::MISSING_COLUMN, $e->getErrorCode());
			$this->assertSame(['sheet' => 'Applicaties', 'column' => 'Leverancier', 'columns' => ['Leverancier', 'Pakket']], $e->getDetails());
			$this->assertSame(422, $e->getHttpStatus());
		} finally {
			unlink($path);
		}
	}//end testAFileWithoutTheRequiredColumnsIsRefusedWithTheirNames()

	/**
	 * The xlsx fixture is a workbook the reader accepts, and reads to the same rows as the CSV when PhpSpreadsheet is there.
	 *
	 * @return void
	 */
	public function testTheXlsxFixtureReadsLikeTheCsv(): void {
		$path = self::fixtures() . '/vng-swc-export.xlsx';
		$reader = new CmdbWorkbookReader();
		$reader->assertXlsx(path: $path, fileName: 'vng-swc-export.xlsx');

		if (CmdbTestSupport::loadPhpSpreadsheet() === false) {
			$this->markTestSkipped('PhpSpreadsheet not found: set OPENREGISTER_DIR to an OpenRegister app with its vendor/ installed.');
		}

		$profile = $this->profile();
		$xlsx = $reader->read(path: $path, profile: $profile)['rows'];
		$csv = (new CmdbCsvReader())->read(path: self::fixtures() . '/vng-swc-export.csv', profile: $profile)['rows'];

		$normaliser = new CmdbRowNormaliser();
		$normalise = static fn (array $row): array => array_filter(
			$normaliser->normalise(cells: $row['cells'], dateColumns: [], idColumns: $profile->idColumns()),
			static fn (string $value): bool => $value !== ''
		);
		$this->assertSame(array_map($normalise, $csv), array_map($normalise, $xlsx));
	}//end testTheXlsxFixtureReadsLikeTheCsv()

	/**
	 * A pack that maps legacyId is refused: the import sets it from the profile's id columns.
	 *
	 * @return void
	 */
	public function testAPackMayNotMapTheLegacyId(): void {
		$directory = sys_get_temp_dir() . '/stackiq-vng-swc-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import/vng-swc-*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		$pack = json_decode((string)file_get_contents($directory . '/vng-swc-usage.json'), true);
		$pack['fieldMappings'][] = ['source' => 'Gebruik ID', 'target' => 'legacyId', 'transform' => ['type' => 'trim']];
		file_put_contents($directory . '/vng-swc-usage.json', json_encode($pack));

		try {
			$this->profile(directory: $directory);
			$this->fail('MAPPING_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame(CmdbImportException::MAPPING_UNAVAILABLE, $e->getErrorCode());
			$this->assertStringContainsString('"legacyId"', $e->getMessage());
		} finally {
			array_map('unlink', glob($directory . '/*.json'));
			rmdir($directory);
		}
	}//end testAPackMayNotMapTheLegacyId()

	/**
	 * The CMDB export's profile keeps its five targets and its editable packs.
	 *
	 * @return void
	 */
	public function testTheCmdbProfileKeepsItsTargets(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this));

		$this->assertSame(CmdbImportProfile::TARGETS, $profile->targets());
		$this->assertSame(CmdbImportProfile::EDITABLE_TARGETS, $profile->editableTargets());
		$this->assertSame([], $profile->legacyIdColumns());
		$this->assertSame([], $profile->linkColumns());
		$this->assertNull($profile->csvSheetName());
	}//end testTheCmdbProfileKeepsItsTargets()
}//end class
