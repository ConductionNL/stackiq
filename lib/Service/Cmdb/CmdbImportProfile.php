<?php

/**
 * CMDB import profile.
 *
 * Loads `lib/Settings/cmdb-import/topdesk-profile.json` and the six
 * migration packs it names, validates every pack with OpenRegister's
 * `MigrationPack\PackDefinitionValidator`, and answers the questions the
 * reader, the normaliser and the import service ask about the export: which
 * sheets, which columns are required, which are dates or ids, and which
 * columns may be read at all (the allowlist, design D3).
 *
 * `PackDefinitionValidator` is not part of OpenRegister's `Contract`
 * namespace, so it is resolved defensively. When it is missing, or a shipped
 * pack is invalid, loading throws `MAPPING_UNAVAILABLE` (503) before any
 * row is read (REQ-CMDB-005).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The validated import profile plus its packs.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One small accessor per profile setting, so
 * callers never read the raw JSON.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The accessors each guard against a
 * malformed profile value; the sum passes the threshold, no single method is complex.
 */
class CmdbImportProfile {
	/**
	 * OpenRegister's pack validator (not a public contract).
	 */
	public const VALIDATOR_CLASS = 'OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator';

	/**
	 * The targets every profile must name a pack for.
	 *
	 * @var array<int, string>
	 */
	public const TARGETS = ['module', 'manufacturer', 'municipality', 'usage', 'businessOwner', 'technicalOwner'];

	/**
	 * Default upload limit when the profile file cannot be read (10 MB).
	 */
	public const DEFAULT_MAX_FILE_BYTES = 10485760;

	/**
	 * Sources of the municipality pack that come from the request, not from a sheet.
	 *
	 * @var array<int, string>
	 */
	private const OPTION_SOURCES = ['municipalityName'];

	/**
	 * The decoded profile, once loaded.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $profile = null;

	/**
	 * The validated packs per target, once loaded.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $packs = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's validator.
	 * @param string|null $directory Directory of the profile and packs; null is the shipped one.
	 * @param string $profileFile File name of the profile inside the directory.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private ?string $directory = null,
		private readonly string $profileFile = 'topdesk-profile.json',
	) {
		if ($this->directory === null) {
			$this->directory = __DIR__ . '/../../Settings/cmdb-import';
		}
	}//end __construct()

	/**
	 * Load the profile and validate every pack it names.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the validator is missing,
	 *                             or the profile or a pack is unreadable or invalid.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function load(): void {
		$validator = $this->resolveValidator();
		$profile = $this->decodeFile(fileName: $this->profileFile);

		$packs = [];
		foreach (self::TARGETS as $target) {
			$fileName = $profile['packs'][$target] ?? null;
			if (is_string($fileName) === false || $fileName === '' || basename($fileName) !== $fileName) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
					message: 'CMDB import profile names no pack for target ' . $target
				);
			}

			$pack = $this->decodeFile(fileName: $fileName);
			$errors = $validator->validate($pack);
			if (is_array($errors) === true && $errors !== []) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
					message: 'CMDB mapping pack ' . $fileName . ' is invalid: ' . implode('; ', array_map('strval', $errors))
				);
			}

			$packs[$target] = $pack;
		}

		$this->profile = $profile;
		$this->packs = $packs;
	}//end load()

	/**
	 * The upload limit in bytes, readable without validating the packs.
	 *
	 * The controller checks the size before anything else, so this must not
	 * depend on OpenRegister being available.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function maxFileBytes(): int {
		try {
			$profile = $this->profile ?? $this->decodeFile(fileName: $this->profileFile);
		} catch (CmdbImportException $e) {
			return self::DEFAULT_MAX_FILE_BYTES;
		}

		$limit = $profile['maxFileBytes'] ?? null;
		if (is_int($limit) === true && $limit > 0) {
			return $limit;
		}

		return self::DEFAULT_MAX_FILE_BYTES;
	}//end maxFileBytes()

	/**
	 * The maximum number of non-empty rows per source sheet.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function maxRowsPerSheet(): int {
		$limit = $this->profile()['maxRowsPerSheet'] ?? 10000;
		if (is_int($limit) === false || $limit < 0) {
			return 10000;
		}

		return $limit;
	}//end maxRowsPerSheet()

	/**
	 * The source sheets with the Soort values each accepts.
	 *
	 * @return array<int, array{name: string, acceptedKinds: array<int, string>}>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function sheets(): array {
		$sheets = [];
		foreach (($this->profile()['sheets'] ?? []) as $sheet) {
			if (is_array($sheet) === false || is_string($sheet['name'] ?? null) === false) {
				continue;
			}

			$sheets[] = [
				'name' => $sheet['name'],
				'acceptedKinds' => array_values(array_map('strval', ($sheet['acceptedKinds'] ?? []))),
			];
		}

		return $sheets;
	}//end sheets()

	/**
	 * The names of the source sheets.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function sheetNames(): array {
		return array_column($this->sheets(), 'name');
	}//end sheetNames()

	/**
	 * The Soort values a sheet accepts.
	 *
	 * @param string $sheetName The sheet name.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function acceptedKinds(string $sheetName): array {
		foreach ($this->sheets() as $sheet) {
			if ($sheet['name'] === $sheetName) {
				return $sheet['acceptedKinds'];
			}
		}

		return [];
	}//end acceptedKinds()

	/**
	 * The match key column ("Middel-ID").
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function keyColumn(): string {
		return (string)($this->profile()['keyColumn'] ?? 'Middel-ID');
	}//end keyColumn()

	/**
	 * The application name column ("Naam").
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function nameColumn(): string {
		return (string)($this->profile()['nameColumn'] ?? 'Naam');
	}//end nameColumn()

	/**
	 * The row-kind column ("Soort").
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function kindColumn(): string {
		return (string)($this->profile()['kindColumn'] ?? 'Soort');
	}//end kindColumn()

	/**
	 * Columns whose absence stops the import with MISSING_COLUMN.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function requiredColumns(): array {
		return $this->stringList(key: 'requiredColumns');
	}//end requiredColumns()

	/**
	 * Columns that hold Excel serial dates.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function dateColumns(): array {
		return $this->stringList(key: 'dateColumns');
	}//end dateColumns()

	/**
	 * Columns that hold identifiers which must not carry a decimal part.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function idColumns(): array {
		return $this->stringList(key: 'idColumns');
	}//end idColumns()

	/**
	 * The prefix of the module match key ("topdesk").
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function externalKeyPrefix(): string {
		return (string)($this->profile()['externalKeyPrefix'] ?? 'topdesk');
	}//end externalKeyPrefix()

	/**
	 * The validated pack for a target.
	 *
	 * @param string $target One of self::TARGETS.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function pack(string $target): array {
		$this->profile();
		return ($this->packs[$target] ?? []);
	}//end pack()

	/**
	 * Values set on create only, per target, as field => value.
	 *
	 * @param string $target The target.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function createOnlyDefaults(string $target): array {
		$value = $this->profile()['createOnly'][$target] ?? [];
		if (is_array($value) === false || array_is_list($value) === true) {
			return [];
		}

		return $value;
	}//end createOnlyDefaults()

	/**
	 * Mapped fields written on create, or on update only when the stored value is empty.
	 *
	 * @param string $target The target.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function createOnlyFields(string $target): array {
		$value = $this->profile()['createOnly'][$target] ?? [];
		if (is_array($value) === false) {
			return [];
		}

		if (array_is_list($value) === true) {
			return array_values(array_map('strval', $value));
		}

		return array_map('strval', array_keys($value));
	}//end createOnlyFields()

	/**
	 * Fields the import never writes on an existing object.
	 *
	 * @param string $target The target.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function neverWrittenOnUpdate(string $target): array {
		$value = $this->profile()['neverWritten'][$target] ?? [];
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_map('strval', $value));
	}//end neverWrittenOnUpdate()

	/**
	 * The accepted values of the missingRecords option.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function missingRecordsModes(): array {
		$modes = $this->stringList(key: 'missingRecords');
		if ($modes === []) {
			return ['keep'];
		}

		return $modes;
	}//end missingRecordsModes()

	/**
	 * Every column the profile or a pack references: the read allowlist.
	 *
	 * The municipality pack maps the request options, not a sheet, so its
	 * sources are left out.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function referencedColumns(): array {
		$columns = array_merge(
			[$this->keyColumn(), $this->nameColumn(), $this->kindColumn()],
			$this->requiredColumns(),
			$this->dateColumns(),
			$this->idColumns()
		);

		foreach (self::TARGETS as $target) {
			foreach (($this->pack(target: $target)['fieldMappings'] ?? []) as $mapping) {
				$columns[] = (string)($mapping['source'] ?? '');
				foreach (($mapping['transform']['fields'] ?? []) as $extra) {
					$columns[] = (string)$extra;
				}
			}
		}

		$columns = array_filter(
			$columns,
			fn (string $column): bool => $column !== '' && $column[0] !== '/' && in_array($column, self::OPTION_SOURCES, true) === false
		);

		return array_values(array_unique($columns));
	}//end referencedColumns()

	/**
	 * The loaded profile.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when load() failed.
	 */
	private function profile(): array {
		if ($this->profile === null) {
			$this->load();
		}

		return ($this->profile ?? []);
	}//end profile()

	/**
	 * A list of strings from the profile.
	 *
	 * @param string $key The profile key.
	 *
	 * @return array<int, string>
	 */
	private function stringList(string $key): array {
		$value = $this->profile()[$key] ?? [];
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_map('strval', $value));
	}//end stringList()

	/**
	 * Resolve OpenRegister's pack validator.
	 *
	 * @return object The validator, with a `validate(array): array` method.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when it is not available.
	 */
	private function resolveValidator(): object {
		$class = static::VALIDATOR_CLASS;

		try {
			if ($this->container->has($class) === true) {
				$validator = $this->container->get($class);
				if (is_object($validator) === true && method_exists($validator, 'validate') === true) {
					return $validator;
				}
			}
		} catch (Throwable $e) {
			// Fall through to the class check below.
			$validator = null;
		}

		if (class_exists($class) === true) {
			$validator = new $class();
			if (method_exists($validator, 'validate') === true) {
				return $validator;
			}
		}

		throw new CmdbImportException(
			errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
			message: 'OpenRegister PackDefinitionValidator is not available'
		);
	}//end resolveValidator()

	/**
	 * Read and decode one JSON file from the profile directory.
	 *
	 * @param string $fileName The file name.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the file is missing or not a JSON object.
	 */
	private function decodeFile(string $fileName): array {
		$path = $this->directory . '/' . $fileName;
		$content = false;
		if (is_readable($path) === true) {
			$content = file_get_contents($path);
		}

		$decoded = null;
		if (is_string($content) === true) {
			$decoded = json_decode($content, true);
		}

		if (is_array($decoded) === false) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'CMDB import file ' . $fileName . ' is missing or not valid JSON'
			);
		}

		return $decoded;
	}//end decodeFile()
}//end class
