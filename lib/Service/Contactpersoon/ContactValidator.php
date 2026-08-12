<?php

/**
 * Contact Validator for SoftwareCatalog
 *
 * Extracts email, phone, and name validation from ContactpersoonService to reduce
 * CyclomaticComplexity and ExcessiveClassLength on that service.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\Contactpersoon;

use InvalidArgumentException;

/**
 * Validates contact person fields: email, phone, and name.
 *
 * All methods return either the sanitised/normalised value on success, or
 * throw an InvalidArgumentException describing the validation failure.
 * This allows ContactpersoonService to inline guard-clause checks without
 * carrying the full validation logic in its own body.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class ContactValidator {
	/**
	 * Validate and normalise an email address.
	 *
	 * @param string $email The raw email address to validate.
	 *
	 * @return string The normalised (lowercased, trimmed) email address.
	 *
	 * @throws InvalidArgumentException When the email address is syntactically invalid.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	public function validateEmail(string $email): string {
		$normalized = strtolower(trim($email));

		if ($normalized === '') {
			throw new InvalidArgumentException('Email address may not be empty.');
		}

		if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException(
				sprintf('Invalid email address: "%s".', $normalized)
			);
		}

		return $normalized;
	}//end validateEmail()

	/**
	 * Validate and normalise a phone number.
	 *
	 * Accepts Dutch and international formats. Strips whitespace and dashes for
	 * storage; returns the normalised string.
	 *
	 * @param string $phone The raw phone number to validate.
	 *
	 * @return string The normalised phone number.
	 *
	 * @throws InvalidArgumentException When the phone number contains illegal characters.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	public function validatePhone(string $phone): string {
		$normalized = preg_replace('/[\s\-]/', '', trim($phone));

		if ($normalized === null || $normalized === '') {
			throw new InvalidArgumentException('Phone number may not be empty.');
		}

		// Accept digits, leading +, and parentheses only.
		if (preg_match('/^\+?[\d()]{6,15}$/', $normalized) !== 1) {
			throw new InvalidArgumentException(
				sprintf('Invalid phone number format: "%s".', $phone)
			);
		}

		return $normalized;
	}//end validatePhone()

	/**
	 * Validate a contact person name (voornaam or achternaam).
	 *
	 * @param string $name The raw name value.
	 *
	 * @return string The trimmed name.
	 *
	 * @throws InvalidArgumentException When the name is empty or exceeds the maximum length.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	public function validateName(string $name): string {
		$trimmed = trim($name);

		if ($trimmed === '') {
			throw new InvalidArgumentException('Name may not be empty.');
		}

		if (strlen($trimmed) > 255) {
			throw new InvalidArgumentException(
				sprintf('Name exceeds maximum length of 255 characters (got %d).', strlen($trimmed))
			);
		}

		return $trimmed;
	}//end validateName()

	/**
	 * Validate a full contact data array for required and format constraints.
	 *
	 * @param array<string,mixed> $data The contact data array (typically from a request or OR object).
	 *
	 * @return array<string,string> Map of field → error message for each violation found.
	 *                              Empty array means all validations passed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	public function validateContactData(array $data): array {
		$errors = [];

		$errors = array_merge($errors, $this->validateEmailField(data: $data));
		$errors = array_merge($errors, $this->validatePhoneField(data: $data));
		$errors = array_merge($errors, $this->validateNameField(data: $data, key: 'voornaam'));
		$errors = array_merge($errors, $this->validateNameField(data: $data, key: 'achternaam'));

		return $errors;
	}//end validateContactData()

	/**
	 * Validate the e-mailadres field if present.
	 *
	 * @param array<string,mixed> $data Contact data array.
	 *
	 * @return array<string,string> Errors keyed by field name.
	 */
	private function validateEmailField(array $data): array {
		if (isset($data['e-mailadres']) === false || $data['e-mailadres'] === '') {
			return [];
		}

		try {
			$this->validateEmail(email: (string)$data['e-mailadres']);
		} catch (InvalidArgumentException $e) {
			return ['e-mailadres' => $e->getMessage()];
		}

		return [];
	}//end validateEmailField()

	/**
	 * Validate the telefoonnummer field if present.
	 *
	 * @param array<string,mixed> $data Contact data array.
	 *
	 * @return array<string,string> Errors keyed by field name.
	 */
	private function validatePhoneField(array $data): array {
		if (isset($data['telefoonnummer']) === false || $data['telefoonnummer'] === '') {
			return [];
		}

		try {
			$this->validatePhone(phone: (string)$data['telefoonnummer']);
		} catch (InvalidArgumentException $e) {
			return ['telefoonnummer' => $e->getMessage()];
		}

		return [];
	}//end validatePhoneField()

	/**
	 * Validate a name field by key if present.
	 *
	 * @param array<string,mixed> $data Contact data array.
	 * @param string $key The field key to validate (e.g. 'voornaam', 'achternaam').
	 *
	 * @return array<string,string> Errors keyed by field name.
	 */
	private function validateNameField(array $data, string $key): array {
		if (isset($data[$key]) === false || $data[$key] === '') {
			return [];
		}

		try {
			$this->validateName(name: (string)$data[$key]);
		} catch (InvalidArgumentException $e) {
			return [$key => $e->getMessage()];
		}

		return [];
	}//end validateNameField()
}//end class
