<?php

/**
 * Cronjob Settings for Stackiq
 *
 * Moved out of SettingsService: the stored per-job cronjob configuration
 * (`cronjob_config`), the switch the organisation contact sync job reads,
 * and the deprecated per-job user/organisation context.
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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the cronjob configuration.
 *
 * Not final, like the other settings handlers in this directory, so the
 * services that depend on it can be tested against a mock.
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class CronjobSettings {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config     The app config holding `cronjob_config`.
	 * @param IAppManager        $appManager Tells whether OpenRegister is installed.
	 * @param ContainerInterface $container  Resolves OpenRegister's organisation mapper.
	 * @param LoggerInterface    $logger     The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get all cronjob configurations.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 *
	 * @return array The cronjob configurations indexed by job name
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getCronjobConfig(): array {
		try {
			$configJson = $this->config->getValueString(AppIdentity::APP_ID, 'cronjob_config', '{}');
			$config = json_decode($configJson, true);

			if (is_array($config) === false) {
				$config = [];
			}

			// Define available cronjobs with their metadata.
			$availableCronjobs = $this->getAvailableCronjobs();

			// Merge stored config with defaults for each cronjob.
			$result = [];
			foreach ($availableCronjobs as $jobId => $jobMeta) {
				$result[$jobId] = [
					'id' => $jobId,
					'name' => $jobMeta['name'],
					'description' => $jobMeta['description'],
					'interval' => $jobMeta['interval'],
					'userId' => $config[$jobId]['userId'] ?? null,
					'organisationUuid' => $config[$jobId]['organisationUuid'] ?? null,
					'enabled' => $config[$jobId]['enabled'] ?? true,
				];
			}

			return [
				'success' => true,
				'cronjobs' => $result,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get cronjob config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get cronjob config: ' . $e->getMessage(),
				'cronjobs' => [],
			];
		}//end try
	}//end getCronjobConfig()

	/**
	 * Whether an admin left a cronjob switched on in the cronjob settings.
	 *
	 * Reads the `enabled` flag `updateCronjobConfig()` stores under
	 * `cronjob_config`. A job that was never saved is on, as the settings
	 * screen shows it (`getCronjobConfig()` defaults `enabled` to true).
	 *
	 * @param string $jobId The cronjob identifier, e.g. `organization_contact_sync`.
	 *
	 * @return bool False only when the admin switched the job off.
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/tasks.md#task-3
	 */
	public function isCronjobEnabled(string $jobId): bool {
		$config = json_decode($this->config->getValueString(AppIdentity::APP_ID, 'cronjob_config', '{}'), true);
		if (is_array($config) === false || is_array($config[$jobId] ?? null) === false) {
			return true;
		}

		$enabled = ($config[$jobId]['enabled'] ?? true);

		return in_array($enabled, [false, 0, '0', 'false'], true) === false;
	}//end isCronjobEnabled()

	/**
	 * Get list of available cronjobs with their metadata.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *
	 * @return array List of cronjob definitions
	 */
	private function getAvailableCronjobs(): array {
		return [
			'organization_contact_sync' => [
				'name' => 'Organization Contact Sync',
				'description' => 'Syncs organizations and contacts between Stackiq and OpenRegister.',
				'interval' => 300,
				// 5 minutes.
				'class' => 'OCA\\Stackiq\\BackgroundJob\\OrganizationContactSyncJob',
			],
		];
	}//end getAvailableCronjobs()

	/**
	 * Update cronjob configuration.
	 *
	 * @param array $data The cronjob configuration data.
	 *
	 * @return array Result of the update operation.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function updateCronjobConfig(array $data): array {
		try {
			// Get existing config.
			$configJson = $this->config->getValueString(AppIdentity::APP_ID, 'cronjob_config', '{}');
			$config = json_decode($configJson, true);

			if (is_array($config) === false) {
				$config = [];
			}

			// Update configuration for the specified cronjob.
			$jobId = $data['jobId'] ?? null;
			if ($jobId === null) {
				return [
					'success' => false,
					'message' => 'Job ID is required',
				];
			}

			// Validate that the job exists.
			$availableCronjobs = $this->getAvailableCronjobs();
			if (isset($availableCronjobs[$jobId]) === false) {
				return [
					'success' => false,
					'message' => 'Unknown cronjob: ' . $jobId,
				];
			}

			// Update the config for this job.
			$config[$jobId] = [
				'userId' => $data['userId'] ?? null,
				'organisationUuid' => $data['organisationUuid'] ?? null,
				'enabled' => $data['enabled'] ?? true,
			];

			// Save the updated config.
			$this->config->setValueString(
				AppIdentity::APP_ID,
				'cronjob_config',
				json_encode($config, JSON_PRETTY_PRINT)
			);

			$this->logger->info(
				'Cronjob configuration updated',
				[
					'jobId' => $jobId,
					'userId' => $config[$jobId]['userId'],
					'organisationUuid' => $config[$jobId]['organisationUuid'],
				]
			);

			return [
				'success' => true,
				'message' => 'Cronjob configuration updated successfully',
				'config' => $config[$jobId],
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update cronjob config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update cronjob config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateCronjobConfig()

	/**
	 * Get cronjob context for a specific job.
	 *
	 * @param string $jobId The cronjob identifier.
	 *
	 * @return array|null The context configuration or null if not configured.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function getCronjobContext(string $jobId): ?array {
		try {
			$configJson = $this->config->getValueString(AppIdentity::APP_ID, 'cronjob_config', '{}');
			$config = json_decode($configJson, true);

			if (is_array($config) === false || isset($config[$jobId]) === false) {
				return null;
			}

			$jobConfig = $config[$jobId];

			// Only return if both user and organisation are configured.
			if (empty($jobConfig['userId']) === true || empty($jobConfig['organisationUuid']) === true) {
				return null;
			}

			return [
				'userId' => $jobConfig['userId'],
				'organisationUuid' => $jobConfig['organisationUuid'],
				'enabled' => $jobConfig['enabled'] ?? true,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get cronjob context',
				[
					'jobId' => $jobId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end getCronjobContext()

	/**
	 * Get available organisations for cronjob configuration.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 *
	 * @return array List of organisations with uuid and name
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAvailableOrganisationsForCronjobs(): array {
		try {
			if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
				return [
					'success' => false,
					'message' => 'OpenRegister is not installed',
					'organisations' => [],
				];
			}

			$organisationMapper = $this->container->get(\OCA\OpenRegister\Db\OrganisationMapper::class);

			// Get all organisations. The mapper applies no RBAC; an unbounded ListQuery
			// asks for every row (findAll() is paged and takes int limit/offset).
			$organisations = $organisationMapper->findList(query: \OCA\OpenRegister\Db\ListQuery::unbounded());

			$result = [];
			foreach ($organisations as $org) {
				$result[] = [
					'uuid' => $org->getUuid(),
					'name' => $org->getName(),
					'description' => $org->getDescription(),
				];
			}

			return [
				'success' => true,
				'organisations' => $result,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get available organisations for cronjobs',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get available organisations: ' . $e->getMessage(),
				'organisations' => [],
			];
		}//end try
	}//end getAvailableOrganisationsForCronjobs()
}//end class
