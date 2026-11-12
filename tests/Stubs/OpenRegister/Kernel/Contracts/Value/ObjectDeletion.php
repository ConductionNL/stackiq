<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9099),
 * so tests can assert what a deleteObject() call asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCP\IUser;

/**
 * Stub of one object deletion: uuid, target, acting user and permanence.
 */
final class ObjectDeletion {

	/**
	 * Build the deletion.
	 *
	 * @param string           $uuid        Object uuid.
	 * @param ObjectFindTarget $target      Register and schema the object lives in.
	 * @param IUser|null       $currentUser The acting user, if not the session user.
	 * @param bool             $permanent   Hard-delete instead of soft-delete.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?IUser $currentUser=null,
		public readonly bool $permanent=false,
	) {
	}//end __construct()

}//end class
