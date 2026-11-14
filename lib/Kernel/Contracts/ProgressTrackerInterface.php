<?php

/**
 * Contract of ProgressTracker
 *
 * The public surface of ProgressTracker that other components call, so they depend
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
 * Contract of ProgressTracker.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgressTrackerInterface {

	/**
	 * Get current progress state
	 *
	 * @param string $operationId Operation ID to get progress for
	 *
	 * @return array|null Progress data or null if not found
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function getProgress(?string $operationId = null): ?array;

	/**
	 * The running operation of an operation type, or null when none runs.
	 *
	 * @param string $operationType The operation type, e.g. `organisation_sync`.
	 *
	 * @return array<string, mixed>|null The running operation's progress.
	 */
	public function getCurrentOperation(string $operationType): ?array;
}//end interface
