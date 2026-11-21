<?php

/**
 * Who may mark a compliance claim verified, and when a claim goes back to claimed.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Pure rules over compliancy records. A claim reads verified only once a
 * reviewer of another organisation than the supplier marked it; evidence alone
 * leaves it claimed, and changed evidence returns it to claimed.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */
final class ComplianceVerification {

	/**
	 * The fields only the verify action writes.
	 *
	 * @var list<string>
	 */
	public const FIELDS = ['verifiedBy', 'verifiedAt', 'verifiedByOrganisation'];

	/**
	 * The fields that carry a claim's evidence.
	 *
	 * @var list<string>
	 */
	public const EVIDENCE = ['evidence', 'url', 'evidenceReference'];

	/**
	 * The groups with review rights.
	 *
	 * @var list<string>
	 */
	public const REVIEW_GROUPS = ['admin', 'software-catalog-admins', 'functioneel-beheerder'];

	/**
	 * Whether a value is set: not null, not a blank string, not an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isSet(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return $value !== null && $value !== false;
	}//end isSet()

	/**
	 * Whether a record carries evidence.
	 *
	 * @param array<string, mixed> $record The compliancy record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public static function hasEvidence(array $record): bool {
		foreach (self::EVIDENCE as $field) {
			if (self::isSet(value: ($record[$field] ?? null)) === true) {
				return true;
			}
		}

		return false;
	}//end hasEvidence()

	/**
	 * A field's value, or null when it is not set.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $field  The field.
	 *
	 * @return mixed
	 */
	private static function valueOf(array $record, string $field): mixed {
		if (self::isSet(value: ($record[$field] ?? null)) === false) {
			return null;
		}

		return $record[$field];
	}//end valueOf()

	/**
	 * Whether any of the given fields differs between two states.
	 *
	 * @param array<string, mixed> $new    The record as it will be stored.
	 * @param array<string, mixed> $old    The stored record ([] on create).
	 * @param list<string>         $fields The fields to compare.
	 *
	 * @return bool
	 */
	private static function differs(array $new, array $old, array $fields): bool {
		foreach ($fields as $field) {
			if (json_encode(self::valueOf(record: $old, field: $field)) !== json_encode(self::valueOf(record: $new, field: $field))) {
				return true;
			}
		}

		return false;
	}//end differs()

	/**
	 * Whether a write changes the verification fields.
	 *
	 * @param array<string, mixed> $new The record as it will be stored.
	 * @param array<string, mixed> $old The stored record ([] on create).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public static function changesVerification(array $new, array $old): bool {
		return self::differs(new: $new, old: $old, fields: self::FIELDS);
	}//end changesVerification()

	/**
	 * The fields to clear when a write changes the evidence of a verified claim, or [].
	 *
	 * @param array<string, mixed> $new The record as it will be stored.
	 * @param array<string, mixed> $old The stored record ([] on create).
	 *
	 * @return array<string, null>
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public static function resetOnEvidenceChange(array $new, array $old): array {
		if (self::isSet(value: ($old['verifiedBy'] ?? null)) === false
			|| self::differs(new: $new, old: $old, fields: self::EVIDENCE) === false
		) {
			return [];
		}

		return array_fill_keys(self::FIELDS, null);
	}//end resetOnEvidenceChange()

	/**
	 * Why a caller may not verify a claim, as [HTTP status, message], or null when it may.
	 *
	 * The supplier is the module's provider. An unknown supplier refuses, so a
	 * claim whose application cannot be read is never verified by default.
	 *
	 * @param list<string>         $groups       The caller's groups.
	 * @param string               $organisation The caller's active organisation uuid.
	 * @param string|null          $supplier     The supplier organisation uuid.
	 * @param array<string, mixed> $record       The compliancy record.
	 *
	 * @return array{0: int, 1: string}|null
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	public static function refusal(array $groups, string $organisation, ?string $supplier, array $record): ?array {
		if (array_intersect(self::REVIEW_GROUPS, $groups) === []) {
			return [403, 'Only a reviewer may mark a claim verified'];
		}

		if ($organisation === '') {
			return [403, 'A reviewer needs an active organisation'];
		}

		if ($supplier === null || $supplier === '') {
			return [409, 'The supplier of this claim is not known'];
		}

		if ($organisation === $supplier) {
			return [403, 'A supplier cannot verify its own claim'];
		}

		if (self::hasEvidence(record: $record) === false) {
			return [422, 'A claim without evidence cannot be verified'];
		}

		return null;
	}//end refusal()
}//end class
