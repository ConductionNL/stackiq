<?php

/**
 * The ArchiMate export writes no copy of the exported model anywhere.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * A leftover debug line wrote every exported model to `/tmp/debug_export.xml`:
 * a fixed name in a folder every process on the host can read, and logged at
 * info level. The export is returned to the caller; it must not leave a copy.
 */
class ArchiMateExportNoDebugDumpTest extends TestCase {

	public function testTheExportServiceWritesNoFile(): void {
		$code = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ArchiMateExportService.php');
		$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code);

		$this->assertStringNotContainsString('debug_export', $code);
		$this->assertSame(0, preg_match('/\bfile_put_contents\s*\(|\bfopen\s*\(/', $code), 'the export returns its XML and writes no file');
	}
}
