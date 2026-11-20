<?php

/**
 * The permission list the app shell filters its menu with.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Controller\AccessReviewController;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Which menu permissions a signed-in user holds.
 *
 * The shell's menu shows an entry that declares a `permission` only to a user
 * whose list holds it, and shows every entry when the list is empty. So every
 * signed-in user gets `user`, which keeps the list non-empty: without it a
 * user with no other permission would see every admin entry. `admin` is for
 * Nextcloud admins; `access.review` for admins and the members of the groups
 * AccessReviewController lets review an organisation. The list only hides
 * menu entries; each endpoint still checks access itself.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 */
final class ShellPermissions {

	/**
	 * Held by every signed-in user.
	 *
	 * @var string
	 */
	public const USER = 'user';

	/**
	 * Held by Nextcloud admins.
	 *
	 * @var string
	 */
	public const ADMIN = 'admin';

	/**
	 * Held by those who may open the Access review page.
	 *
	 * @var string
	 */
	public const ACCESS_REVIEW = 'access.review';

	/**
	 * Held by those who may see the organisation sync.
	 *
	 * @var string
	 */
	public const SYNC_VIEW = 'sync.view';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Nextcloud's group manager.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The permissions of a user; none without one.
	 *
	 * @param IUser|null $user The signed-in user.
	 *
	 * @return list<string> The permissions, `user` first.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
	 */
	public function forUser(?IUser $user): array {
		if ($user === null) {
			return [];
		}

		$permissions = [self::USER];
		$isAdmin     = $this->groupManager->isAdmin($user->getUID());
		$groupIds    = $this->groupIds(user: $user);
		if ($isAdmin === true) {
			$permissions[] = self::ADMIN;
		}

		if ($isAdmin === true || array_intersect(AccessReviewController::REVIEWER_GROUPS, $groupIds) !== []) {
			$permissions[] = self::ACCESS_REVIEW;
		}

		// The organisation sync, by SyncAccessPolicy's rule: admins and functional administrators.
		if ($isAdmin === true || in_array(SyncAccessPolicy::FUNCTIONAL_ADMIN_GROUP, $groupIds, true) === true) {
			$permissions[] = self::SYNC_VIEW;
		}

		return $permissions;
	}//end forUser()

	/**
	 * The ids of the groups the user is in.
	 *
	 * @param IUser $user The user.
	 *
	 * @return list<string> The group ids.
	 */
	private function groupIds(IUser $user): array {
		return array_values(
			array_map(
				static fn (IGroup $group): string => $group->getGID(),
				($this->groupManager->getUserGroups($user) ?? [])
			)
		);
	}//end groupIds()
}//end class
