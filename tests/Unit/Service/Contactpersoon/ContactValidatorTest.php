<?php

/**
 * Unit tests for ContactValidator.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Contactpersoon;

use InvalidArgumentException;
use OCA\Stackiq\Service\Contactpersoon\ContactValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the normalisation and rejection rules for contact person fields.
 *
 * @covers \OCA\Stackiq\Service\Contactpersoon\ContactValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class ContactValidatorTest extends TestCase {

	/**
	 * @var ContactValidator
	 */
	private ContactValidator $validator;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new ContactValidator();
	}//end setUp()

	/**
	 * An email address is trimmed and lowercased.
	 *
	 * @return void
	 */
	public function testEmailIsTrimmedAndLowercased(): void {
		$this->assertSame(expected: 'jan@gemeente.nl', actual: $this->validator->validateEmail('  Jan@Gemeente.NL '));
	}//end testEmailIsTrimmedAndLowercased()

	/**
	 * Invalid email input and the message it is rejected with.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalidEmails(): array {
		return [
			'empty' => ['', 'Email address may not be empty.'],
			'only spaces' => ['   ', 'Email address may not be empty.'],
			'no at sign' => ['jan.gemeente.nl', 'Invalid email address: "jan.gemeente.nl".'],
			'no domain' => ['jan@', 'Invalid email address: "jan@".'],
		];
	}//end invalidEmails()

	/**
	 * An empty or malformed email address is rejected.
	 *
	 * @param string $email   The input.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('invalidEmails')]
	public function testInvalidEmailIsRejected(string $email, string $message): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: $message);
		$this->validator->validateEmail($email);
	}//end testInvalidEmailIsRejected()

	/**
	 * Spaces and dashes are stripped from a phone number; + and parentheses stay.
	 *
	 * @return void
	 */
	public function testPhoneIsNormalised(): void {
		$this->assertSame(expected: '0612345678', actual: $this->validator->validatePhone(' 06-1234 5678 '));
		$this->assertSame(expected: '+31(0)201234567', actual: $this->validator->validatePhone('+31 (0)20 123 4567'));
	}//end testPhoneIsNormalised()

	/**
	 * Invalid phone input and the message it is rejected with.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalidPhones(): array {
		return [
			'empty' => ['', 'Phone number may not be empty.'],
			'only separators' => [' - - ', 'Phone number may not be empty.'],
			'letters' => ['06-ABCDEFGH', 'Invalid phone number format: "06-ABCDEFGH".'],
			'too short' => ['12345', 'Invalid phone number format: "12345".'],
			'too long' => ['1234567890123456', 'Invalid phone number format: "1234567890123456".'],
			'plus in the middle' => ['06+12345678', 'Invalid phone number format: "06+12345678".'],
		];
	}//end invalidPhones()

	/**
	 * An empty or malformed phone number is rejected with the original input in the message.
	 *
	 * @param string $phone   The input.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('invalidPhones')]
	public function testInvalidPhoneIsRejected(string $phone, string $message): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: $message);
		$this->validator->validatePhone($phone);
	}//end testInvalidPhoneIsRejected()

	/**
	 * A name is trimmed and accepted up to 255 bytes.
	 *
	 * @return void
	 */
	public function testNameIsTrimmedAndAcceptedAtTheLimit(): void {
		$this->assertSame(expected: 'Jansen', actual: $this->validator->validateName("  Jansen\t"));
		$atLimit = str_repeat('a', 255);
		$this->assertSame(expected: $atLimit, actual: $this->validator->validateName($atLimit));
	}//end testNameIsTrimmedAndAcceptedAtTheLimit()

	/**
	 * An empty name is rejected.
	 *
	 * @return void
	 */
	public function testEmptyNameIsRejected(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Name may not be empty.');
		$this->validator->validateName('   ');
	}//end testEmptyNameIsRejected()

	/**
	 * A name over 255 bytes is rejected with its length.
	 *
	 * @return void
	 */
	public function testOverlongNameIsRejected(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Name exceeds maximum length of 255 characters (got 256).');
		$this->validator->validateName(str_repeat('a', 256));
	}//end testOverlongNameIsRejected()

	/**
	 * Valid, absent and empty fields produce no errors.
	 *
	 * @return void
	 */
	public function testValidOrAbsentContactDataHasNoErrors(): void {
		$this->assertSame(expected: [], actual: $this->validator->validateContactData([]));
		$this->assertSame(
			expected: [],
			actual: $this->validator->validateContactData(
				[
					'e-mailadres' => '',
					'telefoonnummer' => '',
					'voornaam' => '',
					'achternaam' => '',
				]
			)
		);
		$this->assertSame(
			expected: [],
			actual: $this->validator->validateContactData(
				[
					'e-mailadres' => 'jan@gemeente.nl',
					'telefoonnummer' => '0612345678',
					'voornaam' => 'Jan',
					'achternaam' => 'Jansen',
				]
			)
		);
	}//end testValidOrAbsentContactDataHasNoErrors()

	/**
	 * Every invalid field is reported under its own key.
	 *
	 * @return void
	 */
	public function testEveryInvalidFieldIsReported(): void {
		$errors = $this->validator->validateContactData(
			[
				'e-mailadres' => 'geen-adres',
				'telefoonnummer' => 'bel mij',
				'voornaam' => '   ',
				'achternaam' => str_repeat('b', 300),
			]
		);

		$this->assertSame(
			expected: [
				'e-mailadres' => 'Invalid email address: "geen-adres".',
				'telefoonnummer' => 'Invalid phone number format: "bel mij".',
				'voornaam' => 'Name may not be empty.',
				'achternaam' => 'Name exceeds maximum length of 255 characters (got 300).',
			],
			actual: $errors
		);
	}//end testEveryInvalidFieldIsReported()
}//end class
