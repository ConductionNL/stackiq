<?php

/**
 * Contract of AangebodenGebruikService
 *
 * The public surface of AangebodenGebruikService that other components call, so they depend
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

use Exception;

/**
 * Contract of AangebodenGebruikService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AangebodenGebruikServiceInterface {

	/**
	 * Delete (deny) a gebruik or koppeling object with security validation
	 *
	 * This method allows deleting a gebruik or koppeling object, but only if the active
	 * organization is the afnemer (consumer) or aanbieder (provider) for that object.
	 * This implements the "deny" workflow where a gemeente can reject a suggestion from
	 * a leverancier, or a leverancier can reject/delete their own koppelingen.
	 *
	 * Security: Since we disable RBAC to access cross-organisation objects, we must
	 * implement our own security checks to ensure only the afnemer or aanbieder can delete.
	 *
	 * @param string $gebruikId The UUID of the gebruik or koppeling object to delete
	 * @param array $options Additional options for the operation
	 *
	 * @return array Result array with success status and details
	 * @spec   openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function deleteGebruikAsAfnemer(string $gebruikId, array $options = []): array;

	/**
	 * Get all gebruiks objects (ignoring RBAC and multitenancy) - restricted to ambtenaar group
	 *
	 * This method retrieves all gebruiks objects regardless of ownership or organization,
	 * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
	 * with the "ambtenaar" group.
	 *
	 * @param array $options Additional query options (limit, offset, filters, etc.).
	 *
	 * @deprecated Use getKoppelingenGebruik() instead.
	 *
	 * @return array searchObjectsPaginated result with all gebruiks.
	 *
	 * @throws Exception When OpenRegister service is not available.
	 * @spec   openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function getAllGebruiksForAmbtenaar(array $options = []): array;

	/**
	 * Get all gebruiks, koppelingen, and other objects where the active organization is the afnemer (consumer)
	 *
	 * This method retrieves all objects (gebruiks, koppelingen, modules, etc.) where the active organization
	 * appears as the afnemer using standard RBAC filtering. It excludes objects where '@self.organisation'
	 * equals the currently active organisation, meaning only offered objects that haven't been accepted
	 * (overnomen) by this organisation yet are returned.
	 *
	 * @param array $options Additional query options (limit, offset, filters, etc.).
	 *
	 * @return array Array with success status, objects data, and metadata.
	 *
	 * @throws Exception When OpenRegister service is not available.
	 * @spec   openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function getGebruiksWhereAfnemer(array $options = []): array;

	/**
	 * Get all gebruiks objects where the active organization is in deelnemers (participants)
	 *
	 * This method retrieves all gebruiks objects where the active organization
	 * appears in the deelnemers array, using RBAC-disabled search.
	 *
	 * @param array $options Additional query options (limit, offset, filters, etc.)
	 *
	 * @return array Array with success status, gebruiks data, and metadata
	 *
	 * @throws Exception When OpenRegister service is not available
	 * @spec   openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function getGebruiksWhereDeelnemers(array $options = []): array;

	/**
	 * Get koppelingen and gebruiks for a specific organisation, application, or module UUID
	 *
	 * This method retrieves koppelingen and gebruiks related to a specific UUID based on:
	 * - If user has ambtenaar role: return all related objects (optionally filtered by organization)
	 * - If user's organization owns the application/module: return all related usage
	 * - Otherwise: return empty result
	 *
	 * The UUID can be:
	 * - An organisation UUID: returns all gebruiks/koppelingen for that organisation
	 * - An application/suite UUID: returns all gebruiks/koppelingen that reference that suite
	 * - A module UUID: returns all gebruiks/koppelingen that reference that module
	 *
	 * @param string $uuid The UUID of the organisation, application, or module.
	 * @param array $options Additional query options (limit, offset, filters, organisation, etc.).
	 * @param bool $isAmbtenaar Whether the user has ambtenaar privileges.
	 *
	 * @return array searchObjectsPaginated result with koppelingen and gebruiks for the UUID.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isAmbtenaar is a simple privilege toggle
	 * @spec                                        openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function getKoppelingenGebruikByUuid(string $uuid, array $options = [], bool $isAmbtenaar = false): array;

	/**
	 * Get all gebruiks objects belonging to a specific suite ID - restricted to ambtenaar group.
	 *
	 * This method retrieves all gebruiks objects that belong to the specified suite ID,
	 * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
	 * with the "ambtenaar" group.
	 *
	 * @param string $suiteId The ID of the suite to get gebruiks for
	 * @param array $options Additional query options (extend, fields, etc.)
	 *
	 * @return array searchObjectsPaginated result with all gebruiks for the suite
	 *
	 * @throws Exception When OpenRegister service is not available
	 * @spec   openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function getSingleGebruikForAmbtenaar(string $suiteId, array $options = []): array;

	/**
	 * Set the @self property of a gebruik to the active organization
	 *
	 * This method updates the @self.organisation property of a specific gebruik
	 * or koppeling object, but only if the active organization is the afnemer
	 * (consumer) or aanbieder (provider) for that object.
	 *
	 * @param string $gebruikId The UUID of the gebruik or koppeling object to update
	 * @param array $options Additional update options
	 *
	 * @return array Result with success status and updated object data
	 *
	 * @throws Exception When OpenRegister service is not available or operation fails
	 * @spec   openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function setGebruikSelfToActiveOrg(string $gebruikId, array $options = []): array;
}//end interface
