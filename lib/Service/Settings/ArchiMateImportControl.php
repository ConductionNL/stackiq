<?php

/**
 * ArchiMate Import Control for Stackiq
 *
 * Moved out of the general settings service: setting and clearing the
 * ArchiMate import and export status, and stopping or cancelling a running
 * import. Each call is handed to ArchiMateService, with the app config as
 * the fallback when ArchiMateService cannot be resolved.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets, clears and cancels the ArchiMate import and export status.
 *
 * Not final, like the other settings handlers in this directory, so the
 * services that depend on it can be tested against a mock.
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class ArchiMateImportControl {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config    The app config, the fallback store of the status.
	 * @param ContainerInterface $container Resolves ArchiMateService.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set ArchiMate import status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @param array $status The import status.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setArchiMateImportStatus(array $status): void {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$archiMateService->setArchiMateImportStatus($status);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to set ArchiMate import status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$jsonStatus = json_encode($status, JSON_PRETTY_PRINT);
			$this->config->setValueString(AppIdentity::APP_ID, 'archimate_import_status', $jsonStatus);
		}
	}//end setArchiMateImportStatus()

	/**
	 * Set ArchiMate export status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @param array $status The export status.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setArchiMateExportStatus(array $status): void {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$archiMateService->setArchiMateExportStatus($status);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to set ArchiMate export status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$jsonStatus = json_encode($status, JSON_PRETTY_PRINT);
			$this->config->setValueString(AppIdentity::APP_ID, 'archimate_export_status', $jsonStatus);
		}
	}//end setArchiMateExportStatus()

	/**
	 * Clear ArchiMate import status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return array Clear operation result
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearArchiMateImportStatus(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->clearArchiMateImportStatus();
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to clear ArchiMate import status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$this->config->deleteKey(AppIdentity::APP_ID, 'archimate_import_status');

			return [
				'cleared' => true,
				'process_killed' => false,
				'process_id' => null,
				'was_running' => false,
				'messages' => ['Import status cleared via fallback method'],
			];
		}//end try
	}//end clearArchiMateImportStatus()

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
	public function killArchiMateImport(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->clearArchiMateImportStatus(true);
			// KillProcess = true.
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to kill ArchiMate import process via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to just clearing config if ArchiMateService is not available.
			$this->config->deleteKey(AppIdentity::APP_ID, 'archimate_import_status');

			return [
				'cleared' => true,
				'process_killed' => false,
				'process_id' => null,
				'was_running' => false,
				'messages' => ['Import status cleared via fallback method - could not kill process'],
			];
		}//end try
	}//end killArchiMateImport()

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
	public function cancelArchiMateImport(?string $operationId = null): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->cancelArchiMateImport(operationId: $operationId);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to cancel ArchiMate import via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to just clearing config if ArchiMateService is not available.
			$this->config->deleteKey(AppIdentity::APP_ID, 'archimate_import_status');

			return [
				'cancelled' => true,
				'was_running' => false,
				'process_id' => null,
				'process_killed' => false,
				'status_cleared' => true,
				'cancellation_time' => date('Y-m-d H:i:s'),
				'messages' => ['Import status cleared via fallback method - ArchiMateService not available'],
			];
		}//end try
	}//end cancelArchiMateImport()

	/**
	 * Clear ArchiMate export status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearArchiMateExportStatus(): void {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$archiMateService->clearArchiMateExportStatus();
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to clear ArchiMate export status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$this->config->deleteKey(AppIdentity::APP_ID, 'archimate_export_status');
		}
	}//end clearArchiMateExportStatus()
}//end class
