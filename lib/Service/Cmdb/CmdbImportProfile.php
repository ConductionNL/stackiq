<?php

/**
 * CMDB import profile.
 *
 * Loads `lib/Settings/cmdb-import/topdesk-profile.json` and the five
 * migration packs it names, validates every pack with OpenRegister's
 * `MigrationPack\PackDefinitionValidator`, and answers the questions the
 * reader, the normaliser and the import service ask about the export: which
 * sheets, which columns are required, which are dates or ids, which values
 * mean empty, which constants a sheet adds to its rows, and which columns
 * may be read at all (the allowlist, design D3).
 *
 * The four sheet packs come from OpenRegister's migration-pack store when it
 * is available (CmdbImportPackStore), so an administrator's edit there is
 * what the import runs; the shipped files are the seed. The municipality
 * pack maps the request options and always comes from its file. A pack from
 * either source must pass the validator and the import's own rules
 * (CmdbImportPackRules).
 *
 * `PackDefinitionValidator` is not part of OpenRegister's `Contract`
 * namespace, so it is resolved defensively. When it is missing, a pack is
 * invalid or breaks a rule, or the store that holds the packs is gone,
 * loading throws `MAPPING_UNAVAILABLE` (503) before any row is read
 * (REQ-CMDB-005, REQ-CMDB-021).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
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
 * @SuppressWarnings(PHPMD.TooManyMethods) The same accessors, plus the loader's small private helpers.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The accessors each guard against a
 * malformed profile value; the sum passes the threshold, no single method is complex.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The same accessors with their docblocks, and the
 * loader that picks each pack's source; the store, the marker and the import rules are classes of their own.
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
	public const TARGETS = ['module', 'manufacturer', 'municipality', 'usage', 'businessOwner'];

	/**
	 * Default upload limit when the profile file cannot be read (10 MB).
	 */
	public const DEFAULT_MAX_FILE_BYTES = 10485760;

	/**
	 * Default limit on the unpacked size of a workbook (50 MB).
	 */
	public const DEFAULT_MAX_UNCOMPRESSED_BYTES = 52428800;

	/**
	 * Default limit on the unpacked size of any one part of a workbook (10 MB).
	 */
	public const DEFAULT_MAX_PART_BYTES = 10485760;

	/**
	 * Default limit on the number of entries in a workbook's shared-strings table.
	 */
	public const DEFAULT_MAX_SHARED_STRINGS = 200000;

	/**
	 * Default limit on the shared-string text a workbook's cells reference together (64 MB).
	 */
	public const DEFAULT_MAX_REFERENCED_STRING_BYTES = 67108864;

	/**
	 * The targets whose pack an administrator edits in OpenRegister.
	 *
	 * The municipality pack maps the request options, not a sheet, so it
	 * stays a shipped file.
	 *
	 * @var array<int, string>
	 */
	public const EDITABLE_TARGETS = ['module', 'manufacturer', 'usage', 'businessOwner'];

	/**
	 * Sources of the municipality pack that come from the request, not from a sheet.
	 *
	 * @var array<int, string>
	 */
	private const OPTION_SOURCES = ['municipalityName'];

	/**
	 * The separator of a concat mapping when the pack names none (OpenRegister's default).
	 */
	private const DEFAULT_CONCAT_SEPARATOR = ' ';

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
	 * Where each loaded pack came from, per target.
	 *
	 * @var array<string, array<string, mixed>> Per target: source, packId, storedVersion, shippedVersion, edited, newerShipped.
	 */
	private array $sources = [];

	/**
	 * Whether OpenRegister's pack store was available when the packs were loaded.
	 *
	 * @var bool
	 */
	private bool $storeAvailable = false;

	/**
	 * The import rules every pack must follow.
	 *
	 * @var CmdbImportPackRules
	 */
	private readonly CmdbImportPackRules $rules;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's validator.
	 * @param string|null $directory Directory of the profile and packs; null is the shipped one.
	 * @param string $profileFile File name of the profile inside the directory.
	 * @param CmdbImportPackStore|null $packStore OpenRegister's pack store; null builds one on the container.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private ?string $directory = null,
		private readonly string $profileFile = 'topdesk-profile.json',
		private ?CmdbImportPackStore $packStore = null,
	) {
		if ($this->directory === null) {
			$this->directory = __DIR__ . '/../../Settings/cmdb-import';
		}

		$this->rules = new CmdbImportPackRules();
	}//end __construct()

	/**
	 * Load the profile and validate every pack it names.
	 *
	 * Each sheet pack is taken from OpenRegister's pack store when the store
	 * is available, seeded from its shipped file when it is missing there, and
	 * otherwise from the shipped file. Once the packs were stored in
	 * OpenRegister, a store that is gone fails closed: the import never falls
	 * back to the files and silently drops an administrator's edit.
	 *
	 * The import run and the seed step load this way; viewing the mapping
	 * uses loadForView(), which writes nothing.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the validator is missing, the profile
	 *                             or a pack is unreadable, invalid or breaks an import rule, or
	 *                             the store that holds the packs is not available.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function load(): void {
		$this->loadPacks(storedPack: fn (CmdbImportPackStore $store, array $shipped): array => $store->resolve(shipped: $shipped));
	}//end load()

	/**
	 * Load the profile and validate every pack it names, reading the store only.
	 *
	 * A pack the store does not hold yet is taken from its shipped file, and
	 * nothing is seeded, updated or recorded: viewing the mapping changes
	 * neither OpenRegister nor stackiq's app config. The next import run
	 * seeds and updates as load() does.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE as load().
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
	 */
	public function loadForView(): void {
		$this->loadPacks(storedPack: fn (CmdbImportPackStore $store, array $shipped): ?array => $store->find(shipped: $shipped));
	}//end loadForView()

	/**
	 * Load the profile and the packs, taking each editable pack from the store as the callback answers it.
	 *
	 * @param callable $storedPack (store, shipped pack) => the stored pack, or null to use the shipped file.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE as load().
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function loadPacks(callable $storedPack): void {
		$validator = $this->resolveValidator();
		$profile = $this->decodeFile(fileName: $this->profileFile);
		$store = $this->packStore();
		$storeAvailable = $store->isAvailable();
		if ($storeAvailable === false) {
			$store->assertShippedFallbackAllowed();
		}

		$packs = [];
		$sources = [];
		foreach (self::TARGETS as $target) {
			$fileName = (string)($profile['packs'][$target] ?? '');
			$shipped = $this->readShippedPack(profile: $profile, target: $target, validator: $validator);
			$pack = $shipped;
			$label = 'CMDB mapping pack ' . $fileName;
			$details = [];
			$source = [
				'source' => 'file',
				'packId' => null,
				'storedVersion' => null,
				'shippedVersion' => (string)($shipped['version'] ?? ''),
				'edited' => null,
				'newerShipped' => false,
			];

			$stored = null;
			if ($storeAvailable === true && in_array($target, self::EDITABLE_TARGETS, true) === true) {
				$stored = $storedPack($store, $shipped);
			}

			if ($stored !== null) {
				$pack = $stored['pack'];
				$label = 'OpenRegister migration pack ' . $stored['slug'] . ' (id ' . $stored['id'] . ')';
				$details = ['pack' => $stored['slug'], 'packId' => $stored['id']];
				$this->assertValidPack(validator: $validator, pack: $pack, label: $label, details: $details);
				$source = array_merge(
					$source,
					[
						'source' => 'openregister',
						'packId' => $stored['id'],
						'storedVersion' => $stored['version'],
						'edited' => $stored['edited'],
						'newerShipped' => $stored['newerShipped'],
					]
				);
			}

			$this->assertImportRules(target: $target, pack: $pack, profile: $profile, label: $label, details: $details);
			$packs[$target] = $pack;
			$sources[$target] = $source;
		}//end foreach

		$this->profile = $profile;
		$this->packs = $packs;
		$this->sources = $sources;
		$this->storeAvailable = $storeAvailable;
	}//end loadPacks()

	/**
	 * The shipped packs an administrator edits in OpenRegister, validated, per target.
	 *
	 * The seed step stores these; it reads them without touching the store.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the validator is missing or a file is unreadable or invalid.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
	 */
	public function shippedEditablePacks(): array {
		$validator = $this->resolveValidator();
		$profile = $this->decodeFile(fileName: $this->profileFile);

		$packs = [];
		foreach (self::EDITABLE_TARGETS as $target) {
			$packs[$target] = $this->readShippedPack(profile: $profile, target: $target, validator: $validator);
		}

		return $packs;
	}//end shippedEditablePacks()

	/**
	 * Whether the import uses the shipped files because OpenRegister's pack store is not available.
	 *
	 * Only true while the packs were never stored there; afterwards a missing
	 * store fails the load instead.
	 *
	 * @return bool
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when loading fails.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function usesShippedFallback(): bool {
		$this->profile();
		return $this->storeAvailable === false;
	}//end usesShippedFallback()

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
	 * The limit on the unpacked size of a workbook, in bytes.
	 *
	 * The upload limit is on the compressed file; a sheet of identical rows
	 * compresses a hundredfold, so the unpacked size is bounded too.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function maxUncompressedBytes(): int {
		$limit = $this->profile()['maxUncompressedBytes'] ?? null;
		if (is_int($limit) === true && $limit > 0) {
			return $limit;
		}

		return self::DEFAULT_MAX_UNCOMPRESSED_BYTES;
	}//end maxUncompressedBytes()

	/**
	 * The limit on the unpacked size of any one part of a workbook, in bytes.
	 *
	 * PhpSpreadsheet parses the shared-strings part and every loaded sheet part
	 * whole, into structures many times the part's size, so the parts are
	 * bounded one by one, not only their total.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function maxPartBytes(): int {
		$limit = $this->profile()['maxPartBytes'] ?? null;
		if (is_int($limit) === true && $limit > 0) {
			return $limit;
		}

		return self::DEFAULT_MAX_PART_BYTES;
	}//end maxPartBytes()

	/**
	 * The limit on the number of entries in a workbook's shared-strings table.
	 *
	 * PhpSpreadsheet builds the whole table before it reads a sheet, and many
	 * short strings cost far more memory than their bytes.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function maxSharedStrings(): int {
		$limit = $this->profile()['maxSharedStrings'] ?? null;
		if (is_int($limit) === true && $limit > 0) {
			return $limit;
		}

		return self::DEFAULT_MAX_SHARED_STRINGS;
	}//end maxSharedStrings()

	/**
	 * The limit on the shared-string text a workbook's cells reference together, in bytes.
	 *
	 * PhpSpreadsheet gives every cell that references a shared string its own
	 * copy of the text, so one long string referenced by many cells costs far
	 * more memory than the file's size; the reader adds up what the cells
	 * reference before it parses a sheet.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function maxReferencedStringBytes(): int {
		$limit = $this->profile()['maxReferencedStringBytes'] ?? null;
		if (is_int($limit) === true && $limit > 0) {
			return $limit;
		}

		return self::DEFAULT_MAX_REFERENCED_STRING_BYTES;
	}//end maxReferencedStringBytes()

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
	 * The source sheets, each with the constants it adds to its rows and the
	 * pack columns it is known not to have.
	 *
	 * @return array<int, array{name: string, constants: array<string, string>, absentColumns: array<int, string>}>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function sheets(): array {
		$sheets = [];
		foreach (($this->profile()['sheets'] ?? []) as $sheet) {
			if (is_array($sheet) === false || is_string($sheet['name'] ?? null) === false) {
				continue;
			}

			$constants = [];
			if (is_array($sheet['constants'] ?? null) === true) {
				foreach ($sheet['constants'] as $column => $value) {
					if (is_scalar($value) === true) {
						$constants[(string)$column] = (string)$value;
					}
				}
			}

			$absent = [];
			if (is_array($sheet['absentColumns'] ?? null) === true) {
				$absent = array_values(array_map('strval', $sheet['absentColumns']));
			}

			$sheets[] = ['name' => $sheet['name'], 'constants' => $constants, 'absentColumns' => $absent];
		}//end foreach

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
	 * The rank of a sheet when an APPID is on more than one: lower wins.
	 *
	 * The profile's `sheetPrecedence` lists the sheets, the winner first; a
	 * sheet it does not list ranks after every listed one, in profile order.
	 *
	 * @param string $sheetName The sheet name.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function sheetRank(string $sheetName): int {
		$order = array_values(array_unique(array_merge($this->stringList(key: 'sheetPrecedence'), $this->sheetNames())));
		$rank = array_search($sheetName, $order, true);
		if ($rank === false) {
			return count($order);
		}

		return (int)$rank;
	}//end sheetRank()

	/**
	 * The constants a sheet adds to each of its rows, as column => value.
	 *
	 * A constant is mapped like a column (the usage pack reads "Beheer"), but
	 * it is never looked up in the sheet.
	 *
	 * @param string $sheetName The sheet name.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function sheetConstants(string $sheetName): array {
		foreach ($this->sheets() as $sheet) {
			if ($sheet['name'] === $sheetName) {
				return $sheet['constants'];
			}
		}

		return [];
	}//end sheetConstants()

	/**
	 * The pack columns a sheet is known not to have; their absence is no warning.
	 *
	 * @param string $sheetName The sheet name.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function absentColumns(string $sheetName): array {
		foreach ($this->sheets() as $sheet) {
			if ($sheet['name'] === $sheetName) {
				return $sheet['absentColumns'];
			}
		}

		return [];
	}//end absentColumns()

	/**
	 * The names of every sheet constant; these are never read from a sheet.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function constantColumns(): array {
		$columns = [];
		foreach ($this->sheets() as $sheet) {
			$columns = array_merge($columns, array_keys($sheet['constants']));
		}

		return array_values(array_unique(array_map('strval', $columns)));
	}//end constantColumns()

	/**
	 * Values that mean "empty" per column, such as the "NB" a CMDB sheet
	 * writes for an unknown BNN classification.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function emptyValues(): array {
		$value = $this->profile()['emptyValues'] ?? [];
		if (is_array($value) === false) {
			return [];
		}

		$empty = [];
		foreach ($value as $column => $values) {
			if (is_array($values) === true) {
				$empty[(string)$column] = array_values(array_map('strval', $values));
			}
		}

		return $empty;
	}//end emptyValues()

	/**
	 * The match key column ("APPID").
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function keyColumn(): string {
		return (string)($this->profile()['keyColumn'] ?? 'APPID');
	}//end keyColumn()

	/**
	 * The application name column ("Applicatie Naam").
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function nameColumn(): string {
		return (string)($this->profile()['nameColumn'] ?? 'Applicatie Naam');
	}//end nameColumn()

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
	 * Columns that hold Excel serial dates: the profile's, plus every column a pack maps with a date transform.
	 *
	 * A pack edited in OpenRegister can map a new column to a date; that
	 * column is converted too, so the date transform gets `Y-m-d` and not an
	 * Excel serial number.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function dateColumns(): array {
		$columns = $this->stringList(key: 'dateColumns');
		foreach (self::TARGETS as $target) {
			if ($target === 'municipality') {
				continue;
			}

			foreach (($this->pack(target: $target)['fieldMappings'] ?? []) as $mapping) {
				if (is_array($mapping) === true && ($mapping['transform']['type'] ?? null) === 'date') {
					$columns[] = (string)($mapping['source'] ?? '');
				}
			}
		}

		$constants = $this->constantColumns();
		return array_values(
			array_unique(
				array_filter(
					$columns,
					fn (string $column): bool => $column !== '' && in_array($column, $constants, true) === false
				)
			)
		);
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
	 * The separator of the concat mapping that writes a field, or null when no concat mapping writes it.
	 *
	 * The import drops the empty parts of a concatenated note; it splits on
	 * the separator the pack uses, which an administrator can change. A later
	 * plain mapping of the same field does not cancel it: the engine skips a
	 * mapping whose column is empty, so the concatenated value can still be
	 * the one kept, and the cleanup does no harm to a plain value. With several
	 * concat mappings, the last one's separator counts.
	 *
	 * @param string $target The profile target.
	 * @param string $field The target field.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	public function concatSeparator(string $target, string $field): ?string {
		$separator = null;
		foreach (($this->pack(target: $target)['fieldMappings'] ?? []) as $mapping) {
			if (is_array($mapping) === false || ($mapping['target'] ?? null) !== $field) {
				continue;
			}

			if (($mapping['transform']['type'] ?? null) !== 'concat') {
				continue;
			}

			$separator = ($mapping['transform']['separator'] ?? self::DEFAULT_CONCAT_SEPARATOR);
			if (is_string($separator) === false || $separator === '') {
				$separator = self::DEFAULT_CONCAT_SEPARATOR;
			}
		}

		return $separator;
	}//end concatSeparator()

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
	 * The municipality pack maps the request options, not a sheet, and the
	 * sheet constants are added by the import, so both are left out.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	public function referencedColumns(): array {
		$columns = array_merge(
			[$this->keyColumn(), $this->nameColumn()],
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

		$excluded = array_merge(self::OPTION_SOURCES, $this->constantColumns());
		$columns = array_filter(
			$columns,
			fn (string $column): bool => $column !== '' && $column[0] !== '/' && in_array($column, $excluded, true) === false
		);

		return array_values(array_unique($columns));
	}//end referencedColumns()

	/**
	 * The profile and the packs as the admin settings show them: what the next import runs.
	 *
	 * Loads and validates everything first, so a broken pack throws here as
	 * it does when an import starts. The transform of each field mapping is
	 * passed through as the pack stores it, so no key the engine reads is
	 * hidden from the admin; `required` is normalised to a boolean. Each pack
	 * also carries the write rules the import applies on top of the mapping:
	 * `defaults` (pack values set on every write), `createOnlyDefaults`
	 * (values set on create only, not mapped columns), `createOnlyFields` (mapped
	 * fields written on create or, on update, only when the stored value is
	 * empty; a key of `createOnlyDefaults` only when it is also mapped) and `neverWritten` (fields the
	 * import never writes on an existing object). It also says where it came
	 * from (`source`, `packId`, the stored and the shipped version, whether it
	 * was edited and whether a newer shipped pack was left out because of
	 * that) and whether it is edited in OpenRegister (`editable`).
	 *
	 * @return array{profile: array<string, mixed>, packs: array<int, array<string, mixed>>, openRegisterPacks: bool}
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the validator is missing,
	 *                             or the profile or a pack is unreadable or invalid.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-3
	 */
	public function mappingOverview(): array {
		$profile = $this->profile();

		$packs = [];
		foreach (self::TARGETS as $target) {
			$pack = $this->pack(target: $target);
			$mappings = [];
			foreach (($pack['fieldMappings'] ?? []) as $mapping) {
				if (is_array($mapping) === false) {
					continue;
				}

				$transform = $mapping['transform'] ?? null;
				if (is_array($transform) === false) {
					$transform = null;
				}

				$mappings[] = [
					'source' => (string)($mapping['source'] ?? ''),
					'target' => (string)($mapping['target'] ?? ''),
					'required' => (bool)($mapping['required'] ?? false),
					'transform' => $transform,
				];
			}

			// A create-only default that is not a mapped field (the module type) is a
			// value the import sets on create; merge() never reads it on update, so it
			// is listed under createOnlyDefaults only. A mapped field keeps its place
			// in createOnlyFields, since merge() guards it on update.
			$createOnlyDefaults = $this->createOnlyDefaults(target: $target);
			$unmappedDefaults = array_diff(array_map('strval', array_keys($createOnlyDefaults)), array_column($mappings, 'target'));
			$createOnlyFields = array_values(array_diff($this->createOnlyFields(target: $target), $unmappedDefaults));

			$defaults = $pack['defaults'] ?? [];
			if (is_array($defaults) === false) {
				$defaults = [];
			}

			$packs[] = array_merge(
				[
					'target' => $target,
					'file' => (string)($profile['packs'][$target] ?? ''),
					'id' => (string)($pack['id'] ?? ''),
					'name' => (string)($pack['name'] ?? ''),
					'version' => (string)($pack['version'] ?? ''),
					'description' => (string)($pack['description'] ?? ''),
					'fieldMappings' => $mappings,
					'defaults' => $defaults,
					'createOnlyDefaults' => $createOnlyDefaults,
					'createOnlyFields' => $createOnlyFields,
					'neverWritten' => $this->neverWrittenOnUpdate(target: $target),
				],
				$this->sources[$target],
				['editable' => in_array($target, self::EDITABLE_TARGETS, true)]
			);
		}//end foreach

		return [
			'profile' => [
				'id' => (string)($profile['id'] ?? ''),
				'name' => (string)($profile['name'] ?? ''),
				'version' => (string)($profile['version'] ?? ''),
				'profileFile' => $this->profileFile,
				'sheets' => $this->sheets(),
				'sheetPrecedence' => $this->stringList(key: 'sheetPrecedence'),
				'keyColumn' => $this->keyColumn(),
				'nameColumn' => $this->nameColumn(),
				'requiredColumns' => $this->requiredColumns(),
				'dateColumns' => $this->dateColumns(),
				'idColumns' => $this->idColumns(),
				'emptyValues' => $this->emptyValues(),
				'externalKeyPrefix' => $this->externalKeyPrefix(),
				'missingRecords' => $this->missingRecordsModes(),
			],
			'packs' => $packs,
			'openRegisterPacks' => $this->storeAvailable,
		];
	}//end mappingOverview()

	/**
	 * The loaded profile; loaded read only when nothing loaded it yet.
	 *
	 * The import run calls load() itself before it reads a row, so a lazy
	 * load is a read: the limits, the sheet names or the mapping view.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when load() failed.
	 */
	private function profile(): array {
		if ($this->profile === null) {
			$this->loadForView();
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
	 * OpenRegister's pack store, built on the container when none was injected.
	 *
	 * @return CmdbImportPackStore
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function packStore(): CmdbImportPackStore {
		if ($this->packStore === null) {
			$this->packStore = new CmdbImportPackStore(container: $this->container);
		}

		return $this->packStore;
	}//end packStore()

	/**
	 * Read and validate the shipped pack of a target.
	 *
	 * @param array<string, mixed> $profile The decoded profile.
	 * @param string $target The target.
	 * @param object $validator OpenRegister's validator.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the profile names no pack or the file is unreadable or invalid.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function readShippedPack(array $profile, string $target, object $validator): array {
		$fileName = $profile['packs'][$target] ?? null;
		if (is_string($fileName) === false || $fileName === '' || basename($fileName) !== $fileName) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: 'CMDB import profile names no pack for target ' . $target
			);
		}

		$pack = $this->decodeFile(fileName: $fileName);
		$this->assertValidPack(validator: $validator, pack: $pack, label: 'CMDB mapping pack ' . $fileName);

		return $pack;
	}//end readShippedPack()

	/**
	 * Refuse a pack OpenRegister's validator refuses.
	 *
	 * @param object $validator OpenRegister's validator.
	 * @param array<string, mixed> $pack The pack.
	 * @param string $label The pack as the message names it: its file, or its OpenRegister slug and id.
	 * @param array<string, mixed> $details The exception details: the OpenRegister pack and its id, for a stored pack.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the pack is invalid.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function assertValidPack(object $validator, array $pack, string $label, array $details = []): void {
		$errors = $validator->validate($pack);
		if (is_array($errors) === true && $errors !== []) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: $label . ' is invalid: ' . implode('; ', array_map('strval', $errors)),
				details: $details
			);
		}
	}//end assertValidPack()

	/**
	 * Refuse a pack that breaks a rule of the import, which the validator does not know.
	 *
	 * The import writes with OpenRegister's RBAC and multitenancy off, so a
	 * pack may map any plain property of its target schema, never the
	 * object's metadata, the keys the import sets itself, a relation, a file,
	 * the publication dates or an admin field, and must keep the mappings the
	 * import matches records on (REQ-CMDB-021, CmdbImportPackRules).
	 *
	 * @param string $target The target.
	 * @param array<string, mixed> $pack The validated pack.
	 * @param array<string, mixed> $profile The decoded profile.
	 * @param string $label The pack as the message names it.
	 * @param array<string, mixed> $details The exception details: the OpenRegister pack and its id, for a stored pack.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the pack breaks a rule, or the register cannot be read.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
	 */
	private function assertImportRules(string $target, array $pack, array $profile, string $label, array $details): void {
		$violations = $this->rules->violations(target: $target, pack: $pack, profile: $profile);
		if ($violations !== []) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MAPPING_UNAVAILABLE,
				message: $label . ' breaks a rule of the CMDB import: ' . implode('; ', $violations),
				details: $details
			);
		}
	}//end assertImportRules()

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
