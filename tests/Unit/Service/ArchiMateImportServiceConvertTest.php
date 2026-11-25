<?php

/**
 * The import and the round-trip check share one conversion, and the export and the check one generation.
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
use OCA\Stackiq\Tests\Unit\Support\BuildsArchiMateImportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 * @covers \OCA\Stackiq\Service\ArchiMateExportService
 */
final class ArchiMateImportServiceConvertTest extends TestCase {
	use BuildsArchiMateImportService;

	/**
	 * The golden test model: elements, relationships, views, organisations and property definitions.
	 */
	private const FIXTURE = __DIR__ . '/../../data/archimate_golden.xml';

	/**
	 * The objects the import handed to saveObjects, over all calls.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * An object service that records the saves and finds nothing.
	 *
	 * @return ObjectServiceInterface
	 */
	private function recordingObjects(): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('saveObjects')->willReturnCallback(
			function (array $batch): array {
				foreach ($batch as $object) {
					$this->saved[] = $object;
				}

				return ['saved' => [], 'updated' => [], 'unchanged' => [], 'invalid' => []];
			}
		);
		$objects->method('searchObjects')->willReturn([]);

		return $objects;
	}//end recordingObjects()

	/**
	 * Objects in a stable order, so two lists compare regardless of how the saves were batched.
	 *
	 * @param list<array<string, mixed>> $objects The objects.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function sorted(array $objects): array {
		usort(
			array: $objects,
			callback: fn (array $left, array $right): int => [$left['section'] ?? '', $left['identifier'] ?? '']
				<=> [$right['section'] ?? '', $right['identifier'] ?? '']
		);

		return $objects;
	}//end sorted()

	/**
	 * The optimised import saves exactly the objects convertFileToObjects builds.
	 *
	 * @return void
	 */
	public function testTheImportSavesWhatTheConversionBuilds(): void {
		$result = $this->importService(objectService: $this->recordingObjects())
			->importArchiMateFileFromPathOptimized(options: ['file_path' => self::FIXTURE]);
		$this->assertTrue(condition: $result['success'], message: (string) ($result['error'] ?? ''));

		$converted = $this->importService(objectService: $this->createMock(originalClassName: ObjectServiceInterface::class))
			->convertFileToObjects(filePath: self::FIXTURE);

		$this->assertNotNull(actual: $converted);
		$this->assertNotSame(expected: [], actual: $converted['objects']);
		$this->assertSame(expected: self::sorted(objects: $this->saved), actual: self::sorted(objects: $converted['objects']));
		$this->assertSame(
			expected: ['parse', 'memoryCleanup', 'modelIdentifier', 'transform'],
			actual: array_keys(array: $converted['timings'])
		);
	}//end testTheImportSavesWhatTheConversionBuilds()

	/**
	 * The conversion writes nothing.
	 *
	 * @return void
	 */
	public function testTheConversionSavesNothing(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('saveObjects');

		$converted = $this->importService(objectService: $objects)->convertFileToObjects(filePath: self::FIXTURE);

		$this->assertNotNull(actual: $converted);
		foreach ($converted['objects'] as $object) {
			$this->assertSame(expected: $converted['modelIdentifier'], actual: $object['model_identifier']);
		}
	}//end testTheConversionSavesNothing()

	/**
	 * The model identifier read on its own matches the one the conversion uses.
	 *
	 * @return void
	 */
	public function testTheModelIdentifierOfAFileMatchesTheConversion(): void {
		$service = $this->importService(objectService: $this->createMock(originalClassName: ObjectServiceInterface::class));

		$converted = $service->convertFileToObjects(filePath: self::FIXTURE);

		$this->assertNotNull(actual: $converted);
		$this->assertSame(expected: $converted['modelIdentifier'], actual: $service->modelIdentifierOfFile(filePath: self::FIXTURE));
	}//end testTheModelIdentifierOfAFileMatchesTheConversion()

	/**
	 * The full export generates its file with generateXmlFromObjects.
	 *
	 * @return void
	 */
	public function testTheExportGeneratesWithTheSharedGeneration(): void {
		$converted = $this->importService(objectService: $this->createMock(originalClassName: ObjectServiceInterface::class))
			->convertFileToObjects(filePath: self::FIXTURE);
		$this->assertNotNull(actual: $converted);

		$bySchema = [];
		$schemaIdMap = [];
		foreach ($converted['objects'] as $object) {
			$bySchema[$object['@self']['schema']][] = $object;
			$schemaIdMap[$object['@self']['schema']] = $object['section'];
		}

		$stored = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$stored->method('searchObjects')->willReturnCallback(
			fn (array $query): array => $bySchema[$query['@self']['schema']] ?? []
		);

		$export = new ArchiMateExportService(logger: new NullLogger());
		$exported = $export->exportArchiMateXml(objectService: $stored, registerId: 7, schemaIdMap: $schemaIdMap);
		$generated = $export->generateXmlFromObjects(
			objects: $export->getObjectsFromDatabase(objectService: $stored, registerId: 7, schemaIdMap: $schemaIdMap)
		);

		$this->assertSame(expected: $generated, actual: $exported);
	}//end testTheExportGeneratesWithTheSharedGeneration()
}//end class
