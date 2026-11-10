<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\SaveActor.
 *
 * Mirrors OpenRegister's value object (openregister #9123): who one save runs as.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * Stub of the acting user, explicit owner and unowned flag of one save.
 */
final class SaveActor {

	/**
	 * Build the actor.
	 *
	 * @param IUser|null  $currentUser The acting user, if not the session user.
	 * @param string|null $owner       An explicit owner for a new object.
	 * @param bool        $unowned     Save without an owner.
	 */
	public function __construct(
		public readonly ?IUser $currentUser=null,
		public readonly ?string $owner=null,
		public readonly bool $unowned=false,
	) {
	}//end __construct()

}//end class
