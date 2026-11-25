<?php

/**
 * ArchiMate Round-Trip Service.
 *
 * Runs the round-trip check (architecture-round-trip-check, REQ-ART-002) in
 * one of two modes, writing nothing. Before import, it converts the uploaded
 * file with the import's own conversion, generates an exchange file from the
 * result with the export's own generation, and compares the two. Against the
 * imported model, it generates an exchange file from the stored objects that
 * carry the file's model identifier and compares that with the file.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Checks what an exchange file loses on its way through stackiq.
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
 */
class ArchiMateRoundTripService {

	/**
	 * Convert the file and generate it back, before anything is imported.
	 */
	public const MODE_BEFORE_IMPORT = 'before-import';

	/**
	 * Generate the file back from the stored objects of its model.
	 */
	public const MODE_AGAINST_IMPORTED = 'against-imported';

	/**
	 * The modes, in the order the settings page offers them.
	 */
	public const MODES = [self::MODE_BEFORE_IMPORT, self::MODE_AGAINST_IMPORTED];

	/**
	 * Constructor.
	 *
	 * @param ArchiMateImportService   $importService The import, whose conversion the check uses.
	 * @param ArchiMateExportService   $exportService The export, whose generation the check uses.
	 * @param ArchiMateModelComparator $comparator    Compares the two exchange files.
	 */
	public function __construct(
		private readonly ArchiMateImportService $importService,
		private readonly ArchiMateExportService $exportService,
		private readonly ArchiMateModelComparator $comparator = new ArchiMateModelComparator(),
	) {
	}//end __construct()

	/**
	 * Convert the file and generate it back, saving nothing, and compare.
	 *
	 * @param string $filePath Path of the uploaded exchange file.
	 *
	 * @return array<string, mixed> The mode, the model identifier, `imported` true and the comparison.
	 *
	 * @throws RuntimeException When the conversion stops.
	 *
	 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	public function checkBeforeImport(string $filePath): array {
		$converted = $this->importService->convertFileToObjects(filePath: $filePath);
		if ($converted === null) {
			throw new RuntimeException('The conversion of the file was cancelled.');
		}

		$generated = $this->exportService->generateXmlFromObjects(objects: $converted['objects']);

		return $this->report(
			mode: self::MODE_BEFORE_IMPORT,
			modelIdentifier: $converted['modelIdentifier'],
			sourceXml: $this->readFile(filePath: $filePath),
			resultXml: $generated
		);
	}//end checkBeforeImport()

	/**
	 * Generate the file back from the stored objects of its model and compare.
	 *
	 * Only the stored objects whose model identifier equals the file's take part.
	 * When none does, the answer says the model is not imported.
	 *
	 * @param string $filePath      Path of the uploaded exchange file.
	 * @param array  $storedObjects The objects the AMEF register holds, as arrays or serialisable objects.
	 *
	 * @return array<string, mixed> The mode, the model identifier, `imported` and, when imported, the comparison.
	 *
	 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	public function checkAgainstImported(string $filePath, array $storedObjects): array {
		$modelIdentifier = $this->importService->modelIdentifierOfFile(filePath: $filePath);
		$modelObjects = self::objectsOfModel(objects: $storedObjects, modelIdentifier: $modelIdentifier);

		if ($modelObjects === []) {
			return [
				'mode' => self::MODE_AGAINST_IMPORTED,
				'modelIdentifier' => $modelIdentifier,
				'imported' => false,
				'message' => 'This model is not imported.',
			];
		}

		return $this->report(
			mode: self::MODE_AGAINST_IMPORTED,
			modelIdentifier: $modelIdentifier,
			sourceXml: $this->readFile(filePath: $filePath),
			resultXml: $this->exportService->generateXmlFromObjects(objects: $modelObjects)
		);
	}//end checkAgainstImported()

	/**
	 * The stored objects that belong to one model.
	 *
	 * @param array  $objects         The stored objects, as arrays or serialisable objects.
	 * @param string $modelIdentifier The model identifier the file carries.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	public static function objectsOfModel(array $objects, string $modelIdentifier): array {
		$ofModel = [];
		foreach ($objects as $object) {
			if ($object instanceof \JsonSerializable) {
				$object = $object->jsonSerialize();
			}

			if (is_array($object) === true && ($object['model_identifier'] ?? null) === $modelIdentifier) {
				$ofModel[] = $object;
			}
		}

		return $ofModel;
	}//end objectsOfModel()

	/**
	 * The answer for one compared pair of files.
	 *
	 * @param string $mode            The mode that ran.
	 * @param string $modelIdentifier The model identifier of the file.
	 * @param string $sourceXml       The uploaded file.
	 * @param string $resultXml       The file generated back.
	 *
	 * @return array<string, mixed>
	 */
	private function report(string $mode, string $modelIdentifier, string $sourceXml, string $resultXml): array {
		$comparison = $this->comparator->compare(sourceXml: $sourceXml, resultXml: $resultXml);

		return [
			'mode' => $mode,
			'modelIdentifier' => $modelIdentifier,
			'imported' => true,
			'lossless' => $comparison['lossless'],
			'categories' => $comparison['categories'],
		];
	}//end report()

	/**
	 * The contents of the uploaded file.
	 *
	 * @param string $filePath Path of the file.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the file cannot be read.
	 */
	private function readFile(string $filePath): string {
		$contents = false;
		if (is_file($filePath) === true) {
			$contents = file_get_contents($filePath);
		}

		if ($contents === false) {
			throw new InvalidArgumentException('The exchange file cannot be read.');
		}

		return $contents;
	}//end readFile()
}//end class
