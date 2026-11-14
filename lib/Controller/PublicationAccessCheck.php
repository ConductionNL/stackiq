<?php

/**
 * One check of who may publish or depublish a catalog entry.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCP\IUser;

/**
 * The entry being checked, the signed-in caller and the entry data once resolved.
 *
 * Built and read by PublicationController only.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */
final class PublicationAccessCheck {
	/**
	 * The signed-in caller.
	 *
	 * @var IUser|null
	 */
	public ?IUser $user = null;

	/**
	 * The resolved entry's data.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * Constructor.
	 *
	 * @param string $objectType The catalog object type.
	 * @param string $uuid The entry uuid.
	 */
	public function __construct(
		public readonly string $objectType,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
