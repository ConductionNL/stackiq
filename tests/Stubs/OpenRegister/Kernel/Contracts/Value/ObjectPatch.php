<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9096),
 * so tests can assert what a patchObject() call asks for.
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
 * Stub of one object patch: id, changed properties, target and acting user.
 */
final class ObjectPatch {

	/**
	 * Build the patch.
	 *
	 * @param string               $objectId    Object id or uuid.
	 * @param array<string, mixed> $data        The properties to change.
	 * @param ObjectFindTarget     $target      Register and schema the object lives in.
	 * @param IUser|null           $currentUser The acting user, if not the session user.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly array $data,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?IUser $currentUser=null,
	) {
	}//end __construct()

}//end class
