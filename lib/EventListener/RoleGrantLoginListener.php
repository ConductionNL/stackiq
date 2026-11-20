<?php

/**
 * Syncs the role groups of a person when they sign in.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * At sign-in, puts the person in exactly the role groups of the roles they derive.
 *
 * Runs only once the role groups are seeded and only for an account with a
 * contact person. A failure is logged and never stops the sign-in.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
class RoleGrantLoginListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RoleGrantSync   $roleGrantSync The role sync.
	 * @param RoleGroupMap    $roleGroupMap  Says whether the role groups are seeded.
	 * @param LoggerInterface $logger        Logs a failed sync.
	 */
	public function __construct(
		private readonly RoleGrantSync $roleGrantSync,
		private readonly RoleGroupMap $roleGroupMap,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Sync the signing-in user.
	 *
	 * @param Event $event The event; anything but UserLoggedInEvent is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
	 */
	public function handle(Event $event): void {
		if (($event instanceof UserLoggedInEvent) === false) {
			return;
		}

		$user = $event->getUser();
		try {
			if ($this->roleGroupMap->grantsSeeded() === false) {
				return;
			}

			$this->roleGrantSync->syncCatalogueUser(user: $user);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RoleGrantLoginListener: role groups not synced at sign-in',
				['username' => $user->getUID(), 'error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
