<?php

/**
 * Contract of AanbodService
 *
 * The public surface of AanbodService that other components call, so they depend
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
 * Contract of AanbodService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AanbodServiceInterface {

	/**
	 * Accept an aanbod object (set @self.organisation to current organisation).
	 *
	 * This method updates the @self.organisation property of an aanbod object
	 * to the active organization, but only if the active organization is either
	 * the afnemer (for gebruiks) or aanbieder (for modules, diensten, koppelingen).
	 *
	 * @param string $aanbodId The UUID of the aanbod object to accept
	 * @param array $options Additional update options
	 *
	 * @return array Result with success status and updated object data
	 *
	 * @throws Exception When OpenRegister service is not available or operation fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function acceptAanbod(string $aanbodId, array $options = []): array;

	/**
	 * Deny an aanbod object (delete it).
	 *
	 * This method deletes an aanbod object, but only if the active organization
	 * is either the afnemer (for gebruiks) or aanbieder (for modules, diensten, koppelingen).
	 *
	 * @param string $aanbodId The UUID of the aanbod object to deny
	 * @param array $options Additional options for the operation
	 *
	 * @return array Result array with success status and details
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function denyAanbod(string $aanbodId, array $options = []): array;

	/**
	 * Get all aanbod objects (modules, diensten, koppelingen, gebruiks).
	 *
	 * Returns modules, diensten, and koppelingen where the current organisation
	 * is in the aanbieder property, or gebruiks where the current organisation
	 * is in the afnemer property. Excludes objects where @self.organisation
	 * equals the current organisation (already accepted).
	 *
	 * @param array $options Additional query options (limit, offset, filters, etc.)
	 *
	 * @return array Array with success status, aanbod objects data, and metadata
	 *
	 * @throws Exception When OpenRegister service is not available
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getAanbod(array $options = []): array;
}//end interface
