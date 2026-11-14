<?php

/**
 * One row of a CMDB import, and what its steps found so far.
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
 * A row (or an application the reconciliation handles): where it is, its values,
 * its warnings, the step it is at and the objects it saved.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
final class CmdbImportRow {

	/**
	 * The normalised row, whose values are also taken out of a failure's log line.
	 *
	 * @var array<string, string>
	 */
	public array $values = [];

	/**
	 * Row warnings.
	 *
	 * @var array<int, string>
	 */
	public array $warnings = [];

	/**
	 * The APPID's match key.
	 *
	 * @var string
	 */
	public string $matchKey = '';

	/**
	 * The module's import key.
	 *
	 * @var string
	 */
	public string $externalKey = '';

	/**
	 * The step the row is at.
	 *
	 * @var string
	 */
	public string $step = 'mapping';

	/**
	 * The supplier, when there is one.
	 *
	 * @var string|null
	 */
	public ?string $providerUuid = null;

	/**
	 * The owner contact persons by usage field.
	 *
	 * @var array<string, string|null>
	 */
	public array $owners = [];

	/**
	 * The module, once saved or matched.
	 *
	 * @var string|null
	 */
	public ?string $moduleUuid = null;

	/**
	 * The usage, once saved.
	 *
	 * @var string|null
	 */
	public ?string $usageUuid = null;

	/**
	 * Build the row.
	 *
	 * @param CmdbImportRun|null                                          $run   The run, or null for a lookup outside one.
	 * @param array{sheet: string, row: int, appId: string, name: string} $entry Where the row is.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function __construct(
		public readonly ?CmdbImportRun $run,
		public readonly array $entry,
	) {
	}//end __construct()

	/**
	 * Add the row's outcome to the run's report, with its warnings and objects.
	 *
	 * @param string             $outcome The outcome.
	 * @param array<int, string> $reasons Reasons.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function report(string $outcome, array $reasons = []): void {
		$this->run?->report->addRow(row: $this, outcome: new CmdbRowOutcome(outcome: $outcome, reasons: $reasons));
	}//end report()
}//end class
