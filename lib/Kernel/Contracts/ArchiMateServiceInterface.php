<?php

/**
 * Contract of ArchiMateService
 *
 * The public surface of ArchiMateService that other components call, so they depend
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
 * Contract of ArchiMateService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ArchiMateServiceInterface {

	/**
	 * Export organization-specific ArchiMate data to XML
	 *
	 * Produces an enriched AMEFF file with the base GEMMA model plus the
	 * organization's applications plotted on referentiecomponenten in views.
	 *
	 * @param string $organizationUuid UUID of the organization to export for.
	 * @param array $options Optional export options.
	 *
	 * @return array Export results with 'success', 'xml', 'file_name'
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function exportOrgArchiMate(string $organizationUuid, array $options = []): array;

	/**
	 * Export ArchiMate data to XML
	 *
	 * @param string|null $organization Organization filter (currently not implemented)
	 *
	 * @return array Export results
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function exportToArchiMate(?string $organization = null): array;

	/**
	 * Import ArchiMate XML file from path with model detection and round-trip fidelity
	 *
	 * This method handles the complete import workflow:
	 * 1. Parse XML to array (capturing all possible XML values)
	 * 2. Detect if model already exists or is new
	 * 3. Normalize data structure for storage as JSON blob
	 * 4. Convert to OpenRegister objects with proper @self structure
	 * 5. Save objects using ObjectService::saveObjects
	 *
	 * @param array $options Import options including file_path, fileName, etc.
	 *
	 * @return array Import results with detailed status
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function importArchiMateFileFromPath(array $options = []): array;

	/**
	 * OPTIMIZED: Import ArchiMate XML file using OpenRegister-style performance optimization
	 *
	 * This method follows the same pattern as OpenRegister ImportService:
	 * 1. Parse ALL XML data first (single pass)
	 * 2. Transform to objects array (batch processing)
	 * 3. Single saveObjects() call with all objects
	 *
	 * Expected performance: <1 minute for 8000 objects (vs current 13 minutes)
	 *
	 * @param array $options Import options including file_path, fileName, etc.
	 *
	 * @return array Import results with detailed status
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function importArchiMateFileFromPathOptimized(array $options = []): array;
}//end interface
