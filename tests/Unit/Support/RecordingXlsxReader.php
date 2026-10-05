<?php

/**
 * PhpSpreadsheet's Xlsx reader, counting how often a workbook is loaded.
 *
 * Lets a reader test prove that a bound stopped the import before
 * PhpSpreadsheet loaded a sheet. Require it only after
 * CmdbTestSupport::loadPhpSpreadsheet() returned true.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
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

namespace OCA\Stackiq\Tests\Unit\Support;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The Xlsx reader with a load counter.
 */
class RecordingXlsxReader extends Xlsx {
	/**
	 * Workbooks loaded since the last reset.
	 *
	 * @var int
	 */
	public static int $loads = 0;

	/**
	 * Load a workbook and count it.
	 *
	 * @param string $filename The file.
	 * @param int $flags PhpSpreadsheet load flags.
	 *
	 * @return Spreadsheet
	 */
	public function load(string $filename, int $flags = 0): Spreadsheet {
		self::$loads++;
		return parent::load($filename, $flags);
	}//end load()
}//end class
