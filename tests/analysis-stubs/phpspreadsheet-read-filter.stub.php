<?php

/**
 * Static-analysis stub for PhpSpreadsheet's read-filter interface.
 *
 * ANALYSIS-ONLY — referenced from phpstan.neon `scanFiles` and psalm.xml
 * `<stubs>`, and NEVER loaded at runtime or during PHPUnit, where the real
 * interface comes from OpenRegister's vendor directory.
 *
 * PhpSpreadsheet is shipped by OpenRegister, not by this app's composer.json,
 * so it is absent from the analysis path. CmdbReadFilter implements this
 * interface; the signature mirrors
 * phpoffice/phpspreadsheet/src/PhpSpreadsheet/Reader/IReadFilter.php (5.x).
 *
 * @category Test
 * @package  PhpOffice\PhpSpreadsheet\Reader
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace PhpOffice\PhpSpreadsheet\Reader;

/**
 * Should a cell be read?
 */
interface IReadFilter {
	/**
	 * Should this cell be read?
	 *
	 * @param string $columnAddress Column address, such as "A" or "IV".
	 * @param int $row Row number.
	 * @param string $worksheetName Optional worksheet name.
	 *
	 * @return bool
	 */
	public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool;
}//end interface
