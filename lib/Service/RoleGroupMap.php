<?php

/**
 * The catalogue roles, their role groups and the groups an admin maps onto them.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * The single source of the role vocabulary.
 *
 * Each catalogue role keeps the lower-case role group the register's
 * `authorization` rules name (D1 of the design): the admin does not rename
 * that group, they choose which of their own groups grant the role. On top
 * of that the map holds the default role per organisation type and the
 * interval after which a person's access is due for review.
 *
 * Every lookup ignores case and surrounding whitespace, because the roles
 * enum is capitalised (Aanbod-beheerder) while the groups are lower case
 * (aanbod-beheerder).
 *
 * Not final, so the services that use it can be tested against a mock.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
 */
class RoleGroupMap {

	/**
	 * The catalogue roles and the role group each one keeps.
	 *
	 * @var array<string, string>
	 */
	public const ROLES = [
		'Aanbod-beheerder' => 'aanbod-beheerder',
		'Gebruik-beheerder' => 'gebruik-beheerder',
		'Gebruik-raadpleger' => 'gebruik-raadpleger',
		'Functioneel-beheerder' => 'functioneel-beheerder',
		'Organisatie-beheerder' => 'organisatie-beheerder',
		'VNG-raadpleger' => 'vng-raadpleger',
	];

	/**
	 * The default role per organisation type, keyed on the stored enum.
	 *
	 * @var array<string, string|null>
	 */
	public const DEFAULT_ORGANISATION_TYPE_ROLES = [
		'Municipality' => 'Gebruik-beheerder',
		'Supplier' => 'Aanbod-beheerder',
		'Collaboration' => 'Gebruik-beheerder',
		'Community' => 'Aanbod-beheerder',
	];

	/**
	 * Days after a review before the person is due again, when none is saved.
	 *
	 * @var int
	 */
	public const DEFAULT_REVIEW_INTERVAL_DAYS = 365;

	/**
	 * The longest review interval an admin may save, ten years.
	 *
	 * @var int
	 */
	public const MAX_REVIEW_INTERVAL_DAYS = 3650;

	/**
	 * App config key of the source groups per role.
	 *
	 * @var string
	 */
	private const KEY_ROLE_MAPPING = 'role_mapping';

	/**
	 * App config key of the default role per organisation type.
	 *
	 * @var string
	 */
	private const KEY_ORGANISATION_TYPE_ROLES = 'organisation_type_roles';

	/**
	 * App config key of the review interval.
	 *
	 * @var string
	 */
	private const KEY_REVIEW_INTERVAL = 'access_review_interval_days';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config holding the mapping.
	 * @param IL10N|null $l10n      Translates the validation messages; null answers them in English.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ?IL10N $l10n = null,
	) {
	}//end __construct()

	/**
	 * The catalogue role names, in display order.
	 *
	 * @return list<string> The roles.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
	 */
	public function roles(): array {
		return array_keys(self::ROLES);
	}//end roles()

	/**
	 * The role groups, the only groups the role sync may change.
	 *
	 * @return list<string> The lower-case role groups.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function roleGroups(): array {
		return array_values(self::ROLES);
	}//end roleGroups()

	/**
	 * The canonical name of a role, from the role or its role group in any case.
	 *
	 * @param string $roleOrGroup A role (Aanbod-beheerder) or role group (aanbod-beheerder).
	 *
	 * @return string|null The role as the enum writes it, or null when it is no catalogue role.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function canonicalRole(string $roleOrGroup): ?string {
		$needle = self::fold(value: $roleOrGroup);
		if ($needle === '') {
			return null;
		}

		foreach (self::ROLES as $role => $group) {
			if (self::fold(value: $role) === $needle || $group === $needle) {
				return $role;
			}
		}

		return null;
	}//end canonicalRole()

	/**
	 * The role group of a role.
	 *
	 * @param string $role The role, in any case.
	 *
	 * @return string|null The lower-case role group, or null for an unknown role.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function roleGroup(string $role): ?string {
		$canonical = $this->canonicalRole(roleOrGroup: $role);
		if ($canonical === null) {
			return null;
		}

		return self::ROLES[$canonical];
	}//end roleGroup()

	/**
	 * Whether a group is one of the role groups, compared without regard to case.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return bool True for a role group.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function isRoleGroup(string $groupId): bool {
		return in_array(self::fold(value: $groupId), self::ROLES, true);
	}//end isRoleGroup()

	/**
	 * The admin-chosen groups whose members hold each role.
	 *
	 * Every role is present, with an empty list when no group grants it.
	 *
	 * @return array<string, list<string>> The source groups per role.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
	 */
	public function sourceGroups(): array {
		$mapping = array_fill_keys(keys: $this->roles(), value: []);
		$stored = $this->readJson(key: self::KEY_ROLE_MAPPING);

		foreach ($stored as $role => $groups) {
			$canonical = $this->canonicalRole(roleOrGroup: (string) $role);
			if ($canonical === null || is_array($groups) === false) {
				continue;
			}

			foreach ($groups as $group) {
				if (is_string($group) === true && trim($group) !== '' && in_array(trim($group), $mapping[$canonical], true) === false) {
					$mapping[$canonical][] = trim($group);
				}
			}
		}

		return $mapping;
	}//end sourceGroups()

	/**
	 * The default role of each organisation type; null when the type grants none.
	 *
	 * @return array<string, string|null> The role per organisation type.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
	 */
	public function organisationTypeRoles(): array {
		$roles = self::DEFAULT_ORGANISATION_TYPE_ROLES;
		$stored = $this->readJson(key: self::KEY_ORGANISATION_TYPE_ROLES);

		foreach ($stored as $type => $role) {
			$canonicalType = self::canonicalOrganisationType(type: (string) $type);
			if ($canonicalType === null) {
				continue;
			}

			$roles[$canonicalType] = null;
			if (is_string($role) === true) {
				$roles[$canonicalType] = $this->canonicalRole(roleOrGroup: $role);
			}
		}

		return $roles;
	}//end organisationTypeRoles()

	/**
	 * The default role of an organisation type.
	 *
	 * @param string $organisationType The organisation type, in any case.
	 *
	 * @return string|null The role, or null when the type is unknown or grants none.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function defaultRoleForOrganisationType(string $organisationType): ?string {
		$canonicalType = self::canonicalOrganisationType(type: $organisationType);
		if ($canonicalType === null) {
			return null;
		}

		return $this->organisationTypeRoles()[$canonicalType];
	}//end defaultRoleForOrganisationType()

	/**
	 * Days after a review before the person is due again.
	 *
	 * @return int The interval, the default when nothing valid is saved.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public function reviewIntervalDays(): int {
		$stored = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY_REVIEW_INTERVAL, ''));
		if (preg_match('/^\d+$/', $stored) !== 1) {
			return self::DEFAULT_REVIEW_INTERVAL_DAYS;
		}

		$days = (int) $stored;
		if ($days < 1 || $days > self::MAX_REVIEW_INTERVAL_DAYS) {
			return self::DEFAULT_REVIEW_INTERVAL_DAYS;
		}

		return $days;
	}//end reviewIntervalDays()

	/**
	 * The whole map as the admin settings read it.
	 *
	 * @return array<string, mixed> `roleMapping` (role group and source groups per role),
	 *                              `organisationTypeRoles` and `accessReviewIntervalDays`.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
	 */
	public function toArray(): array {
		$roleMapping = [];
		foreach ($this->sourceGroups() as $role => $groups) {
			$roleMapping[$role] = [
				'group' => self::ROLES[$role],
				'sourceGroups' => $groups,
			];
		}

		return [
			'roleMapping' => $roleMapping,
			'organisationTypeRoles' => $this->organisationTypeRoles(),
			'accessReviewIntervalDays' => $this->reviewIntervalDays(),
		];
	}//end toArray()

	/**
	 * Validate and save the parts of the map the config carries.
	 *
	 * Nothing is written unless every part is valid, so a rejected save never
	 * leaves half a mapping behind. `roleMapping` takes either a list of
	 * groups per role or the `{sourceGroups: [...]}` shape toArray() returns.
	 *
	 * @param array<string, mixed> $config The submitted `roleMapping`, `organisationTypeRoles` and `accessReviewIntervalDays`.
	 *
	 * @return array{success: bool, message: string, errors?: list<string>}
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
	 */
	public function update(array $config): array {
		$errors = [];
		$writes = [];

		if (array_key_exists('roleMapping', $config) === true) {
			[$mapping, $mappingErrors] = $this->validateRoleMapping(submitted: $config['roleMapping']);
			$errors = array_merge($errors, $mappingErrors);
			$writes[self::KEY_ROLE_MAPPING] = json_encode($mapping, JSON_THROW_ON_ERROR);
		}

		if (array_key_exists('organisationTypeRoles', $config) === true) {
			[$typeRoles, $typeErrors] = $this->validateOrganisationTypeRoles(submitted: $config['organisationTypeRoles']);
			$errors = array_merge($errors, $typeErrors);
			$writes[self::KEY_ORGANISATION_TYPE_ROLES] = json_encode($typeRoles, JSON_THROW_ON_ERROR);
		}

		if (array_key_exists('accessReviewIntervalDays', $config) === true) {
			$days = self::validInterval(submitted: $config['accessReviewIntervalDays']);
			if ($days === null) {
				$errors[] = $this->translate(
					text: 'The review interval must be a whole number of days from 1 to %s.',
					parameters: [(string) self::MAX_REVIEW_INTERVAL_DAYS]
				);
			}

			$writes[self::KEY_REVIEW_INTERVAL] = (string) $days;
		}

		if ($errors !== []) {
			return [
				'success' => false,
				'message' => $this->translate(text: 'The role mapping was not saved.', parameters: []),
				'errors' => $errors,
			];
		}

		foreach ($writes as $key => $value) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, $key, $value);
		}

		return [
			'success' => true,
			'message' => $this->translate(text: 'The role mapping was saved.', parameters: []),
		];
	}//end update()

	/**
	 * Validate the submitted source groups per role.
	 *
	 * @param mixed $submitted The submitted `roleMapping`.
	 *
	 * @return array{0: array<string, list<string>>, 1: list<string>} The mapping to store and the errors.
	 */
	private function validateRoleMapping(mixed $submitted): array {
		$mapping = array_fill_keys(keys: $this->roles(), value: []);
		if (is_array($submitted) === false) {
			return [$mapping, [$this->translate(text: 'The role mapping must list groups per role.', parameters: [])]];
		}

		$errors = [];
		foreach ($submitted as $role => $groups) {
			$canonical = $this->canonicalRole(roleOrGroup: (string) $role);
			if ($canonical === null) {
				$errors[] = $this->translate(text: '%s is not a catalogue role.', parameters: [(string) $role]);
				continue;
			}

			if (is_array($groups) === true && array_key_exists('sourceGroups', $groups) === true) {
				$groups = $groups['sourceGroups'];
			}

			if (is_array($groups) === false) {
				$errors[] = $this->translate(text: 'The groups of %s must be a list.', parameters: [$canonical]);
				continue;
			}

			[$mapping[$canonical], $groupErrors] = $this->validateSourceGroups(role: $canonical, groups: $groups);
			$errors = array_merge($errors, $groupErrors);
		}

		return [$mapping, $errors];
	}//end validateRoleMapping()

	/**
	 * Validate the source groups of one role.
	 *
	 * A role group cannot be a source group: membership of it would grant the
	 * role that keeps the person in it, so the sync could never take it away.
	 *
	 * @param string $role   The canonical role.
	 * @param array  $groups The submitted group ids.
	 *
	 * @return array{0: list<string>, 1: list<string>} The groups to store and the errors.
	 */
	private function validateSourceGroups(string $role, array $groups): array {
		$valid = [];
		$errors = [];
		foreach ($groups as $group) {
			if (is_string($group) === false || trim($group) === '' || mb_strlen(trim($group)) > 255) {
				$errors[] = $this->translate(text: 'A group of %s has no valid name.', parameters: [$role]);
				continue;
			}

			$group = trim($group);
			if ($this->isRoleGroup(groupId: $group) === true) {
				$errors[] = $this->translate(
					text: '%1$s is a role group and cannot grant %2$s.',
					parameters: [$group, $role]
				);
				continue;
			}

			if (in_array($group, $valid, true) === false) {
				$valid[] = $group;
			}
		}//end foreach

		return [$valid, $errors];
	}//end validateSourceGroups()

	/**
	 * Validate the submitted default role per organisation type.
	 *
	 * @param mixed $submitted The submitted `organisationTypeRoles`.
	 *
	 * @return array{0: array<string, string|null>, 1: list<string>} The defaults to store and the errors.
	 */
	private function validateOrganisationTypeRoles(mixed $submitted): array {
		$roles = $this->organisationTypeRoles();
		if (is_array($submitted) === false) {
			return [$roles, [$this->translate(text: 'The organisation type roles must name a role per type.', parameters: [])]];
		}

		$errors = [];
		foreach ($submitted as $type => $role) {
			$canonicalType = self::canonicalOrganisationType(type: (string) $type);
			if ($canonicalType === null) {
				$errors[] = $this->translate(text: '%s is not an organisation type.', parameters: [(string) $type]);
				continue;
			}

			if ($role === null || $role === '') {
				$roles[$canonicalType] = null;
				continue;
			}

			$canonicalRole = null;
			if (is_string($role) === true) {
				$canonicalRole = $this->canonicalRole(roleOrGroup: $role);
			}

			if ($canonicalRole === null) {
				$shown = gettype($role);
				if (is_scalar($role) === true) {
					$shown = (string) $role;
				}

				$errors[] = $this->translate(text: '%s is not a catalogue role.', parameters: [$shown]);
				continue;
			}

			$roles[$canonicalType] = $canonicalRole;
		}//end foreach

		return [$roles, $errors];
	}//end validateOrganisationTypeRoles()

	/**
	 * The submitted review interval when it is a whole number of days in range.
	 *
	 * @param mixed $submitted The submitted interval.
	 *
	 * @return int|null The interval, or null when it is not valid.
	 */
	private static function validInterval(mixed $submitted): ?int {
		if (is_int($submitted) === false && (is_string($submitted) === false || preg_match('/^\d+$/', trim($submitted)) !== 1)) {
			return null;
		}

		$days = (int) $submitted;
		if ($days < 1 || $days > self::MAX_REVIEW_INTERVAL_DAYS) {
			return null;
		}

		return $days;
	}//end validInterval()

	/**
	 * The organisation type as the enum stores it.
	 *
	 * @param string $type The type, in any case.
	 *
	 * @return string|null The stored enum value, or null for an unknown type.
	 */
	private static function canonicalOrganisationType(string $type): ?string {
		$needle = self::fold(value: $type);
		foreach (array_keys(self::DEFAULT_ORGANISATION_TYPE_ROLES) as $known) {
			if (self::fold(value: $known) === $needle) {
				return $known;
			}
		}

		return null;
	}//end canonicalOrganisationType()

	/**
	 * Read a JSON object from app config.
	 *
	 * @param string $key The app config key.
	 *
	 * @return array<mixed> The decoded object, or [] when absent or malformed.
	 */
	private function readJson(string $key): array {
		$json = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		if ($json === '') {
			return [];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end readJson()

	/**
	 * Translate a message, or answer it in English without a translator.
	 *
	 * @param string       $text       The English message with printf placeholders.
	 * @param list<string> $parameters The placeholder values.
	 *
	 * @return string The message.
	 */
	private function translate(string $text, array $parameters): string {
		if ($this->l10n !== null) {
			return $this->l10n->t($text, $parameters);
		}

		return vsprintf($text, $parameters);
	}//end translate()

	/**
	 * A name trimmed and lower-cased for comparison.
	 *
	 * @param string $value The name.
	 *
	 * @return string The folded name.
	 */
	private static function fold(string $value): string {
		return mb_strtolower(trim($value));
	}//end fold()
}//end class
