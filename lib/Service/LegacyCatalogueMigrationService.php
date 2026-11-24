<?php

/**
 * Legacy catalogue migration service
 *
 * Brings over what an organisation registered in the previous Softwarecatalogus: reads its export with the
 * `vng-swc` import profile and previews, per row, whether the supplier, product and version match what stackiq
 * holds or are to be created. The preview writes nothing.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Kernel\Contracts\OpenRegisterLookupInterface;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Service\LegacyCatalogue\LegacyCatalogueMatcher;
use OCA\Stackiq\Service\LegacyCatalogue\LegacyCatalogueOpenRegister;
use OCA\Stackiq\Service\LegacyCatalogue\LegacyCatalogueRowMapper;
use OCP\IUserSession;

/**
 * The preview of an export from the previous Softwarecatalogus.
 *
 * Only a member of an active organisation who may create usages previews; the check runs before the file is read.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
 */
class LegacyCatalogueMigrationService {

	/**
	 * The decision for a row that names no version.
	 */
	public const NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param LegacyCatalogueOpenRegister $openRegister The right to create usages, the candidates, the engine and the profile.
	 * @param IUserSession                $userSession  The caller.
	 * @param OpenRegisterLookupInterface $lookup       The caller's active organisation.
	 * @param CmdbWorkbookReader|null     $reader       The workbook reader; null builds one.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function __construct(
		private readonly LegacyCatalogueOpenRegister $openRegister,
		private readonly IUserSession $userSession,
		private readonly OpenRegisterLookupInterface $lookup,
		private ?CmdbWorkbookReader $reader = null,
	) {
	}//end __construct()

	/**
	 * Refuse unless the caller is a member of an active organisation who may create usages. Fails closed.
	 *
	 * @return string The uuid of the caller's active organisation.
	 *
	 * @throws CmdbImportException FORBIDDEN (403).
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function assertMayImport(): string {
		$organisation = null;
		$allowed = false;
		if ($this->userSession->getUser() !== null) {
			$organisation = $this->lookup->activeOrganisationUuid();
			$allowed = ($organisation !== null && $organisation !== '' && $this->openRegister->mayCreateUsages() === true);
		}

		if ($allowed === false) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::FORBIDDEN,
				message: 'Only a member of an active organisation who may create usages may bring over an export'
			);
		}

		return (string)$organisation;
	}//end assertMayImport()

	/**
	 * The largest export the profile accepts, in bytes.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function maxFileBytes(): int {
		return $this->openRegister->profile()->maxFileBytes();
	}//end maxFileBytes()

	/**
	 * Preview an export: per row the match or create decision for supplier, product and version. Writes nothing.
	 *
	 * @param string $path The uploaded file.
	 * @param string $fileName The name the client gave it.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>, warnings: array<int, mixed>}
	 *
	 * @throws CmdbImportException FORBIDDEN before the file is read; NOT_XLSX, MISSING_COLUMN and the other read errors.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function preview(string $path, string $fileName): array {
		$this->assertMayImport();

		$profile = $this->openRegister->profile();
		$profile->load();
		$reader = $this->reader();
		$reader->assertXlsx(path: $path, fileName: $fileName);
		$read = $reader->read(path: $path, profile: $profile);

		$preview = $this->previewRows(rows: ($read['rows'] ?? []));
		$preview['warnings'] = array_values(($read['warnings'] ?? []));
		return $preview;
	}//end preview()

	/**
	 * The preview of rows as the workbook reader returns them.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with `sheet`, `row` and `cells`.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
	 *
	 * @throws CmdbImportException NOT_CONFIGURED or MAPPING_UNAVAILABLE.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	public function previewRows(array $rows): array {
		$mapper = new LegacyCatalogueRowMapper(profile: $this->openRegister->profile(), engine: $this->openRegister->engine());
		$mapped = array_map(static fn (array $row): array => $mapper->map(row: $row), $rows);

		$matchable = array_values(array_filter($mapped, static fn (array $row): bool => $row['skipped'] === false));
		$candidates = $this->openRegister->candidates()->forRows(rows: array_column($matchable, 'match'));
		$matcher = new LegacyCatalogueMatcher(
			organisations: $candidates['organisations'],
			modules: $candidates['modules'],
			versions: $candidates['versions']
		);

		$preview = [];
		foreach ($mapped as $row) {
			$preview[] = self::decided(row: $row, matcher: $matcher);
		}

		return ['rows' => $preview, 'summary' => self::summary(rows: $preview)];
	}//end previewRows()

	/**
	 * A mapped row with the matcher's decisions, as the preview answers it.
	 *
	 * @param array<string, mixed> $row The mapped row.
	 * @param LegacyCatalogueMatcher $matcher The matcher.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function decided(array $row, LegacyCatalogueMatcher $matcher): array {
		$match = $row['match'];
		$decisions = [
			'supplier' => ['decision' => self::NONE, 'id' => null],
			'product' => ['decision' => self::NONE, 'id' => null],
			'version' => ['decision' => self::NONE, 'id' => null],
		];
		if ($row['skipped'] === false) {
			$decisions = $matcher->decide(row: $match);
		}

		if ($match['version'] === '') {
			$decisions['version'] = ['decision' => self::NONE, 'id' => null];
		}

		return [
			'sheet' => $row['sheet'],
			'row' => $row['row'],
			'legacyId' => $row['legacyId'],
			'supplier' => array_merge(['name' => $match['supplierName'], 'kvk' => $match['supplierKvk']], $decisions['supplier']),
			'product' => array_merge(['name' => $match['product']], $decisions['product']),
			'version' => array_merge(['number' => $match['version']], $decisions['version']),
			'usage' => $row['records']['usage'],
			'errors' => $row['errors'],
			'skipped' => $row['skipped'],
		];
	}//end decided()

	/**
	 * The counts of a preview: rows, skipped rows, and per object the matched and created ones.
	 *
	 * @param array<int, array<string, mixed>> $rows The previewed rows.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private static function summary(array $rows): array {
		$summary = ['rows' => count($rows), 'skipped' => 0];
		foreach (['supplier', 'product', 'version'] as $object) {
			$summary[$object] = [LegacyCatalogueMatcher::MATCHED => 0, LegacyCatalogueMatcher::CREATE => 0];
		}

		foreach ($rows as $row) {
			if ($row['skipped'] === true) {
				$summary['skipped']++;
				continue;
			}

			foreach (['supplier', 'product', 'version'] as $object) {
				$decision = $row[$object]['decision'];
				if (isset($summary[$object][$decision]) === true) {
					$summary[$object][$decision]++;
				}
			}
		}

		return $summary;
	}//end summary()

	/**
	 * The workbook reader.
	 *
	 * @return CmdbWorkbookReader
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-2
	 */
	private function reader(): CmdbWorkbookReader {
		if ($this->reader === null) {
			$this->reader = new CmdbWorkbookReader();
		}

		return $this->reader;
	}//end reader()
}//end class
