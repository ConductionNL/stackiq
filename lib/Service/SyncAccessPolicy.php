<?php

/**
 * SyncAccessPolicy
 *
 * The one place that answers whether a user may see the organisation sync.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\IGroupManager;

/**
 * A Nextcloud admin or a functional administrator may see the sync (design D4).
 *
 * `functioneel-beheerder` is the group GroupHandler keeps in step with the
 * contact person role Functioneel-beheerder.
 */
class SyncAccessPolicy {

	/**
	 * The functional administrators' group.
	 *
	 * @var string
	 */
	public const FUNCTIONAL_ADMIN_GROUP = 'functioneel-beheerder';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether a user may see the organisation sync.
	 *
	 * @param string|null $uid The user, or null when nobody is signed in.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
	 */
	public function mayViewSync(?string $uid): bool {
		if ($uid === null || $uid === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		return $this->groupManager->isInGroup($uid, self::FUNCTIONAL_ADMIN_GROUP) === true;
	}//end mayViewSync()
}//end class
