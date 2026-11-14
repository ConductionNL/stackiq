<?php

/**
 * Test stub for OCA\OpenRegister\Service\Organisation\NewOrganisation.
 *
 * Mirrors the value object OpenRegister's createOrganisation() takes. The
 * real class wins whenever OpenRegister is loaded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

if (class_exists(NewOrganisation::class) === false) {
	/**
	 * The organisation to create: name, description and an optional uuid.
	 */
	final class NewOrganisation {
		/**
		 * @param string $name        The name.
		 * @param string $description The description.
		 * @param string $uuid        The uuid to use, or '' for a generated one.
		 */
		public function __construct(
			public readonly string $name,
			public readonly string $description = '',
			public readonly string $uuid = '',
		) {
		}
	}
}
