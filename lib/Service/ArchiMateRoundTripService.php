<?php

/**
 * ArchiMateRoundTripService
 *
 * Checks that an exchange file survives the import and export, before an
 * import or against the imported model, writing nothing
 * (architecture-round-trip-check REQ-ART-002, D2).
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
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCA\Stackiq\Service\ArchiMate\AmefRegisterId;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IAppConfig;
use RuntimeException;
use XMLReader;

/**
 * Runs the round-trip check in one of its two modes.
 *
 * - `before-import`: the import's own conversion turns the file into the
 *   objects the import would save, the export's own generation turns them
 *   back into an exchange file, and the comparator compares it with the file.
 * - `against-imported`: the stored AMEF objects whose model identifier is the
 *   file's are read back, generated into an exchange file and compared.
 *
 * Neither mode saves anything: the conversion stops before the save, and the
 * stored objects are only read.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
 */
class ArchiMateRoundTripService {

	/**
	 * Convert and generate without touching the register.
	 *
	 * @var string
	 */
	public const MODE_BEFORE_IMPORT = 'before-import';

	/**
	 * Compare with the objects stored for the file's model.
	 *
	 * @var string
	 */
	public const MODE_AGAINST_IMPORTED = 'against-imported';

	/**
	 * The object types whose schemas hold a model's objects.
	 *
	 * @var list<string>
	 */
	private const SCHEMA_TYPES = ['model', 'element', 'relationship', 'view', 'organization', 'property_definition'];

	/**
	 * Constructor.
	 *
	 * @param ArchiMateImportService        $importService The import's conversion.
	 * @param ArchiMateExportService        $exportService The export's generation and stored-object reader.
	 * @param ArchiMateModelComparator      $comparator    Compares two exchange files.
	 * @param RegisterConfigReaderInterface $settings      The AMEF register, its schemas and OpenRegister's object service.
	 * @param IAppConfig                    $config        Resolves the AMEF register id.
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	public function __construct(
		private readonly ArchiMateImportService $importService,
		private readonly ArchiMateExportService $exportService,
		private readonly ArchiMateModelComparator $comparator,
		private readonly RegisterConfigReaderInterface $settings,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * Check one exchange file in one mode.
	 *
	 * @param string $filePath The uploaded exchange file.
	 * @param string $mode     `before-import` or `against-imported`.
	 *
	 * @return array `mode`, `modelIdentifier`, `imported`, `seconds`, and for a
	 *               model that was compared the comparator's `lossless` and `categories`.
	 *
	 * @throws InvalidArgumentException When the mode is unknown or the file is missing.
	 * @throws RuntimeException         When the stored objects cannot be read.
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	public function check(string $filePath, string $mode): array {
		if (in_array($mode, [self::MODE_BEFORE_IMPORT, self::MODE_AGAINST_IMPORTED], true) === false) {
			throw new InvalidArgumentException('mode must be before-import or against-imported');
		}

		if ($filePath === '' || is_file($filePath) === false) {
			throw new InvalidArgumentException('An uploaded exchange file is required');
		}

		$started = microtime(true);
		if ($mode === self::MODE_BEFORE_IMPORT) {
			$conversion = $this->importService->convertFileToObjects(filePath: $filePath);
			return $this->report(
				head: ['mode' => $mode, 'modelIdentifier' => $conversion['modelIdentifier'], 'imported' => null],
				filePath: $filePath,
				objects: $conversion['objects'],
				started: $started
			);
		}

		$modelIdentifier = self::modelIdentifierOf(filePath: $filePath);
		$objects = [];
		if ($modelIdentifier !== '') {
			$objects = $this->storedObjectsOf(modelIdentifier: $modelIdentifier);
		}

		if ($objects === []) {
			return [
				'mode'            => $mode,
				'modelIdentifier' => $modelIdentifier,
				'imported'        => false,
				'message'         => 'The model is not imported',
				'seconds'         => round(microtime(true) - $started, 3),
			];
		}

		return $this->report(
			head: ['mode' => $mode, 'modelIdentifier' => $modelIdentifier, 'imported' => true],
			filePath: $filePath,
			objects: $objects,
			started: $started
		);
	}//end check()

	/**
	 * Generate the exchange file from the objects and compare it with the uploaded one.
	 *
	 * @param array  $head     The mode, model identifier and imported flag.
	 * @param string $filePath The uploaded exchange file.
	 * @param array  $objects  The AMEF objects.
	 * @param float  $started  When the check began.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	private function report(array $head, string $filePath, array $objects, float $started): array {
		$comparison = $this->comparator->compare(
			sourceXml: (string) file_get_contents($filePath),
			resultXml: $this->exportService->generateXmlFromObjects(objects: $objects)
		);

		return array_merge($head, $comparison, ['seconds' => round(microtime(true) - $started, 3)]);
	}//end report()

	/**
	 * The stored AMEF objects that carry this model identifier.
	 *
	 * @param string $modelIdentifier The file's model identifier.
	 *
	 * @return array
	 *
	 * @throws RuntimeException When OpenRegister or the AMEF register is not available.
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	private function storedObjectsOf(string $modelIdentifier): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$registerId = (new AmefRegisterId(config: $this->config))->resolve(amefConfig: $this->settings->getAmefConfig());
		if ($registerId === null) {
			throw new RuntimeException('The AMEF register is not configured');
		}

		$schemaIdMap = [];
		foreach (self::SCHEMA_TYPES as $type) {
			$schemaId = $this->settings->getSchemaIdForObjectType($type);
			if (empty($schemaId) === false) {
				$schemaIdMap[$schemaId] = $type;
			}
		}

		$stored = $this->exportService->getObjectsFromDatabase(objectService: $objectService, registerId: $registerId, schemaIdMap: $schemaIdMap);

		return array_values(
			array_filter(
				$stored,
				static fn ($object): bool => is_array($object) === true && (string) ($object['model_identifier'] ?? '') === $modelIdentifier
			)
		);
	}//end storedObjectsOf()

	/**
	 * The identifier on the root element of an exchange file, read without loading the whole file.
	 *
	 * @param string $filePath The exchange file.
	 *
	 * @return string The identifier, or '' when the root has none.
	 *
	 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
	 */
	public static function modelIdentifierOf(string $filePath): string {
		$reader = new XMLReader();
		if ($reader->open($filePath, null, LIBXML_NONET) === false) {
			return '';
		}

		$identifier = '';
		while ($reader->read() === true) {
			if ($reader->nodeType === XMLReader::ELEMENT) {
				$identifier = (string) $reader->getAttribute('identifier');
				break;
			}
		}

		$reader->close();

		return $identifier;
	}//end modelIdentifierOf()
}//end class
