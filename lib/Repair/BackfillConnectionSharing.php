<?php

/**
 * Fills the participant organisations on the connections of existing shared usages.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\ConnectionShareSync;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Usages shared before `sharedWithOrganisations` existed never passed the
 * subscriber, so their connections stay unreadable to the participants until
 * this step writes the field. Idempotent: a connection that already holds the
 * right organisations is not written. It never throws.
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */
class BackfillConnectionSharing implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ConnectionShareSync $sync   The connection sharing writer.
	 * @param LoggerInterface     $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConnectionShareSync $sync,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude repair step plumbing.
	 */
	public function getName(): string {
		return 'Let participants of a shared usage read its connections';
	}//end getName()

	/**
	 * Write the field on every connection of a usage with participants.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public function run(IOutput $output): void {
		try {
			$written = $this->sync->backfill();
		} catch (Throwable $exception) {
			$this->logger->warning('BackfillConnectionSharing: nothing done', ['exception' => $exception->getMessage()]);
			$output->info('BackfillConnectionSharing: could not read the shared usages; nothing done.');
			return;
		}

		$output->info(sprintf('BackfillConnectionSharing: %d connection(s) now readable to the participants of their usages.', $written));
	}//end run()
}//end class
