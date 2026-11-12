<?php

/**
 * Role group membership
 *
 * A user and one role-based group, as GroupHandler syncs the user's
 * membership of it with the user's roles.
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Stackiq;

use OCP\IGroup;
use OCP\IUser;

/**
 * The membership of one user in one role-based group.
 *
 * @category Handler
 * @package  OCA\Stackiq\Service\Stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
final class RoleGroupMembership {

	/**
	 * The user.
	 *
	 * @var IUser
	 */
	public IUser $user;

	/**
	 * The configured name of the group.
	 *
	 * @var string
	 */
	public string $groupName = '';

	/**
	 * The group.
	 *
	 * @var IGroup
	 */
	public IGroup $group;
}//end class
