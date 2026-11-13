<?php

/**
 * CMDB import request
 *
 * One CMDB import as the admin asked for it: the file, the options and when it started.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

/**
 * What CmdbExportImportService::import() was handed, with the moment it started, for the run under the lock.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
final class CmdbImportRequest {
	/**
	 * Constructor.
	 *
	 * @param string               $path      The xlsx file.
	 * @param array<string, mixed> $options   The import options.
	 * @param string               $startedAt ISO start time of the import.
	 */
	public function __construct(
		public readonly string $path,
		public readonly array $options,
		public readonly string $startedAt,
	) {
	}//end __construct()
}//end class
