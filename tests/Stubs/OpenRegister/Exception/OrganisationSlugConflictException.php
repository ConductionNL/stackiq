<?php

/**
 * Test copy of OpenRegister's OrganisationSlugConflictException (openregister#5462).
 *
 * Loaded only when OpenRegister does not provide the class. It sits two
 * directories below tests/Stubs so the early glob in tests/bootstrap.php does not
 * load it before Nextcloud boots.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A new organisation's slug is already taken.
 */
class OrganisationSlugConflictException extends RuntimeException {

	/**
	 * @param string          $slug     The taken slug.
	 * @param \Throwable|null $previous The cause.
	 */
	public function __construct(public readonly string $slug, ?\Throwable $previous = null) {
		parent::__construct(message: 'An organisation with this name already exists.', code: 409, previous: $previous);
	}//end __construct()
}//end class
