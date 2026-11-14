<?php

/**
 * Test stub of OpenRegister's UserProfileUpdatedEvent.
 *
 * A copy of `lib/Event/UserProfileUpdatedEvent.php` in ConductionNL/openregister at
 * development 551af4c (3 Nov 2026): the same constructor and the same
 * accessors, so a listener test that calls a method the real event lacks fails
 * here as it would in production.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;
use OCP\IUser;

/**
 * Dispatched after a user's profile fields change.
 */
class UserProfileUpdatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param IUser                $user    The user whose profile changed.
	 * @param array<string, mixed> $oldData The profile before the change.
	 * @param array<string, mixed> $newData The profile after the change.
	 * @param array<int, string>   $changes The names of the changed fields.
	 */
	public function __construct(
		private readonly IUser $user,
		private readonly array $oldData,
		private readonly array $newData,
		private readonly array $changes,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The user whose profile changed.
	 *
	 * @return IUser
	 */
	public function getUser(): IUser {
		return $this->user;
	}//end getUser()

	/**
	 * The user id.
	 *
	 * @return string
	 */
	public function getUserId(): string {
		return $this->user->getUID();
	}//end getUserId()

	/**
	 * The profile before the change.
	 *
	 * @return array<string, mixed>
	 */
	public function getOldData(): array {
		return $this->oldData;
	}//end getOldData()

	/**
	 * The profile after the change.
	 *
	 * @return array<string, mixed>
	 */
	public function getNewData(): array {
		return $this->newData;
	}//end getNewData()

	/**
	 * The names of the changed fields.
	 *
	 * @return array<int, string>
	 */
	public function getChanges(): array {
		return $this->changes;
	}//end getChanges()

	/**
	 * Whether a field changed.
	 *
	 * @param string $fieldName The field name.
	 *
	 * @return bool
	 */
	public function hasChanged(string $fieldName): bool {
		return in_array($fieldName, $this->changes, true);
	}//end hasChanged()

	/**
	 * Whether any name field changed.
	 *
	 * @return bool
	 */
	public function hasNameChanges(): bool {
		$nameFields = ['firstName', 'lastName', 'middleName', 'displayName'];
		foreach ($nameFields as $field) {
			if ($this->hasChanged(fieldName: $field) === true) {
				return true;
			}
		}

		return false;
	}//end hasNameChanges()
}//end class
