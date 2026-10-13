<?php

/**
 * A stand-in for OpenRegister's pack controller on an OpenRegister with the hardened store.
 *
 * Its write methods carry no attribute or annotation that opens them, so
 * Nextcloud keeps them admin-only and CSRF-protected; CmdbImportPackStore
 * checks exactly that by reflection.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

/**
 * The pack routes.
 */
class MigrationPacksController {

	/**
	 * Create a pack.
	 *
	 * @return void
	 */
	public function create(): void {
	}//end create()

	/**
	 * Update a pack.
	 *
	 * @return void
	 */
	public function update(): void {
	}//end update()

	/**
	 * Delete a pack.
	 *
	 * @return void
	 */
	public function destroy(): void {
	}//end destroy()

	/**
	 * Import a pack.
	 *
	 * @return void
	 */
	public function import(): void {
	}//end import()

	/**
	 * Preview a pack.
	 *
	 * @return void
	 */
	public function preview(): void {
	}//end preview()
}//end class
