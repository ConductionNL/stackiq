<?php

/**
 * OpenRegister's access flags are passed by name, never by position.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `find()`, `findAll()` and `saveObject()` take `$_rbac` and `$_multitenancy`
 * after optional parameters. Passed by position, a parameter added or moved in
 * OpenRegister silently shifts which flag a `false` turns off.
 */
final class NamedAccessFlagsTest extends TestCase {
	/**
	 * No call in lib/ passes a bare boolean by position to these methods.
	 *
	 * @return void
	 */
	public function testAccessFlagsAreNamed(): void {
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib'));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			preg_match_all('/->(?:find|findAll|saveObject)\(((?:[^()]|\((?1)\))*)\)/s', $source, $calls, PREG_OFFSET_CAPTURE);
			foreach ($calls[1] as [$arguments, $offset]) {
				if (preg_match('/(^|,)\s*(true|false)\s*(?=,|$)/', $arguments) === 1) {
					$found[] = basename($file->getPathname()) . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
				}
			}
		}

		$this->assertSame(expected: [], actual: $found, message: 'Pass OpenRegister access flags by name.');
	}//end testAccessFlagsAreNamed()
}//end class
