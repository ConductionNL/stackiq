<?php

/**
 * Unit tests for CamelCaseNames.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\CamelCaseNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the label to camelCase conversion.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CamelCaseNamesTest extends TestCase {

	/**
	 * Each label converts to the expected camelCase name.
	 *
	 * @param string $label    The property label.
	 * @param string $expected The camelCase name.
	 *
	 * @return void
	 */
	#[DataProvider('labels')]
	public function testConvertName(string $label, string $expected): void {
		$this->assertSame($expected, (new CamelCaseNames())->convertName($label));
	}//end testConvertName()

	/**
	 * Labels and their camelCase names.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function labels(): array {
		return [
			'two words'          => ['Object ID', 'objectId'],
			'business unit'      => ['Business Unit', 'businessUnit'],
			'single word'        => ['GEMMA', 'gemma'],
			'three words'        => ['GEMMA Type Name', 'gemmaTypeName'],
			'surrounding spaces' => ['  System Name  ', 'systemName'],
			'mixed case words'   => ['sYSTEM nAME', 'systemName'],
			'empty'              => ['', ''],
		];
	}//end labels()

	/**
	 * Repeated conversions return the same result from the cache.
	 *
	 * @return void
	 */
	public function testRepeatedConversionIsStable(): void {
		$names = new CamelCaseNames();

		$this->assertSame('objectId', $names->convertName('Object ID'));
		$this->assertSame('objectId', $names->convertName('Object ID'));
		$this->assertSame('otherName', $names->convertName('Other Name'));
	}//end testRepeatedConversionIsStable()
}//end class
