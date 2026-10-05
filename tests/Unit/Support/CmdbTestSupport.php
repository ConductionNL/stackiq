<?php

/**
 * Shared helpers for the CMDB import tests.
 *
 * Loads OpenRegister's migration-pack classes and the PhpSpreadsheet library
 * OpenRegister ships, without adding either to this app's composer.json:
 *
 * - `MappingEngine` and `PackDefinitionValidator` come from a real
 *   OpenRegister checkout when one is found (`OPENREGISTER_DIR`, or a sibling
 *   `openregister` app directory), and otherwise from the verbatim copies in
 *   `tests/Unit/Support/OpenRegister/`.
 * - PhpSpreadsheet only comes from a real OpenRegister `vendor/`. Without it
 *   the reader tests are skipped, never passed.
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

/**
 * Locates and loads the OpenRegister pieces the CMDB import uses.
 */
final class CmdbTestSupport {
	/**
	 * Which migration-pack classes were loaded: "real" or "copy".
	 *
	 * @var string|null
	 */
	private static ?string $packSource = null;

	/**
	 * The app root.
	 *
	 * @return string
	 */
	public static function appRoot(): string {
		return dirname(__DIR__, 3);
	}//end appRoot()

	/**
	 * The fixture directory.
	 *
	 * @return string
	 */
	public static function fixtures(): string {
		return self::appRoot() . '/tests/fixtures/cmdb';
	}//end fixtures()

	/**
	 * The OpenRegister app directory, when one is available.
	 *
	 * @return string|null
	 */
	public static function openRegisterDir(): ?string {
		$candidates = [];
		$env = getenv('OPENREGISTER_DIR');
		if (is_string($env) === true && $env !== '') {
			$candidates[] = $env;
		}

		// An app next to openregister, or a worktree two levels below the apps directory.
		$candidates[] = dirname(self::appRoot()) . '/openregister';
		$candidates[] = dirname(self::appRoot(), 2) . '/openregister';

		foreach ($candidates as $candidate) {
			if (is_file($candidate . '/lib/Service/MigrationPack/MappingEngine.php') === true) {
				return $candidate;
			}
		}

		return null;
	}//end openRegisterDir()

	/**
	 * Load MappingEngine and PackDefinitionValidator.
	 *
	 * @return string "real" or "copy".
	 */
	public static function loadMigrationPack(): string {
		if (self::$packSource !== null) {
			return self::$packSource;
		}

		$dir = self::openRegisterDir();
		$source = 'copy';
		$base = __DIR__ . '/OpenRegister';
		if ($dir !== null) {
			$source = 'real';
			$base = $dir . '/lib/Service/MigrationPack';
		}

		foreach (['PackDefinitionValidator', 'MappingEngine'] as $class) {
			if (class_exists('OCA\\OpenRegister\\Service\\MigrationPack\\' . $class, false) === false) {
				require_once $base . '/' . $class . '.php';
			}
		}

		self::$packSource = $source;
		return $source;
	}//end loadMigrationPack()

	/**
	 * Make PhpSpreadsheet loadable from OpenRegister's vendor directory.
	 *
	 * @return bool Whether the Xlsx reader can be loaded.
	 */
	public static function loadPhpSpreadsheet(): bool {
		if (class_exists('PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx') === true) {
			return true;
		}

		$dir = self::openRegisterDir();
		if ($dir === null || is_dir($dir . '/vendor/phpoffice/phpspreadsheet') === false) {
			return false;
		}

		$vendor = $dir . '/vendor';
		$prefixes = [
			'PhpOffice\\PhpSpreadsheet\\' => $vendor . '/phpoffice/phpspreadsheet/src/PhpSpreadsheet/',
			'Psr\\SimpleCache\\' => $vendor . '/psr/simple-cache/src/',
			'Composer\\Pcre\\' => $vendor . '/composer/pcre/src/',
			'Matrix\\' => $vendor . '/markbaker/matrix/classes/src/',
			'Complex\\' => $vendor . '/markbaker/complex/classes/src/',
		];

		// Appended, so a library this app already ships keeps winning.
		spl_autoload_register(
			static function (string $class) use ($prefixes): void {
				foreach ($prefixes as $prefix => $path) {
					if (str_starts_with($class, $prefix) === false) {
						continue;
					}

					$file = $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
					if (is_file($file) === true) {
						require_once $file;
					}

					return;
				}
			}
		);

		return class_exists('PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx') === true;
	}//end loadPhpSpreadsheet()
	/**
	 * Build a minimal xlsx package in a temporary file.
	 *
	 * A cell is a string (an inline string), an int or float (a number), or
	 * `['f' => formula, 'v' => cached value]` (a formula with its cached string
	 * value, or without one when `v` is absent). The caller removes the file.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $sheets Sheet name => rows of cells, row 1 first.
	 * @param string $prologue XML placed before every `<worksheet>` element, such as a DOCTYPE.
	 * @param array<string, string> $extraParts Path inside the package => content.
	 *
	 * @return string The file path, ending in .xlsx.
	 */
	public static function buildWorkbook(array $sheets, string $prologue = '', array $extraParts = []): string {
		$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
		$types = '';
		$entries = '';
		$rels = '';
		$parts = [];
		$number = 0;
		foreach ($sheets as $name => $rows) {
			$number++;
			$types .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$entries .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
			$rels .= '<Relationship Id="rId' . $number . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
			$parts['xl/worksheets/sheet' . $number . '.xml'] = '<?xml version="1.0" encoding="UTF-8"?>' . $prologue
				. '<worksheet xmlns="' . $main . '"><sheetData>' . self::sheetRows(rows: $rows) . '</sheetData></worksheet>';
		}

		$parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $types . '</Types>';
		$parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $pkg . '"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>';
		$parts['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets>' . $entries . '</sheets></workbook>';
		$parts['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $pkg . '">' . $rels . '</Relationships>';

		$path = tempnam(sys_get_temp_dir(), 'cmdb-wb') . '.xlsx';
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		foreach (array_merge($parts, $extraParts) as $part => $content) {
			$zip->addFromString($part, $content);
		}

		$zip->close();
		return $path;
	}//end buildWorkbook()

	/**
	 * The `<row>` elements of a sheet.
	 *
	 * @param array<int, array<int, mixed>> $rows Rows of cells, row 1 first.
	 *
	 * @return string
	 */
	private static function sheetRows(array $rows): string {
		$xml = '';
		foreach (array_values($rows) as $index => $cells) {
			$rowNumber = ($index + 1);
			$xml .= '<row r="' . $rowNumber . '">';
			foreach (array_values($cells) as $column => $value) {
				if ($value === null) {
					continue;
				}

				$xml .= self::cell(reference: self::letters(index: $column + 1) . $rowNumber, value: $value);
			}

			$xml .= '</row>';
		}

		return $xml;
	}//end sheetRows()

	/**
	 * One `<c>` element.
	 *
	 * @param string $reference The cell reference.
	 * @param mixed $value The cell, as buildWorkbook() describes.
	 *
	 * @return string
	 */
	private static function cell(string $reference, mixed $value): string {
		if (is_array($value) === true) {
			$cached = '';
			if (array_key_exists('v', $value) === true) {
				$cached = '<v>' . htmlspecialchars((string)$value['v'], ENT_XML1) . '</v>';
			}

			return '<c r="' . $reference . '" t="str"><f>' . htmlspecialchars((string)$value['f'], ENT_XML1) . '</f>' . $cached . '</c>';
		}

		if (is_int($value) === true || is_float($value) === true) {
			return '<c r="' . $reference . '"><v>' . $value . '</v></c>';
		}

		return '<c r="' . $reference . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$value, ENT_XML1) . '</t></is></c>';
	}//end cell()

	/**
	 * A 1-based column index to its letters.
	 *
	 * @param int $index The column index.
	 *
	 * @return string
	 */
	private static function letters(int $index): string {
		$letters = '';
		while ($index > 0) {
			$letters = chr(65 + (($index - 1) % 26)) . $letters;
			$index = intdiv(($index - 1), 26);
		}

		return $letters;
	}//end letters()

	/**
	 * A copy of the shipped profile directory with profile keys overridden.
	 *
	 * @param array<string, mixed> $overrides Profile key => value.
	 *
	 * @return string The directory; remove it with removeDirectory().
	 */
	public static function profileDirectory(array $overrides): string {
		$directory = sys_get_temp_dir() . '/stackiq-cmdb-profile-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(self::appRoot() . '/lib/Settings/cmdb-import/*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		$profile = json_decode((string)file_get_contents($directory . '/topdesk-profile.json'), true);
		file_put_contents($directory . '/topdesk-profile.json', json_encode(array_merge($profile, $overrides)));

		return $directory;
	}//end profileDirectory()

	/**
	 * Remove a directory made by profileDirectory().
	 *
	 * @param string $directory The directory.
	 *
	 * @return void
	 */
	public static function removeDirectory(string $directory): void {
		array_map('unlink', glob($directory . '/*.json'));
		rmdir($directory);
	}//end removeDirectory()
}//end class
