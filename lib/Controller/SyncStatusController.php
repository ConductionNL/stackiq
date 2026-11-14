<?php

/**
 * Sync Status Controller for Stackiq
 *
 * The schedule, the last run and a running run of the organisation sync, for
 * the Synchronisation page.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Kernel\Contracts\CronjobSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\ProgressTrackerInterface;
use OCA\Stackiq\Service\SyncAccessPolicy;
use OCA\Stackiq\Service\SyncProgress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/sync/status: interval, switch, last run and the running run.
 *
 * Nextcloud admins and functional administrators only (SyncAccessPolicy);
 * any other signed-in user gets 403.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 */
class SyncStatusController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request         The request.
	 * @param IUserSession             $userSession     The signed-in user.
	 * @param SyncAccessPolicy         $policy          Who may see the sync.
	 * @param CronjobSettingsInterface $cronjobSettings Whether the sync is switched on.
	 * @param ProgressTrackerInterface $progress        The running sync.
	 * @param IAppConfig               $appConfig       The last run.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly SyncAccessPolicy $policy,
		private readonly CronjobSettingsInterface $cronjobSettings,
		private readonly ProgressTrackerInterface $progress,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The sync's schedule, its last run and the running run (null when none runs).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($this->policy->mayViewSync(uid: $uid) === false) {
			return new JSONResponse(['message' => 'Not allowed to see the synchronisation'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			[
				'interval' => OrganizationContactSyncJob::INTERVAL_SECONDS,
				'enabled'  => $this->cronjobSettings->isCronjobEnabled(jobId: OrganizationContactSyncJob::JOB_ID),
				'lastRun'  => $this->lastRun(),
				'current'  => $this->progress->getCurrentOperation(operationType: SyncProgress::OPERATION_TYPE),
			]
		);
	}//end status()

	/**
	 * The record of the last run, or only its time for a run before records were kept, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lastRun(): ?array {
		$result = $this->appConfig->getValueArray(AppIdentity::APP_ID, 'last_sync_result', []);
		if ($result !== []) {
			return $result;
		}

		$time = $this->appConfig->getValueString(AppIdentity::APP_ID, 'last_sync_time', '');
		if ($time === '' || $time === 'Never') {
			return null;
		}

		return ['finishedAt' => $time];
	}//end lastRun()
}//end class
