<?php

/**
 * Schedule of the organisation contact sync job
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel;

/**
 * The switch id and interval of the organisation contact sync job, shared by
 * the job, its cronjob settings and the sync status.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural constants, no functional requirement.
 */
final class OrganizationContactSyncSchedule {

	/**
	 * The id this job's switch is stored under in `cronjob_config`.
	 *
	 * @var string
	 */
	public const JOB_ID = 'organization_contact_sync';

	/**
	 * How often the job runs, in seconds; the cronjob settings show the same value.
	 *
	 * @var integer
	 */
	public const INTERVAL_SECONDS = 300;
}//end class
