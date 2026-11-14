<?php

/**
 * Nightly sync of every catalogue user's role groups.
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\BackgroundJob;

use OCA\Stackiq\Service\RoleGrantSeeder;
use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\SearchBounds;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks the contact persons in bounded batches and syncs each account's role groups.
 *
 * A change in a directory group reaches people who do not sign in this way.
 * It first finishes the seeding when the upgrade step could not, and does not
 * sync until the role groups are seeded.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
class RoleGrantSyncJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var integer
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Contact persons read per search.
	 *
	 * @var integer
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                  $timeFactory    The clock.
	 * @param RoleGrantSync                 $roleGrantSync  The role sync.
	 * @param RoleGrantSeeder               $seeder         Finishes an unfinished seeding.
	 * @param RoleGroupMap                  $roleGroupMap   Says whether the role groups are seeded.
	 * @param RegisterConfigReaderInterface $registerConfig Finds the contact persons.
	 * @param IUserManager                  $userManager    Finds each contact person's account.
	 * @param LoggerInterface               $logger         Logs the run.
	 */
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly RoleGrantSync $roleGrantSync,
		private readonly RoleGrantSeeder $seeder,
		private readonly RoleGroupMap $roleGroupMap,
		private readonly RegisterConfigReaderInterface $registerConfig,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $timeFactory);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Seed when needed, then sync every contact person's account.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	protected function run($argument): void {
		try {
			if ($this->roleGroupMap->grantsSeeded() === false && $this->seeder->seed()['seeded'] === false) {
				$this->logger->warning('RoleGrantSyncJob: role groups not seeded yet; sync skipped');
				return;
			}

			$counts = $this->syncAll();
			$this->logger->info('RoleGrantSyncJob: role groups synced', $counts);
		} catch (Throwable $e) {
			$this->logger->error('RoleGrantSyncJob: sync failed', ['error' => $e->getMessage()]);
		}
	}//end run()

	/**
	 * Sync the account of every contact person, one bounded batch at a time.
	 *
	 * @return array{synced: int, failed: int} The accounts synced and the ones that failed.
	 *
	 * @throws \RuntimeException When the contact person register or schema is not configured.
	 */
	private function syncAll(): array {
		$registerId = $this->registerConfig->getRegisterIdForObjectType('contactPerson');
		$schemaId = $this->registerConfig->getSchemaIdForObjectType('contactPerson');
		$objectService = $this->registerConfig->getObjectService();
		if ($registerId === null || $schemaId === null || $objectService === null) {
			throw new \RuntimeException('The contact person register or schema is not configured.');
		}

		$counts = ['synced' => 0, 'failed' => 0];
		for ($offset = 0; $offset < SearchBounds::CEILING; $offset += self::BATCH_SIZE) {
			$page = $objectService->searchObjects(
				query: [
					'@self' => ['register' => $registerId, 'schema' => $schemaId],
					'_limit' => self::BATCH_SIZE,
					'_offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
			if (is_array($page) === false || $page === []) {
				break;
			}

			foreach ($page as $row) {
				$outcome = $this->syncRow(row: $row);
				if ($outcome !== null) {
					$counts[$outcome]++;
				}
			}

			if (count($page) < self::BATCH_SIZE) {
				break;
			}
		}//end for

		return $counts;
	}//end syncAll()

	/**
	 * Sync the account of one contact person.
	 *
	 * @param mixed $row The contact person as OpenRegister returned it.
	 *
	 * @return string|null `synced`, `failed`, or null when it has no account.
	 */
	private function syncRow(mixed $row): ?string {
		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$row = $row->getObject();
		}

		$username = '';
		if (is_array($row) === true && is_string($row['username'] ?? null) === true) {
			$username = trim($row['username']);
		}

		if ($username === '') {
			return null;
		}

		$user = $this->userManager->get($username);
		if ($user === null) {
			return null;
		}

		try {
			$this->roleGrantSync->sync(user: $user, contactPerson: $row);
			return 'synced';
		} catch (Throwable $e) {
			$this->logger->warning('RoleGrantSyncJob: one account not synced', ['username' => $username, 'error' => $e->getMessage()]);
			return 'failed';
		}
	}//end syncRow()
}//end class
