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
}//end class
