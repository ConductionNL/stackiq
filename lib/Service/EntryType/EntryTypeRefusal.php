<?php

/**
 * Entry type change refusal
 *
 * Why a type change was refused, with the HTTP status the controller answers.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\EntryType
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\EntryType;

use RuntimeException;

/**
 * A refused type change: 403, 404, 409 or 422, with the blockers when there are any.
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 */
class EntryTypeRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message Why, for the caller.
	 * @param int $status The HTTP status.
	 * @param array<int, array{schema: string, field: string, count: int}> $blockers The references that block it.
	 */
	public function __construct(
		string $message,
		public readonly int $status,
		public readonly array $blockers=[],
	) {
		parent::__construct(message: $message);

	}//end __construct()
}//end class
