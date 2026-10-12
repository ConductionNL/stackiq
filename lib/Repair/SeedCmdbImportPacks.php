<?php

/**
 * SeedCmdbImportPacks — stores the CMDB import's sheet packs in OpenRegister.
 *
 * The module, manufacturer, usage and business-owner packs that ship under
 * `lib/Settings/cmdb-import/` are created in OpenRegister's migration-pack
 * store as built-in packs when they are missing, so an administrator can
 * view and edit them in OpenRegister. A stored pack nobody edited is replaced
 * by the shipped pack of this release; an edited one is left alone and
 * reported. The municipality pack maps the import options, not a sheet, and
 * is never stored.
 *
 * Modelled on OpenRegister's SeedZgwZakenMigrationPack, but it never calls
 * the store's import, which upserts and would overwrite an edit. Never
 * throws: without OpenRegister's store, or on any failure, it reports and
 * leaves the instance as it was; the import then seeds lazily on its next
 * run (CmdbImportProfile::load()).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\Cmdb\CmdbImportPackStore;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds and updates the CMDB import packs in OpenRegister's migration-pack store.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
 */
class SeedCmdbImportPacks implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param CmdbImportProfile $profile Reads and validates the shipped packs.
	 * @param CmdbImportPackStore $store OpenRegister's migration-pack store.
	 * @param LoggerInterface $logger Logger for seed diagnostics.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
	 */
	public function __construct(
		private readonly CmdbImportProfile $profile,
		private readonly CmdbImportPackStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
	 */
	public function getName(): string {
		return 'Seed the CMDB import mapping packs into OpenRegister';
	}//end getName()

	/**
	 * Seed the missing packs and update the unedited ones.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
	 */
	public function run(IOutput $output): void {
		if ($this->store->isAvailable() === false) {
			$output->info('OpenRegister migration pack store not available; CMDB import packs not seeded');
			return;
		}

		try {
			$packs = $this->profile->shippedEditablePacks();
		} catch (Throwable $e) {
			$this->logger->warning('[SeedCmdbImportPacks] shipped packs unreadable: ' . $e->getMessage());
			$output->warning('CMDB import packs not seeded: ' . $e->getMessage());
			return;
		}

		foreach ($packs as $shipped) {
			$this->seed(output: $output, shipped: $shipped);
		}
	}//end run()

	/**
	 * Seed or update one pack, and report what happened.
	 *
	 * @param IOutput $output Output interface for status messages.
	 * @param array<string, mixed> $shipped The shipped pack.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-2
	 */
	private function seed(IOutput $output, array $shipped): void {
		$slug = (string)($shipped['id'] ?? '');
		try {
			$stored = $this->store->resolve(shipped: $shipped);
		} catch (Throwable $e) {
			$this->logger->warning('[SeedCmdbImportPacks] ' . $slug . ': ' . $e->getMessage());
			$output->warning('CMDB import pack ' . $slug . ' not seeded: ' . $e->getMessage());
			return;
		}

		$where = $slug . ' (id ' . $stored['id'] . ')';
		if ($stored['outcome'] === CmdbImportPackStore::SEEDED) {
			$output->info('Seeded CMDB import pack ' . $where);
			return;
		}

		if ($stored['outcome'] === CmdbImportPackStore::UPDATED) {
			$output->info('Updated unedited CMDB import pack ' . $where . ' to version ' . $stored['version']);
			return;
		}

		if ($stored['newerShipped'] === true) {
			$output->warning(
				'CMDB import pack ' . $where . ' was edited in OpenRegister and is kept; this release ships version '
				. (string)($shipped['version'] ?? '') . ', which is not applied'
			);
			return;
		}

		$output->info('CMDB import pack ' . $where . ' already present');
	}//end seed()
}//end class
