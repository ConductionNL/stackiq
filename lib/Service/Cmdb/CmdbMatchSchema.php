<?php

/**
 * CMDB match schema
 *
 * One schema the CMDB import matches on: its key and its configured id.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

/**
 * A schema CmdbExportImportService checks before an import: the schema key its refusals name and the id it reads.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
 */
final class CmdbMatchSchema {
	/**
	 * Constructor.
	 *
	 * @param string $type The schema key (module, organization, usage, contactPerson).
	 * @param int    $id   The configured schema id.
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $id,
	) {
	}//end __construct()
}//end class
