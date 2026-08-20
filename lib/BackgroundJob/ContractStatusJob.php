<?php

/**
 * Contract Status Background Job.
 *
 * Daily TimedJob that transitions catalog contracts from `Actief` to
 * `Verlopen` once their `eindDatum` has passed. All decision and persistence
 * logic lives in ContractStatusService (testable); this job is the scheduler
 * shell. The OpenRegister lifecycle TransitionEngine only expresses guarded
 * MANUAL transitions (caller must hold `update` permission) and has no
 * scheduled date-driven transition path, so a server-side TimedJob is the
 * correct mechanism (tasks.md 2.1 → 2.2).
 *
 * @category  BackgroundJob
 * @package   OCA\SoftwareCatalog\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/contract-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCA\SoftwareCatalog\Service\ContractStatusService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily background job that expires past-end-date active contracts.
 */
class ContractStatusJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $timeFactory The time factory for scheduling.
	 * @param ContractStatusService $statusService The contract status maintenance service.
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly ContractStatusService $statusService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $timeFactory);
		// Run once a day (86400s).
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the daily contract-status maintenance pass.
	 *
	 * @param mixed $argument Job arguments (not used).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-administration/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$this->logger->info('[ContractStatusJob] OpenRegister not installed, skipping run');
			return;
		}

		try {
			$count = $this->statusService->expirePastContracts();
			$this->logger->info('[ContractStatusJob] Contract status pass complete', ['transitioned' => $count]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[ContractStatusJob] Fatal error during contract status pass — cron pass protected',
				['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
			);
		}
	}//end run()
}//end class
