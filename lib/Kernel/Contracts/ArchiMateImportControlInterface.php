<?php

/**
 * Contract of ArchiMateImportControl
 *
 * The public surface of ArchiMateImportControl that other components call, so they depend
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
 * Contract of ArchiMateImportControl.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ArchiMateImportControlInterface {

	/**
	 * Cancel a running ArchiMate import
	 *
	 * This method combines force clearing and process killing for a complete
	 * import cancellation. It delegates to ArchiMateService for the actual work.
	 *
	 * @param string|null $operationId The running import's operation id, when the page knows it
	 *
	 * @return array Cancellation result with detailed status
	 * @spec   openspec/specs/settings-service/spec.md
	 * @spec   openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function cancelArchiMateImport(?string $operationId = null): array;

	/**
	 * Clear ArchiMate export status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearArchiMateExportStatus(): void;

	/**
	 * Clear ArchiMate import status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return array Clear operation result
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearArchiMateImportStatus(): array;

	/**
	 * Force kill running ArchiMate import process and clear status
	 *
	 * This method delegates to ArchiMateService to handle process termination
	 * and status cleanup.
	 *
	 * @return array Kill operation result
	 * @deprecated Use cancelArchiMateImport() instead
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function killArchiMateImport(): array;
}//end interface
