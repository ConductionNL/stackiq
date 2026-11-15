<?php

/**
 * Contract of CronjobSettings
 *
 * The public surface of CronjobSettings that other components call, so they depend
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
 * Contract of CronjobSettings.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CronjobSettingsInterface {

	/**
	 * Get all cronjob configurations.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 *
	 * @return array The cronjob configurations indexed by job name
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getCronjobConfig(): array;

	/**
	 * Whether an admin left a cronjob switched on in the cronjob settings.
	 *
	 * Reads the `enabled` flag `updateCronjobConfig()` stores under
	 * `cronjob_config`. A job that was never saved is on, as the settings
	 * screen shows it (`getCronjobConfig()` defaults `enabled` to true).
	 *
	 * @param string $jobId The cronjob identifier, e.g. `organization_contact_sync`.
	 *
	 * @return bool False only when the admin switched the job off.
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/tasks.md#task-3
	 */
	public function isCronjobEnabled(string $jobId): bool;

	/**
	 * Update cronjob configuration.
	 *
	 * @param array $data The cronjob configuration data.
	 *
	 * @return array Result of the update operation.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function updateCronjobConfig(array $data): array;
}//end interface
