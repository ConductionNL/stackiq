<?php

/**
 * Where a loaded CMDB mapping pack came from, as a refusal names it.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

/**
 * The label a refusal names a pack by, and the details it carries.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/tasks.md#task-1
 */
final class CmdbPackOrigin {
	/**
	 * Constructor.
	 *
	 * @param string $label The pack as the message names it: its file, or its OpenRegister slug and id.
	 * @param array<string, mixed> $details The exception details: the OpenRegister pack and its id, for a stored pack.
	 */
	public function __construct(
		public readonly string $label,
		public readonly array $details = [],
	) {
	}//end __construct()
}//end class
