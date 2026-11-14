<?php

/**
 * Derives a person's catalogue roles and keeps their role groups in step.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One role service for every path (D2 of the design).
 *
 * A person's roles are the union of three sources: the `roles` on their
 * contact person, the roles whose mapped source groups they are in, and the
 * default role of their organisation's type. sync() puts them in exactly the
 * role groups of those roles: it adds the missing ones and removes the ones
 * they no longer derive. It only ever changes the role groups RoleGroupMap
 * names, never any other group.
 *
 * This class is a callable service only. Nothing in the app runs sync() on
 * its own yet: running it at sign-in, nightly and after an upgrade that first
 * writes current members' roles onto their contact persons is a later step,
 * so that nobody loses a role group by surprise.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
class RoleGrantSync {

	/**
	 * Source type of a role written on the contact person.
	 *
	 * @var string
	 */
	public const SOURCE_CONTACT_PERSON = 'contactPerson';

	/**
	 * Source type of a role granted by a mapped group.
	 *
	 * @var string
	 */
	public const SOURCE_GROUP = 'group';

	/**
	 * Source type of a role granted by the organisation's type.
	 *
	 * @var string
	 */
	public const SOURCE_ORGANISATION_TYPE = 'organisationType';

	/**
	 * Constructor.
	 *
	 * @param RoleGroupMap                  $roleGroupMap   The roles, their groups and the mapping.
	 * @param IGroupManager                 $groupManager   Reads and changes group memberships.
	 * @param RegisterConfigReaderInterface $registerConfig Finds the contact person and organisation in OpenRegister.
	 * @param LoggerInterface               $logger         Logs every membership change.
	 */
	public function __construct(
		private readonly RoleGroupMap $roleGroupMap,
		private readonly IGroupManager $groupManager,
		private readonly RegisterConfigReaderInterface $registerConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The roles a person derives, as the enum writes them, in map order.
	 *
	 * @param IUser                     $user          The person's account.
	 * @param array<string, mixed>|null $contactPerson The contact person's data; null looks it up by username.
	 *
	 * @return list<string> The roles.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function rolesFor(IUser $user, ?array $contactPerson = null): array {
		return array_keys($this->roleSourcesFor(user: $user, contactPerson: $contactPerson));
	}//end rolesFor()

	/**
	 * The roles a person derives, each with every source it comes from.
	 *
	 * A source is `{type: contactPerson}`, `{type: group, group: <gid>}` or
	 * `{type: organisationType, organisationType: <type>}`.
	 *
	 * @param IUser                     $user          The person's account.
	 * @param array<string, mixed>|null $contactPerson The contact person's data; null looks it up by username.
	 *
	 * @return array<string, list<array<string, string>>> The sources per role, in map order.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function roleSourcesFor(IUser $user, ?array $contactPerson = null): array {
		if ($contactPerson === null) {
			$contactPerson = $this->findContactPerson(user: $user) ?? [];
		}

		$sources = array_fill_keys(keys: $this->roleGroupMap->roles(), value: []);

		foreach ($this->contactPersonRoles(contactPerson: $contactPerson) as $role) {
			$sources[$role][] = ['type' => self::SOURCE_CONTACT_PERSON];
		}

		$userGroups = $this->groupIdsOf(user: $user);
		foreach ($this->roleGroupMap->sourceGroups() as $role => $groups) {
			foreach ($groups as $group) {
				if (in_array(mb_strtolower($group), $userGroups, true) === true) {
					$sources[$role][] = ['type' => self::SOURCE_GROUP, 'group' => $group];
				}
			}
		}

		$organisationType = $this->organisationType(contactPerson: $contactPerson);
		$typeRole = $this->roleGroupMap->defaultRoleForOrganisationType(organisationType: $organisationType);
		if ($typeRole !== null) {
			$sources[$typeRole][] = ['type' => self::SOURCE_ORGANISATION_TYPE, 'organisationType' => $organisationType];
		}

		return array_filter($sources, static fn (array $roleSources): bool => $roleSources !== []);
	}//end roleSourcesFor()

	/**
	 * Put a person in exactly the role groups of the roles they derive.
	 *
	 * Missing role groups are created. Groups that are not role groups are
	 * never read for removal nor changed.
	 *
	 * @param IUser                     $user          The person's account.
	 * @param array<string, mixed>|null $contactPerson The contact person's data; null looks it up by username.
	 *
	 * @return array{added: list<string>, removed: list<string>} The role groups joined and left.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function sync(IUser $user, ?array $contactPerson = null): array {
		$derived = $this->rolesFor(user: $user, contactPerson: $contactPerson);
		$result = ['added' => [], 'removed' => []];

		foreach ($this->roleGroupMap->roles() as $role) {
			$change = $this->syncRoleGroup(user: $user, role: $role, holds: in_array($role, $derived, true));
			if ($change !== null) {
				$result[$change][] = (string) $this->roleGroupMap->roleGroup(role: $role);
			}
		}

		return $result;
	}//end sync()

	/**
	 * Take a person out of one role's group when they no longer derive the role.
	 *
	 * Used after a role is withdrawn from their contact person: only that one
	 * role group is touched, so a withdrawal never changes another role.
	 *
	 * @param IUser                $user          The person's account.
	 * @param string               $role          The withdrawn role, in any case.
	 * @param array<string, mixed> $contactPerson The contact person's data after the withdrawal.
	 *
	 * @return bool True when the person left the role group.
	 *
	 * @throws RuntimeException When the role is no catalogue role.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
	 */
	public function revokeIfUnderived(IUser $user, string $role, array $contactPerson): bool {
		$canonical = $this->roleGroupMap->canonicalRole(roleOrGroup: $role);
		if ($canonical === null) {
			throw new RuntimeException('Unknown catalogue role: ' . $role);
		}

		$holds = in_array($canonical, $this->rolesFor(user: $user, contactPerson: $contactPerson), true);
		if ($holds === true) {
			return false;
		}

		return $this->syncRoleGroup(user: $user, role: $canonical, holds: false) === 'removed';
	}//end revokeIfUnderived()

	/**
	 * Bring one role group in line with whether the person holds its role.
	 *
	 * @param IUser  $user  The person's account.
	 * @param string $role  The canonical role.
	 * @param bool   $holds Whether the person derives the role.
	 *
	 * @return string|null `added`, `removed`, or null when nothing changed.
	 */
	private function syncRoleGroup(IUser $user, string $role, bool $holds): ?string {
		$groupId = (string) $this->roleGroupMap->roleGroup(role: $role);
		$group = $this->roleGroup(groupId: $groupId, create: $holds);
		if ($group === null) {
			return null;
		}

		$inGroup = $group->inGroup($user);
		if ($holds === $inGroup) {
			return null;
		}

		if ($holds === true) {
			$group->addUser($user);
			$this->logger->info('RoleGrantSync: added user to role group', ['username' => $user->getUID(), 'group' => $groupId, 'role' => $role]);
			return 'added';
		}

		$group->removeUser($user);
		$this->logger->info('RoleGrantSync: removed user from role group', ['username' => $user->getUID(), 'group' => $groupId, 'role' => $role]);
		return 'removed';
	}//end syncRoleGroup()

	/**
	 * A role group, created when the person must join it and it does not exist.
	 *
	 * @param string $groupId The role group.
	 * @param bool   $create  Whether to create it when missing.
	 *
	 * @return IGroup|null The group, or null when it does not exist and is not needed.
	 */
	private function roleGroup(string $groupId, bool $create): ?IGroup {
		$group = $this->groupManager->get($groupId);
		if ($group !== null || $create === false) {
			return $group;
		}

		$group = $this->groupManager->createGroup($groupId);
		if ($group === null) {
			throw new RuntimeException('Could not create role group ' . $groupId);
		}

		$this->logger->info('RoleGrantSync: created role group', ['group' => $groupId]);
		return $group;
	}//end roleGroup()

	/**
	 * The catalogue roles written on a contact person, canonical and unique.
	 *
	 * @param array<string, mixed> $contactPerson The contact person's data.
	 *
	 * @return list<string> The roles.
	 */
	private function contactPersonRoles(array $contactPerson): array {
		$roles = $contactPerson['roles'] ?? [];
		if (is_array($roles) === false) {
			return [];
		}

		$canonical = [];
		foreach ($roles as $role) {
			if (is_string($role) === false) {
				continue;
			}

			$name = $this->roleGroupMap->canonicalRole(roleOrGroup: $role);
			if ($name !== null && in_array($name, $canonical, true) === false) {
				$canonical[] = $name;
			}
		}

		return $canonical;
	}//end contactPersonRoles()

	/**
	 * The lower-cased ids of the groups the person is in.
	 *
	 * @param IUser $user The person's account.
	 *
	 * @return list<string> The group ids.
	 */
	private function groupIdsOf(IUser $user): array {
		$groups = $this->groupManager->getUserGroups($user);
		if (is_array($groups) === false) {
			return [];
		}

		return array_values(
			array_map(
				static fn (IGroup $group): string => mb_strtolower($group->getGID()),
				$groups
			)
		);
	}//end groupIdsOf()

	/**
	 * The type of the contact person's organisation.
	 *
	 * @param array<string, mixed> $contactPerson The contact person's data.
	 *
	 * @return string The stored type, or '' when there is no organisation or it has none.
	 */
	private function organisationType(array $contactPerson): string {
		$organisation = $contactPerson['organisation'] ?? $contactPerson['organization'] ?? '';
		if (is_array($organisation) === true) {
			if (isset($organisation['type']) === true && is_string($organisation['type']) === true) {
				return $organisation['type'];
			}

			$organisation = $organisation['id'] ?? $organisation['uuid'] ?? '';
		}

		if (is_string($organisation) === false || $organisation === '') {
			return '';
		}

		$registerId = $this->registerConfig->getRegisterIdForObjectType('organization');
		$schemaId = $this->registerConfig->getSchemaIdForObjectType('organization');
		$objectService = $this->registerConfig->getObjectService();
		if ($registerId === null || $schemaId === null || $objectService === null) {
			throw new RuntimeException('The organisation register or schema is not configured.');
		}

		$entity = $objectService->find(
			id: $organisation,
			_extend: [],
			files: false,
			register: $registerId,
			schema: $schemaId,
			_rbac: false,
			_multitenancy: false
		);
		if ($entity === null) {
			return '';
		}

		$type = ($entity->getObject()['type'] ?? '');
		if (is_string($type) === false) {
			return '';
		}

		return $type;
	}//end organisationType()

	/**
	 * The contact person whose username is the user's id.
	 *
	 * @param IUser $user The person's account.
	 *
	 * @return array<string, mixed>|null The contact person's data, or null when they have none.
	 */
	private function findContactPerson(IUser $user): ?array {
		$registerId = $this->registerConfig->getRegisterIdForObjectType('contactPerson');
		$schemaId = $this->registerConfig->getSchemaIdForObjectType('contactPerson');
		$objectService = $this->registerConfig->getObjectService();
		if ($registerId === null || $schemaId === null || $objectService === null) {
			throw new RuntimeException('The contact person register or schema is not configured.');
		}

		$results = $objectService->searchObjects(
			query: [
				'@self' => ['register' => $registerId, 'schema' => $schemaId],
				'username' => $user->getUID(),
				'_limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);
		if (is_array($results) === false || $results === []) {
			return null;
		}

		$first = reset($results);
		if (is_object($first) === true && method_exists($first, 'getObject') === true) {
			return $first->getObject();
		}

		if (is_array($first) === true) {
			return $first;
		}

		return null;
	}//end findContactPerson()
}//end class
