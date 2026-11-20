<?php
/**
 * Writes the role of every current role group member onto their contact person.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Role groups become managed by the role sync, which takes people out of role
 * groups they do not derive. This step runs first, at install and upgrade:
 * every current member gets the role on their contact person, and each write
 * is logged, so the upgrade changes nobody's access. Until it has finished
 * without a failed write the sign-in and nightly sync do not run; the nightly
 * job retries it. It never throws, so it cannot abort an upgrade.
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\RoleGrantSeeder;
use OCA\Stackiq\Service\RoleGroupMap;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the contact persons' roles from the role groups, once.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
class SeedRolesFromGroups implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param RoleGrantSeeder $seeder       Writes the roles.
	 * @param RoleGroupMap    $roleGroupMap Says whether the seeding already finished.
	 * @param LoggerInterface $logger       Logger.
	 */
	public function __construct(
		private readonly RoleGrantSeeder $seeder,
		private readonly RoleGroupMap $roleGroupMap,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function getName(): string {
		return 'Stackiq: write the role of each role group member onto their contact person';
	}//end getName()

	/**
	 * Seed the roles unless that already finished; report what was written.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->roleGroupMap->grantsSeeded() === true) {
				return;
			}

			$result = $this->seeder->seed();
			$output->info(
				sprintf(
					'Role groups: %d contact persons got their role group roles, %d were already complete, %d members have no contact person.',
					$result['written'],
					$result['unchanged'],
					$result['withoutContactPerson']
				)
			);

			if ($result['seeded'] === false) {
				$output->warning(
					sprintf(
						'Role groups: %d contact persons could not be written; the role sync stays off until the nightly job finishes the seeding.',
						$result['failed']
					)
				);
			}
		} catch (Throwable $e) {
			$output->warning('Role groups: the roles could not be seeded yet; the nightly job retries.');
			$this->logger->warning('SeedRolesFromGroups: seeding failed', ['error' => $e->getMessage()]);
		}//end try
	}//end run()
}//end class
