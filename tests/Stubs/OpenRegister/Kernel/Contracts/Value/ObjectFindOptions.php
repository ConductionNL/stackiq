<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9116),
 * so tests can assert the options a find() call builds. The register and
 * schema accept the kernel Register/Schema contracts in OpenRegister; the stub
 * widens those to object, which no Stackiq test constructs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\OpenRegister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Stub of the read options of one ObjectService::find() call.
 */
final class ObjectFindOptions {

	/**
	 * Build the options.
	 *
	 * @param object|string|int|null $register Register entity, id, UUID or slug.
	 * @param object|string|int|null $schema   Schema entity, id, UUID or slug.
	 * @param array|null             $extend   Extend directives.
	 * @param bool                   $files    Include files.
	 * @param bool                   $render   Render the object.
	 * @param bool                   $audit    Write the read audit entry.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {
	}//end __construct()

}//end class
