<?php

/**
 * What every row and the reconciliation of one CMDB import share.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

/**
 * One import run: its report, the municipality, the archive sheet and the options.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
final class CmdbImportRun {

	/**
	 * The consumer, as the service resolved it.
	 *
	 * @var array{uuid: string, name: string, created: bool}
	 */
	public readonly array $municipality;

	/**
	 * The archive sheet.
	 *
	 * @var array{sheet: string, present: bool, keyColumnMissing: bool, uncachedRows: array<int, int>, keys: array<string, true>}
	 */
	public readonly array $archive;

	/**
	 * The operation id.
	 *
	 * @var string
	 */
	public readonly string $operationId;

	/**
	 * The `missingRecords` mode: keep or archive.
	 *
	 * @var string
	 */
	public readonly string $missingRecords;

	/**
	 * Whether a matched module is updated.
	 *
	 * @var bool
	 */
	public readonly bool $updateExisting;

	/**
	 * The publicationDate of a created module, or null to create it unpublished.
	 *
	 * @var string|null
	 */
	public readonly ?string $publicationDate;

	/**
	 * The workbook's date system.
	 *
	 * @var bool
	 */
	public readonly bool $date1904;

	/**
	 * Build the run.
	 *
	 * @param CmdbImportReport     $report   The report.
	 * @param array<string, mixed> $settings municipality, archive, operationId, missingRecords,
	 *                                       updateExisting, publicationDate and date1904.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function __construct(
		public readonly CmdbImportReport $report,
		array $settings,
	) {
		$this->municipality    = $settings['municipality'];
		$this->archive         = $settings['archive'];
		$this->operationId     = (string) $settings['operationId'];
		$this->missingRecords  = (string) $settings['missingRecords'];
		$this->updateExisting  = (bool) $settings['updateExisting'];
		$this->publicationDate = $settings['publicationDate'];
		$this->date1904        = (bool) $settings['date1904'];
	}//end __construct()

	/**
	 * The consumer's uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function municipalityUuid(): string {
		return $this->municipality['uuid'];
	}//end municipalityUuid()
}//end class
