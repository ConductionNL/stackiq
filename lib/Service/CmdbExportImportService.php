<?php

/**
 * CMDB export import service.
 *
 * Turns a TOPdesk CMDB export (xlsx) into stackiq objects for one
 * municipality (openspec/changes/cmdb-export-import). Every application row
 * of the CMDB sheets ("Onbeh Applicaties CMDB", "Beheerde Applicaties
 * CMDB") becomes, or updates, a `module` with its vendor `organization`
 * (type Supplier), a `usage` that links it to the municipality, and a
 * `contactPerson` for its owner, resolved through Nextcloud Contacts. Rows
 * are matched on `externalKey` = `topdesk:<municipality uuid>:<APPID>` (the
 * APPID in lower case), so a
 * second import of a newer export updates the same records.
 *
 * What each column becomes is declarative: the migration packs under
 * `lib/Settings/cmdb-import/`, executed by OpenRegister's
 * `MigrationPack\MappingEngine` (ADR-031). This class is the imperative glue
 * around the file: reading it, splitting a row over four linked objects,
 * resolving contacts, progress and cancel. Every read and write goes through
 * OpenRegister's `ObjectServiceInterface` (ADR-022).
 *
 * Rules stated once and enforced here:
 * - A module matches on `externalKey`; a usage on (consumer, module); a
 *   supplier on its normalised name and type Supplier; a contact person on
 *   (contactsUid, organization). An organisation that was merged away
 *   (status `merged`) or is `Inactive` is never matched by name.
 * - `publicationDate` is set to the import's start on create and never
 *   written on update; neither is `depublicationDate`.
 * - Records missing from a newer export are left untouched.
 * - Owners become contact persons, never Nextcloud user accounts, and no
 *   report entry or log line carries an owner name or e-mail address.
 * - One import runs per register at a time: every match is find-then-create,
 *   so two interleaved runs would each create the same records.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbImportReport;
use OCA\Stackiq\Service\Cmdb\CmdbRowNormaliser;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports a TOPdesk CMDB export for one municipality.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One import run resolves four linked
 * object kinds per row (supplier, module, owners, usage), each with its own match rule,
 * create-only fields and run cache. Splitting them over several classes would hand the
 * same run state from class to class without making any one rule simpler to read.
 * @SuppressWarnings(PHPMD.TooManyFields) The run caches are one field per matched kind.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reader, normaliser, profile, report,
 * contacts, progress and OpenRegister are the import's collaborators by design.
 * @SuppressWarnings(PHPMD.TooManyMethods) Each match rule and each step of a row is its own
 * small method; merging them back would only make the steps longer.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Most of the length is docblocks that state
 * the matching rules; the code itself is under the threshold.
 */
class CmdbExportImportService {
	/**
	 * The ProgressTracker operation type of an import.
	 */
	public const OPERATION_TYPE = 'cmdb_import';

	/**
	 * Operation ids a client may choose; anything else gets a generated id.
	 *
	 * `\z`, not `$`: `$` also matches before a trailing newline, which would
	 * let "cmdb-12345678\n" through as a cache key.
	 */
	public const OPERATION_ID_PATTERN = '/^cmdb-[A-Za-z0-9-]{8,64}\z/';

	/**
	 * OpenRegister's migration-pack mapping engine (not a public contract).
	 */
	public const ENGINE_CLASS = 'OCA\OpenRegister\Service\MigrationPack\MappingEngine';

	/**
	 * OpenRegister's schema mapper (not a public contract).
	 */
	public const SCHEMA_MAPPER_CLASS = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * The properties every match search and the module key rely on, per schema.
	 *
	 * OpenRegister answers a filter on a property its schema does not declare
	 * with no rows, not an error; without these the import would create a
	 * duplicate of every record instead of matching it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const MATCH_PROPERTIES = [
		'module' => ['externalKey', 'externalId', 'externalNumber'],
		'organization' => ['name', 'type'],
		'usage' => ['consumer', 'module'],
		'contactPerson' => ['contactsUid', 'organization'],
	];

	/**
	 * Wall-clock seconds an import may run, below the default 3600 s lifetime of a Nextcloud lock.
	 */
	public const TIME_LIMIT_SECONDS = 3000;

	/**
	 * The lock an import holds for its register, so imports never interleave.
	 */
	private const LOCK_PREFIX = 'stackiq/cmdb-import/register-';

	/**
	 * Organisation statuses a name never matches: a merge tombstone, and a retired organisation.
	 *
	 * @var array<int, string>
	 */
	private const UNMATCHED_STATUSES = ['merged', 'Inactive'];

	/**
	 * Page size for loading the organisations a name may match.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Separator of the concat mapping for the internal note.
	 */
	private const NOTE_SEPARATOR = ' / ';

	/**
	 * The mapping engine of the current run.
	 *
	 * @var object|null
	 */
	private ?object $engine = null;

	/**
	 * OpenRegister and the register/schema ids of the current run.
	 *
	 * @var array{objectService: ObjectServiceInterface, register: int, module: int, organization: int, usage: int, contactPerson: int}|null
	 */
	private ?array $coordinates = null;

	/**
	 * Suppliers by normalised name, loaded once per run.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $suppliers = null;

	/**
	 * APPIDs seen in this upload.
	 *
	 * @var array<string, true>
	 */
	private array $seenKeys = [];

	/**
	 * Per APPID match key, the sheet that wins when the APPID is on more than one sheet.
	 *
	 * @var array<string, string>
	 */
	private array $winningSheets = [];

	/**
	 * Contact UIDs by e-mail or display name, per run.
	 *
	 * @var array<string, string|null>
	 */
	private array $contactUids = [];

	/**
	 * Contact person uuids by contactsUid and organisation, per run.
	 *
	 * @var array<string, string>
	 */
	private array $contactPersons = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister services.
	 * @param SettingsService $settingsService Register and schema ids.
	 * @param StackiqContactSyncService $contactSync Nextcloud Contacts bridge.
	 * @param ProgressTracker $progressTracker Progress and cancel.
	 * @param CmdbImportProfile $profile The import profile and packs.
	 * @param CmdbWorkbookReader $reader The xlsx reader.
	 * @param CmdbRowNormaliser $normaliser Dates and ids to strings.
	 * @param IL10N $l10n Translates report reasons and warnings.
	 * @param LoggerInterface $logger Logger; never handed person data.
	 * @param ILockingProvider $lockingProvider Serialises imports per register.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each collaborator is one concern of the
	 * import (the file, the packs, OpenRegister, Contacts, progress, the lock); grouping them
	 * into a parameter object would only move the same list one class further.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly StackiqContactSyncService $contactSync,
		private readonly ProgressTracker $progressTracker,
		private readonly CmdbImportProfile $profile,
		private readonly CmdbWorkbookReader $reader,
		private readonly CmdbRowNormaliser $normaliser,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $lockingProvider,
	) {
	}//end __construct()

	/**
	 * The upload limit in bytes (the profile's `maxFileBytes`).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function maxFileBytes(): int {
		return $this->profile->maxFileBytes();
	}//end maxFileBytes()

	/**
	 * Check that an upload is an xlsx workbook, without parsing it.
	 *
	 * @param string $path The uploaded temporary file.
	 * @param string $fileName The original file name.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException NOT_XLSX.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function assertXlsx(string $path, string $fileName): void {
		$this->reader->assertXlsx(path: $path, fileName: $fileName);
	}//end assertXlsx()

	/**
	 * Whether a `missingRecords` value is supported (only `keep`).
	 *
	 * @param string $mode The requested value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function supportsMissingRecords(string $mode): bool {
		return $mode === 'keep';
	}//end supportsMissingRecords()

	/**
	 * Ask a running import to stop between rows.
	 *
	 * @param string $operationId The operation id.
	 *
	 * @return bool False when no running `cmdb_import` operation has this id.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function requestCancel(string $operationId): bool {
		if (preg_match(self::OPERATION_ID_PATTERN, $operationId) !== 1) {
			return false;
		}

		$progress = $this->progressTracker->getProgress(operationId: $operationId);
		if (is_array($progress) === false
			|| ($progress['operation_type'] ?? null) !== self::OPERATION_TYPE
			|| ($progress['status'] ?? null) !== 'running'
		) {
			return false;
		}

		$this->progressTracker->setCancelRequested(operationId: $operationId);
		return true;
	}//end requestCancel()

	/**
	 * Import an export for one municipality.
	 *
	 * Validation that can fail the whole import runs before any object is
	 * written: the packs and the engine, the configuration, the workbook and
	 * the municipality uuid. After that every row is processed in its own
	 * error boundary. The import holds an exclusive lock on its register from
	 * before the file is read until it returns or throws.
	 *
	 * @param string $path The xlsx file, already checked by assertXlsx().
	 * @param array<string, mixed> $options municipalityUuid, municipalityName, updateExisting, operationId.
	 *
	 * @return array<string, mixed> The report (contract.md).
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE, NOT_CONFIGURED, SCHEMA_OUTDATED, IMPORT_IN_PROGRESS,
	 *                             WORKBOOK_TOO_LARGE, READER_UNAVAILABLE, NOT_XLSX, NO_SOURCE_SHEET,
	 *                             MISSING_COLUMN, TOO_MANY_ROWS, MUNICIPALITY_REQUIRED,
	 *                             MUNICIPALITY_INVALID or MUNICIPALITY_AMBIGUOUS.
	 * @throws \Exception         An unexpected OpenRegister error outside a row, such as
	 *                             creating the municipality; rows catch their own.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function import(string $path, array $options): array {
		$this->resetRun();
		$this->keepRunning();
		$startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

		$this->profile->load();
		$this->engine = $this->resolveEngine();
		$this->coordinates = $this->resolveCoordinates();

		$lock = $this->acquireImportLock(register: $this->coordinates['register']);
		try {
			return $this->runImport(path: $path, options: $options, startedAt: $startedAt);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end import()

	/**
	 * Let the import finish when the browser goes away, within a bounded time.
	 *
	 * A closed tab or a proxy that gives up would otherwise stop PHP after
	 * some rows, with no report and the operation left running. The time
	 * limit is only ever raised to TIME_LIMIT_SECONDS, never lowered, and an
	 * unlimited one (the CLI) stays unlimited.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function keepRunning(): void {
		ignore_user_abort(true);

		$current = (int)ini_get('max_execution_time');
		if ($current > 0 && $current < self::TIME_LIMIT_SECONDS && function_exists('set_time_limit') === true) {
			set_time_limit(self::TIME_LIMIT_SECONDS);
		}
	}//end keepRunning()

	/**
	 * Take the register's import lock, or refuse because another import holds it.
	 *
	 * @param int $register The register id.
	 *
	 * @return string The lock path, to release.
	 *
	 * @throws CmdbImportException IMPORT_IN_PROGRESS.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function acquireImportLock(int $register): string {
		$lock = self::LOCK_PREFIX . $register;
		try {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'CMDB import');
		} catch (LockedException $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::IMPORT_IN_PROGRESS,
				message: 'Another CMDB import is running for this register',
				previous: $e
			);
		}

		return $lock;
	}//end acquireImportLock()

	/**
	 * Read the workbook and import its rows, under the register's lock.
	 *
	 * @param string $path The xlsx file.
	 * @param array<string, mixed> $options The import options.
	 * @param string $startedAt ISO start time of the import.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function runImport(string $path, array $options, string $startedAt): array {
		$workbook = $this->reader->read(path: $path, profile: $this->profile);
		$municipality = $this->resolveMunicipality(options: $options);

		$operationId = $this->operationIdFrom(options: $options);
		$rows = array_values($workbook['rows']);
		$report = new CmdbImportReport(operationId: $operationId, rowsRead: count($rows));
		$report->setMunicipality(uuid: $municipality['uuid'], name: $municipality['name'], created: $municipality['created']);
		$report->addImportWarnings(warnings: $this->translateImportWarnings(warnings: $workbook['importWarnings']));
		if ($municipality['created'] === true) {
			$report->addImportWarnings(
				warnings: [
					[
						'sheet' => '',
						'message' => $this->l10n->t(
							'No municipality named "%s" was found, so it was created. Check the name if you meant an existing one.',
							[$municipality['name']]
						),
					],
				]
			);
		}

		$this->progressTracker->startOperation(
			operationType: self::OPERATION_TYPE,
			options: ['total_items' => count($rows)],
			operationId: $operationId
		);
		$this->progressTracker->setPhase(phase: 'processing_elements', data: ['total_items' => count($rows)]);

		$this->winningSheets = $this->winningSheets(rows: $rows);
		$updateExisting = (($options['updateExisting'] ?? true) !== false);
		try {
			foreach ($rows as $index => $row) {
				if ($this->progressTracker->isCancelRequested(operationId: $operationId) === true) {
					$report->markCancelled();
					break;
				}

				$this->processRow(
					row: $row,
					municipalityUuid: $municipality['uuid'],
					updateExisting: $updateExisting,
					startedAt: $startedAt,
					date1904: $workbook['date1904'],
					report: $report
				);
				$this->progressTracker->updateProgress(processedItems: ($index + 1));
			}

			$result = $report->toArray();
			$this->finishOperation(report: $result);
		} catch (Throwable $e) {
			// Rows catch their own errors; this is the run itself failing, so the
			// operation stops as failed instead of staying running until it expires.
			$this->progressTracker->failOperation(message: $e->getMessage());
			throw $e;
		}//end try

		$this->logger->info(
			'CmdbExportImportService: import finished',
			['operationId' => $operationId, 'summary' => $result['summary'], 'cancelled' => $result['cancelled']]
		);

		return $result;
	}//end runImport()

	/**
	 * Process one row in its own error boundary and add its outcome.
	 *
	 * @param array{sheet: string, row: int, cells: array<string, mixed>, uncached?: array<int, string>} $row The reader row.
	 * @param string $municipalityUuid The consumer.
	 * @param bool $updateExisting Whether matched rows are updated.
	 * @param string $startedAt ISO start time of the import.
	 * @param bool $date1904 The workbook's date system.
	 * @param CmdbImportReport $report The report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function processRow(
		array $row,
		string $municipalityUuid,
		bool $updateExisting,
		string $startedAt,
		bool $date1904,
		CmdbImportReport $report,
	): void {
		$sheet = $row['sheet'];
		$values = $this->normaliser->normalise(
			cells: array_merge($row['cells'], $this->profile->sheetConstants(sheetName: $sheet)),
			dateColumns: $this->profile->dateColumns(),
			idColumns: $this->profile->idColumns(),
			date1904: $date1904,
			emptyValues: $this->profile->emptyValues()
		);
		$rowNumber = $row['row'];
		$appId = ($values[$this->profile->keyColumn()] ?? '');
		$name = ($values[$this->profile->nameColumn()] ?? '');

		$entry = ['sheet' => $sheet, 'row' => $rowNumber, 'appId' => $appId, 'name' => $name];

		$warnings = [];
		foreach (($row['uncached'] ?? []) as $column) {
			$warnings[] = $this->l10n->t('Column "%s": formula without a cached value, read as empty', [(string)$column]);
		}

		$matchKey = self::matchKey(appId: $appId);
		if ($this->skipForWinningSheet(report: $report, entry: $entry, matchKey: $matchKey, warnings: $warnings) === true) {
			return;
		}

		$skipReason = $this->skipReason(appId: $appId, matchKey: $matchKey);
		if ($skipReason !== null) {
			$this->addRow(report: $report, entry: $entry, outcome: CmdbImportReport::SKIPPED, reasons: [$skipReason], warnings: $warnings);
			return;
		}

		$step = 'mapping';
		$moduleUuid = null;
		$usageUuid = null;

		try {
			$module = $this->map(target: 'module', values: $values, rowNumber: $rowNumber, warnings: $warnings);
			if ($module['missing'] !== []) {
				$reasons = array_map(fn (string $column): string => $this->l10n->t('missing %s', [$column]), $module['missing']);
				$this->addRow(report: $report, entry: $entry, outcome: CmdbImportReport::SKIPPED, reasons: $reasons, warnings: $warnings);
				return;
			}

			// Claimed only now: a row skipped for a missing value leaves its APPID to a later row.
			$this->seenKeys[$matchKey] = true;

			$step = 'manufacturer';
			$providerUuid = $this->resolveManufacturer(values: $values, rowNumber: $rowNumber);

			$step = 'module';
			$externalKey = $this->profile->externalKeyPrefix() . ':' . $municipalityUuid . ':' . $matchKey;
			$moduleResult = $this->upsertModule(
				data: $module['data'],
				externalKey: $externalKey,
				providerUuid: $providerUuid,
				startedAt: $startedAt,
				updateExisting: $updateExisting
			);
			$moduleUuid = $moduleResult['uuid'];
			if ($moduleResult['outcome'] === 'exists') {
				$this->addRow(
					report: $report,
					entry: $entry,
					outcome: CmdbImportReport::SKIPPED,
					reasons: [$this->l10n->t('exists')],
					warnings: $warnings,
					moduleUuid: $moduleUuid
				);
				return;
			}

			$step = 'owners';
			$owners = $this->resolveOwners(values: $values, rowNumber: $rowNumber, municipalityUuid: $municipalityUuid, warnings: $warnings);

			$step = 'usage';
			$usage = $this->map(target: 'usage', values: $values, rowNumber: $rowNumber, warnings: $warnings);
			$usageResult = $this->upsertUsage(
				data: $usage['data'],
				municipalityUuid: $municipalityUuid,
				moduleUuid: $moduleUuid,
				providerUuid: $providerUuid,
				owners: $owners
			);
			$usageUuid = $usageResult['uuid'];
		} catch (Throwable $e) {
			$this->failRow(report: $report, entry: $entry, step: $step, e: $e, warnings: $warnings, uuids: [$moduleUuid, $usageUuid]);
			return;
		}//end try

		$outcome = self::rowOutcome(module: $moduleResult['outcome'], usage: $usageResult['outcome']);
		$this->addRow(report: $report, entry: $entry, outcome: $outcome, warnings: $warnings, moduleUuid: $moduleUuid, usageUuid: $usageUuid);
	}//end processRow()

	/**
	 * Report a row as failed at a step, and log it without person data.
	 *
	 * @param CmdbImportReport $report The report.
	 * @param array{sheet: string, row: int, appId: string, name: string} $entry Where the row is.
	 * @param string $step The step that failed.
	 * @param Throwable $e The cause.
	 * @param array<int, string> $warnings Row warnings so far.
	 * @param array{0: string|null, 1: string|null} $uuids Module and usage, when saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function failRow(CmdbImportReport $report, array $entry, string $step, Throwable $e, array $warnings, array $uuids): void {
		$detail = $this->safeMessage(step: $step, e: $e);
		$this->logger->warning(
			'CmdbExportImportService: row failed',
			array_merge(
				['sheet' => $entry['sheet'], 'row' => $entry['row'], 'appId' => $entry['appId']],
				['step' => $step, 'exception' => get_class($e), 'error' => $detail]
			)
		);

		$reason = $this->l10n->t('step "%s" failed', [$step]);
		if ($detail !== '') {
			$reason = $this->l10n->t('step "%1$s" failed: %2$s', [$step, $detail]);
		}

		$this->addRow(
			report: $report,
			entry: $entry,
			outcome: CmdbImportReport::FAILED,
			reasons: [$reason],
			warnings: $warnings,
			moduleUuid: $uuids[0],
			usageUuid: $uuids[1]
		);
	}//end failRow()

	/**
	 * Translate the reader's import-level warnings.
	 *
	 * @param array<int, array{sheet: string, column?: string, message: string}> $warnings The reader warnings.
	 *
	 * @return array<int, array{sheet: string, message: string}>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function translateImportWarnings(array $warnings): array {
		$translated = [];
		foreach ($warnings as $warning) {
			$message = $warning['message'];
			if (isset($warning['column']) === true) {
				$message = $this->l10n->t('Optional column "%s" not found', [$warning['column']]);
			}

			$translated[] = ['sheet' => $warning['sheet'], 'message' => $message];
		}

		return $translated;
	}//end translateImportWarnings()

	/**
	 * The row outcome from the module and usage outcomes.
	 *
	 * @param string $module The module outcome.
	 * @param string $usage The usage outcome.
	 *
	 * @return string created when the module was created, updated when anything was saved, else unchanged.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private static function rowOutcome(string $module, string $usage): string {
		if ($module === CmdbImportReport::CREATED) {
			return CmdbImportReport::CREATED;
		}

		if ($module !== CmdbImportReport::UNCHANGED || $usage !== CmdbImportReport::UNCHANGED) {
			return CmdbImportReport::UPDATED;
		}

		return CmdbImportReport::UNCHANGED;
	}//end rowOutcome()

	/**
	 * Store the report with the operation and close it, as completed or cancelled.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function finishOperation(array $report): void {
		if ($report['cancelled'] === true) {
			$this->progressTracker->updateStatistics(statistics: ['report' => $report]);
			$this->progressTracker->cancelOperation();
			return;
		}

		$this->progressTracker->completeOperation(finalStatistics: ['report' => $report]);
		// A cancel that came in after the last row's check has nothing left to stop.
		$this->progressTracker->clearCancelRequested(operationId: (string)$report['operationId']);
	}//end finishOperation()

	/**
	 * Add a row outcome to the report.
	 *
	 * @param CmdbImportReport $report The report.
	 * @param array{sheet: string, row: int, appId: string, name: string} $entry Where the row is.
	 * @param string $outcome The outcome.
	 * @param array<int, string> $reasons Reasons.
	 * @param array<int, string> $warnings Warnings.
	 * @param string|null $moduleUuid The module.
	 * @param string|null $usageUuid The usage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function addRow(
		CmdbImportReport $report,
		array $entry,
		string $outcome,
		array $reasons = [],
		array $warnings = [],
		?string $moduleUuid = null,
		?string $usageUuid = null,
	): void {
		$report->addRow(
			sheet: $entry['sheet'],
			row: $entry['row'],
			appId: $entry['appId'],
			name: $entry['name'],
			outcome: $outcome,
			reasons: $reasons,
			warnings: $warnings,
			moduleUuid: $moduleUuid,
			usageUuid: $usageUuid
		);
	}//end addRow()

	/**
	 * Skip a row whose APPID another sheet wins, with a warning naming the APPID and that sheet.
	 *
	 * @param CmdbImportReport $report The report.
	 * @param array{sheet: string, row: int, appId: string, name: string} $entry Where the row is.
	 * @param string $matchKey The APPID's match key.
	 * @param array<int, string> $warnings Row warnings so far.
	 *
	 * @return bool Whether the row was skipped.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function skipForWinningSheet(CmdbImportReport $report, array $entry, string $matchKey, array $warnings): bool {
		$winner = ($this->winningSheets[$matchKey] ?? $entry['sheet']);
		if ($entry['appId'] === '' || $winner === $entry['sheet']) {
			return false;
		}

		$warnings[] = $this->l10n->t('APPID %1$s is also on sheet "%2$s", which wins; this row is not imported', [$entry['appId'], $winner]);
		$this->addRow(
			report: $report,
			entry: $entry,
			outcome: CmdbImportReport::SKIPPED,
			reasons: [$this->l10n->t('duplicate %s in file', [$this->profile->keyColumn()])],
			warnings: $warnings
		);

		return true;
	}//end skipForWinningSheet()

	/**
	 * Per APPID, the sheet whose row is imported when the APPID is on more than one sheet.
	 *
	 * The profile ranks the sheets ("Beheerde Applicaties CMDB" before
	 * "Onbeh Applicaties CMDB"): an application whose maintenance is arranged
	 * is imported as such, whichever sheet the export lists first.
	 *
	 * @param array<int, array{sheet: string, row: int, cells: array<string, mixed>}> $rows The reader rows.
	 *
	 * @return array<string, string> APPID match key => sheet name.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function winningSheets(array $rows): array {
		$key = $this->profile->keyColumn();
		$winners = [];
		foreach ($rows as $row) {
			$values = $this->normaliser->normalise(cells: [$key => ($row['cells'][$key] ?? null)], dateColumns: [], idColumns: $this->profile->idColumns());
			$matchKey = self::matchKey(appId: ($values[$key] ?? ''));
			if ($matchKey === '') {
				continue;
			}

			$current = ($winners[$matchKey] ?? null);
			if ($current === null || $this->profile->sheetRank(sheetName: $row['sheet']) < $this->profile->sheetRank(sheetName: $current)) {
				$winners[$matchKey] = $row['sheet'];
			}
		}

		return $winners;
	}//end winningSheets()

	/**
	 * Why a row is skipped before mapping, or null when it is imported.
	 *
	 * An APPID an earlier row of the same sheet imported is a duplicate; across
	 * sheets winningSheets() decides. APPIDs compare by their match key, so `APP-1` and
	 * `app-1` are the same application.
	 *
	 * @param string $appId The APPID.
	 * @param string $matchKey The APPID's match key.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function skipReason(string $appId, string $matchKey): ?string {
		if ($appId === '') {
			return $this->l10n->t('missing %s', [$this->profile->keyColumn()]);
		}

		if (isset($this->seenKeys[$matchKey]) === true) {
			return $this->l10n->t('duplicate %s in file', [$this->profile->keyColumn()]);
		}

		return null;
	}//end skipReason()

	/**
	 * The key an APPID is matched on: lower case, so a change of case in the export is the same application.
	 *
	 * Surrounding whitespace, including a non-breaking space, is already
	 * removed by the normaliser.
	 *
	 * @param string $appId The normalised APPID.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public static function matchKey(string $appId): string {
		return mb_strtolower($appId);
	}//end matchKey()

	/**
	 * Map a row through a target's pack.
	 *
	 * Errors on required mappings are returned as missing columns. Other
	 * errors drop the field and become a warning naming the column and the
	 * value, except for the owner and manufacturer packs, whose errors are
	 * silent: such a row simply has no owner or no manufacturer.
	 *
	 * @param string $target The pack target.
	 * @param array<string, string> $values The normalised row.
	 * @param int $rowNumber The sheet row number, for the engine's errors.
	 * @param array<int, string> $warnings Row warnings, appended to.
	 *
	 * @return array{data: array<string, mixed>, missing: array<int, string>}
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function map(string $target, array $values, int $rowNumber, array &$warnings): array {
		$pack = $this->profile->pack(target: $target);
		$result = $this->engine->mapRow($pack, $values, $rowNumber);

		$required = [];
		foreach (($pack['fieldMappings'] ?? []) as $mapping) {
			if (($mapping['required'] ?? false) === true) {
				$required[] = (string)($mapping['source'] ?? '');
			}
		}

		$silent = in_array($target, ['manufacturer', 'businessOwner', 'municipality'], true);
		$missing = [];
		foreach (($result['errors'] ?? []) as $error) {
			$source = (string)($error['source'] ?? '');
			if (in_array($source, $required, true) === true) {
				$missing[] = $source;
				continue;
			}

			if ($silent === false) {
				$warnings[] = $this->l10n->t('Column "%1$s": %2$s', [$source, (string)($error['message'] ?? '')]);
			}
		}

		// A lookup whose default is null maps a known "no value" (an Applicatiesoort
		// that is not a hosting model) to null; that field is left out, as an empty
		// cell is, so an update never blanks what the field already holds.
		$data = array_filter(($result['data'] ?? []), static fn ($value): bool => $value !== null);
		unset($data['id']);

		return ['data' => $data, 'missing' => array_values(array_unique($missing))];
	}//end map()

	/**
	 * Find or create the Supplier organisation for the row's manufacturer.
	 *
	 * @param array<string, string> $values The normalised row.
	 * @param int $rowNumber The sheet row number.
	 *
	 * @return string|null The supplier uuid, or null when the row names no manufacturer.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function resolveManufacturer(array $values, int $rowNumber): ?string {
		$warnings = [];
		$mapped = $this->map(target: 'manufacturer', values: $values, rowNumber: $rowNumber, warnings: $warnings);
		$name = trim((string)($mapped['data']['name'] ?? ''));
		if ($mapped['missing'] !== [] || $name === '') {
			return null;
		}

		$key = self::normaliseName(name: $name);
		$suppliers = $this->suppliers();
		if (isset($suppliers[$key]) === true) {
			return $suppliers[$key];
		}

		$data = $mapped['data'];
		$data['name'] = (string)preg_replace('/\s+/u', ' ', $name);
		$uuid = $this->save(schemaKey: 'organization', data: $data, uuid: null);
		$this->suppliers[$key] = $uuid;

		return $uuid;
	}//end resolveManufacturer()

	/**
	 * Create, update, or leave the module matched on its external key.
	 *
	 * @param array<string, mixed> $data The mapped module fields.
	 * @param string $externalKey The match key.
	 * @param string|null $providerUuid The supplier, when there is one.
	 * @param string $startedAt ISO start time of the import.
	 * @param bool $updateExisting Whether a match is updated.
	 *
	 * @return array{uuid: string, outcome: string} Outcome created, updated, unchanged or exists.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function upsertModule(array $data, string $externalKey, ?string $providerUuid, string $startedAt, bool $updateExisting): array {
		$data['externalKey'] = $externalKey;
		if ($providerUuid !== null) {
			$data['provider'] = $providerUuid;
		}

		$existing = $this->findOne(schemaKey: 'module', filters: ['externalKey' => $externalKey]);
		if ($existing === null) {
			$create = array_merge($this->profile->createOnlyDefaults(target: 'module'), $data);
			$create['publicationDate'] = $startedAt;
			return ['uuid' => $this->save(schemaKey: 'module', data: $create, uuid: null), 'outcome' => CmdbImportReport::CREATED];
		}

		$uuid = (string)$existing->getUuid();
		if ($updateExisting === false) {
			return ['uuid' => $uuid, 'outcome' => 'exists'];
		}

		$merged = $this->merge(target: 'module', stored: $existing->getObject(), mapped: $data);
		if ($merged === null) {
			return ['uuid' => $uuid, 'outcome' => CmdbImportReport::UNCHANGED];
		}

		$this->save(schemaKey: 'module', data: $merged, uuid: $uuid);
		return ['uuid' => $uuid, 'outcome' => CmdbImportReport::UPDATED];
	}//end upsertModule()

	/**
	 * Create, update, or leave the usage of the module for the municipality.
	 *
	 * @param array<string, mixed> $data The mapped usage fields.
	 * @param string $municipalityUuid The consumer.
	 * @param string $moduleUuid The module.
	 * @param string|null $providerUuid The supplier, when there is one.
	 * @param array{businessOwner: string|null} $owners The owner contact person.
	 *
	 * @return array{uuid: string, outcome: string}
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function upsertUsage(array $data, string $municipalityUuid, string $moduleUuid, ?string $providerUuid, array $owners): array {
		if (isset($data['interneAnnotation']) === true && is_string($data['interneAnnotation']) === true) {
			// The concat mapping leaves an empty part for every empty column; drop those.
			$parts = array_filter(
				array_map('trim', explode(self::NOTE_SEPARATOR, $data['interneAnnotation'])),
				fn (string $part): bool => $part !== ''
			);
			$data['interneAnnotation'] = implode(self::NOTE_SEPARATOR, $parts);
		}

		$data['consumer'] = $municipalityUuid;
		$data['module'] = $moduleUuid;
		if ($providerUuid !== null) {
			$data['provider'] = $providerUuid;
		}

		foreach ($owners as $field => $contactPersonUuid) {
			if ($contactPersonUuid !== null) {
				$data[$field] = $contactPersonUuid;
			}
		}

		$existing = $this->findOne(schemaKey: 'usage', filters: ['consumer' => $municipalityUuid, 'module' => $moduleUuid]);
		if ($existing === null) {
			return ['uuid' => $this->save(schemaKey: 'usage', data: $data, uuid: null), 'outcome' => CmdbImportReport::CREATED];
		}

		$uuid = (string)$existing->getUuid();
		$merged = $this->merge(target: 'usage', stored: $existing->getObject(), mapped: $data);
		if ($merged === null) {
			return ['uuid' => $uuid, 'outcome' => CmdbImportReport::UNCHANGED];
		}

		$this->save(schemaKey: 'usage', data: $merged, uuid: $uuid);
		return ['uuid' => $uuid, 'outcome' => CmdbImportReport::UPDATED];
	}//end upsertUsage()

	/**
	 * Merge mapped fields onto a stored object.
	 *
	 * Fields the pack does not map stay as they are. Create-only fields are
	 * written only when the stored value is empty; never-written fields are
	 * never touched.
	 *
	 * @param string $target The profile target.
	 * @param array<string, mixed> $stored The stored object data.
	 * @param array<string, mixed> $mapped The mapped fields.
	 *
	 * @return array<string, mixed>|null The merged object, or null when nothing changes.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function merge(string $target, array $stored, array $mapped): ?array {
		$createOnly = $this->profile->createOnlyFields(target: $target);
		$never = $this->profile->neverWrittenOnUpdate(target: $target);
		$merged = $stored;
		unset($merged['@self']);
		$changed = false;

		foreach ($mapped as $field => $value) {
			if (in_array($field, $never, true) === true) {
				continue;
			}

			$current = ($stored[$field] ?? null);
			if (in_array($field, $createOnly, true) === true && self::isEmptyValue(value: $current) === false) {
				continue;
			}

			if (self::sameValue(stored: $current, value: $value) === true) {
				continue;
			}

			$merged[$field] = $value;
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $merged;
	}//end merge()

	/**
	 * Resolve the owner of a row (Applicatie Eigenaar) as the usage's business owner.
	 *
	 * No technical owner is read: the functional administrator columns are
	 * not part of the mapping.
	 *
	 * @param array<string, string> $values The normalised row.
	 * @param int $rowNumber The sheet row number.
	 * @param string $municipalityUuid The municipality the contact person belongs to.
	 * @param array<int, string> $warnings Row warnings, appended to.
	 *
	 * @return array{businessOwner: string|null}
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	private function resolveOwners(array $values, int $rowNumber, string $municipalityUuid, array &$warnings): array {
		$owners = ['businessOwner' => null];
		$identities = [];
		foreach (array_keys($owners) as $target) {
			$silent = [];
			$mapped = $this->map(target: $target, values: $values, rowNumber: $rowNumber, warnings: $silent);
			$name = trim((string)($mapped['data']['name'] ?? ''));
			if ($mapped['missing'] === [] && $name !== '') {
				$identities[$target] = $mapped['data'];
			}
		}

		if ($identities === []) {
			return $owners;
		}

		if ($this->contactSync->isAvailable() === false) {
			$warnings[] = $this->l10n->t('Owners skipped: Nextcloud Contacts is unavailable');
			return $owners;
		}

		foreach ($identities as $target => $identity) {
			$column = $this->ownerColumn(target: $target);
			try {
				$contactsUid = $this->resolveContactUid(identity: $identity);
				if ($contactsUid === null) {
					$warnings[] = $this->l10n->t('Owner from column "%s" could not be resolved in Nextcloud Contacts', [$column]);
					continue;
				}

				$owners[$target] = $this->resolveContactPerson(
					contactsUid: $contactsUid,
					municipalityUuid: $municipalityUuid,
					role: trim((string)($identity['role'] ?? ''))
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'CmdbExportImportService: owner could not be resolved',
					['row' => $rowNumber, 'column' => $column, 'exception' => get_class($e)]
				);
				$warnings[] = $this->l10n->t('Owner from column "%s" could not be resolved', [$column]);
			}
		}//end foreach

		return $owners;
	}//end resolveOwners()

	/**
	 * Resolve the Nextcloud contact of an owner identity.
	 *
	 * With an e-mail address, StackiqContactSyncService matches on it or
	 * creates the contact. Without one, only a contact whose display name is
	 * exactly the owner's name (case-insensitive) is reused, so an owner
	 * known by name alone is not created again on every import.
	 *
	 * @param array<string, mixed> $identity name, email and role from the owner pack.
	 *
	 * @return string|null The contact UID, or null.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	private function resolveContactUid(array $identity): ?string {
		$parts = self::splitPersonName(name: (string)$identity['name']);
		$displayName = trim($parts['voornaam'] . ' ' . $parts['achternaam']);
		$email = trim((string)($identity['email'] ?? ''));

		$cacheKey = 'name:' . mb_strtolower($displayName);
		if ($email !== '') {
			$cacheKey = 'email:' . mb_strtolower($email);
		}

		if (array_key_exists($cacheKey, $this->contactUids) === true) {
			return $this->contactUids[$cacheKey];
		}

		$uid = null;
		if ($email === '') {
			$uid = $this->contactByDisplayName(displayName: $displayName);
		}

		if ($uid === null) {
			$record = ['voornaam' => $parts['voornaam'], 'achternaam' => $parts['achternaam']];
			if ($email !== '') {
				$record['email'] = $email;
			}

			$role = trim((string)($identity['role'] ?? ''));
			if ($role !== '') {
				$record['role'] = $role;
			}

			$uid = $this->contactSync->syncToContacts(objectType: 'contactPerson', record: $record);
			if ($uid === '') {
				$uid = null;
			}
		}

		$this->contactUids[$cacheKey] = $uid;
		return $uid;
	}//end resolveContactUid()

	/**
	 * The contact whose display name is exactly this one, case-insensitive.
	 *
	 * @param string $displayName The display name.
	 *
	 * @return string|null The contact UID, or null.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	private function contactByDisplayName(string $displayName): ?string {
		$needle = mb_strtolower($displayName);
		foreach ($this->contactSync->searchContacts(query: $displayName) as $contact) {
			if (mb_strtolower(trim((string)($contact['name'] ?? ''))) === $needle) {
				return (string)$contact['uid'];
			}
		}

		return null;
	}//end contactByDisplayName()

	/**
	 * Find or create the contact person of a contact for the municipality.
	 *
	 * The object carries only `contactsUid`, `organization` and `role`: no
	 * e-mail and no username, so neither the contact-person listener nor
	 * OrganizationSyncService::performUserSync() provisions a user for it.
	 *
	 * @param string $contactsUid The Nextcloud contact UID.
	 * @param string $municipalityUuid The organisation.
	 * @param string $role The owner's function, or ''.
	 *
	 * @return string The contact person uuid.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	private function resolveContactPerson(string $contactsUid, string $municipalityUuid, string $role): string {
		$cacheKey = $contactsUid . '|' . $municipalityUuid;
		if (isset($this->contactPersons[$cacheKey]) === true) {
			return $this->contactPersons[$cacheKey];
		}

		$existing = $this->findOne(schemaKey: 'contactPerson', filters: ['contactsUid' => $contactsUid, 'organization' => $municipalityUuid]);
		$data = ['contactsUid' => $contactsUid, 'organization' => $municipalityUuid];
		if ($role !== '') {
			$data['role'] = $role;
		}

		$uuid = (string)$existing?->getUuid();
		if ($existing === null) {
			$uuid = $this->save(schemaKey: 'contactPerson', data: $data, uuid: null);
		}

		$this->contactPersons[$cacheKey] = $uuid;
		return $uuid;
	}//end resolveContactPerson()

	/**
	 * Resolve the consuming municipality from the options.
	 *
	 * A name matches the one live Municipality with that normalised name.
	 * Several matches are refused rather than guessed: the uuid embedded in
	 * every external key would bind the whole catalogue to the guess. No
	 * match creates the municipality, which the report then warns about.
	 *
	 * @param array<string, mixed> $options municipalityUuid or municipalityName.
	 *
	 * @return array{uuid: string, name: string, created: bool}
	 *
	 * @throws CmdbImportException MUNICIPALITY_REQUIRED, MUNICIPALITY_INVALID or MUNICIPALITY_AMBIGUOUS.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function resolveMunicipality(array $options): array {
		$uuid = trim((string)($options['municipalityUuid'] ?? ''));
		if ($uuid !== '') {
			return $this->municipalityByUuid(uuid: $uuid);
		}

		$warnings = [];
		$values = ['municipalityName' => trim((string)($options['municipalityName'] ?? ''))];
		$mapped = $this->map(target: 'municipality', values: $values, rowNumber: 0, warnings: $warnings);
		$name = trim((string)preg_replace('/\s+/u', ' ', (string)($mapped['data']['name'] ?? '')));
		if ($mapped['missing'] !== [] || $name === '') {
			throw new CmdbImportException(errorCode: CmdbImportException::MUNICIPALITY_REQUIRED, message: 'No municipality given');
		}

		$key = self::normaliseName(name: $name);
		$matches = array_values(
			array_filter(
				$this->organisationsOfType(type: 'Municipality'),
				static fn (array $organisation): bool => self::normaliseName(name: (string)($organisation['name'] ?? '')) === $key
			)
		);

		if (count($matches) > 1) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MUNICIPALITY_AMBIGUOUS,
				message: 'Several municipalities have this name',
				details: ['matches' => array_column($matches, 'uuid')]
			);
		}

		if ($matches !== []) {
			return ['uuid' => $matches[0]['uuid'], 'name' => (string)$matches[0]['name'], 'created' => false];
		}

		$data = $mapped['data'];
		$data['name'] = $name;
		$created = $this->save(schemaKey: 'organization', data: $data, uuid: null);

		return ['uuid' => $created, 'name' => $name, 'created' => true];
	}//end resolveMunicipality()

	/**
	 * Resolve a municipality uuid, which must be an organisation of type Municipality that was not merged away.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return array{uuid: string, name: string, created: bool}
	 *
	 * @throws CmdbImportException MUNICIPALITY_INVALID.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function municipalityByUuid(string $uuid): array {
		$coordinates = $this->coordinates();
		$organisation = null;
		try {
			$organisation = $coordinates['objectService']->find(
				id: $uuid,
				register: $coordinates['register'],
				schema: $coordinates['organization'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// OpenRegister throws DoesNotExistException for an unknown uuid.
			$organisation = null;
		}

		$data = [];
		if ($organisation !== null) {
			$data = $organisation->getObject();
		}

		if ($organisation === null || ($data['type'] ?? null) !== 'Municipality') {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MUNICIPALITY_INVALID,
				message: 'The municipality uuid is not an organisation of type Municipality'
			);
		}

		// A merge tombstone points at the organisation that replaced it; data never goes to the tombstone.
		if (($data['status'] ?? null) === 'merged') {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MUNICIPALITY_INVALID,
				message: 'The municipality uuid is an organisation that was merged into another'
			);
		}

		return ['uuid' => (string)$organisation->getUuid(), 'name' => (string)($data['name'] ?? ''), 'created' => false];
	}//end municipalityByUuid()

	/**
	 * Suppliers by normalised name, loaded once per run.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function suppliers(): array {
		if ($this->suppliers === null) {
			$this->suppliers = [];
			foreach ($this->organisationsOfType(type: 'Supplier') as $organisation) {
				$key = self::normaliseName(name: (string)($organisation['name'] ?? ''));
				if ($key !== '' && isset($this->suppliers[$key]) === false) {
					$this->suppliers[$key] = $organisation['uuid'];
				}
			}
		}

		return $this->suppliers;
	}//end suppliers()

	/**
	 * Every organisation of a type that a name may match, as uuid and name.
	 *
	 * Merge tombstones and inactive organisations are left out: new data
	 * linked to them would never show where the live organisation is used.
	 *
	 * @param string $type Municipality or Supplier.
	 *
	 * @return array<int, array{uuid: string, name: mixed}>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function organisationsOfType(string $type): array {
		$coordinates = $this->coordinates();
		$found = [];
		$offset = 0;
		do {
			$page = $coordinates['objectService']->searchObjects(
				query: [
					'@self' => ['register' => $coordinates['register'], 'schema' => $coordinates['organization']],
					'type' => $type,
					'_limit' => self::PAGE_SIZE,
					'_offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
			if (is_array($page) === false) {
				$page = [];
			}

			foreach ($page as $entity) {
				$data = $entity->getObject();
				// The filter is checked again: a filter OpenRegister cannot apply must not widen the match.
				if (($data['type'] ?? null) === $type
					&& $entity->getUuid() !== null
					&& in_array(($data['status'] ?? null), self::UNMATCHED_STATUSES, true) === false
				) {
					$found[] = ['uuid' => (string)$entity->getUuid(), 'name' => ($data['name'] ?? '')];
				}
			}

			$offset += self::PAGE_SIZE;
			$pageSize = count($page);
		} while ($pageSize === self::PAGE_SIZE);

		return $found;
	}//end organisationsOfType()

	/**
	 * The one object matching every filter, or null.
	 *
	 * @param string $schemaKey The coordinates key of the schema.
	 * @param array<string, string> $filters Field => exact value.
	 *
	 * @return object|null The entity (ObjectEntityInterface).
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function findOne(string $schemaKey, array $filters): ?object {
		$coordinates = $this->coordinates();
		$results = $coordinates['objectService']->searchObjects(
			query: array_merge(
				['@self' => ['register' => $coordinates['register'], 'schema' => $coordinates[$schemaKey]], '_limit' => 10],
				$filters
			),
			_rbac: false,
			_multitenancy: false
		);
		if (is_array($results) === false) {
			return null;
		}

		foreach ($results as $entity) {
			$data = $entity->getObject();
			$matches = true;
			foreach ($filters as $field => $value) {
				if (self::relationUuid(value: ($data[$field] ?? null)) !== $value) {
					$matches = false;
					break;
				}
			}

			if ($matches === true) {
				return $entity;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Save an object through OpenRegister and return its uuid.
	 *
	 * @param string $schemaKey The coordinates key of the schema.
	 * @param array<string, mixed> $data The object data.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function save(string $schemaKey, array $data, ?string $uuid): string {
		$coordinates = $this->coordinates();
		$entity = $coordinates['objectService']->saveObject(
			object: $data,
			register: $coordinates['register'],
			schema: $coordinates[$schemaKey],
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return (string)$entity->getUuid();
	}//end save()

	/**
	 * Resolve OpenRegister's mapping engine.
	 *
	 * @return object The engine, with `mapRow(array, array, int): array`.
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-3
	 */
	private function resolveEngine(): object {
		$class = static::ENGINE_CLASS;
		try {
			if ($this->container->has($class) === true) {
				$engine = $this->container->get($class);
				if (is_object($engine) === true && method_exists($engine, 'mapRow') === true) {
					return $engine;
				}
			}
		} catch (Throwable $e) {
			// Fall through to the class check below.
			$engine = null;
		}

		if (class_exists($class) === true) {
			$engine = new $class();
			if (method_exists($engine, 'mapRow') === true) {
				return $engine;
			}
		}

		throw new CmdbImportException(errorCode: CmdbImportException::MAPPING_UNAVAILABLE, message: 'OpenRegister MappingEngine is not available');
	}//end resolveEngine()

	/**
	 * Resolve OpenRegister and the register and schema ids, failing closed.
	 *
	 * @return array{objectService: ObjectServiceInterface, register: int, module: int, organization: int, usage: int, contactPerson: int}
	 *
	 * @throws CmdbImportException NOT_CONFIGURED when OpenRegister or a schema is not configured,
	 *                             SCHEMA_OUTDATED when a schema lacks a match property.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function resolveCoordinates(): array {
		try {
			$objectService = $this->container->get(ObjectServiceInterface::class);
		} catch (Throwable $e) {
			$objectService = null;
		}

		$register = (int)($this->settingsService->getVoorzieningenConfig()['register'] ?? 0);
		$schemas = [];
		foreach (array_keys(self::MATCH_PROPERTIES) as $type) {
			$schemas[$type] = (int)($this->settingsService->getSchemaIdForObjectType($type) ?? 0);
		}

		if ($objectService instanceof ObjectServiceInterface === false || $register <= 0 || in_array(0, $schemas, true) === true) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NOT_CONFIGURED,
				message: 'OpenRegister or the stackiq register and schemas are not configured'
			);
		}

		$this->assertMatchProperties(schemas: $schemas);

		return array_merge(['objectService' => $objectService, 'register' => $register], $schemas);
	}//end resolveCoordinates()

	/**
	 * Refuse the import when a schema does not declare the properties the matching relies on.
	 *
	 * The module properties arrive with the register fragment (module 0.3.5 and
	 * later), which an installation gets only after its register configuration
	 * is imported again.
	 *
	 * @param array<string, int> $schemas Schema key => schema id.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException NOT_CONFIGURED when the schemas cannot be read,
	 *                             SCHEMA_OUTDATED when one lacks a match property.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	private function assertMatchProperties(array $schemas): void {
		try {
			$mapper = $this->container->get(static::SCHEMA_MAPPER_CLASS);
		} catch (Throwable $e) {
			$mapper = null;
		}

		if (is_object($mapper) === false || method_exists($mapper, 'find') === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_CONFIGURED, message: 'OpenRegister SchemaMapper is not available');
		}

		foreach (self::MATCH_PROPERTIES as $type => $required) {
			try {
				$properties = $mapper->find(id: $schemas[$type], _rbac: false, _multitenancy: false)->getProperties();
			} catch (Throwable $e) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::NOT_CONFIGURED,
					message: 'The ' . $type . ' schema cannot be read: ' . get_class($e),
					previous: $e
				);
			}

			$missing = array_values(array_diff($required, array_keys((array)$properties)));
			if ($missing !== []) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::SCHEMA_OUTDATED,
					message: 'The ' . $type . ' schema does not declare the properties the import matches on',
					details: ['schema' => $type, 'missing' => $missing]
				);
			}
		}
	}//end assertMatchProperties()

	/**
	 * The coordinates of the current run.
	 *
	 * @return array{objectService: ObjectServiceInterface, register: int, module: int, organization: int, usage: int, contactPerson: int}
	 *
	 * @throws CmdbImportException NOT_CONFIGURED.
	 */
	private function coordinates(): array {
		if ($this->coordinates === null) {
			$this->coordinates = $this->resolveCoordinates();
		}

		return $this->coordinates;
	}//end coordinates()

	/**
	 * The operation id the client chose, or a new one.
	 *
	 * The client's id is replaced when it does not match the pattern, and
	 * also when an operation with that id is still running: reusing it would
	 * overwrite that run's progress and owner, and a cancel would stop both.
	 * An id that is taken drops any cancel request left over from an earlier
	 * run with the same id, so the new run is not stopped before row 1.
	 *
	 * @param array<string, mixed> $options The import options.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	private function operationIdFrom(array $options): string {
		$operationId = $options['operationId'] ?? null;
		if (is_string($operationId) === true
			&& preg_match(self::OPERATION_ID_PATTERN, $operationId) === 1
			&& ($this->progressTracker->getProgress(operationId: $operationId)['status'] ?? null) !== 'running'
		) {
			$this->progressTracker->clearCancelRequested(operationId: $operationId);
			return $operationId;
		}

		return 'cmdb-' . bin2hex(random_bytes(16));
	}//end operationIdFrom()

	/**
	 * Clear the run caches.
	 *
	 * @return void
	 */
	private function resetRun(): void {
		$this->engine = null;
		$this->coordinates = null;
		$this->suppliers = null;
		$this->seenKeys = [];
		$this->winningSheets = [];
		$this->contactUids = [];
		$this->contactPersons = [];
	}//end resetRun()

	/**
	 * The source column of an owner target, for warnings.
	 *
	 * @param string $target businessOwner.
	 *
	 * @return string
	 */
	private function ownerColumn(string $target): string {
		foreach (($this->profile->pack(target: $target)['fieldMappings'] ?? []) as $mapping) {
			if (($mapping['target'] ?? null) === 'name') {
				return (string)($mapping['source'] ?? $target);
			}
		}

		return $target;
	}//end ownerColumn()

	/**
	 * An exception message that is safe for the report.
	 *
	 * Owner steps get no detail, so no contact data can leak into the report.
	 *
	 * @param string $step The step that failed.
	 * @param Throwable $e The exception.
	 *
	 * @return string
	 */
	private function safeMessage(string $step, Throwable $e): string {
		if ($step === 'owners') {
			return '';
		}

		return mb_substr(trim($e->getMessage()), 0, 300);
	}//end safeMessage()

	/**
	 * Split a TOPdesk person name ("Achternaam, Voornaam").
	 *
	 * @param string $name The name.
	 *
	 * @return array{voornaam: string, achternaam: string}
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	public static function splitPersonName(string $name): array {
		$name = trim((string)preg_replace('/\s+/u', ' ', $name));
		if (str_contains($name, ',') === true) {
			[$last, $first] = array_map('trim', explode(',', $name, 2));
			return ['voornaam' => $first, 'achternaam' => $last];
		}

		return ['voornaam' => '', 'achternaam' => $name];
	}//end splitPersonName()

	/**
	 * Normalise an organisation name for matching: trim, collapse whitespace, lower case.
	 *
	 * @param string $name The name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 */
	public static function normaliseName(string $name): string {
		return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
	}//end normaliseName()

	/**
	 * A relation value (uuid string, or array/object with uuid or id) as a string.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private static function relationUuid(mixed $value): ?string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		if (is_array($value) === true) {
			$uuid = ($value['uuid'] ?? ($value['id'] ?? null));
			if (is_scalar($uuid) === true) {
				return (string)$uuid;
			}
		}

		return null;
	}//end relationUuid()

	/**
	 * Whether a stored value equals a mapped value.
	 *
	 * @param mixed $stored The stored value.
	 * @param mixed $value The mapped value.
	 *
	 * @return bool
	 */
	private static function sameValue(mixed $stored, mixed $value): bool {
		if (is_scalar($value) === true && (is_scalar($stored) === true || is_array($stored) === true)) {
			return self::relationUuid(value: $stored) === (string)$value;
		}

		return $stored === $value;
	}//end sameValue()

	/**
	 * Whether a stored value counts as empty.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return bool
	 */
	private static function isEmptyValue(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}//end isEmptyValue()
}//end class
