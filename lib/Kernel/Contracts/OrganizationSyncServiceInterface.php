<?php

/**
 * Contract of OrganizationSyncService
 *
 * The public surface of OrganizationSyncService that other components call, so they depend
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
 * Contract of OrganizationSyncService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganizationSyncServiceInterface {

	/**
	 * Gets synchronization status with error handling
	 *
	 * This method provides status information with proper error handling
	 * for API responses.
	 *
	 * @param int $minutesBack The number of minutes to look back.
	 *
	 * @return array Status information with error handling.
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function getSyncStatusWithErrorHandling(int $minutesBack = 10): array;

	/**
	 * Performs manual synchronization with API-specific logging
	 *
	 * This method is designed to be called by the API controller and includes
	 * all necessary logging, error handling, and response formatting.
	 * Uses full sync (minutesBack = 0) for manual triggers by default.
	 *
	 * @param int $minutesBack Number of minutes to look back for changes (default: 0 for full sync)
	 *
	 * @return array Synchronization results formatted for API response
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performManualSync(int $minutesBack = 0): array;

	/**
	 * Performs optimized manual synchronization for large datasets
	 *
	 * This method processes organizations in multiple batches to handle large numbers
	 * (800+) efficiently while staying under timeout limits.
	 *
	 * @param int $maxRounds Maximum number of batch rounds to execute (default: 10)
	 * @param int $batchSize Number of items to process per batch (default: 100)
	 *
	 * @return array Comprehensive synchronization results
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performOptimizedManualSync(int $maxRounds = 10, int $batchSize = 100): array;

	/**
	 * Performs scheduled synchronization with comprehensive logging
	 *
	 * This method is designed to be called by the background job and includes
	 * all necessary logging, error handling, and status tracking.
	 * Uses default full sync (0 minutes) to process all organizations.
	 *
	 * @param int $minutesBack Number of minutes to look back for changes (default: 0 = full sync)
	 *
	 * @return array Synchronization results with detailed logging information
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function performScheduledSync(int $minutesBack = 0): array;
}//end interface
