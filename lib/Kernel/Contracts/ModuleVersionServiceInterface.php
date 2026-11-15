<?php

/**
 * Contract of ModuleVersionService
 *
 * The public surface of ModuleVersionService that other components call, so they depend
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
 * Contract of ModuleVersionService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ModuleVersionServiceInterface {

	/**
	 * Ensures a module has at least one version.
	 *
	 * Thin orchestrator: resolves config + existing versions via
	 * `fetchVersionData()`, decides whether action is needed via
	 * `compareVersions()`, then writes through `updateVersionRecord()`.
	 *
	 * @param object $moduleObject The module object entity
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
	 */
	public function ensureDefaultVersion(object $moduleObject): void;
}//end interface
