<?php

/**
 * Tests for the CMDB import profile's fallbacks on missing or malformed profile values.
 *
 * Every getter of the profile reads a hand-edited JSON file, so each one
 * answers a safe default for a value of the wrong type or range instead of
 * passing it on.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Loads the shipped profile with overridden keys and reads the getters.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbImportProfile
 * @uses   \OCA\Stackiq\Exception\CmdbImportException
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackRules
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackStore
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackStoreProbe
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPackLoadRun
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPackOrigin
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbRegisterSchemaFields
 * @uses   \OCA\Stackiq\Service\Cmdb\OpenRegisterInstance
 */
class CmdbImportProfileFallbacksTest extends TestCase {
	private const MANAGED = 'Beheerde Applicaties CMDB';
	private const UNMANAGED = 'Onbeh Applicaties CMDB';

	/**
	 * Profile directories to remove after the test.
	 *
	 * @var array<int, string>
	 */
	private array $directories = [];

	/**
	 * Remove the profile directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->directories as $directory) {
			CmdbTestSupport::removeDirectory(directory: $directory);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The shipped profile with keys overridden, loaded.
	 *
	 * @param array<string, mixed> $overrides Profile key => value.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(array $overrides): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		$directory = CmdbTestSupport::profileDirectory(overrides: $overrides);
		$this->directories[] = $directory;

		$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this), directory: $directory);
		$profile->load();
		return $profile;
	}//end profile()

	/**
	 * The byte and count limits, each with its default.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function limits(): array {
		return [
			'file bytes' => ['maxFileBytes', 'maxFileBytes', CmdbImportProfile::DEFAULT_MAX_FILE_BYTES],
			'uncompressed bytes' => ['maxUncompressedBytes', 'maxUncompressedBytes', CmdbImportProfile::DEFAULT_MAX_UNCOMPRESSED_BYTES],
			'part bytes' => ['maxPartBytes', 'maxPartBytes', CmdbImportProfile::DEFAULT_MAX_PART_BYTES],
			'shared strings' => ['maxSharedStrings', 'maxSharedStrings', CmdbImportProfile::DEFAULT_MAX_SHARED_STRINGS],
			'referenced string bytes' => ['maxReferencedStringBytes', 'maxReferencedStringBytes', CmdbImportProfile::DEFAULT_MAX_REFERENCED_STRING_BYTES],
		];
	}//end limits()

	/**
	 * A limit that is not a positive integer falls back to its default; a positive one is kept.
	 *
	 * @param string $key The profile key.
	 * @param string $method The getter.
	 * @param int $default The default.
	 *
	 * @return void
	 */
	#[DataProvider('limits')]
	public function testALimitThatIsNotAPositiveIntegerFallsBackToItsDefault(string $key, string $method, int $default): void {
		$this->assertSame($default, $this->profile(overrides: [$key => '5000'])->$method());
		$this->assertSame($default, $this->profile(overrides: [$key => 0])->$method());
		$this->assertSame($default, $this->profile(overrides: [$key => -1])->$method());
		$this->assertSame(4321, $this->profile(overrides: [$key => 4321])->$method());
	}//end testALimitThatIsNotAPositiveIntegerFallsBackToItsDefault()

	/**
	 * The row limit falls back to 10000 when it is not an integer or negative; 0 is kept.
	 *
	 * @return void
	 */
	public function testTheRowLimitFallsBackOnlyWhenNotANonNegativeInteger(): void {
		$this->assertSame(10000, $this->profile(overrides: ['maxRowsPerSheet' => '7'])->maxRowsPerSheet());
		$this->assertSame(10000, $this->profile(overrides: ['maxRowsPerSheet' => -1])->maxRowsPerSheet());
		$this->assertSame(0, $this->profile(overrides: ['maxRowsPerSheet' => 0])->maxRowsPerSheet());
	}//end testTheRowLimitFallsBackOnlyWhenNotANonNegativeInteger()

	/**
	 * Sheet entries without a string name are skipped; non-scalar constants are dropped; absent columns become strings.
	 *
	 * @return void
	 */
	public function testMalformedSheetEntriesAreSkippedOrCleaned(): void {
		$profile = $this->profile(
			overrides: [
				'sheets' => [
					'not a sheet',
					['name' => 12],
					['name' => self::MANAGED, 'constants' => ['Beheer' => 'Ja', 'Lijst' => ['x'], 'Getal' => 3], 'absentColumns' => [5, 'Vendor']],
					['name' => self::UNMANAGED, 'constants' => 'Nee'],
				],
			]
		);

		$this->assertSame(
			[
				['name' => self::MANAGED, 'constants' => ['Beheer' => 'Ja', 'Getal' => '3'], 'absentColumns' => ['5', 'Vendor']],
				['name' => self::UNMANAGED, 'constants' => [], 'absentColumns' => []],
			],
			$profile->sheets()
		);
		$this->assertSame(['Beheer', 'Getal'], $profile->constantColumns());
		$this->assertSame([], $profile->sheetConstants(sheetName: 'Onbekend'));
		$this->assertSame([], $profile->absentColumns(sheetName: 'Onbekend'));
	}//end testMalformedSheetEntriesAreSkippedOrCleaned()

	/**
	 * An archive sheet that is empty, not a string or also a source sheet is no archive sheet.
	 *
	 * @return void
	 */
	public function testAnArchiveSheetNameMustBeAnotherNonEmptyString(): void {
		$this->assertNull($this->profile(overrides: ['archiveSheet' => ['name' => '']])->archiveSheetName());
		$this->assertNull($this->profile(overrides: ['archiveSheet' => ['name' => 7]])->archiveSheetName());

		$sameAsSource = $this->profile(overrides: ['archiveSheet' => ['name' => self::MANAGED]]);
		$this->assertNull($sameAsSource->archiveSheetName());
		$this->assertSame([self::UNMANAGED, self::MANAGED], $sameAsSource->readSheetNames());
	}//end testAnArchiveSheetNameMustBeAnotherNonEmptyString()

	/**
	 * A sheet the precedence does not list ranks after every listed one; a malformed precedence leaves profile order.
	 *
	 * @return void
	 */
	public function testSheetRankFallsBackToProfileOrder(): void {
		$listed = $this->profile(overrides: ['sheetPrecedence' => [self::MANAGED]]);
		$this->assertSame(0, $listed->sheetRank(sheetName: self::MANAGED));
		$this->assertSame(1, $listed->sheetRank(sheetName: self::UNMANAGED));
		$this->assertSame(2, $listed->sheetRank(sheetName: 'Onbekend'));

		$malformed = $this->profile(overrides: ['sheetPrecedence' => 'Beheerde Applicaties CMDB']);
		$this->assertSame(0, $malformed->sheetRank(sheetName: self::UNMANAGED));
		$this->assertSame(1, $malformed->sheetRank(sheetName: self::MANAGED));
	}//end testSheetRankFallsBackToProfileOrder()

	/**
	 * Empty values must be an object of lists; anything else is ignored.
	 *
	 * @return void
	 */
	public function testEmptyValuesIgnoreMalformedEntries(): void {
		$this->assertSame([], $this->profile(overrides: ['emptyValues' => 'NB'])->emptyValues());
		$this->assertSame(
			['BNN Classificatie' => ['NB', '0']],
			$this->profile(overrides: ['emptyValues' => ['BNN Classificatie' => ['NB', 0], 'Vendor' => 'NB']])->emptyValues()
		);
	}//end testEmptyValuesIgnoreMalformedEntries()

	/**
	 * Create-only and never-written fields that are not lists read as none.
	 *
	 * @return void
	 */
	public function testFieldListsThatAreNotListsReadAsNone(): void {
		$profile = $this->profile(overrides: ['createOnly' => ['application' => 'name'], 'neverWritten' => ['application' => 'status']]);

		$this->assertSame([], $profile->createOnlyFields(target: 'application'));
		$this->assertSame([], $profile->neverWrittenOnUpdate(target: 'application'));
	}//end testFieldListsThatAreNotListsReadAsNone()

	/**
	 * The missing-records modes fall back to "keep" when absent, empty or not a list, and are strings otherwise.
	 *
	 * @return void
	 */
	public function testMissingRecordsModesFallBackToKeep(): void {
		$this->assertSame(['keep'], $this->profile(overrides: ['missingRecords' => 'archive'])->missingRecordsModes());
		$this->assertSame(['keep'], $this->profile(overrides: ['missingRecords' => []])->missingRecordsModes());
		$this->assertSame(['archive', '1'], $this->profile(overrides: ['missingRecords' => ['archive', 1]])->missingRecordsModes());
	}//end testMissingRecordsModesFallBackToKeep()
}//end class
