<?php

/**
 * Repair step that clears the `oc_jobs` rows orphaned by the namespace rename.
 *
 * `oc_jobs` stores a background job's CLASS NAME as a string, so the four rows
 * this app registered under `OCA\SoftwareCatalog\BackgroundJob\` now name
 * classes that no longer exist. Nextcloud re-registers the new
 * `OCA\Stackiq\BackgroundJob\` classes from `appinfo/info.xml` on install, but
 * it never removes the old rows — they linger as jobs that can never be
 * constructed. Nothing reports that: the job simply never runs again, which
 * presents as "contract statuses stopped updating", not as an error.
 *
 * This step deregisters the legacy class names explicitly, so the only
 * surviving registration is the one Nextcloud just wrote.
 *
 * SAFE BY CONSTRUCTION: it removes ONLY the four literal legacy FQCNs listed
 * below. It cannot touch another app's jobs, and it cannot touch the app's own
 * new jobs, because none of them share those strings.
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-background-job-classes-survive-the-rename
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\AppInfo\Application;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes background job registrations left behind by the namespace rename.
 *
 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-background-job-classes-survive-the-rename
 */
class MigrateBackgroundJobClasses implements IRepairStep {
	/**
	 * The exact job class strings written to `oc_jobs` before the rename.
	 *
	 * Written out in full rather than derived from the current class names,
	 * so that a later namespace change cannot silently widen what this step
	 * deletes. These four are the complete `<background-jobs>` list as it stood
	 * in `appinfo/info.xml` before the rename.
	 */
	public const LEGACY_JOB_CLASSES = [
		'OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob',
		'OCA\SoftwareCatalog\BackgroundJob\ContractStatusJob',
		'OCA\SoftwareCatalog\BackgroundJob\FederationSyncJob',
		'OCA\SoftwareCatalog\BackgroundJob\EolSyncJob',
	];

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-background-job-classes-survive-the-rename
	 */
	public function getName(): string {
		return 'Deregister background jobs orphaned by the OCA\\SoftwareCatalog namespace rename';
	}//end getName()

	/**
	 * Remove every legacy job registration.
	 *
	 * The whole body sits inside the try. This step runs under `<install>` —
	 * the only hook that fires on the fresh install an app-id rename performs —
	 * so an escaping throw aborts the install and the app never enables at all.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-app-id-to-stackiq/specs/app-id-rename/spec.md#requirement-stored-background-job-classes-survive-the-rename
	 */
	public function run(IOutput $output): void {
		try {
			$removed = 0;

			foreach (self::LEGACY_JOB_CLASSES as $legacyClass) {
				// remove() is a no-op when the row is absent, so has() is only an
				// accounting call — it keeps the reported count honest on a
				// re-run rather than claiming work that did not happen.
				if ($this->jobList->has($legacyClass, null) === false) {
					continue;
				}

				$this->jobList->remove($legacyClass, null);
				$removed++;
			}

			$output->info(
				sprintf('Stackiq: deregistered %d orphaned background job(s) from the legacy namespace', $removed)
			);
		} catch (Throwable $e) {
			// Swallowed deliberately — see the docblock.
			$this->logger->error(
				'Stackiq: failed to deregister legacy background jobs: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'exception' => $e,
				]
			);
			$output->warning('Stackiq: legacy background job cleanup failed; see the log.');
		}//end try
	}//end run()
}//end class
