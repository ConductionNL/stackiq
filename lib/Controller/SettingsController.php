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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-1
 */

namespace OCA\SoftwareCatalog\Controller;

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\AppFramework\Http\StreamResponse;
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
 */
class SettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var ObjectService|null The OpenRegister object service.
     */
    private $objectService;

    /**
     * SettingsController constructor.
     *
     * @param string                  $appName          The name of the app.
     * @param IRequest                $request          The request object.
     * @param IAppConfig              $config           The app configuration.
     * @param ContainerInterface      $container        The container.
     * @param IAppManager             $appManager       The app manager.
     * @param IGroupManager           $groupManager     The group manager.
     * @param IUserSession            $userSession      The user session.
     * @param SettingsService         $settingsService  The settings service.
     * @param OrganizationSyncService $orgSyncSvc       The organization sync service.
     * @param ArchiMateService        $archiMateService The ArchiMate import/export service.
     * @param ProgressTracker         $progressTracker  The progress tracking service.
     * @param LoggerInterface         $logger           The logger instance.
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
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws RuntimeException If the service is not available.
     */
    public function getObjectService(): ?ObjectService
    {
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
     */
    public function getConfigurationService(): ?ConfigurationService
    {
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
     * Retrieve the current settings.
     *
     * @return JSONResponse JSON response containing the current settings.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            $user    = $this->userSession->getUser();
            $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

            // Delegate all business logic to service.
            $data = $this->settingsService->getAllSettings();
            $data['openRegisters'] = in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps());
            $data['isAdmin']       = $isAdmin;

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
     * Handle the post request to update settings.
     *
     * @return JSONResponse JSON response containing the updated settings.
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Handle different types of settings updates.
            $result = [];

            // Update schema/register configuration.
            if (isset($data['configuration']) === true || isset($data['selectedRegister']) === true) {
                $configData = array_filter(
                        $data,
                        function ($key) {
                            return in_array(needle: $key, haystack: ['userGroups', 'emailSettings']) === false;
                        },
                        ARRAY_FILTER_USE_KEY
                        );

                if (empty($configData) === false) {
                    $result['configuration'] = $this->settingsService->updateSettings($configData);
                }
            }

            // Update user groups.
            if (isset($data['userGroups']) === true) {
                $userGroups = $data['userGroups'];

                if (isset($userGroups['generic']) === true) {
                    $validation = $this->settingsService->validateGroups($userGroups['generic']);
                    if (empty($validation['invalid']) === false) {
                        return new JSONResponse(
                                [
                                    'error'      => 'Invalid generic group names provided',
                                    'validation' => $validation,
                                ],
                                400
                                );
                    }

                    $this->settingsService->setGenericUserGroups($validation['valid']);
                    $result['userGroups']['generic'] = $validation['valid'];
                }

                if (isset($userGroups['organizationAdmin']) === true) {
                    $validation = $this->settingsService->validateGroups($userGroups['organizationAdmin']);
                    if (empty($validation['invalid']) === false) {
                        return new JSONResponse(
                                [
                                    'error'      => 'Invalid organization admin group names provided',
                                    'validation' => $validation,
                                ],
                                400
                                );
                    }

                    $this->settingsService->setOrganizationAdminGroups($validation['valid']);
                    $result['userGroups']['organizationAdmin'] = $validation['valid'];
                }

                if (isset($userGroups['superUser']) === true) {
                    $validation = $this->settingsService->validateGroups($userGroups['superUser']);
                    if (empty($validation['invalid']) === false) {
                        return new JSONResponse(
                                [
                                    'error'      => 'Invalid super user group names provided',
                                    'validation' => $validation,
                                ],
                                400
                                );
                    }

                    $this->settingsService->setSuperUserGroups($validation['valid']);
                    $result['userGroups']['superUser'] = $validation['valid'];
                }
            }//end if

            // Update email settings.
            if (isset($data['emailSettings']) === true) {
                $result['emailSettings'] = $this->settingsService->updateEmailSettings($data['emailSettings']);
            }

            return new JSONResponse(
                    [
                        'success' => true,
                        'data'    => $result,
                        'message' => 'Settings updated successfully',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to update settings',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try

    }//end create()

    /**
     * Get general configuration settings
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse General configuration
     */
    public function getGeneralConfig(): JSONResponse
    {
        try {
            $config = [
                'catalogLocation' => $this->settingsService->getCatalogLocation(),
            ];

            return new JSONResponse(
                    [
                        'success' => true,
                        'config'  => $config,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get general config',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to get general config: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getGeneralConfig()

    /**
     * Update general configuration settings
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Update result
     */
    public function updateGeneralConfig(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            if (isset($data['catalogLocation']) === true) {
                $this->settingsService->setCatalogLocation($data['catalogLocation']);
            }

            return new JSONResponse(
                    [
                        'success' => true,
                        'message' => 'General configuration updated successfully',
                        'config'  => [
                            'catalogLocation' => $this->settingsService->getCatalogLocation(),
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to update general config',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to update general config: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end updateGeneralConfig()

    /**
     * Get organization synchronization configuration
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Sync configuration
     */
    public function getSyncConfig(): JSONResponse
    {
        try {
            $config = [
                'syncTimeWindow' => $this->config->getValueString($this->appName, 'syncTimeWindow', '10'),
            ];

            return new JSONResponse(
                    [
                        'success' => true,
                        'config'  => $config,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get sync config',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to get sync config: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getSyncConfig()

    /**
     * Update organization synchronization configuration
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Update result
     */
    public function updateSyncConfig(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            if (isset($data['syncTimeWindow']) === true) {
                $this->config->setValueString($this->appName, 'syncTimeWindow', (string) $data['syncTimeWindow']);
            }

            return new JSONResponse(
                    [
                        'success' => true,
                        'message' => 'Sync configuration updated successfully',
                        'config'  => [
                            'syncTimeWindow' => $this->config->getValueString($this->appName, 'syncTimeWindow', '10'),
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to update sync config',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to update sync config: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end updateSyncConfig()

    /**
     * Load the settings from the publication_register.json file.
     *
     * @return JSONResponse JSON response containing the settings.
     *
     * @NoCSRFRequired
     */
    public function load(): JSONResponse
    {
        try {
            $result = $this->settingsService->loadSettings();
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end load()

    /**
     * Initialize the SoftwareCatalog settings
     *
     * @return JSONResponse JSON response containing the initialization results
     *
     * @NoCSRFRequired
     */
    public function initialize(): JSONResponse
    {
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
     */
    public function status(): JSONResponse
    {
        try {
            $this->logger->debug('SettingsController: Getting configuration status');

            $status            = $this->settingsService->getConfigurationStatus();
            $isFullyConfigured = $this->settingsService->isFullyConfigured();
            $versionInfo       = $this->settingsService->getVersionInfo();

            $responseData = [
                'status'              => $status,
                'fullyConfigured'     => $isFullyConfigured,
                'versionInfo'         => $versionInfo,
                'timestamp'           => time(),
                'autoConfigCompleted' => $this->config->getValueString(
                    'softwarecatalog',
                    'auto_config_completed',
                    'false'
                ) === 'true',
            ];

            $this->logger->info(
                    'SettingsController: Configuration status compiled',
                    [
                        'fullyConfigured' => $isFullyConfigured,
                        'needsUpdate'     => $versionInfo['needsUpdate'] ?? null,
                        'versionsMatch'   => $versionInfo['versionsMatch'] ?? null,
                    ]
                    );

            return new JSONResponse($responseData);
        } catch (\Exception $e) {
            $this->logger->error(
                    'SettingsController: Failed to get configuration status',
                    [
                        'exception_message' => $e->getMessage(),
                        'exception'         => $e,
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'     => $e->getMessage(),
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
     */
    public function autoConfigure(): JSONResponse
    {
        try {
            $configuration = $this->settingsService->autoConfigure();
            if (empty($configuration) === false) {
                $result = $this->settingsService->updateSettings($configuration);
                return new JSONResponse(
                        [
                            'success'       => true,
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
     */
    public function stats(): JSONResponse
    {
        try {
            $statistics = $this->settingsService->getObjectCountsStatistics();
            return new JSONResponse(
                    [
                        'success'    => true,
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
                        'error'   => $e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end stats()

    /**
     * Get debug information for settings
     *
     * @return JSONResponse JSON response containing debug information
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function debug(): JSONResponse
    {
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
     */
    public function sendTestEmail(): JSONResponse
    {
        try {
            $data          = $this->request->getParams();
            $email         = $data['email'] ?? '';
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
                    'SoftwareCatalog: Failed to send test email in controller',
                    [
                        'exception_class'   => get_class($e),
                        'exception_message' => $e->getMessage(),
                        'requestData'       => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to send test email: '.$e->getMessage(),
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
     */
    public function getSyncStatus(int $minutesBack=10): JSONResponse
    {
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
     */
    public function performSync(int $minutesBack=0): JSONResponse
    {
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
                            'success'     => true,
                            'results'     => $result,
                            'message'     => 'Optimized synchronization completed successfully',
                            'isOptimized' => true,
                        ]
                        );
            }//end if

            // For incremental sync, use the original method.
            $result = $this->orgSyncSvc->performManualSync($minutesBack);

            if ($result['success'] === true) {
            }

            return new JSONResponse($result, 500);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Manual sync failed',
                    [
                        'minutesBack' => $minutesBack,
                        'exception'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Synchronization failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     */
    public function heartbeat(): JSONResponse
    {
        try {
            $timestamp = $this->request->getParam('timestamp', time() * 1000);

            $this->logger->debug(
                    'Heartbeat received',
                    [
                        'timestamp'   => $timestamp,
                        'server_time' => time() * 1000,
                    ]
                    );

            return new JSONResponse(
                    [
                        'success'     => true,
                        'message'     => 'Heartbeat received',
                        'timestamp'   => $timestamp,
                        'server_time' => time() * 1000,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Heartbeat error: '.$e->getMessage(),
                    [
                        'exception' => $e,
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Heartbeat failed',
                        'error'   => $e->getMessage(),
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
     */
    public function getVersionInfo(): JSONResponse
    {
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
                        'exception'         => $e,
                    ]
                    );
            return new JSONResponse(
                    [
                        'error'     => $e->getMessage(),
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
     */
    public function resetAutoConfig(): JSONResponse
    {
        try {
            $params = $this->request->getParams();
            $resetConfiguration = isset($params['resetConfiguration']) === true && $params['resetConfiguration'] === true;

            $result = $this->settingsService->resetAutoConfiguration($resetConfiguration);

            if ($result['success'] === true) {
            }

                return new JSONResponse($result, 400);
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Reset failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     */
    public function clearCache(): JSONResponse
    {
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
                        'message' => 'Cache clear failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     */
    public function manualImport(): JSONResponse
    {
        try {
            $params      = $this->request->getParams();
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

            if ($result['success'] === true) {
            }

                return new JSONResponse($result, 400);
        } catch (\Exception $e) {
            $this->logger->error(
                    'SettingsController: Manual import failed',
                    [
                        'exception_message' => $e->getMessage(),
                        'exception'         => $e,
                    ]
                    );
            return new JSONResponse(
                    [
                        'success'   => false,
                        'message'   => 'Import failed: '.$e->getMessage(),
                        'error'     => $e->getMessage(),
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
     */
    public function forceUpdate(): JSONResponse
    {
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
                            'json_error'  => json_last_error_msg(),
                            'result_keys' => array_keys($result),
                        ]
                        );
                // Return a simplified response if serialization fails.
                return new JSONResponse(
                        [
                            'success'   => $result['success'] ?? false,
                            'message'   => $result['message'] ?? 'Force update completed but response serialization failed',
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
                        'exception_class'   => get_class($e),
                        'exception_trace'   => $e->getTraceAsString(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success'   => false,
                        'message'   => 'Force update failed: '.$e->getMessage(),
                        'error'     => $e->getMessage(),
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
     */
    public function consolidatedAutoConfigure(): JSONResponse
    {
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
                }
            }

            return new JSONResponse($results, $httpStatus);
        } catch (\Exception $e) {
            $this->logger->error(
                    'SettingsController: Consolidated auto-configuration failed',
                    [
                        'exception_message' => $e->getMessage(),
                        'exception'         => $e,
                    ]
                    );
            return new JSONResponse(
                    [
                        'success'   => false,
                        'message'   => 'Auto-configuration failed: '.$e->getMessage(),
                        'error'     => $e->getMessage(),
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
     */
    public function getProgress(string $operationId): JSONResponse
    {
        try {
            $progress = $this->progressTracker->getProgress($operationId);

            if ($progress === null) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => 'Operation not found',
                            'error'   => 'OPERATION_NOT_FOUND',
                        ],
                        404
                        );
            }

            return new JSONResponse(
                    [
                        'success'  => true,
                        'progress' => $progress,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get progress',
                    [
                        'operation_id' => $operationId,
                        'error'        => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to get progress: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function streamProgress(string $operationId): Response
    {
        // Set headers for Server-Sent Events.
        $response = new class($operationId, $this->progressTracker, $this->logger) extends Response {
            /**
             * Constructor for the SSE response.
             *
             * @param string          $operationId     The operation ID to stream.
             * @param ProgressTracker $progressTracker The progress tracker service.
             * @param LoggerInterface $logger          The logger instance.
             */
            public function __construct(
                private string $operationId,
                private ProgressTracker $progressTracker,
                private LoggerInterface $logger
            ) {
                parent::__construct();
                $this->addHeader(name: 'Content-Type', value: 'text/event-stream');
                $this->addHeader(name: 'Cache-Control', value: 'no-cache');
                $this->addHeader(name: 'Connection', value: 'keep-alive');
                $this->addHeader(name: 'Access-Control-Allow-Origin', value: '*');
                $this->addHeader(name: 'Access-Control-Allow-Headers', value: 'Cache-Control');
            }//end __construct()

            /**
             * Render the SSE stream.
             *
             * @return string Empty string (output is streamed directly).
             */
            public function render(): string
            {
                // Enable output buffering and turn off compression.
                if (ob_get_level() !== 0) {
                    ob_end_clean();
                }

                ob_implicit_flush(true);

                // Stream progress updates.
                $lastProgress = null;
                $maxAttempts  = 300;
                // 5 minutes with 1-second intervals.
                $attempts = 0;

                while ($attempts < $maxAttempts) {
                    try {
                        $progress = $this->progressTracker->getProgress($this->operationId);

                        if ($progress === null) {
                            // Operation not found, send error and close.
                            echo "event: error\n";
                            echo "data: ".json_encode(['error' => 'Operation not found'])."\n\n";
                            break;
                        }

                        // Only send update if progress changed.
                        if ($progress !== $lastProgress) {
                            echo "event: progress\n";
                            echo "data: ".json_encode($progress)."\n\n";
                            $lastProgress = $progress;

                            // If operation completed, send final event and close.
                            if ($progress['phase'] === 'completed') {
                                echo "event: completed\n";
                                echo "data: ".json_encode($progress)."\n\n";
                                break;
                            }
                        }

                        // Send heartbeat every 10 seconds.
                        if ($attempts % 10 === 0) {
                            echo "event: heartbeat\n";
                            echo "data: ".json_encode(['timestamp' => time()])."\n\n";
                        }

                        flush();
                        sleep(1);
                        $attempts++;
                    } catch (\Exception $e) {
                        $this->logger->error(
                                'Progress streaming error',
                                [
                                    'operation_id' => $this->operationId,
                                    'error'        => $e->getMessage(),
                                ]
                                );

                        echo "event: error\n";
                        echo "data: ".json_encode(['error' => $e->getMessage()])."\n\n";
                        break;
                    }//end try
                }//end while

                // Send final close event.
                echo "event: close\n";
                echo "data: ".json_encode(['reason' => 'Stream ended'])."\n\n";
                flush();

                return '';
            }//end render()
        };

        return $response;
    }//end streamProgress()

    /**
     * Import ArchiMate file.
     *
     * @return JSONResponse Result of the import operation with progress tracking.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function importArchiMate(): JSONResponse
    {
        try {
            // Increase memory limit for large imports.
            ini_set('memory_limit', '4096M');
            $this->logger->info(
                    'Memory limit increased for import',
                    [
                        'old_limit' => ini_get('memory_limit'),
                        'new_limit' => '4096M',
                    ]
                    );
            // Get JSON data from request body.
            $rawInput = file_get_contents('php://input');
            $data     = json_decode($rawInput, true);

            $contentType = $this->request->getHeader('Content-Type');
            $isMultipart = strpos(haystack: $contentType, needle: 'multipart/form-data') !== false;

            // Enhanced debug logging.
            $this->logger->info(
                    'ArchiMate import request received',
                    [
                        'rawInput'       => $rawInput,
                        'decodedData'    => $data,
                        'jsonError'      => json_last_error_msg(),
                        'contentType'    => $contentType,
                        'isMultipart'    => $isMultipart,
                        'requestMethod'  => $this->request->getMethod(),
                        'userAgent'      => $this->request->getHeader('User-Agent'),
                        'xRequestedWith' => $this->request->getHeader('X-Requested-With'),
                        '_FILES'         => $_FILES,
                        '_POST'          => $_POST,
                        'requestParams'  => $this->request->getParams(),
                    ]
                    );

            // Check if a file was uploaded (traditional file upload).
            $uploadedFiles = $this->request->getUploadedFile('archiMateFile');

            // Also check $_FILES directly as fallback.
            $filesArray = $_FILES['archiMateFile'] ?? null;

            $hasUploadedFiles = empty($uploadedFiles) === false;
            $hasFilesArray    = empty($filesArray) === false;

            $this->logger->info(
                    'File upload detection detailed',
                    [
                        'uploadedFiles'     => $uploadedFiles,
                        'filesArray'        => $filesArray,
                        'requestMethod'     => $this->request->getMethod(),
                        'contentType'       => $contentType,
                        'hasUploadedFiles'  => $hasUploadedFiles,
                        'hasFilesArray'     => $hasFilesArray,
                        'uploadedFilesType' => gettype($uploadedFiles),
                        'filesArrayType'    => gettype($filesArray),
                        'allFilesKeys'      => array_keys($_FILES ?? []),
                    ]
                    );

            if ($hasUploadedFiles === true || $hasFilesArray === true) {
                // Use $_FILES as fallback if getUploadedFile doesn't work.
                    $fileData = $filesArray;
                if ($uploadedFiles !== null) {
                }

                // Handle file upload.
                $options = [
                    'updateExisting' => $this->request->getParam('updateExisting', 'true') === 'true',
                    'deleteOrphaned' => $this->request->getParam('deleteOrphaned', 'false') === 'true',
                    'preserveIds'    => $this->request->getParam('preserveIds', 'true') === 'true',
                    'processingMode' => $this->request->getParam('processingMode', 'speed'),
                    'filePath'       => $fileData['tmp_name'],
                    'fileName'       => $fileData['name'],
                    'fileSize'       => $fileData['size'] ?? filesize($fileData['tmp_name']),
                    'mimeType'       => $fileData['type'] ?? 'text/xml',
                ];

                $this->logger->info('File upload detected.', ['options' => $options]);
            } else if ($data !== null && isset($data['file_path']) === true) {
                // Handle file path from JSON payload.
                    $fileSize = 0;
                if (file_exists($data['file_path']) === true) {
                }

                $options = [
                    'updateExisting' => $data['updateExisting'] ?? true,
                    'deleteOrphaned' => $data['deleteOrphaned'] ?? false,
                    'preserveIds'    => $data['preserveIds'] ?? true,
                    'processingMode' => $data['processingMode'] ?? 'speed',
                    'filePath'       => $data['file_path'],
                    'fileName'       => $data['fileName'] ?? basename($data['file_path']),
                    'fileSize'       => $data['fileSize'] ?? $fileSize,
                    'mimeType'       => $data['mimeType'] ?? 'text/xml',
                ];

                $this->logger->info('JSON payload detected.', ['options' => $options]);
            }//end if

            if (isset($options) === false) {
                $this->logger->error(
                        'No file uploaded or file path provided — DETAILED DEBUG',
                        [
                            'uploadedFiles'  => $uploadedFiles,
                            'filesArray'     => $filesArray,
                            'data'           => $data,
                            'rawInput'       => $rawInput,
                            'contentType'    => $contentType,
                            'isMultipart'    => $isMultipart,
                            'requestMethod'  => $this->request->getMethod(),
                            '_FILES_DEBUG'   => $_FILES,
                            '_POST_DEBUG'    => $_POST,
                            'requestParams'  => $this->request->getParams(),
                            'userAgent'      => $this->request->getHeader('User-Agent'),
                            'xRequestedWith' => $this->request->getHeader('X-Requested-With'),
                        ]
                        );

                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => 'No ArchiMate file uploaded or file path provided',
                            'error'   => 'NO_FILE_UPLOADED_OR_PATH',
                            'debug'   => [
                                'contentType' => $contentType,
                                'isMultipart' => $isMultipart,
                                'filesKeys'   => array_keys($_FILES ?? []),
                            ],
                        ],
                        400
                        );
            }//end if

            // OPTIMIZATION: Use optimized method if available or if explicitly requested.
            $useOptimized = $this->request->getParam('useOptimized', 'true') === 'true';
            $hasOptimized = method_exists($this->archiMateService, 'importArchiMateFileFromPathOptimized');
            $this->logger->info('Using STANDARD ArchiMate import method.');
            $result = $this->archiMateService->importArchiMateFileFromPath($options);
            if ($useOptimized === true && $hasOptimized === true) {
                $this->logger->info('Using OPTIMIZED ArchiMate import method.');
                $result = $this->archiMateService->importArchiMateFileFromPathOptimized($options);
            }

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                    'ArchiMate import failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            // Determine appropriate HTTP status code based on error type.
            $statusCode = $this->getHttpStatusForException(e: $e);

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Import failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    $statusCode
                    );
        }//end try
    }//end importArchiMate()

    /**
     * Export to ArchiMate format - returns file directly for download.
     *
     * @return Response File download response or JSON error response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function exportArchiMate(): Response
    {
        try {
            // Get JSON data from request parameters or body.
            $rawInput = file_get_contents('php://input');
            $data     = json_decode($rawInput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Fallback to request parameters if JSON decode fails.
                $data = [
                    'organization' => $this->request->getParam('organization', null),
                ];
            }

            // Simple organization filter - only parameter we support.
            $organization = $data['organization'] ?? null;

            // Call export service with simplified parameters.
            $result = $this->archiMateService->exportToArchiMate($organization);

            // Check if export was successful.
            if ($result['success'] === false) {
                // Determine appropriate status code based on error message.
                $statusCode = $this->getHttpStatusForErrorMessage(message: $result['error'] ?? 'Export failed');

                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => $result['error'] ?? 'Export failed',
                            'error'   => $result['error'] ?? 'EXPORT_FAILED',
                        ],
                        $statusCode
                        );
            }

            // Return the XML file directly for download.
            $fileName   = $result['file_name'] ?? 'archimate_export_'.date('Y-m-d_H-i-s').'.xml';
            $xmlContent = $result['xml'] ?? '<?xml version="1.0" encoding="UTF-8"?><model></model>';

            // Always return XML format.
            $contentType = 'application/xml';

            // Create direct download response.
            $response = new class($xmlContent) extends Response {
                /**
                 * Constructor for the download response.
                 *
                 * @param string $content The XML content to return.
                 *
                 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
                 */
                public function __construct(private string $content)
                {
                                        parent::__construct();
                }//end __construct()

                /**
                 * Render the response content.
                 *
                 * @return string The response content.
                 */
                public function render(): string
                {
                    return $this->content;
                }//end render()
            };

            $response->setStatus(200);
            $response->addHeader('Content-Type', $contentType);
            $response->addHeader('Content-Disposition', 'attachment; filename="'.$fileName.'"');
            $response->addHeader('Content-Length', (string) strlen($xmlContent));
            $response->addHeader('Cache-Control', 'no-cache');

            $this->logger->info(
                    'ArchiMate export completed',
                    [
                        'fileName'         => $fileName,
                        'size'             => strlen($xmlContent),
                        'objects_exported' => $result['statistics']['objects_exported'] ?? 0,
                    ]
                    );

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                    'ArchiMate export failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            // Determine appropriate HTTP status code based on error type.
            $statusCode = $this->getHttpStatusForException(e: $e);

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Export failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
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
     */
    public function exportOrgArchiMate(string $organizationUuid): Response
    {
        try {
            // Read boolean query parameters.
            $modules   = $this->request->getParam('modules', 'true') === 'true';
            $deelnames = $this->request->getParam('deelnames', 'false') === 'true';
            $gebruik   = $this->request->getParam('gebruik', 'false') === 'true';

            $options = [
                'modules'   => $modules,
                'deelnames' => $deelnames,
                'gebruik'   => $gebruik,
            ];

            $result = $this->archiMateService->exportOrgArchiMate(
                organizationUuid: $organizationUuid,
                options: $options
            );

            if ($result['success'] === false) {
                $statusCode = 500;
                if (str_contains(haystack: ($result['error'] ?? ''), needle: 'not found') === true) {
                    $statusCode = 404;
                }

                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => $result['error'] ?? 'Export failed',
                            'error'   => $result['error'] ?? 'EXPORT_FAILED',
                        ],
                        $statusCode
                        );
            }

            $fileName   = $result['file_name'] ?? 'archimate_org_export_'.date('Y-m-d_H-i-s').'.xml';
            $xmlContent = $result['xml'] ?? '<?xml version="1.0" encoding="UTF-8"?><model></model>';

            $response = new class($xmlContent) extends Response {
                /**
                 * Constructor for the org download response.
                 *
                 * @param string $content The XML content to return.
                 *
                 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
                 */
                public function __construct(private string $content)
                {
                                        parent::__construct();
                }//end __construct()

                /**
                 * Render the response content.
                 *
                 * @return string The response content.
                 */
                public function render(): string
                {
                    return $this->content;
                }//end render()
            };

            $response->setStatus(200);
            $response->addHeader('Content-Type', 'application/xml');
            $response->addHeader('Content-Disposition', 'attachment; filename="'.$fileName.'"');
            $response->addHeader('Content-Length', (string) strlen($xmlContent));
            $response->addHeader('Cache-Control', 'no-cache');

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Organization ArchiMate export failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            $statusCode = $this->getHttpStatusForException(e: $e);

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Export failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    $statusCode
                    );
        }//end try
    }//end exportOrgArchiMate()

    /**
     * Download ArchiMate file.
     *
     * @param string $fileName The filename to download.
     *
     * @return Response File download response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function downloadArchiMate(string $fileName): Response
    {
        try {
            // Security: validate filename to prevent path traversal.
            if (strpos(haystack: $fileName, needle: '..') !== false
                || strpos(haystack: $fileName, needle: '/') !== false
            ) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => 'Invalid filename',
                            'error'   => 'INVALID_FILENAME',
                        ],
                        400
                        );
            }

            // Get user folder.
            $userSession = $this->container->get(\OCP\IUserSession::class);
            $rootFolder  = $this->container->get(\OCP\Files\IRootFolder::class);
            $userFolder  = $rootFolder->getUserFolder($userSession->getUser()->getUID());

            // Check if file exists.
            if ($userFolder->nodeExists($fileName) === false) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => 'File not found',
                            'error'   => 'FILE_NOT_FOUND',
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
                            'error'   => 'INVALID_FILE_TYPE',
                        ],
                        400
                        );
            }

            // Determine content type based on file extension.
            $extension   = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $contentType = match ($extension) {
                'xml', 'archimate' => 'application/xml',
                'json' => 'application/json',
                default => 'application/octet-stream'
            };

            // Create download response.
            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $contentType);
            $response->addHeader('Content-Disposition', 'attachment; filename="'.$fileName.'"');
            $response->addHeader('Content-Length', (string) $file->getSize());

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                    'ArchiMate download failed',
                    [
                        'fileName' => $fileName,
                        'error'    => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Download failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test connection result
     */
    public function testEmailConnection(): JSONResponse
    {
        $this->logger->info('SoftwareCatalog: Email connection test endpoint called');

        try {
            $data          = $this->request->getParams();
            $emailSettings = $data['emailSettings'] ?? $data ?? [];

            $this->logger->info(
                    'SoftwareCatalog: Email connection test request data',
                    [
                        'has_email_settings' => empty($emailSettings) === false,
                        'transport_type'     => $emailSettings['transportType'] ?? 'not specified',
                    ]
                    );

            // Call the settings service to test the connection (without sending email).
            $result = $this->settingsService->testEmailConnection($emailSettings);

            $this->logger->info(
                    'SoftwareCatalog: Email connection test result from service',
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
                    'SoftwareCatalog: Failed to test email connection',
                    [
                        'exception_class'   => get_class($e),
                        'exception_message' => $e->getMessage(),
                        'requestData'       => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to test email connection: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end testEmailConnection()

    /**
     * Get email settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Current email settings
     */
    public function getEmailSettings(): JSONResponse
    {
        try {
            $emailSettings = $this->settingsService->getEmailSettings();

            return new JSONResponse(
                    [
                        'success'       => true,
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
                        'message' => 'Failed to get email settings: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getEmailSettings()

    /**
     * Update email settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Update result
     */
    public function updateEmailSettings(): JSONResponse
    {
        try {
            $data          = $this->request->getParams();
            $emailSettings = $data['emailSettings'] ?? $data;

            $result = $this->settingsService->updateEmailSettings($emailSettings);

            return new JSONResponse(
                    [
                        'success'       => $result['success'],
                        'message'       => $result['message'] ?? 'Email settings updated successfully',
                        'emailSettings' => $result['emailSettings'] ?? null,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to update email settings',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to update email settings: '.$e->getMessage(),
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
     */
    public function getEmailTemplates(): JSONResponse
    {
        try {
            // Delegate all business logic to service.
            $templates = $this->settingsService->getAllEmailTemplates();

            return new JSONResponse(
                    [
                        'success'   => true,
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
                        'message' => 'Failed to get email templates: '.$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getEmailTemplate(string $templateName): JSONResponse
    {
        try {
            $template = $this->settingsService->getEmailTemplate($templateName);

            return new JSONResponse(
                    [
                        'success'      => true,
                        'template'     => $template,
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
                        'message' => "Failed to get email template {$templateName}: ".$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function updateEmailTemplate(string $templateName): JSONResponse
    {
        try {
            $data            = $this->request->getParams();
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
            }

            return new JSONResponse(
                    [
                        'success' => $success,
                        'message' => $updateMsg,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    "Failed to update email template {$templateName}",
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => "Failed to update email template {$templateName}: ".$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getEmailTemplateDefault(string $templateName): JSONResponse
    {
        try {
            $defaultTemplate = $this->settingsService->getDefaultEmailTemplate($templateName);

            return new JSONResponse(
                    [
                        'success'      => true,
                        'template'     => $defaultTemplate,
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
                        'message' => "Failed to get default email template {$templateName}: ".$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getEmailTemplateVariables(string $templateName): JSONResponse
    {
        try {
            $variables = $this->settingsService->getEmailTemplateVariables($templateName);

            return new JSONResponse(
                    [
                        'success'      => true,
                        'variables'    => $variables,
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
                        'message' => "Failed to get email template variables for {$templateName}: ".$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Generic user groups
     */
    public function getGenericUserGroups(): JSONResponse
    {
        try {
            $groups = $this->settingsService->getGenericUserGroups();

            return new JSONResponse(
                    [
                        'success' => true,
                        'groups'  => $groups,
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
                        'message' => 'Failed to get generic user groups: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getGenericUserGroups()

    /**
     * Set generic user groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Update result
     */
    public function setGenericUserGroups(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $groups = $data['groups'] ?? [];

            // Delegate all business logic (including validation) to service.
            $result = $this->settingsService->updateGenericUserGroups($groups);

            return new JSONResponse(
                    [
                        'success' => $result['success'],
                        'message' => $result['message'],
                        'groups'  => $result['groups'] ?? null,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to set generic user groups',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to set generic user groups: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end setGenericUserGroups()

    /**
     * Get organization admin groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Organization admin groups
     */
    public function getOrganizationAdminGroups(): JSONResponse
    {
        try {
            $groups = $this->settingsService->getOrganizationAdminGroups();

            return new JSONResponse(
                    [
                        'success' => true,
                        'groups'  => $groups,
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
                        'message' => 'Failed to get organization admin groups: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getOrganizationAdminGroups()

    /**
     * Set organization admin groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Update result
     */
    public function setOrganizationAdminGroups(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $groups = $data['groups'] ?? [];

            // Delegate all business logic (including validation) to service.
            $result = $this->settingsService->updateOrganizationAdminGroups($groups);

            return new JSONResponse(
                    [
                        'success' => $result['success'],
                        'message' => $result['message'],
                        'groups'  => $result['groups'] ?? null,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to set organization admin groups',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to set organization admin groups: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end setOrganizationAdminGroups()

    /**
     * Get super user groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Super user groups
     */
    public function getSuperUserGroups(): JSONResponse
    {
        try {
            $groups = $this->settingsService->getSuperUserGroups();

            return new JSONResponse(
                    [
                        'success' => true,
                        'groups'  => $groups,
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
                        'message' => 'Failed to get super user groups: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getSuperUserGroups()

    /**
     * Set super user groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Update result
     */
    public function setSuperUserGroups(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $groups = $data['groups'] ?? [];

            // Delegate all business logic (including validation) to service.
            $result = $this->settingsService->updateSuperUserGroups($groups);

            return new JSONResponse(
                    [
                        'success' => $result['success'],
                        'message' => $result['message'],
                        'groups'  => $result['groups'] ?? null,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to set super user groups',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to set super user groups: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end setSuperUserGroups()

    /**
     * Get all user groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse All user groups
     */
    public function getAllGroups(): JSONResponse
    {
        try {
            $allGroups = $this->settingsService->getAllGroups();

            return new JSONResponse(
                    [
                        'success' => true,
                        'groups'  => $allGroups,
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
                        'message' => 'Failed to get all groups: '.$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Clear result
     */
    public function clearArchiMateImportStatus(): JSONResponse
    {
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
                        'message' => 'Failed to clear ArchiMate import status: '.$e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function killArchiMateImport(): JSONResponse
    {
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
                        'message' => 'Failed to kill ArchiMate import process: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end killArchiMateImport()

    /**
     * Cancel a running ArchiMate import
     * This combines force clearing and process killing for complete cancellation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Cancellation result
     */
    public function cancelArchiMateImport(): JSONResponse
    {
        try {
            $result = $this->settingsService->cancelArchiMateImport();

                $message = 'ArchiMate import cancellation failed';
            if ($result['cancelled'] === true) {
            }

            return new JSONResponse(
                    [
                        'success' => $result['cancelled'],
                        'message' => $message,
                        'details' => $result,
                    ]
                    );
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
                        'message' => 'Failed to cancel ArchiMate import: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end cancelArchiMateImport()

    /**
     * Clear ArchiMate export status
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Clear result
     */
    public function clearArchiMateExportStatus(): JSONResponse
    {
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
                        'message' => 'Failed to clear ArchiMate export status: '.$e->getMessage(),
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
     */
    public function testArchiMateRoundTrip(): JSONResponse
    {
        try {
            $this->logger->info('SoftwareCatalog: ArchiMate round-trip test started');

            // Call the ArchiMate service to perform round-trip test.
            $result = $this->archiMateService->testRoundTrip();

            $this->logger->info(
                    'SoftwareCatalog: ArchiMate round-trip test completed',
                    [
                        'success' => $result['success'],
                        'message' => $result['message'] ?? 'no message',
                    ]
                    );

            return new JSONResponse(
                    [
                        'success'    => $result['success'],
                        'message'    => $result['message'],
                        'details'    => $result['details'] ?? null,
                        'statistics' => $result['statistics'] ?? null,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'SoftwareCatalog: ArchiMate round-trip test failed',
                    [
                        'exception_class'   => get_class($e),
                        'exception_message' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Round-trip test failed: '.$e->getMessage(),
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
     */
    public function getArchiMateSettings(): JSONResponse
    {
        try {
            $archimateStatus = $this->settingsService->getArchiMateStatus();

            return new JSONResponse(
                    [
                        'success'   => true,
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
                        'message' => 'Failed to get ArchiMate settings: '.$e->getMessage(),
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
     */
    public function getObjectCounts(): JSONResponse
    {
        try {
            $objectCounts = $this->settingsService->getObjectCountsStatistics();

            return new JSONResponse(
                    [
                        'success'      => true,
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
                        'message' => 'Failed to get object counts: '.$e->getMessage(),
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
     */
    public function getArchiMateConfig(): JSONResponse
    {
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
                        'message' => 'Failed to get ArchiMate config: '.$e->getMessage(),
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
     */
    public function updateArchiMateConfig(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
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
                        'message' => 'Failed to update ArchiMate config: '.$e->getMessage(),
                    ],
                    500
                    );
        }
    }//end updateArchiMateConfig()

    /**
     * Get email configuration only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Email configuration
     */
    public function getEmailConfig(): JSONResponse
    {
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
                        'message' => 'Failed to get email config: '.$e->getMessage(),
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
     */
    public function updateEmailConfig(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
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
                        'message' => 'Failed to update email config: '.$e->getMessage(),
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
     */
    public function getAmefConfig(): JSONResponse
    {
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
                        'message' => 'Failed to get AMEF config: '.$e->getMessage(),
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
     */
    public function updateAmefConfig(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
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
                        'message' => 'Failed to update AMEF config: '.$e->getMessage(),
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
     */
    public function getVoorzieningenConfig(): JSONResponse
    {
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
                        'message' => 'Failed to get Voorzieningen config: '.$e->getMessage(),
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
     */
    public function updateVoorzieningenConfig(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
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
                        'message' => 'Failed to update Voorzieningen config: '.$e->getMessage(),
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
     */
    public function getObjectsCounts(): JSONResponse
    {
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
                        'message' => 'Failed to get object counts: '.$e->getMessage(),
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
     */
    public function getObjectsStatistics(): JSONResponse
    {
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
                        'message' => 'Failed to get object statistics: '.$e->getMessage(),
                    ],
                    500
                    );
        }
    }//end getObjectsStatistics()

    /**
     * Get user groups configuration only
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse User groups configuration
     */
    public function getUserGroupsConfig(): JSONResponse
    {
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
                        'message' => 'Failed to get user groups config: '.$e->getMessage(),
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
     */
    public function updateUserGroupsConfig(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
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
                        'message' => 'Failed to update user groups config: '.$e->getMessage(),
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
    private function getHttpStatusForException(\Exception $e): int
    {
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
    private function getHttpStatusForErrorMessage(string $message): int
    {
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The sync results
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-1
     */
    public function syncOrganisations(): JSONResponse
    {
        try {
            $this->logger->info('SettingsController: Starting organisation sync via API');

            // Get request parameters.
            $requestBody = $this->request->getParams();
            $options     = [
                'batch_size' => (int) ($requestBody['batch_size'] ?? 500),
                'dry_run'    => filter_var($requestBody['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];

            $this->logger->debug('SettingsController: Sync options.', $options);

            // Call the settings service method.
            $result = $this->settingsService->syncOrganisationsToVoorzieningenOptimized($options);

                $statusCode = 500;
            if ($result['success'] === true) {
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
                        'message' => 'Organisation sync failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     */
    public function bulkSyncStandards(): JSONResponse
    {
        try {
            $this->logger->info('SettingsController: Starting bulk sync of module standards.');

            // Get the ModuleComplianceService from the container.
            $moduleComplianceService = $this->container->get(\OCA\SoftwareCatalog\Service\ModuleComplianceService::class);

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
                        'data'    => $results,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'SettingsController: Bulk sync failed',
                    [
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Bulk sync failed: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
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
     */
    public function getCronjobConfig(): JSONResponse
    {
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
                        'message' => 'Failed to get cronjob config: '.$e->getMessage(),
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
     */
    public function updateCronjobConfig(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateCronjobConfig($data);

                $statusCode = 400;
            if ($result['success'] === true) {
            }

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to update cronjob config',
                    [
                        'exception'   => $e->getMessage(),
                        'requestData' => $this->request->getParams(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to update cronjob config: '.$e->getMessage(),
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
     */
    public function getCronjobUsers(): JSONResponse
    {
        try {
            $result = $this->settingsService->getAvailableUsersForCronjobs();
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get cronjob users',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to get cronjob users: '.$e->getMessage(),
                        'users'   => [],
                    ],
                    500
                    );
        }
    }//end getCronjobUsers()

    /**
     * Get available organisations for cronjob configuration
     *
     * @deprecated Cronjob context is no longer needed. Will be removed in a future version.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of available organisations
     */
    public function getCronjobOrganisations(): JSONResponse
    {
        try {
            $result = $this->settingsService->getAvailableOrganisationsForCronjobs();
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get cronjob organisations',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return new JSONResponse(
                    [
                        'success'       => false,
                        'message'       => 'Failed to get cronjob organisations: '.$e->getMessage(),
                        'organisations' => [],
                    ],
                    500
                    );
        }
    }//end getCronjobOrganisations()
}//end class
