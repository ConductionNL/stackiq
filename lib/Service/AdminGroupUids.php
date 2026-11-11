<?php

/**
 * Lists the members of the Nextcloud admin group.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisatie-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\IGroupManager;

/**
 * The uids of the admin group's members.
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
final class AdminGroupUids {
	/**
	 * The uids of the admin group's members, in group order.
	 *
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return array<int, string> The uids, or an empty list when there is no admin group.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public static function of(IGroupManager $groupManager): array {
		$adminGroup = $groupManager->get('admin');
		if ($adminGroup === null) {
			return [];
		}

		$adminUsernames = [];
		foreach ($adminGroup->getUsers() as $user) {
			$adminUsernames[] = $user->getUID();
		}

		return $adminUsernames;
	}//end of()
}//end class
