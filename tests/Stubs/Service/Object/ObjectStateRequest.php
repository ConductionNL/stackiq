<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectStateRequest.
 *
 * Mirrors the request OpenRegister's ArchiveHandler::archiveWith() and
 * unarchiveWith() take. The real class wins whenever OpenRegister is loaded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

if (class_exists(ObjectStateRequest::class) === false) {
	/**
	 * The object an archive verb acts on, the address it was named at, and why.
	 */
	final class ObjectStateRequest {
		/**
		 * @param string      $identifier Object id, uuid, slug or uri.
		 * @param string|null $reason     Why, as the caller put it.
		 * @param string|null $register   The register the url names, if any.
		 * @param string|null $schema     The schema the url names, if any.
		 */
		public function __construct(
			public readonly string $identifier,
			public readonly ?string $reason,
			public readonly ?string $register,
			public readonly ?string $schema,
		) {
		}
	}
}
