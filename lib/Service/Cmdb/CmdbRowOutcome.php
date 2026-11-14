<?php

/**
 * CMDB row outcome
 *
 * What happened to one CMDB import row: the outcome constant and why.
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
 * The outcome of one row, as CmdbImportReport::addRow() records it.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
final class CmdbRowOutcome {
	/**
	 * Constructor.
	 *
	 * @param string             $outcome One of the CmdbImportReport outcome constants.
	 * @param array<int, string> $reasons Why the row was skipped or failed.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly array $reasons=[],
	) {
	}//end __construct()
}//end class
