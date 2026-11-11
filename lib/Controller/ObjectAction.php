<?php

/**
 * Object action for Stackiq controllers
 *
 * One accept/deny style action on a single object: the object id from the
 * route and the action's definition from the controller's action table.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

/**
 * An action on one object, as handled by ObjectActionResponder.
 *
 * The definition holds: idParam (the route parameter left out of the
 * forwarded options), idLabel, logIdKey, notFoundError, endpoint (a sprintf
 * pattern taking the id), method, the started/completed/failed log messages,
 * emptyResult (the fields an error body carries) and reportsDeleted.
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 */
final class ObjectAction {
	/**
	 * Constructor.
	 *
	 * @param string               $id         The object id from the route.
	 * @param array<string, mixed> $definition The action definition.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $definition,
	) {
	}//end __construct()
}//end class
