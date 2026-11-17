<?php

/**
 * Writes the role of every current role group member onto their contact person.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Makes the role groups safe to manage (the upgrade risk of the design).
 *
 * Before this change a person could be in a role group, put there by hand,
 * without the role on their contact person. The role sync takes people out of
 * role groups they do not derive, so before it runs on its own this seeder
 * writes the role of each role group onto the contact person of each of its
 * current members, and logs every one. When every write succeeded it records
 * that the role groups are seeded, which is what lets the sign-in and nightly
 * sync run. Members without a contact person are left alone: the sync never
 * changes an account without one.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
class RoleGrantSeeder {

	/**
	 * Constructor.
	 *
	 * @param RoleGroupMap                  $roleGroupMap   The roles and their role groups.
	 * @param IGroupManager                 $groupManager   Reads the role groups' members.
	 * @param RegisterConfigReaderInterface $registerConfig Finds the contact persons in OpenRegister.
	 * @param LoggerInterface               $logger         Logs every role written.
	 */
	public function __construct(
		private readonly RoleGroupMap $roleGroupMap,
		private readonly IGroupManager $groupManager,
		private readonly RegisterConfigReaderInterface $registerConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write each current member's role group role onto their contact person.
	 *
	 * Idempotent: a contact person that already carries the role, in any case,
	 * is not written. The role groups are recorded as seeded only when no
	 * write failed.
	 *
	 * @return array{written: int, unchanged: int, withoutContactPerson: int, failed: int, seeded: bool} What was done.
	 *
	 * @throws RuntimeException When the contact person register or schema is not configured.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function seed(): array {
		$target = $this->contactPersonTarget();
		$result = ['written' => 0, 'unchanged' => 0, 'withoutContactPerson' => 0, 'failed' => 0, 'seeded' => false];

		foreach ($this->rolesPerMember() as $member) {
			$outcome = $this->seedMember(user: $member['user'], roles: $member['roles'], target: $target);
			$result[$outcome]++;
		}

		if ($result['failed'] === 0) {
			$this->roleGroupMap->markGrantsSeeded();
			$result['seeded'] = true;
		}

		return $result;
	}//end seed()

	/**
	 * The current members of the role groups, each with the roles of the groups they are in.
	 *
	 * @return array<string, array{user: IUser, roles: list<string>}> Keyed by user id.
	 */
	private function rolesPerMember(): array {
		$members = [];
		foreach (RoleGroupMap::ROLES as $role => $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$uid = $user->getUID();
				if (isset($members[$uid]) === false) {
					$members[$uid] = ['user' => $user, 'roles' => []];
				}

				$members[$uid]['roles'][] = $role;
			}
		}

		return $members;
	}//end rolesPerMember()

	/**
	 * Write the missing roles onto one member's contact person.
	 *
	 * @param IUser                                                          $user   The member.
	 * @param list<string>                                                   $roles  The roles of the role groups they are in.
	 * @param array{register: int, schema: int, objectService: ObjectServiceInterface} $target The contact person register.
	 *
	 * @return string `written`, `unchanged`, `withoutContactPerson` or `failed`.
	 */
	private function seedMember(IUser $user, array $roles, array $target): string {
		try {
			$contactPerson = $this->findContactPerson(user: $user, target: $target);
			if ($contactPerson === null) {
				$this->logger->info(
					'RoleGrantSeeder: role group member has no contact person; their groups are left alone',
					['username' => $user->getUID(), 'roles' => $roles]
				);
				return 'withoutContactPerson';
			}

			[$id, $data] = $contactPerson;
			$current = $this->currentRoles(data: $data);
			$missing = $this->missingRoles(current: $current, roles: $roles);
			if ($missing === []) {
				return 'unchanged';
			}

			$target['objectService']->patchObject(
				patch: new ObjectPatch(
					objectId: $id,
					data: ['roles' => array_merge($current, $missing)],
					target: new ObjectFindTarget(register: $target['register'], schema: $target['schema'])
				),
				_rbac: false,
				_multitenancy: false
			);
			$this->logger->info(
				'RoleGrantSeeder: wrote the roles of the role groups onto the contact person',
				['username' => $user->getUID(), 'contactPerson' => $id, 'roles' => $missing]
			);
			return 'written';
		} catch (Throwable $e) {
			$this->logger->warning(
				'RoleGrantSeeder: could not write the roles onto the contact person',
				['username' => $user->getUID(), 'roles' => $roles, 'error' => $e->getMessage()]
			);
			return 'failed';
		}//end try
	}//end seedMember()

	/**
	 * The roles on a contact person, as stored, strings only.
	 *
	 * @param array<string, mixed> $data The contact person's data.
	 *
	 * @return list<string> The roles.
	 */
	private function currentRoles(array $data): array {
		$roles = $data['roles'] ?? [];
		if (is_array($roles) === false) {
			return [];
		}

		return array_values(array_filter($roles, 'is_string'));
	}//end currentRoles()

	/**
	 * The roles not yet on the contact person, compared without regard to case.
	 *
	 * @param list<string> $current The roles on the contact person.
	 * @param list<string> $roles   The roles the member's role groups grant.
	 *
	 * @return list<string> The canonical roles to add.
	 */
	private function missingRoles(array $current, array $roles): array {
		$held = [];
		foreach ($current as $role) {
			$canonical = $this->roleGroupMap->canonicalRole(roleOrGroup: $role);
			if ($canonical !== null) {
				$held[] = $canonical;
			}
		}

		return array_values(array_unique(array_diff($roles, $held)));
	}//end missingRoles()

	/**
	 * The contact person whose username is the user's id.
	 *
	 * @param IUser                                                          $user   The member.
	 * @param array{register: int, schema: int, objectService: ObjectServiceInterface} $target The contact person register.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null Its id and data, or null when there is none.
	 */
	private function findContactPerson(IUser $user, array $target): ?array {
		$results = $target['objectService']->searchObjects(
			query: [
				'@self' => ['register' => $target['register'], 'schema' => $target['schema']],
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
			$id = (string) ($first->getUuid() ?? '');
			$data = $first->getObject();
		} else if (is_array($first) === true) {
			$id = (string) ($first['@self']['id'] ?? $first['id'] ?? $first['uuid'] ?? '');
			$data = $first;
		} else {
			return null;
		}

		if ($id === '') {
			throw new RuntimeException('The contact person of ' . $user->getUID() . ' has no id.');
		}

		return [$id, $data];
	}//end findContactPerson()

	/**
	 * The contact person register, schema and object service.
	 *
	 * @return array{register: int, schema: int, objectService: ObjectServiceInterface}
	 *
	 * @throws RuntimeException When any of them is not configured.
	 */
	private function contactPersonTarget(): array {
		$registerId = $this->registerConfig->getRegisterIdForObjectType('contactPerson');
		$schemaId = $this->registerConfig->getSchemaIdForObjectType('contactPerson');
		$objectService = $this->registerConfig->getObjectService();
		if ($registerId === null || $schemaId === null || $objectService === null) {
			throw new RuntimeException('The contact person register or schema is not configured.');
		}

		return ['register' => $registerId, 'schema' => $schemaId, 'objectService' => $objectService];
	}//end contactPersonTarget()
}//end class
