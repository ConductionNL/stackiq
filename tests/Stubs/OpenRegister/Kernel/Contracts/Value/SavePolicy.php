<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy.
 *
 * Mirrors OpenRegister's value object (openregister #9123): how one save runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Stub of the save policy: events, validation, insert-only and duplicate override.
 */
final class SavePolicy {

	/**
	 * Build the policy.
	 *
	 * @param bool $silent        Skip the object events.
	 * @param bool $validation    Validate against the schema.
	 * @param bool $failIfExists  Refuse when the uuid already exists.
	 * @param bool $dedupOverride Save through a blocking duplicate match.
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}//end __construct()

}//end class
