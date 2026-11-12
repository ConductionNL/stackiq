<?php

/**
 * Record vCard mapping for Stackiq
 *
 * Maps a relationship record's legacy identity fields to vCard properties
 * and reads (possibly multi-valued) vCard property values.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Stateless vCard helpers of StackiqContactSyncService.
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */
final class RecordVCard {
	/**
	 * Map a relationship record's legacy identity fields to vCard properties.
	 *
	 * @param string $objectType The relationship type.
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The vCard property set.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function properties(string $objectType, array $record): array {
		// The identity block differs per kind; the contact channels below are
		// shared by both.
		$properties = $this->identityProperties(objectType: $objectType, record: $record);

		$email = trim((string)($record['e-mailadres'] ?? $record['email'] ?? ''));
		if ($email !== '') {
			$properties['EMAIL'] = $email;
		}

		$phone = trim((string)($record['telefoonnummer'] ?? ''));
		if ($phone !== '') {
			$properties['TEL'] = $phone;
		}

		return $properties;
	}//end properties()

	/**
	 * Select and build the kind-specific vCard identity properties.
	 *
	 * @param string $objectType The relationship type.
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The identity property set.
	 */
	private function identityProperties(string $objectType, array $record): array {
		if ($objectType === 'organization') {
			return $this->organisationIdentityProperties(record: $record);
		}

		return $this->personIdentityProperties(record: $record);
	}//end identityProperties()

	/**
	 * Build the organisation-kind vCard identity properties.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The identity property set.
	 */
	private function organisationIdentityProperties(array $record): array {
		$name = trim((string)($record['name'] ?? ''));
		$properties = [
			'FN' => $name,
			'ORG' => $name,
			'KIND' => 'org',
		];

		$cbsCode = trim((string)($record['cbsCode'] ?? ''));
		if ($cbsCode !== '') {
			$properties['X-KVK'] = $cbsCode;
			$properties['NICKNAME'] = $cbsCode;
		}

		$website = trim((string)($record['website'] ?? ''));
		if ($website !== '') {
			$properties['URL'] = $website;
		}

		return $properties;
	}//end organisationIdentityProperties()

	/**
	 * Build the person-kind vCard identity properties.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The identity property set.
	 */
	private function personIdentityProperties(array $record): array {
		$voornaam = trim((string)($record['voornaam'] ?? ''));
		$tussenvoegsel = trim((string)($record['tussenvoegsel'] ?? ''));
		$lastName = trim((string)($record['achternaam'] ?? ''));
		$family = trim(trim($tussenvoegsel . ' ' . $lastName));

		$properties = [
			'FN' => trim($voornaam . ' ' . $family),
			// N = Family;Given;Additional;Prefix;Suffix.
			'N' => $family . ';' . $voornaam . ';;;',
		];

		$role = trim((string)($record['role'] ?? ''));
		if ($role !== '') {
			$properties['TITLE'] = $role;
		}

		return $properties;
	}//end personIdentityProperties()

	/**
	 * Whether a (possibly multi-valued) vCard property contains the given
	 * needle, case-insensitively.
	 *
	 * @param mixed $value The raw vCard property value.
	 * @param string $needle The needle to compare against.
	 *
	 * @return boolean True when the value equals the needle.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function valueMatches(mixed $value, string $needle): bool {
		$needle = strtolower(trim($needle));
		if ($needle === '') {
			return false;
		}

		if (is_array($value) === true) {
			foreach ($value as $candidate) {
				if (is_array($candidate) === true) {
					$candidate = ($candidate['value'] ?? '');
				}

				if (strtolower(trim((string)$candidate)) === $needle) {
					return true;
				}
			}

			return false;
		}

		return strtolower(trim((string)$value)) === $needle;
	}//end valueMatches()

	/**
	 * Extract the first scalar value from a vCard property that may be an
	 * array (multi-valued / typed) or a string.
	 *
	 * @param mixed $value The raw property value.
	 *
	 * @return string The first scalar value as a string.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function firstValue(mixed $value): string {
		if (is_array($value) === true) {
			$first = ($value[0] ?? '');
			if (is_array($first) === true) {
				return (string)($first['value'] ?? '');
			}

			return (string)$first;
		}

		return (string)$value;
	}//end firstValue()
}//end class
