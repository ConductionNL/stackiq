<?php

/**
 * Contract of ViewService
 *
 * The public surface of ViewService that other components call, so they depend
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
 * Contract of ViewService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ViewServiceInterface {

	/**
	 * Get all views from the system.
	 *
	 * @param array $options Query options including enrichment flags.
	 *
	 * @return array Array of view objects with optional enrichments.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getAllViews(array $options = []): array;

	/**
	 * Get a specific view by ID.
	 *
	 * @param string $viewId The view identifier.
	 * @param array $options Query options including enrichment flags.
	 *
	 * @return array View object with optional enrichments or error response.
	 * @spec   openspec/specs/dashboard-views-api/spec.md
	 */
	public function getView(string $viewId, array $options = []): array;
}//end interface
