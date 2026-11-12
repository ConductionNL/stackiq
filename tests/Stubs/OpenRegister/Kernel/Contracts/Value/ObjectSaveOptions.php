<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9123),
 * so tests can assert what a saveObject() call asks for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Stub of the options of one save: target, uuid, extend, files, policy and actor.
 */
final class ObjectSaveOptions {

	/**
	 * Build the options.
	 *
	 * @param ObjectFindTarget $target        Register and schema to save into.
	 * @param string|null      $uuid          The object uuid (null creates).
	 * @param array|null       $extend        Relations to expand on the result.
	 * @param array|null       $uploadedFiles Files uploaded alongside the object.
	 * @param SavePolicy       $policy        Events, validation and insert-only.
	 * @param SaveActor        $actor         The acting user and owner.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
		public readonly SavePolicy $policy=new SavePolicy(),
		public readonly SaveActor $actor=new SaveActor(),
	) {
	}//end __construct()

}//end class
