<?php

/**
 * Contract of LifecycleCalendarService
 *
 * The public surface of LifecycleCalendarService that other components call, so they depend
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
 * Contract of LifecycleCalendarService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LifecycleCalendarServiceInterface {

	/**
	 * Keep the "Contract ends" event of a contract in step with its end date.
	 *
	 * @param array<string, mixed> $object The contract (uuid, register, schema, data).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.1
	 */
	public function syncContract(array $object): void;

	/**
	 * Keep the "End of support" event of a module version in step with its
	 * end-of-support date, including a date the EOL sync stamps again.
	 *
	 * @param array<string, mixed> $object The module version (uuid, register, schema, data).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.1
	 */
	public function syncModuleVersion(array $object): void;
}//end interface
