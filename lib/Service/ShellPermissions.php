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
	 */
	public function forUser(?IUser $user): array {
		if ($user === null) {
			return [];
		}

		$permissions = [self::USER];
		$isAdmin = $this->groupManager->isAdmin($user->getUID());
		if ($isAdmin === true) {
			$permissions[] = self::ADMIN;
		}

		if ($isAdmin === true || $this->inReviewerGroup(user: $user) === true) {
			$permissions[] = self::ACCESS_REVIEW;
		}

		return $permissions;
	}//end forUser()

	/**
	 * Whether the user is in one of the reviewer groups.
	 *
	 * @param IUser $user The user.
	 *
	 * @return bool True for a member.
	 */
	private function inReviewerGroup(IUser $user): bool {
		$groupIds = array_map(
			static fn (IGroup $group): string => $group->getGID(),
			$this->groupManager->getUserGroups($user)
		);

		return array_intersect(AccessReviewController::REVIEWER_GROUPS, $groupIds) !== [];
	}//end inReviewerGroup()
}//end class
