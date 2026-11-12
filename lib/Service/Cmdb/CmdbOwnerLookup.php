<?php

/**
 * CMDB owner lookup
 *
 * One owner of a CMDB import row to resolve in Nextcloud Contacts: the column it came from and its identity.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

/**
 * The owner CmdbExportImportService resolves for one row: the column its warnings name and its mapped identity.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
 */
final class CmdbOwnerLookup {
	/**
	 * Constructor.
	 *
	 * @param string               $column   The owner's name column, as the row's warnings name it.
	 * @param array<string, mixed> $identity The owner pack's mapped fields: name, e-mail, employee number, role.
	 */
	public function __construct(
		public readonly string $column,
		public readonly array $identity,
	) {
	}//end __construct()
}//end class
