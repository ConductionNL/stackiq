<?php
/**
 * Logs an instance whose organisation sync is switched off.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation contact sync job now honours its switch in the cronjob
 * settings: an instance where an administrator once switched it off stops
 * syncing after the upgrade. This step says so once, in the upgrade output and
 * the Nextcloud log, so nobody has to guess why syncs stopped. It changes
 * nothing and never throws.
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

use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns when the scheduled organisation sync is switched off.
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-003-the-scheduled-sync-must-not-run-while-its-switch-is-off
 */
class LogSwitchedOffSync implements IRepairStep {

	/**
	 * The warning, in the upgrade output and the log.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The scheduled organisation sync is switched off in the Stackiq cronjob settings, so it does not run.'
		.' Switch it on there to sync again.';

	/**
	 * Constructor.
	 *
	 * @param CronjobSettingsInterface $cronjobSettings The cronjob settings.
	 * @param LoggerInterface          $logger          Logger.
	 */
	public function __construct(
		private readonly CronjobSettingsInterface $cronjobSettings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Stackiq: check whether the scheduled organisation sync is switched off';
	}//end getName()

	/**
	 * Warn when the sync is switched off; do nothing otherwise.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-003-the-scheduled-sync-must-not-run-while-its-switch-is-off
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->cronjobSettings->isCronjobEnabled(OrganizationContactSyncJob::JOB_ID) === true) {
				return;
			}

			$output->warning(self::MESSAGE);
			$this->logger->warning(self::MESSAGE, ['app' => 'stackiq']);
		} catch (Throwable $e) {
			$this->logger->warning('LogSwitchedOffSync: could not read the cronjob settings', ['error' => $e->getMessage()]);
		}
	}//end run()
}//end class
