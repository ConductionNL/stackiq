<?php

/**
 * CMDB read filter.
 *
 * Tells PhpSpreadsheet which cells of a CMDB export to materialise, so the
 * cell objects a workbook yields are bounded by the profile (design D3). The
 * header pass admits row 1 only; the data pass admits the rows up to a last
 * row and, per sheet, only the columns the header resolved to an allowlisted
 * name. Every other cell is skipped and never becomes a cell object. The
 * filter does not bound the parse itself: PhpSpreadsheet still builds the
 * shared-strings table and each loaded sheet's XML tree whole, which is why
 * CmdbWorkbookReader caps the parts and the shared strings before loading.
 *
 * The class implements PhpSpreadsheet's `IReadFilter`, which OpenRegister
 * ships. It is only instantiated after `CmdbWorkbookReader::isAvailable()`.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Admits the header row, or the allowlisted columns of the data rows.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 */
class CmdbReadFilter implements IReadFilter {
	/**
	 * Constructor.
	 *
	 * @param int $lastRow The last row number that is read; 1 reads the header row only.
	 * @param array<string, array<int, string>> $columns Sheet name => column letters of the data rows.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function __construct(
		private readonly int $lastRow,
		private readonly array $columns = [],
	) {
	}//end __construct()

	/**
	 * Whether a cell is read.
	 *
	 * @param string $columnAddress The column letters.
	 * @param int $row The row number.
	 * @param string $worksheetName The sheet name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
		if ($row === 1) {
			return $this->lastRow === 1;
		}

		if ($row < 1 || $row > $this->lastRow) {
			return false;
		}

		return in_array(strtoupper($columnAddress), ($this->columns[$worksheetName] ?? []), true);
	}//end readCell()
}//end class
