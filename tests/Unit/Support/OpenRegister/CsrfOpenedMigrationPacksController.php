<?php

/**
 * A pack controller whose update skips the CSRF check with an attribute, as OpenRegister's development branch does.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support\OpenRegister
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

namespace OCA\Stackiq\Tests\Unit\Support\OpenRegister;

use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

/**
 * A pack controller whose update skips the CSRF check with an attribute, as OpenRegister's development branch does.
 */
class CsrfOpenedMigrationPacksController {

	/**
	 * The create route.
	 *
	 * @return void
	 */
	public function create(): void {
	}//end create()

	/**
	 * The update route.
	 *
	 * @return void
	 */
	#[NoCSRFRequired]
	public function update(): void {
	}//end update()

	/**
	 * The destroy route.
	 *
	 * @return void
	 */
	public function destroy(): void {
	}//end destroy()

	/**
	 * The import route.
	 *
	 * @return void
	 */
	public function import(): void {
	}//end import()

	/**
	 * The preview route.
	 *
	 * @return void
	 */
	public function preview(): void {
	}//end preview()
}//end class
