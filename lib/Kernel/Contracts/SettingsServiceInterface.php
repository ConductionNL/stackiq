<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
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

use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;

/**
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface extends RegisterConfigReaderInterface {

	/**
	 * Auto-configure settings based on available registers and schemas
	 * This method now uses the consolidated auto-configuration logic
	 *
	 * @param bool $force Whether to force reload regardless of version.
	 *
	 * @return array The auto-configuration results.
	 *
	 * @throws \RuntimeException If auto-configuration fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is a simple re-import toggle
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function autoConfigure(bool $force = false): array;

	/**
	 * Clear the cached schema and register ids.
	 *
	 * Delegates to CatalogueLocator until every caller uses it directly.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearConfigurationCache(): void;

	/**
	 * Forces a complete configuration update regardless of version checks
	 *
	 * This method forces a complete reconfiguration by resetting all relevant
	 * flags and configurations, then performs import and auto-configuration.
	 *
	 * @return array The force update results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function forceUpdate(): array;

	/**
	 * Get all settings including user groups and email settings
	 * This aggregates all settings data for the main settings endpoint
	 *
	 * @return array Complete settings data
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllSettings(): array;

	/**
	 * Get AMEF configuration only
	 *
	 * @return array AMEF configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAmefConfigFocused(): array;

	/**
	 * Get ArchiMate configuration only
	 *
	 * @return array ArchiMate configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getArchiMateConfig(): array;

	/**
	 * Get ArchiMate import/export status and AMEF object counts
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return array The ArchiMate status with object counts
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getArchiMateStatus(): array;

	/**
	 * Get catalog location
	 *
	 * @return string The catalog location URL
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function getCatalogLocation(): string;

	/**
	 * Gets configuration status for each object type
	 *
	 * @return array Configuration status information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getConfigurationStatus(): array;

	/**
	 * Gets debug information for settings
	 *
	 * @return array Debug information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getDebugInfo(): array;

	/**
	 * Get email configuration only
	 *
	 * @return array Email configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailConfigFocused(): array;

	/**
	 * Get object counts statistics for all configured registers
	 *
	 * @return array Statistics for all registers with object counts
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectCountsStatistics(): array;

	/**
	 * Get object counts only (lightweight)
	 *
	 * @return array Object counts for all registers
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectsCounts(): array;

	/**
	 * Get object statistics (full statistics with configuration)
	 *
	 * @return array Full object statistics
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectsStatistics(): array;

	/**
	 * Get version information for the app and configuration.
	 *
	 * This method returns version information including the current app version
	 * and the stored configuration version in OpenRegister.
	 *
	 * @return array Version information with app and configuration versions.
	 * @throws \RuntimeException If version retrieval fails.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVersionInfo(): array;

	/**
	 * Get Voorzieningen configuration only
	 *
	 * @return array Voorzieningen configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenConfigFocused(): array;

	/**
	 * Initializes the app with all required components
	 *
	 * @param string|null $minOpenRegisterVersion Minimum required OpenRegister version
	 *
	 * @return array The initialization results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function initialize(?string $minOpenRegisterVersion = self::MIN_OPENREGISTER_VERSION): array;

	/**
	 * Checks if all required object types are configured
	 *
	 * @return bool True if all object types have schemas configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function isFullyConfigured(): bool;

	/**
	 * Load settings from register configuration files
	 *
	 * @param bool $force Whether to force the import regardless of version checks.
	 *
	 * @return array The loaded settings configuration
	 *
	 * @throws \RuntimeException If settings loading fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is a simple re-import toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function loadSettings(bool $force = false): array;

	/**
	 * Manually trigger configuration import from JSON.
	 *
	 * This method allows system administrators to manually trigger the import
	 * process, bypassing version checks.
	 *
	 * @param bool $forceImport Whether to force import regardless of version.
	 *
	 * @return array The import results with success/error information.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forceImport is a simple re-import toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function manualImport(bool $forceImport = false): array;

	/**
	 * Perform consolidated auto-configuration with clean separation of concerns
	 *
	 * This method orchestrates the complete auto-configuration process:
	 * 1. Configuration file loading
	 * 2. Voorzieningen register configuration
	 * 3. AMEF register configuration
	 * 4. User groups configuration
	 *
	 * @param bool $force Whether to force configuration loading.
	 *
	 * @return array Consolidated configuration results
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is a simple re-import toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function performConsolidatedAutoConfiguration(bool $force = false): array;

	/**
	 * Resets the auto-configuration to allow it to run again
	 *
	 * This method clears the auto-configuration completion flag and
	 * optionally resets schema/register configurations for testing.
	 *
	 * @param bool $resetConfiguration Whether to also clear schema/register settings
	 *
	 * @return array The reset results
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $resetConfiguration is a simple scope toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function resetAutoConfiguration(bool $resetConfiguration = false): array;

	/**
	 * Sends a test email
	 *
	 * @param string $email The email address to send to
	 * @param array $emailSettings The email settings to use
	 *
	 * @return array Result of the test email
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function sendTestEmail(string $email, array $emailSettings = []): array;

	/**
	 * Set catalog location
	 *
	 * @param string $location The catalog location URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function setCatalogLocation(string $location): void;

	/**
	 * High-performance sync of OpenRegister organisations to voorzieningen register
	 *
	 * Optimized for large-scale operations (1000+ organisations) using bulk operations.
	 * Uses OpenRegister's ultraFastBulkSave for maximum performance.
	 *
	 * @param array $options Configuration options:
	 *                       - batch_size: Number of organisations per batch (default: 500)
	 *                       - dry_run: Only check what would be created (default: false)
	 *
	 * @return array Sync results with performance metrics
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function syncOrganisationsToVoorzieningenOptimized(array $options = []): array;

	/**
	 * Test email connection without sending an actual email
	 *
	 * @param array $emailSettings The email settings to test
	 *
	 * @return array Result of the connection test
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function testEmailConnection(array $emailSettings = []): array;

	/**
	 * Update AMEF configuration
	 *
	 * @param array $config AMEF configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateAmefConfig(array $config): array;

	/**
	 * Update ArchiMate configuration
	 *
	 * @param array $config ArchiMate configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateArchiMateConfig(array $config): array;

	/**
	 * Update email configuration
	 *
	 * @param array $config Email configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailConfig(array $config): array;

	/**
	 * Update the settings configuration
	 *
	 * @param array $data The settings data to update
	 *
	 * @return array The updated settings configuration
	 *
	 * @throws \RuntimeException If settings update fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function updateSettings(array $data): array;

	/**
	 * Update Voorzieningen configuration
	 *
	 * @param array $config Voorzieningen configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateVoorzieningenConfig(array $config): array;
}//end interface
