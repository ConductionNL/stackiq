<?php

/**
 * The round-trip check in its two modes.
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
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsArchiMateImportService.php';

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateModelComparator;
use OCA\Stackiq\Service\ArchiMateRoundTripService;
use OCA\Stackiq\Tests\Unit\Support\BuildsArchiMateImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Stackiq\Service\ArchiMateRoundTripService
 */
final class ArchiMateRoundTripServiceTest extends TestCase {
	use BuildsArchiMateImportService;

	/**
	 * The golden test model, model identifier id-model-golden.
	 */
	private const FIXTURE = __DIR__ . '/../../data/archimate_golden.xml';

	/**
	 * A round-trip service whose object service fails the test on any save.
	 *
	 * @return ArchiMateRoundTripService
	 */
	private function service(): ArchiMateRoundTripService {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('saveObjects');
		$objects->expects($this->never())->method('saveObject');

		return new ArchiMateRoundTripService(
			importService: $this->importService(objectService: $objects),
			exportService: new ArchiMateExportService(logger: new NullLogger())
		);
	}//end service()

	/**
	 * The objects an import of the fixture would store.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function storedObjects(): array {
		$converted = $this->importService(objectService: $this->createMock(originalClassName: ObjectServiceInterface::class))
			->convertFileToObjects(filePath: self::FIXTURE);
		$this->assertNotNull(actual: $converted);

		return $converted['objects'];
	}//end storedObjects()

	/**
	 * Before import, every category is reported and nothing is saved.
	 *
	 * @return void
	 */
	public function testBeforeImportReportsEveryCategoryAndSavesNothing(): void {
		$report = $this->service()->checkBeforeImport(filePath: self::FIXTURE);

		$this->assertSame(expected: ArchiMateRoundTripService::MODE_BEFORE_IMPORT, actual: $report['mode']);
		$this->assertSame(expected: 'id-model-golden', actual: $report['modelIdentifier']);
		$this->assertTrue(condition: $report['imported']);
		$this->assertIsBool(actual: $report['lossless']);
		$this->assertSame(expected: ArchiMateModelComparator::CATEGORIES, actual: array_keys(array: $report['categories']));
		$this->assertGreaterThan(expected: 0, actual: $report['categories']['elements']['source']);
		foreach ($report['categories'] as $category) {
			$this->assertSame(expected: ['source', 'result', 'missing', 'extra', 'changed', 'examples'], actual: array_keys(array: $category));
		}
	}//end testBeforeImportReportsEveryCategoryAndSavesNothing()

	/**
	 * Against the imported model, only the stored objects of the file's model take part.
	 *
	 * @return void
	 */
	public function testAgainstImportedComparesOnlyTheObjectsOfTheModel(): void {
		$stored = $this->storedObjects();
		$own = $this->service()->checkAgainstImported(filePath: self::FIXTURE, storedObjects: $stored);

		$foreign = [
			'section' => 'element',
			'identifier' => 'id-foreign-element',
			'model_identifier' => 'id-another-model',
			'name' => 'From another model',
			'type' => 'ApplicationComponent',
			'@self' => ['schema' => 11],
		];
		$mixed = $this->service()->checkAgainstImported(filePath: self::FIXTURE, storedObjects: [...$stored, $foreign]);

		$this->assertSame(expected: ArchiMateRoundTripService::MODE_AGAINST_IMPORTED, actual: $mixed['mode']);
		$this->assertTrue(condition: $mixed['imported']);
		$this->assertSame(expected: $own['categories'], actual: $mixed['categories']);
		$this->assertSame(expected: ArchiMateModelComparator::CATEGORIES, actual: array_keys(array: $mixed['categories']));
	}//end testAgainstImportedComparesOnlyTheObjectsOfTheModel()

	/**
	 * With no stored object of the file's model, the answer says it is not imported.
	 *
	 * @return void
	 */
	public function testAModelThatWasNeverImportedIsNamedAsSuch(): void {
		$other = ['section' => 'element', 'identifier' => 'id-x', 'model_identifier' => 'id-another-model'];

		$report = $this->service()->checkAgainstImported(filePath: self::FIXTURE, storedObjects: [$other]);

		$this->assertSame(
			expected: [
				'mode' => ArchiMateRoundTripService::MODE_AGAINST_IMPORTED,
				'modelIdentifier' => 'id-model-golden',
				'imported' => false,
				'message' => 'This model is not imported.',
			],
			actual: $report
		);
	}//end testAModelThatWasNeverImportedIsNamedAsSuch()

	/**
	 * Stored objects are matched as arrays or as serialisable entities.
	 *
	 * @return void
	 */
	public function testObjectsOfModelReadsArraysAndSerialisableObjects(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['identifier' => 'id-b', 'model_identifier' => 'm1'];
			}
		};

		$ofModel = ArchiMateRoundTripService::objectsOfModel(
			objects: [['identifier' => 'id-a', 'model_identifier' => 'm1'], ['identifier' => 'id-c', 'model_identifier' => 'm2'], $entity, 'not an object'],
			modelIdentifier: 'm1'
		);

		$this->assertSame(expected: ['id-a', 'id-b'], actual: array_column(array: $ofModel, column_key: 'identifier'));
	}//end testObjectsOfModelReadsArraysAndSerialisableObjects()

	/**
	 * A file that cannot be read is refused.
	 *
	 * @return void
	 */
	public function testAMissingFileIsRefused(): void {
		$this->expectException(exception: \Throwable::class);

		$this->service()->checkBeforeImport(filePath: '/nonexistent/model.xml');
	}//end testAMissingFileIsRefused()
}//end class
