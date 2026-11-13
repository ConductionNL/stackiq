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

	/**
	 * Process a specific organization object (called from event listener)
	 *
	 * This method processes a single organization object, creating or updating
	 * the corresponding organization entity as needed.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object to process
	 *
	 * @return array Processing results
	 * @spec   openspec/specs/organization-sync/spec.md
	 */
	public function processSpecificOrganization($organizationObject): array;

	/**
	 * Why an organisatie may not be linked to an OpenRegister organisation, or null when it may.
	 *
	 * Refused when the organisatie is unknown or already has an organisation, when
	 * the organisation is unknown, is OpenRegister's default organisation, or is
	 * already another organisatie's, and when OpenRegister cannot say which
	 * organisation is its default. Nothing is written.
	 *
	 * @param string $organisatieUuid The organisatie object's uuid.
	 * @param string $entityUuid      The OpenRegister organisation's uuid.
	 *
	 * @return string|null no-organisatie, already-linked, no-organisation, default-unknown,
	 *                     default-organisation, organisation-in-use, or null.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function checkOrganisationLink(string $organisatieUuid, string $entityUuid): ?string;

	/**
	 * Link an organisatie to an existing OpenRegister organisation, as an administrator decides.
	 *
	 * The organisation takes the organisatie's uuid, which is what links the two,
	 * and becomes the organisatie object's owner. The link is recorded so the sync
	 * keeps the two together although the organisation is older. Refused for the
	 * reasons checkOrganisationLink() gives.
	 *
	 * With both uuids the same it confirms an organisatie the sync refused for
	 * carrying the uuid of an older organisation.
	 *
	 * @param string $organisatieUuid The organisatie object's uuid.
	 * @param string $entityUuid      The OpenRegister organisation's uuid.
	 *
	 * @return string linked, or the refusal checkOrganisationLink() gives.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function linkOrganisationEntity(string $organisatieUuid, string $entityUuid): string;
}//end interface
