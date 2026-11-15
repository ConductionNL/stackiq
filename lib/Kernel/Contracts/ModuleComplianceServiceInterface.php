<?php

/**
 * Contract of ModuleComplianceService
 *
 * The public surface of ModuleComplianceService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of ModuleComplianceService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ModuleComplianceServiceInterface {

	/**
	 * Handle module compliance update
	 *
	 * @param object $moduleObject The module object that was updated
	 *
	 * @return void
	 *
	 * @throws \Exception If the update fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 * @spec openspec/specs/module-compliance-assessment/spec.md
	 */
	public function handleModuleComplianceUpdate(object $moduleObject): void;

	/**
	 * Perform bulk sync of module standards from all compliance objects
	 *
	 * @return array Results of the bulk sync operation
	 *
	 * @throws \Exception If the bulk sync fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function bulkSyncModuleStandards(): array;
}//end interface
