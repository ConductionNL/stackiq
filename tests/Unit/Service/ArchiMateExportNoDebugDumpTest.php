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

	/**
	 * The service source holds no file write and no debug dump path.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-export/spec.md
	 */
	public function testTheExportServiceWritesNoFile(): void {
		$code = (string)file_get_contents(filename: dirname(path: __DIR__, levels: 3) . '/lib/Service/ArchiMateExportService.php');
		$code = (string)preg_replace(pattern: '#/\*.*?\*/|//[^\n]*#s', replacement: '', subject: $code);

		$this->assertStringNotContainsString(needle: 'debug_export', haystack: $code);
		$this->assertSame(
			expected: 0,
			actual: preg_match(pattern: '/\bfile_put_contents\s*\(|\bfopen\s*\(/', subject: $code),
			message: 'the export returns its XML and writes no file'
		);
	}
}
