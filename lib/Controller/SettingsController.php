<?php

/**
 * OpenCatalogi Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenCatalogi application.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Stackiq\Controller;

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for handling settings-related operations in the OpenCatalogi.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-3
 */
class SettingsController extends Controller {

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface|null The OpenRegister object service.
	 */
	private $objectService;

	/**
	 * SettingsController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param IAppConfig $config The app configuration.
	 * @param ContainerInterface $container The container.
	 * @param IAppManager $appManager The app manager.
	 * @param IGroupManager $groupManager The group manager.
	 * @param IUserSession $userSession The user session.
	 * @param SettingsService $settingsService The settings service.
	 * @param OrganizationSyncService $orgSyncSvc The organization sync service.
	 * @param ArchiMateService $archiMateService The ArchiMate import/export service.
	 * @param ProgressTracker $progressTracker The progress tracking service.
	 * @param EolSyncService $eolSyncService The EOL feed sync orchestration service.
	 * @param LoggerInterface $logger The logger instance.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly SettingsService $settingsService,
		private readonly OrganizationSyncService $orgSyncSvc,
		private readonly ArchiMateService $archiMateService,
		private readonly ProgressTracker $progressTracker,
		private readonly EolSyncService $eolSyncService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return ObjectServiceInterface|null The OpenRegister service if available, null otherwise.
	 * @throws RuntimeException If the service is not available.
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getObjectService(): ?ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return $this->objectService;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the Configuration service from the container.
	 *
	 * @return ConfigurationService|null The Configuration service if available, null otherwise.
	 * @throws RuntimeException If the service is not available.
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getConfigurationService(): ?ConfigurationService {
		// Check if the 'openregister' app is installed.
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			// Retrieve the ConfigurationService from the container.
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			return $configurationService;
		}

		// Throw an exception if the service is not available.
		throw new RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()

	/**
	 * Return request params with known credential fields redacted.
	 *
	 * Prevents SMTP passwords, API keys and similar secrets from appearing
	 * in application logs when an error occurs during a settings-update request.
	 *
	 * @return array<string,mixed> Sanitised copy of the request parameters.
	 */
	private function getRedactedParams(): array {
		$sensitiveKeys = [
			'smtpPassword',
			'sendgridApiKey',
			'mailgunApiKey',
			'postmarkApiKey',
			'sesSecretKey',
			'mailjetSecretKey',
			'apiKey',
			'password',
			'secret',
		];

		$params = $this->request->getParams();
		foreach ($sensitiveKeys as $key) {
			if (isset($params[$key]) === true) {
				$params[$key] = '***';
			}
		}

		return $params;
	}//end getRedactedParams()

	/**
	 * Build the 500 response for a failed config READ.
	 *
	 * Centralises the error-log emission + the
	 * `{success: false, message: "Failed to <op>: <exception>"}` shape that
	 * the per-section get/update endpoints repeat. Extracted from
	 * `updateGeneralConfig()`, `getSyncConfig()`, `updateSyncConfig()`, and
	 * `getGeneralConfig()` as part of task 3.X.
	 *
	 * A read failure logs no request params — the read endpoints take none.
	 *
	 * @param string $operationLabel "get sync config" / "get general config" / …
	 * @param \Exception $exception The caught exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function buildConfigReadErrorResponse(string $operationLabel, \Exception $exception): JSONResponse {
		return $this->buildConfigErrorResponse(
			operationLabel: $operationLabel,
			exception: $exception,
			context: ['exception' => $exception->getMessage()]
		);
	}//end buildConfigReadErrorResponse()

	/**
	 * Build the 500 response for a failed config WRITE, attaching the redacted
	 * request params to the log payload so a rejected update can be diagnosed.
	 *
	 * @param string $operationLabel "update sync config" / "update general config" / …
	 * @param \Exception $exception The caught exception.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function buildConfigWriteErrorResponse(string $operationLabel, \Exception $exception): JSONResponse {
		return $this->buildConfigErrorResponse(
			operationLabel: $operationLabel,
			exception: $exception,
			context: [
				'exception' => $exception->getMessage(),
				'requestData' => $this->getRedactedParams(),
			]
		);
	}//end buildConfigWriteErrorResponse()

	/**
	 * Log the failure and build the shared 500 config-error response body.
	 *
	 * @param string $operationLabel "update sync config" / "get general config" / …
	 * @param \Exception $exception The caught exception.
	 * @param array<string, mixed> $context The log context payload.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function buildConfigErrorResponse(
		string $operationLabel,
		\Exception $exception,
		array $context,
	): JSONResponse {
		$this->logger->error('Failed to ' . $operationLabel, $context);

		return new JSONResponse(
			[
				'success' => false,
				'message' => 'Failed to ' . $operationLabel . ': ' . $exception->getMessage(),
			],
			500
		);
	}//end buildConfigErrorResponse()

	/**
	 * Retrieve the current settings.
	 *
	 * @return JSONResponse JSON response containing the current settings.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$user = $this->userSession->getUser();
			$isAdmin = $this->groupManager->isAdmin($user->getUID());

			// Delegate all business logic to service.
			$data = $this->settingsService->getAllSettings();
			$data['openRegisters'] = in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps());
			$data['isAdmin'] = $isAdmin;

			return new JSONResponse($data);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to retrieve settings',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}

	}//end index()

	/**
	 * Write the app-configuration settings block read back by index().
	 *
	 * This is the canonical AppHost write verb for `/api/settings`
	 * (`PUT /api/settings` → `settings#update`). It persists exactly the three
	 * sections `index()` surfaces through `SettingsService::getAllSettings()`:
	 *
	 * - `configuration` / `selectedRegister` → `SettingsService::updateSettings`
	 * - `userGroups.{generic,organizationAdmin,superUser}` → validated via
	 *   `validateGroups`, then persisted through the matching setter
	 * - `emailSettings` → `SettingsService::updateEmailSettings`
	 *
	 * It deliberately does NOT absorb the controller's other configuration
	 * surfaces (general/sync/archimate/email/amef/voorzieningen/user-groups/
	 * cronjob/eol-sync config, email templates, ArchiMate import-export,
	 * progress and statistics). Those are separate endpoints on their own URLs
	 * and keep their own handlers — this method is not a catch-all.
	 *
	 * Admin-only: no NoAdminRequired tag is declared, so Nextcloud's security
	 * middleware requires an administrator — the same posture as `create()`.
	 * Net privilege change is zero.
	 *
	 * @return JSONResponse JSON response containing the updated settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md#requirement-settingscontroller-settings-crud-endpoints-req-decomp-013
	 */
	public function update(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = [];

			$this->updateConfigSettings(data: $data, result: $result);

			$groupError = $this->updateUserGroupSettings(data: $data, result: $result);
			if ($groupError !== null) {
				return $groupError;
			}

			$this->applyEmailSettingsUpdate(data: $data, result: $result);

			return new JSONResponse(['success' => true, 'data' => $result, 'message' => 'Settings updated successfully']);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update settings',
				['exception' => $e->getMessage(), 'requestData' => $this->getRedactedParams()]
			);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try

	}//end update()

	/**
	 * Handle the post request to update settings.
	 *
	 * Legacy alias for `update()`, kept so `POST /api/settings` keeps behaving
	 * exactly as before. The auth attributes are repeated here on purpose:
	 * Nextcloud's middleware only evaluates the attributes of the method the
	 * router actually dispatched, so delegation does not inherit them.
	 *
	 * @return JSONResponse JSON response containing the updated settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md#requirement-settingscontroller-settings-crud-endpoints-req-decomp-013
	 */
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Update schema/register configuration settings from request data.
	 *
	 * @param array<string,mixed> $data The raw request params.
	 * @param array<string,mixed> $result The result accumulator (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function updateConfigSettings(array $data, array &$result): void {
		if (isset($data['configuration']) === false && isset($data['selectedRegister']) === false) {
			return;
		}

		$configData = array_filter(
			$data,
			static fn ($key) => in_array(needle: $key, haystack: ['userGroups', 'emailSettings']) === false,
			ARRAY_FILTER_USE_KEY
		);

		if (empty($configData) === false) {
			$result['configuration'] = $this->settingsService->updateSettings($configData);
		}

	}//end updateConfigSettings()

	/**
	 * Update user group settings from request data.
	 *
	 * @param array<string,mixed> $data The raw request params.
	 * @param array<string,mixed> $result The result accumulator (passed by reference).
	 *
	 * @return JSONResponse|null Validation error response, or null when successful.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function updateUserGroupSettings(array $data, array &$result): ?JSONResponse {
		if (isset($data['userGroups']) === false) {
			return null;
		}

		$userGroups = $data['userGroups'];

		$groupConfigs = [
			'generic' => ['setter' => 'setGenericUserGroups', 'label' => 'generic'],
			'organizationAdmin' => ['setter' => 'setOrganizationAdminGroups', 'label' => 'organization admin'],
			'superUser' => ['setter' => 'setSuperUserGroups', 'label' => 'super user'],
		];

		foreach ($groupConfigs as $key => $cfg) {
			if (isset($userGroups[$key]) === false) {
				continue;
			}

			$validation = $this->settingsService->validateGroups($userGroups[$key]);
			if (empty($validation['invalid']) === false) {
				return new JSONResponse(
					['error' => 'Invalid ' . $cfg['label'] . ' group names provided', 'validation' => $validation],
					400
				);
			}

			$this->settingsService->{$cfg['setter']}($validation['valid']);
			$result['userGroups'][$key] = $validation['valid'];
		}

		return null;
	}//end updateUserGroupSettings()

	/**
	 * Apply email-settings update from request data into the result accumulator.
	 *
	 * @param array<string,mixed> $data The raw request params.
	 * @param array<string,mixed> $result The result accumulator (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function applyEmailSettingsUpdate(array $data, array &$result): void {
		if (isset($data['emailSettings']) === false) {
			return;
		}

		$result['emailSettings'] = $this->settingsService->updateEmailSettings($data['emailSettings']);

	}//end applyEmailSettingsUpdate()

	/**
	 * Get general configuration settings
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse General configuration
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getGeneralConfig(): JSONResponse {
		try {
			$config = [
				'catalogLocation' => $this->settingsService->getCatalogLocation(),
			];

			return new JSONResponse(
				[
					'success' => true,
					'config' => $config,
				]
			);
		} catch (\Exception $e) {
			return $this->buildConfigReadErrorResponse(operationLabel: 'get general config', exception: $e);
		}//end try
	}//end getGeneralConfig()

	/**
	 * Update general configuration settings
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function updateGeneralConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();

			if (isset($data['catalogLocation']) === true) {
				$this->settingsService->setCatalogLocation($data['catalogLocation']);
			}

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'General configuration updated successfully',
					'config' => [
						'catalogLocation' => $this->settingsService->getCatalogLocation(),
					],
				]
			);
		} catch (\Exception $e) {
			return $this->buildConfigWriteErrorResponse(operationLabel: 'update general config', exception: $e);
		}//end try
	}//end updateGeneralConfig()

	/**
	 * Get organization synchronization configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Sync configuration
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getSyncConfig(): JSONResponse {
		try {
			$config = [
				'syncTimeWindow' => $this->config->getValueString($this->appName, 'syncTimeWindow', '10'),
			];

			return new JSONResponse(
				[
					'success' => true,
					'config' => $config,
				]
			);
		} catch (\Exception $e) {
			return $this->buildConfigReadErrorResponse(operationLabel: 'get sync config', exception: $e);
		}//end try
	}//end getSyncConfig()

	/**
	 * Update organization synchronization configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function updateSyncConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();

			if (isset($data['syncTimeWindow']) === true) {
				$this->config->setValueString($this->appName, 'syncTimeWindow', (string)$data['syncTimeWindow']);
			}

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'Sync configuration updated successfully',
					'config' => [
						'syncTimeWindow' => $this->config->getValueString($this->appName, 'syncTimeWindow', '10'),
					],
				]
			);
		} catch (\Exception $e) {
			return $this->buildConfigWriteErrorResponse(operationLabel: 'update sync config', exception: $e);
		}//end try
	}//end updateSyncConfig()

	/**
	 * Load the settings from the publication_register.json file.
	 *
	 * @return JSONResponse JSON response containing the settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function load(): JSONResponse {
		try {
			$result = $this->settingsService->loadSettings();
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}

	}//end load()

	/**
	 * Initialize the Stackiq settings
	 *
	 * @return JSONResponse JSON response containing the initialization results
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function initialize(): JSONResponse {
		try {
			$result = $this->settingsService->initialize();
			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to initialize settings',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end initialize()

	/**
	 * Get configuration status
	 *
	 * @return JSONResponse JSON response containing the configuration status
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function status(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->logger->debug('SettingsController: Getting configuration status');

			$status = $this->settingsService->getConfigurationStatus();
			$isFullyConfigured = $this->settingsService->isFullyConfigured();
			$versionInfo = $this->settingsService->getVersionInfo();

			$responseData = [
				'status' => $status,
				'fullyConfigured' => $isFullyConfigured,
				'versionInfo' => $versionInfo,
				'timestamp' => time(),
				'autoConfigCompleted' => $this->config->getValueString(
					'stackiq',
					'auto_config_completed',
					'false'
				) === 'true',
			];

			$this->logger->info(
				'SettingsController: Configuration status compiled',
				[
					'fullyConfigured' => $isFullyConfigured,
					'needsUpdate' => $versionInfo['needsUpdate'] ?? null,
					'versionsMatch' => $versionInfo['versionsMatch'] ?? null,
				]
			);

			return new JSONResponse($responseData);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Failed to get configuration status',
				[
					'exception_message' => $e->getMessage(),
					'exception' => $e,
				]
			);
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'timestamp' => time(),
				],
				500
			);
		}//end try
	}//end status()

	/**
	 * Auto-configure settings
	 *
	 * @return JSONResponse JSON response containing the auto-configuration results
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function autoConfigure(): JSONResponse {
		try {
			$configuration = $this->settingsService->autoConfigure();
			if (empty($configuration) === false) {
				$result = $this->settingsService->updateSettings($configuration);
				return new JSONResponse(
					[
						'success' => true,
						'configuration' => $result,
					]
				);
			}

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'No matching registers or schemas found for auto-configuration',
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to auto-configure settings',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end autoConfigure()

	/**
	 * Get object counts statistics for all configured registers
	 *
	 * @return JSONResponse JSON response containing object counts statistics
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function stats(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$statistics = $this->settingsService->getObjectCountsStatistics();
			return new JSONResponse(
				[
					'success' => true,
					'statistics' => $statistics,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get object counts statistics',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end stats()

	/**
	 * Get debug information for settings (admin-only)
	 *
	 * @return JSONResponse JSON response containing debug information
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function debug(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($this->userSession->getUser()->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$debugInfo = $this->settingsService->getDebugInfo();
			return new JSONResponse($debugInfo);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get debug information',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end debug()

	/**
	 * Send a test email
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function sendTestEmail(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$email = $data['email'] ?? '';
			$emailSettings = $data['emailSettings'] ?? [];

			// Delegate all business logic (including validation) to service.
			$result = $this->settingsService->sendTestEmail(
				email: $email,
				emailSettings: $emailSettings
			);

			return new JSONResponse(
				[
					'success' => $result['success'],
					'message' => $result['message'],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: Failed to send test email in controller',
				[
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to send test email: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end sendTestEmail()

	/**
	 * Get organization synchronization status with processing predictions
	 *
	 * @param int $minutesBack Number of minutes to look back for prediction (default: 10)
	 *
	 * @return JSONResponse JSON response containing sync status information
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Takes no object reference. The only parameter is a
	 *      typed `int $minutesBack` time window, and
	 *      OrganizationSyncService::getSyncStatusWithErrorHandling() accepts no
	 *      object id either — it reports aggregate sync timing, not a record.
	 *      There is nothing for a caller to substitute.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getSyncStatus(int $minutesBack = 10): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$status = $this->orgSyncSvc->getSyncStatusWithErrorHandling($minutesBack);
		return new JSONResponse($status);
	}//end getSyncStatus()

	/**
	 * Perform manual organization synchronization
	 *
	 * @param int $minutesBack Number of minutes to look back for changes (default: 0 for full sync)
	 *
	 * @return JSONResponse JSON response containing sync results
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function performSync(int $minutesBack = 0): JSONResponse {
		try {
			// For full sync (minutesBack = 0), use optimized batch processing to handle large datasets.
			if ($minutesBack === 0) {
				$result = $this->orgSyncSvc->performOptimizedManualSync(
					maxRounds: 15,
					// Up to 15 rounds of processing.
					batchSize: 75
					// 75 items per batch for good performance.
				);

				return new JSONResponse(
					[
						'success' => true,
						'results' => $result,
						'message' => 'Optimized synchronization completed successfully',
						'isOptimized' => true,
					]
				);
			}//end if

			// For incremental sync, use the original method.
			$result = $this->orgSyncSvc->performManualSync($minutesBack);

			$statusCode = 500;
			if ($result['success'] === true) {
				$statusCode = 200;
			}

			return new JSONResponse($result, $statusCode);
		} catch (\Exception $e) {
			$this->logger->error(
				'Manual sync failed',
				[
					'minutesBack' => $minutesBack,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Synchronization failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end performSync()

	/**
	 * Heartbeat endpoint to keep connections alive during long-running operations
	 *
	 * This endpoint prevents 504 gateway timeouts by responding to periodic
	 * keep-alive requests sent during lengthy operations like sync or export.
	 *
	 * @return JSONResponse JSON response confirming heartbeat received
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt An availability probe that touches no storage. The
	 *      body reads one `timestamp` request param, logs it at debug level and
	 *      echoes it back; there is no mapper, service or object lookup on the
	 *      path at all, so there is no direct object reference to substitute.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function heartbeat(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$timestamp = $this->request->getParam('timestamp', time() * 1000);

			$this->logger->debug(
				'Heartbeat received',
				[
					'timestamp' => $timestamp,
					'server_time' => time() * 1000,
				]
			);

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'Heartbeat received',
					'timestamp' => $timestamp,
					'server_time' => time() * 1000,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Heartbeat error: ' . $e->getMessage(),
				[
					'exception' => $e,
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Heartbeat failed',
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end heartbeat()

	/**
	 * Get version information for the app and configuration.
	 *
	 * @return JSONResponse JSON response containing version information.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getVersionInfo(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->logger->info('SettingsController: Getting version information');
			$data = $this->settingsService->getVersionInfo();

			$this->logger->info(
				'SettingsController: Version info retrieved',
				[
					'version_info' => $data,
				]
			);

			// Add timestamp for cache busting.
			$data['timestamp'] = time();

			return new JSONResponse($data);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Failed to get version info',
				[
					'exception_message' => $e->getMessage(),
					'exception' => $e,
				]
			);
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'timestamp' => time(),
				],
				500
			);
		}//end try
	}//end getVersionInfo()

	/**
	 * Reset auto-configuration to allow it to run again.
	 *
	 * @return JSONResponse JSON response containing reset results.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function resetAutoConfig(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$resetConfiguration = isset($params['resetConfiguration']) === true && $params['resetConfiguration'] === true;

			$result = $this->settingsService->resetAutoConfiguration($resetConfiguration);
			$statusCode = 400;
			if ($result['success'] === true) {
				$statusCode = 200;
			}

			return new JSONResponse($result, $statusCode);
		} catch (\Exception $e) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Reset failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end resetAutoConfig()

	/**
	 * Clear configuration cache to force reload of schema IDs and register IDs.
	 *
	 * @return JSONResponse JSON response containing cache clear results.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function clearCache(): JSONResponse {
		try {
			$this->logger->info('SettingsController: Clearing configuration cache');

			$this->settingsService->clearConfigurationCache();

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'Configuration cache cleared successfully',
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Cache clear failed',
				[
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Cache clear failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end clearCache()

	/**
	 * Manually trigger configuration import.
	 *
	 * @return JSONResponse JSON response containing import results.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function manualImport(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$forceImport = isset($params['force']) === true && $params['force'] === true;

			$this->logger->info(
				'SettingsController: Starting manual import',
				[
					'force' => $forceImport,
				]
			);

			$result = $this->settingsService->manualImport($forceImport);

			$this->logger->info(
				'SettingsController: Manual import completed',
				[
					'success' => $result['success'],
					'message' => $result['message'] ?? 'No message',
				]
			);

			// Add timestamp for cache busting.
			$result['timestamp'] = time();

			$importStatusCode = 400;
			if ($result['success'] === true) {
				$importStatusCode = 200;
			}

			return new JSONResponse($result, $importStatusCode);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Manual import failed',
				[
					'exception_message' => $e->getMessage(),
					'exception' => $e,
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Import failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
					'timestamp' => time(),
				],
				500
			);
		}//end try
	}//end manualImport()

	/**
	 * Force a complete configuration update regardless of version checks.
	 *
	 * @return JSONResponse JSON response containing force update results.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function forceUpdate(): JSONResponse {
		try {
			$this->logger->info('SettingsController: Starting force update');

			$result = $this->settingsService->forceUpdate();

			$this->logger->info(
				'SettingsController: Force update completed',
				[
					'success' => $result['success'],
					'message' => $result['message'] ?? 'No message',
				]
			);

			// Add timestamp for cache busting.
			$result['timestamp'] = time();

			// Ensure result is JSON serializable by removing any potential circular references.
			$jsonResult = json_decode(json_encode($result), true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->error(
					'SettingsController: JSON serialization error',
					[
						'json_error' => json_last_error_msg(),
						'result_keys' => array_keys($result),
					]
				);
				// Return a simplified response if serialization fails.
				return new JSONResponse(
					[
						'success' => $result['success'] ?? false,
						'message' => $result['message'] ?? 'Force update completed but response serialization failed',
						'timestamp' => time(),
					],
					200
				);
			}

			// Always return 200 since the operation completed, even if configuration needs attention.
			return new JSONResponse($jsonResult, 200);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SettingsController: Force update failed',
				[
					'exception_message' => $e->getMessage(),
					'exception_class' => get_class($e),
					'exception_trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Force update failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
					'timestamp' => time(),
				],
				500
			);
		}//end try
	}//end forceUpdate()

	/**
	 * Consolidated auto-configuration that handles everything
	 *
	 * This method delegates all business logic to the SettingsService
	 * and simply handles the HTTP request/response.
	 *
	 * @return JSONResponse JSON response containing consolidated results
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function consolidatedAutoConfigure(): JSONResponse {
		try {
			// Get force parameter from request.
			$force = $this->request->getParam('force', false);

			// Delegate all business logic to the service.
			$results = $this->settingsService->performConsolidatedAutoConfiguration($force);

			// Determine HTTP status based on results.
			$httpStatus = 200;
			if ($results['success'] === false) {
				// Multi-status or Server Error.
				$httpStatus = 500;
				if (empty($results['errors']) === false) {
					$httpStatus = Http::STATUS_MULTI_STATUS;
				}
			}

			return new JSONResponse($results, $httpStatus);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Consolidated auto-configuration failed',
				[
					'exception_message' => $e->getMessage(),
					'exception' => $e,
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Auto-configuration failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
					'timestamp' => time(),
				],
				500
			);
		}//end try
	}//end consolidatedAutoConfigure()

	/**
	 * Get current progress for an operation.
	 *
	 * @param string $operationId The operation ID to get progress for.
	 *
	 * @return JSONResponse The current progress data.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getProgress(string $operationId): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$progress = $this->progressTracker->getProgress($operationId);

			if ($progress === null) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Operation not found',
						'error' => 'OPERATION_NOT_FOUND',
					],
					404
				);
			}

			// Verify the caller owns this operation.
			if (isset($progress['owner_uid']) === true && $progress['owner_uid'] !== $currentUser->getUID()) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Operation not found',
						'error' => 'OPERATION_NOT_FOUND',
					],
					404
				);
			}

			return new JSONResponse(
				[
					'success' => true,
					'progress' => $progress,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get progress',
				[
					'operation_id' => $operationId,
					'error' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get progress: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getProgress()

	/**
	 * Stream progress updates using Server-Sent Events.
	 *
	 * @param string $operationId The operation ID to stream progress for.
	 *
	 * @return Response SSE stream response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) framework-required: SSE loop in anonymous Response subclass
	 * @spec                                          openspec/specs/method-decomposition/spec.md
	 */
	public function streamProgress(string $operationId): Response {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Verify the caller owns this operation before streaming.
		$progress = $this->progressTracker->getProgress($operationId);
		if ($progress !== null && isset($progress['owner_uid']) === true && $progress['owner_uid'] !== $currentUser->getUID()) {
			return new JSONResponse(['message' => 'Operation not found', 'error' => 'OPERATION_NOT_FOUND'], 404);
		}

		// Set headers for Server-Sent Events.
		$response = new class($operationId, $this->progressTracker, $this->logger) extends Response {
			/**
			 * Constructor for the SSE response.
			 *
			 * @param string $operationId The operation ID to stream.
			 * @param ProgressTracker $progressTracker The progress tracker service.
			 * @param LoggerInterface $logger The logger instance.
			 */
			public function __construct(
				private string $operationId,
				private ProgressTracker $progressTracker,
				private LoggerInterface $logger,
			) {
				parent::__construct();
				$this->addHeader(name: 'Content-Type', value: 'text/event-stream');
				$this->addHeader(name: 'Cache-Control', value: 'no-cache');
				$this->addHeader(name: 'Connection', value: 'keep-alive');
				$this->addHeader(name: 'Access-Control-Allow-Headers', value: 'Cache-Control');
			}//end __construct()

			/**
			 * Render the SSE stream.
			 *
			 * @return string Empty string (output is streamed directly).
			 *
			 * @spec openspec/specs/settings-admin-controller/spec.md
			 */
			public function render(): string {
				// Enable output buffering and turn off compression.
				if (ob_get_level() !== 0) {
					ob_end_clean();
				}

				ob_implicit_flush(true);

				// Stream progress updates.
				$lastProgress = null;
				$maxAttempts = 300;
				// 5 minutes with 1-second intervals.
				$attempts = 0;

				while ($attempts < $maxAttempts) {
					try {
						$progress = $this->progressTracker->getProgress($this->operationId);

						if ($progress === null) {
							// Operation not found, send error and close.
							echo "event: error\n";
							echo 'data: ' . json_encode(['error' => 'Operation not found']) . "\n\n";
							break;
						}

						// Only send update if progress changed.
						if ($progress !== $lastProgress) {
							echo "event: progress\n";
							echo 'data: ' . json_encode($progress) . "\n\n";
							$lastProgress = $progress;

							// If operation completed, send final event and close.
							if ($progress['phase'] === 'completed') {
								echo "event: completed\n";
								echo 'data: ' . json_encode($progress) . "\n\n";
								break;
							}
						}

						// Send heartbeat every 10 seconds.
						if ($attempts % 10 === 0) {
							echo "event: heartbeat\n";
							echo 'data: ' . json_encode(['timestamp' => time()]) . "\n\n";
						}

						flush();
						sleep(1);
						$attempts++;
					} catch (\Exception $e) {
						$this->logger->error(
							'Progress streaming error',
							[
								'operation_id' => $this->operationId,
								'error' => $e->getMessage(),
							]
						);

						echo "event: error\n";
						echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
						break;
					}//end try
				}//end while

				// Send final close event.
				echo "event: close\n";
				echo 'data: ' . json_encode(['reason' => 'Stream ended']) . "\n\n";
				flush();

				return '';
			}//end render()
		};

		return $response;
	}//end streamProgress()

	/**
	 * Import ArchiMate file.
	 *
	 * Only accepts multipart file uploads — the `file_path` JSON body parameter is
	 * rejected to prevent local filesystem read (path traversal / SSRF).
	 * The `ini_set('memory_limit')` call has been removed; configure PHP memory via
	 * php.ini / pool config instead.
	 *
	 * @return JSONResponse Result of the import operation with progress tracking.
	 *
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @spec                                 openspec/specs/settings-admin-controller/spec.md
	 */
	public function importArchiMate(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$rawInput = file_get_contents('php://input');
			$data = json_decode($rawInput, true);

			if ($data !== null && isset($data['file_path']) === true) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'The file_path parameter is not accepted. Please upload a file via multipart/form-data.',
						'error' => 'FILE_PATH_NOT_ALLOWED',
					],
					400
				);
			}

			$options = $this->parseArchiMateFileUpload();
			if ($options === null) {
				$contentType = $this->request->getHeader('Content-Type');
				$isMultipart = strpos(haystack: $contentType, needle: 'multipart/form-data') !== false;
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'No ArchiMate file uploaded or file path provided',
						'error' => 'NO_FILE_UPLOADED_OR_PATH',
						'debug' => [
							'contentType' => $contentType,
							'isMultipart' => $isMultipart,
							'filesKeys' => array_keys($_FILES ?? []),
						],
					],
					400
				);
			}

			$result = $this->resolveArchiMateMethod(options: $options);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error('ArchiMate import failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			$statusCode = $this->getHttpStatusForException(e: $e);
			return new JSONResponse(['success' => false, 'message' => 'Import failed: ' . $e->getMessage(), 'error' => $e->getMessage()], $statusCode);
		}//end try
	}//end importArchiMate()

	/**
	 * Parse the uploaded ArchiMate file from the multipart request.
	 *
	 * Inspects both the NC request wrapper and $_FILES superglobal as a fallback.
	 * Returns null when no file was uploaded.
	 *
	 * @return array<string,mixed>|null Import options array, or null when no file present.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @spec                                 openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function parseArchiMateFileUpload(): ?array {
		$uploadedFiles = $this->request->getUploadedFile('archiMateFile');
		$filesArray = $_FILES['archiMateFile'] ?? null;

		if (empty($uploadedFiles) === true && empty($filesArray) === true) {
			return null;
		}

		$fileData = $filesArray;
		if ($uploadedFiles !== null) {
			$fileData = $uploadedFiles;
		}

		return [
			'updateExisting' => $this->request->getParam('updateExisting', 'true') === 'true',
			'deleteOrphaned' => $this->request->getParam('deleteOrphaned', 'false') === 'true',
			'preserveIds' => $this->request->getParam('preserveIds', 'true') === 'true',
			'processingMode' => $this->request->getParam('processingMode', 'speed'),
			'filePath' => $fileData['tmp_name'],
			'fileName' => $fileData['name'],
			'fileSize' => $fileData['size'] ?? filesize($fileData['tmp_name']),
			'mimeType' => $fileData['type'] ?? 'text/xml',
		];

	}//end parseArchiMateFileUpload()

	/**
	 * Call the appropriate ArchiMate import service method (optimised when available).
	 *
	 * @param array<string,mixed> $options Import options.
	 *
	 * @return array<string,mixed> Import result.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function resolveArchiMateMethod(array $options): array {
		// No method_exists() probe: ArchiMateService declares
		// importArchiMateFileFromPathOptimized(), so only the request parameter
		// decides which path runs.
		$useOptimized = $this->request->getParam('useOptimized', 'true') === 'true';

		if ($useOptimized === true) {
			$this->logger->info('Using OPTIMIZED ArchiMate import method.');
			return $this->archiMateService->importArchiMateFileFromPathOptimized($options);
		}

		$this->logger->info('Using STANDARD ArchiMate import method.');
		return $this->archiMateService->importArchiMateFileFromPath($options);
	}//end resolveArchiMateMethod()

	/**
	 * Export to ArchiMate format - returns file directly for download.
	 *
	 * @return Response File download response or JSON error response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/settings-admin-controller/spec.md
	 */
	public function exportArchiMate(): Response {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Same guard as the sibling exportOrgArchiMate(), which has carried it
		// all along. This endpoint exports the WHOLE register while the sibling
		// exports one organisation, so it was the broader of the two and the
		// only one unguarded. @NoAdminRequired is kept deliberately: the helper
		// grants organisation-admins as well as admins, which is the tier the
		// admin UI relies on and which the annotation's removal would drop.
		$permissionError = $this->verifyOrgExportPermission(currentUser: $currentUser);
		if ($permissionError !== null) {
			return $permissionError;
		}

		try {
			$rawInput = file_get_contents('php://input');
			$data = json_decode($rawInput, true);

			$organization = $this->request->getParam('organization', null);
			if (json_last_error() === JSON_ERROR_NONE) {
				$organization = $data['organization'] ?? null;
			}

			$result = $this->archiMateService->exportToArchiMate($organization);

			if ($result['success'] === false) {
				$statusCode = $this->getHttpStatusForErrorMessage(message: $result['error'] ?? 'Export failed');
				return new JSONResponse(
					['success' => false, 'message' => $result['error'] ?? 'Export failed', 'error' => $result['error'] ?? 'EXPORT_FAILED'],
					$statusCode
				);
			}

			$fileName = $result['file_name'] ?? 'archimate_export_' . date('Y-m-d_H-i-s') . '.xml';
			$xmlContent = $result['xml'] ?? '<?xml version="1.0" encoding="UTF-8"?><model></model>';

			$this->logger->info(
				'ArchiMate export completed',
				[
					'fileName' => $fileName,
					'size' => strlen($xmlContent),
					'objects_exported' => $result['statistics']['objects_exported'] ?? 0,
				]
			);

			return $this->buildXmlDownloadResponse(xmlContent: $xmlContent, fileName: $fileName);
		} catch (\Exception $e) {
			$this->logger->error('ArchiMate export failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			$statusCode = $this->getHttpStatusForException(e: $e);
			return new JSONResponse(
				['success' => false, 'message' => 'Export failed: ' . $e->getMessage(), 'error' => $e->getMessage()],
				$statusCode
			);
		}//end try
	}//end exportArchiMate()

	/**
	 * Export organization-specific ArchiMate file with enriched views.
	 *
	 * @param string $organizationUuid The organization UUID to export for.
	 *
	 * @return Response File download response or JSON error response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function exportOrgArchiMate(string $organizationUuid): Response {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$permissionError = $this->verifyOrgExportPermission(currentUser: $currentUser);
		if ($permissionError !== null) {
			return $permissionError;
		}

		try {
			$options = [
				'modules' => $this->request->getParam('modules', 'true') === 'true',
				'deelnames' => $this->request->getParam('deelnames', 'false') === 'true',
				'usage' => $this->request->getParam('usage', 'false') === 'true',
			];

			$result = $this->archiMateService->exportOrgArchiMate(organizationUuid: $organizationUuid, options: $options);

			if ($result['success'] === false) {
				$statusCode = 500;
				if (str_contains(haystack: ($result['error'] ?? ''), needle: 'not found') === true) {
					$statusCode = 404;
				}

				return new JSONResponse(
					['success' => false, 'message' => $result['error'] ?? 'Export failed', 'error' => $result['error'] ?? 'EXPORT_FAILED'],
					$statusCode
				);
			}

			$fileName = $result['file_name'] ?? 'archimate_org_export_' . date('Y-m-d_H-i-s') . '.xml';
			$xmlContent = $result['xml'] ?? '<?xml version="1.0" encoding="UTF-8"?><model></model>';

			return $this->buildXmlDownloadResponse(xmlContent: $xmlContent, fileName: $fileName);
		} catch (\Exception $e) {
			$this->logger->error('Organization ArchiMate export failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
			$statusCode = $this->getHttpStatusForException(e: $e);
			return new JSONResponse(['success' => false, 'message' => 'Export failed: ' . $e->getMessage(), 'error' => $e->getMessage()], $statusCode);
		}//end try
	}//end exportOrgArchiMate()

	/**
	 * Verify that the current user has permission to export organisation ArchiMate files.
	 *
	 * @param \OCP\IUser $currentUser The currently authenticated user.
	 *
	 * @return JSONResponse|null Forbidden response, or null when permitted.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function verifyOrgExportPermission(\OCP\IUser $currentUser): ?JSONResponse {
		if ($this->groupManager->isAdmin($currentUser->getUID()) === true) {
			return null;
		}

		$orgAdminGroups = $this->settingsService->getOrganizationAdminGroups();
		foreach ($orgAdminGroups as $groupName) {
			if ($this->groupManager->isInGroup($currentUser->getUID(), $groupName) === true) {
				return null;
			}
		}

		return new JSONResponse(['message' => 'Admin or organisation-admin privileges required'], Http::STATUS_FORBIDDEN);
	}//end verifyOrgExportPermission()

	/**
	 * Build an XML file download Response from content and filename.
	 *
	 * @param string $xmlContent The XML string to serve.
	 * @param string $fileName The attachment filename for the Content-Disposition header.
	 *
	 * @return Response The download response.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-3
	 */
	private function buildXmlDownloadResponse(string $xmlContent, string $fileName): Response {
		$response = new class($xmlContent) extends Response {
			/**
			 * XML content download response constructor.
			 *
			 * @param string $content The XML content to return.
			 */
			public function __construct(
				private string $content,
			) {
				parent::__construct();
			}//end __construct()

			/**
			 * Render the XML content for download.
			 *
			 * @return string
			 *
			 * @spec exclude framework passthrough — inline DataDownloadResponse subclass returning prebuilt content unchanged
			 */
			public function render(): string {
				return $this->content;
			}//end render()
		};

		$response->setStatus(200);
		$response->addHeader('Content-Type', 'application/xml');
		$response->addHeader(
			'Content-Disposition',
			'attachment; filename="' . addslashes($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
		);
		$response->addHeader('Content-Length', (string)strlen($xmlContent));
		$response->addHeader('Cache-Control', 'no-cache');

		return $response;
	}//end buildXmlDownloadResponse()

	/**
	 * Download ArchiMate file.
	 *
	 * @param string $fileName The filename to download.
	 *
	 * @return Response File download response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt The scope is in the RECEIVER, not in an argument.
	 *      `$fileName` is resolved against
	 *      `IRootFolder::getUserFolder($userSession->getUser()->getUID())`, so
	 *      it can only ever name a file in the caller's own home; and `..` and
	 *      `/` are rejected outright before that, so it cannot escape it. A
	 *      substituted filename reaches a different file only if the caller
	 *      already owns that file.
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function downloadArchiMate(string $fileName): Response {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Security: validate filename to prevent path traversal.
			if (strpos(haystack: $fileName, needle: '..') !== false
				|| strpos(haystack: $fileName, needle: '/') !== false
			) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Invalid filename',
						'error' => 'INVALID_FILENAME',
					],
					400
				);
			}

			// Get user folder.
			$userSession = $this->container->get(\OCP\IUserSession::class);
			$rootFolder = $this->container->get(\OCP\Files\IRootFolder::class);
			$userFolder = $rootFolder->getUserFolder($userSession->getUser()->getUID());

			// Check if file exists.
			if ($userFolder->nodeExists($fileName) === false) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'File not found',
						'error' => 'FILE_NOT_FOUND',
					],
					404
				);
			}

			$file = $userFolder->get($fileName);

			if (($file instanceof \OCP\Files\File) === false) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Invalid file type',
						'error' => 'INVALID_FILE_TYPE',
					],
					400
				);
			}

			// Determine content type based on file extension.
			$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
			$contentType = match ($extension) {
				'xml', 'archimate' => 'application/xml',
				'json' => 'application/json',
				default => 'application/octet-stream'
			};

			// Create download response.
			$response = new StreamResponse($file->fopen('r'));
			$response->addHeader('Content-Type', $contentType);
			$response->addHeader(
				'Content-Disposition',
				'attachment; filename="' . addslashes($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
			);
			$response->addHeader('Content-Length', (string)$file->getSize());

			return $response;
		} catch (\Exception $e) {
			$this->logger->error(
				'ArchiMate download failed',
				[
					'fileName' => $fileName,
					'error' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Download failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end downloadArchiMate()

	// ===.
	// EMAIL MANAGEMENT METHODS.
	// ===.

	/**
	 * Test email connection (separate from sending test email)
	 *
	 * Admin-only: the caller-supplied smtpHost/smtpPort reach a DSN in
	 * SettingsService::testEmailConnection(), and the server then opens an
	 * outbound TCP connection to whatever host and port were named. For a
	 * non-admin that is an SSRF and internal port-scan primitive, so the
	 * endpoint must not declare @NoAdminRequired. Its sibling sendTestEmail()
	 * already omits it, as does every other action on this controller.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Test connection result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function testEmailConnection(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->logger->info('Stackiq: Email connection test endpoint called');

		try {
			$data = $this->request->getParams();
			$emailSettings = $data['emailSettings'] ?? $data ?? [];

			$this->logger->info(
				'Stackiq: Email connection test request data',
				[
					'has_email_settings' => empty($emailSettings) === false,
					'transport_type' => $emailSettings['transportType'] ?? 'not specified',
				]
			);

			// Call the settings service to test the connection (without sending email).
			$result = $this->settingsService->testEmailConnection($emailSettings);

			$this->logger->info(
				'Stackiq: Email connection test result from service',
				[
					'success' => $result['success'],
					'message' => $result['message'] ?? 'no message',
				]
			);

			return new JSONResponse(
				[
					'success' => $result['success'],
					'message' => $result['message'],
					'details' => $result['details'] ?? null,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: Failed to test email connection',
				[
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to test email connection: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end testEmailConnection()

	/**
	 * Get email settings
	 *
	 * Secret fields (passwords / API keys) are redacted in the response; only
	 * a boolean presence indicator is returned for each secret.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Current email settings (secrets redacted)
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getEmailSettings(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			// Redaction lives in SettingsService so this route and /api/email/config share
			// one secret list — they had drifted, and the other one was returning the
			// secrets in the clear.
			$emailSettings = $this->settingsService->redactEmailSecrets(
				$this->settingsService->getEmailSettings()
			);

			return new JSONResponse(
				[
					'success' => true,
					'emailSettings' => $emailSettings,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get email settings',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get email settings: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getEmailSettings()

	/**
	 * Update email settings
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateEmailSettings(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = $this->request->getParams();
			$emailSettings = $data['emailSettings'] ?? $data;

			$updatedSettings = $this->settingsService->updateEmailSettings($emailSettings);

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'Email settings updated successfully',
					'emailSettings' => $updatedSettings,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update email settings',
				[
					'exception' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update email settings: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end updateEmailSettings()

	// ===.
	// EMAIL TEMPLATE METHODS.
	// ===.

	/**
	 * Get all email templates.
	 *
	 * @return JSONResponse List of available templates.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function getEmailTemplates(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Delegate all business logic to service.
			$templates = $this->settingsService->getAllEmailTemplates();

			return new JSONResponse(
				[
					'success' => true,
					'templates' => $templates,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get email templates',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get email templates: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getEmailTemplates()

	/**
	 * Get specific email template.
	 *
	 * @param string $templateName Template name.
	 *
	 * @return JSONResponse Template content.
	 *
	 * Admin-only, to match the write it pairs with. It reads admin-authored
	 * mail templates out of app configuration, and no frontend code calls this
	 * route at all — the settings UI reads templates from the bulk settings
	 * payload instead (verified with a positive control on a route that IS
	 * called). Leaving it open bought nothing and exposed admin content.
	 *
	 * @NoCSRFRequired
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function getEmailTemplate(string $templateName): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$template = $this->settingsService->getEmailTemplate($templateName);

			return new JSONResponse(
				[
					'success' => true,
					'template' => $template,
					'templateName' => $templateName,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				"Failed to get email template {$templateName}",
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => "Failed to get email template {$templateName}: " . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getEmailTemplate()

	/**
	 * Update email template.
	 *
	 * @param string $templateName Template name.
	 *
	 * @return JSONResponse Update result.
	 *
	 * Admin-only: this writes app configuration —
	 * setValueString($appName, "email_template_{$templateName}", $content) —
	 * and the stored HTML is rendered into real outbound mail. It was the only
	 * write on this controller declaring @NoAdminRequired, so any authenticated
	 * user could rewrite the templates every recipient receives, and mint
	 * unbounded `email_template_*` config rows besides. Its sibling
	 * updateEmailSettings() already omits the annotation.
	 *
	 * @NoCSRFRequired
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateEmailTemplate(string $templateName): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->request->getParams();
			$templateContent = $data['template'] ?? $data['content'] ?? '';

			if (empty($templateContent) === true) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Template content is required',
					],
					400
				);
			}

			$success = $this->settingsService->updateEmailTemplate(
				templateName: $templateName,
				templateContent: $templateContent
			);

			$updateMsg = "Failed to update template {$templateName}";
			if ($success === true) {
				$updateMsg = "Template {$templateName} updated successfully";
			}

			return new JSONResponse(['success' => $success, 'message' => $updateMsg]);
		} catch (\Exception $e) {
			$this->logger->error(
				"Failed to update email template {$templateName}",
				[
					'exception' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => "Failed to update email template {$templateName}: " . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end updateEmailTemplate()

	/**
	 * Get default email template.
	 *
	 * @param string $templateName Template name.
	 *
	 * @return JSONResponse Default template content.
	 *
	 * Admin-only, for the same reason as getEmailTemplate(): it belongs to the
	 * admin mail-template surface and has no non-admin consumer.
	 *
	 * @NoCSRFRequired
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function getEmailTemplateDefault(string $templateName): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$defaultTemplate = $this->settingsService->getDefaultEmailTemplate($templateName);

			return new JSONResponse(
				[
					'success' => true,
					'template' => $defaultTemplate,
					'templateName' => $templateName,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				"Failed to get default email template {$templateName}",
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => "Failed to get default email template {$templateName}: " . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getEmailTemplateDefault()

	/**
	 * Get email template variables.
	 *
	 * @param string $templateName Template name.
	 *
	 * @return JSONResponse Available variables for template.
	 *
	 * Admin-only, for the same reason as getEmailTemplate(): it belongs to the
	 * admin mail-template surface and has no non-admin consumer.
	 *
	 * @NoCSRFRequired
	 * @spec            openspec/specs/settings-admin-controller/spec.md
	 */
	public function getEmailTemplateVariables(string $templateName): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$variables = $this->settingsService->getEmailTemplateVariables($templateName);

			return new JSONResponse(
				[
					'success' => true,
					'variables' => $variables,
					'templateName' => $templateName,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				"Failed to get email template variables for {$templateName}",
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => "Failed to get email template variables for {$templateName}: " . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getEmailTemplateVariables()

	// ===.
	// USER GROUPS MANAGEMENT METHODS.
	// ===.

	/**
	 * Get generic user groups
	 *
	 * Admin-only: the body rejects a non-admin with 403, so the endpoint
	 * must not declare @NoAdminRequired. Its sibling setGenericUserGroups()
	 * already omits it.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Generic user groups
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getGenericUserGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$groups = $this->settingsService->getGenericUserGroups();

			return new JSONResponse(
				[
					'success' => true,
					'groups' => $groups,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get generic user groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get generic user groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getGenericUserGroups()

	/**
	 * Set generic user groups
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function setGenericUserGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = $this->request->getParams();
			$groups = $data['groups'] ?? [];

			// Delegate all business logic (including validation) to service.
			$result = $this->settingsService->updateGenericUserGroups($groups);

			return new JSONResponse(
				[
					'success' => $result['success'],
					'message' => $result['message'],
					'groups' => $result['groups'] ?? null,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to set generic user groups',
				[
					'exception' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to set generic user groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end setGenericUserGroups()

	/**
	 * Get organization admin groups
	 *
	 * Admin-only: the body rejects a non-admin with 403, so the endpoint
	 * must not declare @NoAdminRequired. Its sibling
	 * setOrganizationAdminGroups() already omits it.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Organization admin groups
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getOrganizationAdminGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$groups = $this->settingsService->getOrganizationAdminGroups();

			return new JSONResponse(
				[
					'success' => true,
					'groups' => $groups,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get organization admin groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get organization admin groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getOrganizationAdminGroups()

	/**
	 * Set organization admin groups
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function setOrganizationAdminGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = $this->request->getParams();
			$groups = $data['groups'] ?? [];

			// Delegate all business logic (including validation) to service.
			$result = $this->settingsService->updateOrganizationAdminGroups($groups);

			return new JSONResponse(
				[
					'success' => $result['success'],
					'message' => $result['message'],
					'groups' => $result['groups'] ?? null,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to set organization admin groups',
				[
					'exception' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to set organization admin groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end setOrganizationAdminGroups()

	/**
	 * Get super user groups
	 *
	 * Admin-only: the body rejects a non-admin with 403, so the endpoint
	 * must not declare @NoAdminRequired. Its sibling setSuperUserGroups()
	 * already omits it.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Super user groups
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getSuperUserGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$groups = $this->settingsService->getSuperUserGroups();

			return new JSONResponse(
				[
					'success' => true,
					'groups' => $groups,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get super user groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get super user groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getSuperUserGroups()

	/**
	 * Set super user groups
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function setSuperUserGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = $this->request->getParams();
			$groups = $data['groups'] ?? [];

			// Delegate all business logic (including validation) to service.
			$result = $this->settingsService->updateSuperUserGroups($groups);

			return new JSONResponse(
				[
					'success' => $result['success'],
					'message' => $result['message'],
					'groups' => $result['groups'] ?? null,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to set super user groups',
				[
					'exception' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to set super user groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end setSuperUserGroups()

	/**
	 * Get all user groups
	 *
	 * Admin-only: the body rejects a non-admin with 403, and the payload is
	 * the full group list of the instance — an enumeration surface. The
	 * endpoint must not declare @NoAdminRequired.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse All user groups
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getAllGroups(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$allGroups = $this->settingsService->getAllGroups();

			return new JSONResponse(
				[
					'success' => true,
					'groups' => $allGroups,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get all groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get all groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getAllGroups()

	// ===.
	// ARCHIMATE STATUS MANAGEMENT METHODS.
	// ===.

	/**
	 * Clear ArchiMate import status
	 *
	 * Admin-only: the body rejects a non-admin with 403, and importArchiMate()
	 * — the operation whose status this clears — is already admin-only.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Clear result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function clearArchiMateImportStatus(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->settingsService->clearArchiMateImportStatus();

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'ArchiMate import status cleared successfully',
					'details' => $result,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to clear ArchiMate import status',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to clear ArchiMate import status: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end clearArchiMateImportStatus()

	/**
	 * Force kill running ArchiMate import process and clear status.
	 *
	 * @return JSONResponse Kill result.
	 *
	 * @deprecated Use cancelArchiMateImport() instead.
	 *
	 * Admin-only: the body rejects a non-admin with 403, and importArchiMate()
	 * — the process this kills — is already admin-only.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function killArchiMateImport(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->settingsService->killArchiMateImport();

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'ArchiMate import termination completed',
					'details' => $result,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to kill ArchiMate import process',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to kill ArchiMate import process: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end killArchiMateImport()

	/**
	 * Cancel a running ArchiMate import
	 * This combines force clearing and process killing for complete cancellation
	 *
	 * Admin-only: the body rejects a non-admin with 403, and importArchiMate()
	 * — the process this cancels — is already admin-only.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Cancellation result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function cancelArchiMateImport(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->settingsService->cancelArchiMateImport();
			$message = 'ArchiMate import cancellation failed';
			if ($result['cancelled'] === true) {
				$message = 'ArchiMate import cancellation succeeded';
			}

			return new JSONResponse(['success' => $result['cancelled'], 'message' => $message, 'details' => $result]);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to cancel ArchiMate import',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to cancel ArchiMate import: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end cancelArchiMateImport()

	/**
	 * Clear ArchiMate export status
	 *
	 * Admin-only: the body rejects a non-admin with 403.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Clear result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function clearArchiMateExportStatus(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->settingsService->clearArchiMateExportStatus();

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'ArchiMate export status cleared successfully',
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to clear ArchiMate export status',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to clear ArchiMate export status: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end clearArchiMateExportStatus()

	// ===.
	// ARCHIMATE TESTING METHODS.
	// ===.

	/**
	 * Test ArchiMate round-trip functionality
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Round-trip test result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function testArchiMateRoundTrip(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->logger->info('Stackiq: ArchiMate round-trip test started');

			// Call the ArchiMate service to perform round-trip test.
			$result = $this->archiMateService->testRoundTrip();

			$this->logger->info(
				'Stackiq: ArchiMate round-trip test completed',
				[
					'success' => $result['success'],
					'message' => $result['message'] ?? 'no message',
				]
			);

			return new JSONResponse(
				[
					'success' => $result['success'],
					'message' => $result['message'],
					'details' => $result['details'] ?? null,
					'statistics' => $result['statistics'] ?? null,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: ArchiMate round-trip test failed',
				[
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Round-trip test failed: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end testArchiMateRoundTrip()

	/**
	 * Get ArchiMate settings and status (without object counts for performance)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse ArchiMate settings and status
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getArchiMateSettings(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$archimateStatus = $this->settingsService->getArchiMateStatus();

			return new JSONResponse(
				[
					'success' => true,
					'archimate' => $archimateStatus,
					'timestamp' => time(),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get ArchiMate settings',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get ArchiMate settings: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getArchiMateSettings()

	/**
	 * Get object counts for all registers (separate endpoint for performance)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Object counts for all registers
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getObjectCounts(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$objectCounts = $this->settingsService->getObjectCountsStatistics();

			return new JSONResponse(
				[
					'success' => true,
					'objectCounts' => $objectCounts,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get object counts',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get object counts: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getObjectCounts()

	// ===.
	// FOCUSED ENDPOINT CONTROLLER METHODS FOR PERFORMANCE OPTIMIZATION.
	// ===.

	/**
	 * Get ArchiMate configuration only
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse ArchiMate configuration
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getArchiMateConfig(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$config = $this->settingsService->getArchiMateConfig();

			return new JSONResponse($config);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get ArchiMate config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get ArchiMate config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getArchiMateConfig()

	/**
	 * Update ArchiMate configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateArchiMateConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateArchiMateConfig($data);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update ArchiMate config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update ArchiMate config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end updateArchiMateConfig()

	/**
	 * Get email configuration only
	 *
	 * Admin-only, and the secrets are redacted before they leave the process.
	 *
	 * This endpoint used to be @NoAdminRequired and returned getEmailSettings() verbatim,
	 * so ANY authenticated user could read the SMTP password and the SendGrid / Mailgun /
	 * Postmark / SES / Mailjet API keys in plaintext. Its sibling getEmailSettings()
	 * (/api/settings/email) had always done both checks correctly — this one was added
	 * later and inherited neither. The redaction is now in SettingsService so the two
	 * cannot drift apart again.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Email configuration (secrets redacted)
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getEmailConfig(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$config = $this->settingsService->getEmailConfigFocused();

			return new JSONResponse($config);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get email config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get email config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getEmailConfig()

	/**
	 * Update email configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateEmailConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateEmailConfig($data);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update email config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update email config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end updateEmailConfig()

	/**
	 * Get AMEF configuration only
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse AMEF configuration
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getAmefConfig(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$config = $this->settingsService->getAmefConfigFocused();

			return new JSONResponse($config);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get AMEF config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get AMEF config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getAmefConfig()

	/**
	 * Update AMEF configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateAmefConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateAmefConfig($data);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update AMEF config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update AMEF config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end updateAmefConfig()

	/**
	 * Get Voorzieningen configuration only
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Voorzieningen configuration
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getVoorzieningenConfig(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$config = $this->settingsService->getVoorzieningenConfigFocused();

			return new JSONResponse($config);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get Voorzieningen config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get Voorzieningen config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getVoorzieningenConfig()

	/**
	 * Update Voorzieningen configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateVoorzieningenConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateVoorzieningenConfig($data);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update Voorzieningen config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update Voorzieningen config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end updateVoorzieningenConfig()

	/**
	 * Get object counts only (lightweight)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Object counts
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getObjectsCounts(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$counts = $this->settingsService->getObjectsCounts();

			return new JSONResponse($counts);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get object counts',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get object counts: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getObjectsCounts()

	/**
	 * Get object statistics (full statistics with configuration)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Object statistics
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getObjectsStatistics(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$statistics = $this->settingsService->getObjectsStatistics();

			return new JSONResponse($statistics);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get object statistics',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get object statistics: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getObjectsStatistics()

	/**
	 * Get user groups configuration only
	 *
	 * This endpoint returns the union of getGenericUserGroups(),
	 * getOrganizationAdminGroups(), getSuperUserGroups() and getAllGroups().
	 * Each of those is exposed on its own route behind an explicit
	 * `isAdmin() === false -> 403` guard, so this aggregate MUST carry the
	 * same guard: without it any authenticated user reads through it the
	 * data the four dedicated routes refuse them, and the guards on those
	 * routes protect nothing.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse User groups configuration
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getUserGroupsConfig(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$config = $this->settingsService->getUserGroupsConfig();

			return new JSONResponse($config);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get user groups config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get user groups config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getUserGroupsConfig()

	/**
	 * Update user groups configuration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateUserGroupsConfig(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateUserGroupsConfig($data);

			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update user groups config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update user groups config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end updateUserGroupsConfig()

	/**
	 * Determine appropriate HTTP status code for an exception.
	 *
	 * @param \Exception $e The exception to classify.
	 *
	 * @return int HTTP status code (400, 404, 422, or 500).
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable)
	 */
	private function getHttpStatusForException(\Exception $e): int {
		// Check exception type first.
		if ($e instanceof \InvalidArgumentException) {
			// Bad Request for invalid arguments/configuration.
			return 400;
		}

		// Fallback to message-based classification.
		$message = $e->getMessage();
		return $this->getHttpStatusForErrorMessage(message: $message);
	}//end getHttpStatusForException()

	/**
	 * Determine appropriate HTTP status code for an error message.
	 *
	 * @param string $message The error message to classify.
	 *
	 * @return int HTTP status code (400, 404, 422, or 500).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function getHttpStatusForErrorMessage(string $message): int {
		$message = strtolower($message);

		// Configuration errors — 400 Bad Request.
		if (str_contains(haystack: $message, needle: 'not configured') === true
			|| str_contains(haystack: $message, needle: 'missing configuration') === true
			|| str_contains(haystack: $message, needle: 'invalid configuration') === true
		) {
			return 400;
		}

		// File not found errors — 404 Not Found.
		if (str_contains(haystack: $message, needle: 'file not found') === true
			|| str_contains(haystack: $message, needle: 'not found') === true
			|| str_contains(haystack: $message, needle: 'missing file') === true
		) {
			return 404;
		}

		// Validation errors — 422 Unprocessable Entity.
		if (str_contains(haystack: $message, needle: 'validation') === true
			|| str_contains(haystack: $message, needle: 'invalid xml') === true
			|| str_contains(haystack: $message, needle: 'parsing error') === true
			|| str_contains(haystack: $message, needle: 'malformed') === true
			|| str_contains(haystack: $message, needle: 'could not be parsed') === true
		) {
			return 422;
		}

		// Default to 500 Internal Server Error for unknown issues.
		return 500;
	}//end getHttpStatusForErrorMessage()

	/**
	 * Sync OpenRegister organisations to voorzieningen register
	 *
	 * Admin-only: this triggers a register-wide write sync with a
	 * caller-chosen batch size, so the endpoint must not carry the
	 * no-admin-required annotation. Its siblings performSync(),
	 * bulkSyncStandards() and triggerEolSync() already omit it — the
	 * annotation's absence IS the check on this controller, which is why none
	 * of them carries an in-body one.
	 *
	 * The annotation is deliberately NOT spelled out above. Nextcloud's own
	 * ControllerMethodReflector matches `^\h+\*\h+@([A-Z]\w+)` against the raw
	 * docblock, so writing "must not declare @NoAdminRequired" at the start of
	 * a comment line RE-DECLARES it — the sentence explaining the removal
	 * undoes the removal, in the framework and not merely in a linter. Gate-7
	 * caught it here; nothing else would have.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The sync results
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function syncOrganisations(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->logger->info('SettingsController: Starting organisation sync via API');

			// Get request parameters.
			$requestBody = $this->request->getParams();
			$options = [
				'batch_size' => (int)($requestBody['batch_size'] ?? 500),
				'dry_run' => filter_var($requestBody['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
			];

			$this->logger->debug('SettingsController: Sync options.', $options);

			// Call the settings service method.
			$result = $this->settingsService->syncOrganisationsToVoorzieningenOptimized($options);

			$statusCode = 500;
			if ($result['success'] === true) {
				$statusCode = 200;
			}

			$this->logger->info(
				'SettingsController: Organisation sync completed',
				[
					'success' => $result['success'],
					'message' => $result['message'] ?? 'No message',
				]
			);

			return new JSONResponse($result, $statusCode);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Organisation sync failed',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Organisation sync failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				500
			);
		}//end try
	}//end syncOrganisations()

	/**
	 * Bulk sync module standards from all compliance objects.
	 *
	 * @return JSONResponse Response containing sync results.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/settings-admin-controller/spec.md
	 */
	public function bulkSyncStandards(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($currentUser->getUID()) === false) {
			return new JSONResponse(['message' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->logger->info('SettingsController: Starting bulk sync of module standards.');

			// Get the ModuleComplianceService from the container.
			$moduleComplianceService = $this->container->get(\OCA\Stackiq\Service\ModuleComplianceService::class);

			// Perform the bulk sync.
			$results = $moduleComplianceService->bulkSyncModuleStandards();

			$this->logger->info(
				'SettingsController: Bulk sync completed successfully',
				[
					'results' => $results,
				]
			);

			return new JSONResponse(
				[
					'success' => true,
					'message' => 'Bulk sync completed successfully',
					'data' => $results,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsController: Bulk sync failed',
				[
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Bulk sync failed: ' . $e->getMessage(),
					'error' => $e->getMessage(),
				],
				$this->getHttpStatusForException(e: $e)
			);
		}//end try
	}//end bulkSyncStandards()

	// ===.
	// CRONJOB CONFIGURATION ENDPOINTS (deprecated — sync now uses _rbac: false).
	// ===.

	/**
	 * Get cronjob configuration
	 *
	 * @deprecated Cronjob context is no longer needed. Will be removed in a future version.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Cronjob configurations
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getCronjobConfig(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$config = $this->settingsService->getCronjobConfig();
			return new JSONResponse($config);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get cronjob config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get cronjob config: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end getCronjobConfig()

	/**
	 * Update cronjob configuration
	 *
	 * @deprecated Cronjob context is no longer needed. Will be removed in a future version.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Update result
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function updateCronjobConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateCronjobConfig($data);
			$statusCode = 400;
			if ($result['success'] === true) {
				$statusCode = 200;
			}

			return new JSONResponse($result, $statusCode);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update cronjob config',
				[
					'exception' => $e->getMessage(),
					'requestData' => $this->getRedactedParams(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to update cronjob config: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end updateCronjobConfig()

	/**
	 * Get available users for cronjob configuration
	 *
	 * @deprecated Cronjob context is no longer needed. Will be removed in a future version.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse List of available users
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getCronjobUsers(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'success' => false,
				'message' => 'This endpoint is deprecated and has been removed. Cronjob user context is no longer required.',
			],
			Http::STATUS_GONE
		);
	}//end getCronjobUsers()

	/**
	 * Get available organisations for cronjob configuration
	 *
	 * @deprecated Removed — cronjob context is no longer needed.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse 410 Gone
	 * @spec   openspec/specs/settings-admin-controller/spec.md
	 */
	public function getCronjobOrganisations(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'success' => false,
				'message' => 'This endpoint is deprecated and has been removed. Cronjob organisation context is no longer required.',
			],
			Http::STATUS_GONE
		);
	}//end getCronjobOrganisations()

	// ===.
	// EOL SYNC ENDPOINTS (eol-feed-integration).
	// ===.

	/**
	 * Get the EOL sync configuration (enabled toggle, register/schema
	 * slugs, sync interval).
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The current EOL sync configuration.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function getEolSyncConfig(): JSONResponse {
		try {
			return new JSONResponse(
				[
					'success' => true,
					'config' => $this->eolSyncService->getConfig(),
				]
			);
		} catch (\Exception $e) {
			return $this->buildConfigReadErrorResponse(operationLabel: 'get EOL sync config', exception: $e);
		}
	}//end getEolSyncConfig()

	/**
	 * Update the EOL sync configuration.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The updated EOL sync configuration.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function updateEolSyncConfig(): JSONResponse {
		try {
			$data = $this->request->getParams();
			return new JSONResponse($this->eolSyncService->updateConfig($data));
		} catch (\Exception $e) {
			return $this->buildConfigWriteErrorResponse(operationLabel: 'update EOL sync config', exception: $e);
		}
	}//end updateEolSyncConfig()

	/**
	 * Trigger an EOL sync run immediately, outside the scheduled interval.
	 * Runs the identical logic the scheduled `EolSyncJob` invokes, so the
	 * two trigger paths can never drift.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The resulting sync status.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
	 */
	public function triggerEolSync(): JSONResponse {
		try {
			return new JSONResponse(
				[
					'success' => true,
					'status' => $this->eolSyncService->run(),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error('Failed to trigger EOL sync', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to trigger EOL sync: ' . $e->getMessage(),
				],
				500
			);
		}
	}//end triggerEolSync()

	/**
	 * Get the last-recorded EOL sync status — distinguishes "not configured"
	 * from "configured but nothing matched yet" from "ran, N matched, M
	 * skipped" (design.md Decision 6).
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The current EOL sync status.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function getEolSyncStatus(): JSONResponse {
		try {
			return new JSONResponse(
				[
					'success' => true,
					'status' => $this->eolSyncService->getStatus(),
				]
			);
		} catch (\Exception $e) {
			return $this->buildConfigReadErrorResponse(operationLabel: 'get EOL sync status', exception: $e);
		}
	}//end getEolSyncStatus()
}//end class
