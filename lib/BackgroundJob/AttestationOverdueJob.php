<?php

/**
 * Attestation Overdue Job
 *
 * Daily: a confirmation request still pending after its deadline becomes overdue.
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 * @spec      openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\BackgroundJob;

use OCA\Stackiq\Service\AttestationAnswerService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Moves pending requests past their deadline to overdue, once a day. Idempotent:
 * only pending moves, so a second run the same day changes nothing.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */
class AttestationOverdueJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $timeFactory The clock.
	 * @param AttestationAnswerService $answers     Marks the overdue requests.
	 * @param IAppManager              $appManager  Whether OpenRegister is installed.
	 * @param LoggerInterface          $logger      The logger.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly AttestationAnswerService $answers,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $timeFactory);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the overdue pass.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$this->logger->info('[AttestationOverdueJob] OpenRegister not installed, skipping run');
			return;
		}

		try {
			$moved = $this->answers->markOverdue();
			$this->logger->info('[AttestationOverdueJob] Overdue pass complete', ['overdue' => $moved]);
		} catch (\Throwable $e) {
			$this->logger->error('[AttestationOverdueJob] Overdue pass failed', ['error' => $e->getMessage()]);
		}
	}//end run()
}//end class
