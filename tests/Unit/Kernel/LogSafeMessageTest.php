<?php

/**
 * LogSafeMessage keeps row values and e-mail addresses out of the log.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Kernel;

use OCA\Stackiq\Kernel\LogSafeMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LogSafeMessage::of().
 */
#[CoversClass(LogSafeMessage::class)]
class LogSafeMessageTest extends TestCase {

	/**
	 * The owner step logs no message at all, whatever the exception says.
	 *
	 * @return void
	 */
	public function testOwnerStepLogsNothing(): void {
		$e = new RuntimeException('Owner jan@example.org not found');

		$this->assertSame('', LogSafeMessage::of('owners', $e, ['owner' => 'Jan']));
	}//end testOwnerStepLogsNothing()

	/**
	 * Only the first line of the message is kept, trimmed.
	 *
	 * @return void
	 */
	public function testOnlyTheFirstLineIsKept(): void {
		$e = new RuntimeException("  Row rejected  \r\nsecond line\nthird line");

		$this->assertSame('Row rejected', LogSafeMessage::of('module', $e, []));
	}//end testOnlyTheFirstLineIsKept()

	/**
	 * The first line is cut at 300 characters, counted as characters, not bytes.
	 *
	 * @return void
	 */
	public function testMessageIsCutAtThreeHundredCharacters(): void {
		$e = new RuntimeException(str_repeat('é', 350));

		$result = LogSafeMessage::of('module', $e, []);

		$this->assertSame(300, mb_strlen($result));
		$this->assertSame(str_repeat('é', 300), $result);
	}//end testMessageIsCutAtThreeHundredCharacters()

	/**
	 * Every e-mail address is replaced by a placeholder, punctuation around it kept.
	 *
	 * @return void
	 */
	public function testEmailAddressesAreReplaced(): void {
		$e = new RuntimeException('Duplicate contact (a.b@example.org, c@d.nl) refused');

		$this->assertSame('Duplicate contact (<e-mail>, <e-mail>) refused', LogSafeMessage::of('contacts', $e, []));
	}//end testEmailAddressesAreReplaced()

	/**
	 * Row values of three characters or more are masked, case-insensitively;
	 * shorter ones are left alone.
	 *
	 * @return void
	 */
	public function testRowValuesOfThreeOrMoreCharactersAreMasked(): void {
		$e = new RuntimeException('Supplier ACME BV has no id 42 for Module X');

		$result = LogSafeMessage::of(
			'module',
			$e,
			['supplier' => 'acme bv', 'id' => '42', 'module' => 'Module X']
		);

		$this->assertSame('Supplier … has no id 42 for …', $result);
	}//end testRowValuesOfThreeOrMoreCharactersAreMasked()

	/**
	 * The longest value is masked first, so a value inside another one does not
	 * leave part of the longer one in the log.
	 *
	 * @return void
	 */
	public function testLongestValueIsMaskedFirst(): void {
		$e = new RuntimeException('Unknown application Zaaksysteem Pro');

		$result = LogSafeMessage::of(
			'module',
			$e,
			['short' => 'Zaak', 'long' => 'Zaaksysteem Pro', 'again' => 'Zaak']
		);

		$this->assertSame('Unknown application …', $result);
	}//end testLongestValueIsMaskedFirst()

	/**
	 * Non-string row values are compared as strings.
	 *
	 * @return void
	 */
	public function testNumericValuesAreComparedAsStrings(): void {
		$e = new RuntimeException('Version 2024 is not valid');

		$this->assertSame('Version … is not valid', LogSafeMessage::of('module', $e, ['version' => 2024]));
	}//end testNumericValuesAreComparedAsStrings()
}//end class
