<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\ObjectSelection.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9103):
 * the ids and relation (uses) a search or count is narrowed to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\OpenRegister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Stub of the ids/uses selection of one search.
 */
final class ObjectSelection {

	/**
	 * Build the selection.
	 *
	 * @param array|null  $ids  Object ids or uuids to narrow to.
	 * @param string|null $uses Uuid the objects must relate to.
	 */
	public function __construct(
		public readonly ?array $ids = null,
		public readonly ?string $uses = null,
	) {
	}//end __construct()

}//end class
