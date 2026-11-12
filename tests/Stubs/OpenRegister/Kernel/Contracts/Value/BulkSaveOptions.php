<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions.
 *
 * Mirrors the constructor of OpenRegister's value object (openregister #9108),
 * so tests can assert what a saveObjects() call asks for.
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
 * Stub of the options of one bulk save: defaults, validation, events and audit.
 */
final class BulkSaveOptions {

	/**
	 * Build the options.
	 *
	 * @param BulkBatchDefaults $defaults   Register and schema the batch falls back to.
	 * @param bool              $validation Validate every object.
	 * @param bool              $events     Dispatch object events.
	 * @param bool              $audit      Write audit entries.
	 */
	public function __construct(
		public readonly BulkBatchDefaults $defaults=new BulkBatchDefaults(),
		public readonly bool $validation=false,
		public readonly bool $events=false,
		public readonly bool $audit=true,
	) {
	}//end __construct()

}//end class
