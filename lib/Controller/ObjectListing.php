<?php

/**
 * Object listing for Stackiq controllers
 *
 * One listing endpoint: the identifying fields its log lines carry and its
 * definition from the controller's listing table.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * A listing request, as handled by ListingResponder.
 *
 * The definition holds: endpoint (as logged), started, completed and failed
 * log messages, rejected (the log message for an anonymous caller, where the
 * endpoint refuses one) and failureLogsEndpoint (the failure line carries the
 * endpoint instead of the stack trace).
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
final class ObjectListing {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $idContext  Identifying fields of the log lines (e.g. the requested id).
	 * @param array<string, mixed> $definition The listing definition.
	 */
	public function __construct(
		public readonly array $idContext,
		public readonly array $definition,
	) {
	}//end __construct()
}//end class
