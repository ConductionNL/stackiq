<?php

/**
 * A contact person as the access review reads it.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\RoleGroupMap;
use OCP\IUser;
use OCP\IUserManager;

/**
 * The id, organisation, account and roles of one contact person under review.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
 */
final class ReviewedContactPerson {

	/**
	 * Constructor.
	 *
	 * @param string               $id               The contact person's UUID.
	 * @param string               $organisationUuid The organisation it belongs to, '' when none.
	 * @param array<string, mixed> $data             The contact person's data.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $organisationUuid,
		public readonly array $data,
	) {
	}//end __construct()

	/**
	 * Read a contact person from an OpenRegister object.
	 *
	 * @param mixed $entity The object, as OpenRegister returns it.
	 *
	 * @return self|null The contact person, or null when it has no id.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public static function fromEntity(mixed $entity): ?self {
		if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return null;
		}

		$id = '';
		if (method_exists($entity, 'getUuid') === true) {
			$id = (string) $entity->getUuid();
		}

		if ($id === '') {
			$id = (string) ($data['id'] ?? '');
		}

		if ($id === '') {
			return null;
		}

		return new self(id: $id, organisationUuid: self::organisationOf(data: $data), data: $data);
	}//end fromEntity()

	/**
	 * The username the contact person's account goes by.
	 *
	 * @return string The username, '' when it has no account.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
	 */
	public function username(): string {
		$username = ($this->data['username'] ?? '');
		if (is_string($username) === false) {
			return '';
		}

		return trim($username);
	}//end username()

	/**
	 * Whether this contact person is the given user's own.
	 *
	 * @param IUser $user The user.
	 *
	 * @return bool True when the contact person's username is the user's id, ignoring case.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
	 */
	public function isAccountOf(IUser $user): bool {
		$username = $this->username();
		return $username !== '' && mb_strtolower($username) === mb_strtolower($user->getUID());
	}//end isAccountOf()

	/**
	 * The contact person's Nextcloud account.
	 *
	 * @param IUserManager $userManager The user manager.
	 *
	 * @return IUser|null The account, or null when it has none.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public function account(IUserManager $userManager): ?IUser {
		$username = $this->username();
		if ($username === '') {
			return null;
		}

		return $userManager->get($username);
	}//end account()

	/**
	 * The contact person's roles without one role.
	 *
	 * @param RoleGroupMap $roleGroupMap The role vocabulary.
	 * @param string       $role         The canonical role to leave out.
	 *
	 * @return list<string>|null The remaining roles, or null when the role is not on the contact person.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
	 */
	public function rolesWithout(RoleGroupMap $roleGroupMap, string $role): ?array {
		$roles = ($this->data['roles'] ?? []);
		if (is_array($roles) === false) {
			return null;
		}

		$found = false;
		$remaining = [];
		foreach ($roles as $held) {
			if (is_string($held) === true && $roleGroupMap->canonicalRole(roleOrGroup: $held) === $role) {
				$found = true;
				continue;
			}

			$remaining[] = $held;
		}

		if ($found === false) {
			return null;
		}

		return $remaining;
	}//end rolesWithout()

	/**
	 * When the access was last reviewed.
	 *
	 * @return string|null The date-time, or null when never.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public function reviewedAt(): ?string {
		return self::nonEmptyString(value: $this->data['accessReviewedAt'] ?? null);
	}//end reviewedAt()

	/**
	 * Who last reviewed the access.
	 *
	 * @return string|null The reviewer's user id, or null when never reviewed.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public function reviewedBy(): ?string {
		return self::nonEmptyString(value: $this->data['accessReviewedBy'] ?? null);
	}//end reviewedBy()

	/**
	 * The organisation UUID a contact person refers to, by either spelling.
	 *
	 * @param array<string, mixed> $data The contact person's data.
	 *
	 * @return string The UUID, '' when there is none.
	 */
	private static function organisationOf(array $data): string {
		foreach (['organisation', 'organization'] as $key) {
			$value = ($data[$key] ?? null);
			if (is_array($value) === true) {
				$value = ($value['id'] ?? $value['uuid'] ?? null);
			}

			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end organisationOf()

	/**
	 * A string value, or null when it is absent or empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end nonEmptyString()
}//end class
