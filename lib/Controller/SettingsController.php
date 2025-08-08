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
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\StreamResponse;

/**
 * Controller for handling settings-related operations in the OpenCatalogi.
 */
class SettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The OpenRegister object service.
     */
    private $objectService;


    /**
     * SettingsController constructor.
     *
     * @param string                  $appName                The name of the app
     * @param IRequest                $request                The request object
     * @param IAppConfig              $config                 The app configuration
     * @param ContainerInterface      $container              The container
     * @param IAppManager             $appManager             The app manager
     * @param SettingsService         $settingsService        The settings service
     * @param OrganizationSyncService $organizationSyncService The organization sync service
     * @param ArchiMateService        $archiMateService       The ArchiMate import/export service
     * @param ProgressTracker         $progressTracker        The progress tracking service
     * @param LoggerInterface         $logger                 The logger instance
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly OrganizationSyncService $organizationSyncService,
        private readonly ArchiMateService $archiMateService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps())) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        // Check if the 'openregister' app is installed.
        if (in_array('openregister', $this->appManager->getInstalledApps())) {
            // Retrieve the ConfigurationService from the container.
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        // Throw an exception if the service is not available.
        throw new \RuntimeException('Configuration service is not available.');

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
            // Delegate all business logic to service
            $data = $this->settingsService->getAllSettings();
            return new JSONResponse($data);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve settings', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end index()


    /**
     * Handle the post request to update settings.
     *
     * @return JSONResponse JSON response containing the updated settings.
     *
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            
            // Handle different types of settings updates
            $result = [];
            
            // Update schema/register configuration
            if (isset($data['configuration']) || isset($data['selectedRegister'])) {
                $configData = array_filter($data, function($key) {
                    return !in_array($key, ['userGroups', 'emailSettings']);
                }, ARRAY_FILTER_USE_KEY);
                
                if (!empty($configData)) {
                    $result['configuration'] = $this->settingsService->updateSettings($configData);
                }
            }
            
            // Update user groups
            if (isset($data['userGroups'])) {
                $userGroups = $data['userGroups'];
                
                if (isset($userGroups['generic'])) {
                    $validation = $this->settingsService->validateGroups($userGroups['generic']);
                    if (!empty($validation['invalid'])) {
                        return new JSONResponse([
                            'error' => 'Invalid generic group names provided',
                            'validation' => $validation
                        ], 400);
                    }
                    $this->settingsService->setGenericUserGroups($validation['valid']);
                    $result['userGroups']['generic'] = $validation['valid'];
                }
                
                if (isset($userGroups['organizationAdmin'])) {
                    $validation = $this->settingsService->validateGroups($userGroups['organizationAdmin']);
                    if (!empty($validation['invalid'])) {
                        return new JSONResponse([
                            'error' => 'Invalid organization admin group names provided',
                            'validation' => $validation
                        ], 400);
                    }
                    $this->settingsService->setOrganizationAdminGroups($validation['valid']);
                    $result['userGroups']['organizationAdmin'] = $validation['valid'];
                }
                
                if (isset($userGroups['superUser'])) {
                    $validation = $this->settingsService->validateGroups($userGroups['superUser']);
                    if (!empty($validation['invalid'])) {
                        return new JSONResponse([
                            'error' => 'Invalid super user group names provided',
                            'validation' => $validation
                        ], 400);
                    }
                    $this->settingsService->setSuperUserGroups($validation['valid']);
                    $result['userGroups']['superUser'] = $validation['valid'];
                }
            }
            
            // Update email settings
            if (isset($data['emailSettings'])) {
                $result['emailSettings'] = $this->settingsService->updateEmailSettings($data['emailSettings']);
            }
            
            return new JSONResponse([
                'success' => true,
                'data' => $result,
                'message' => 'Settings updated successfully'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update settings', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }

    }//end create()


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
            $this->logger->error('Failed to initialize settings', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }


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
            
            $status = $this->settingsService->getConfigurationStatus();
            $isFullyConfigured = $this->settingsService->isFullyConfigured();
            $versionInfo = $this->settingsService->getVersionInfo();
            
            $responseData = [
                'status' => $status,
                'fullyConfigured' => $isFullyConfigured,
                'versionInfo' => $versionInfo,
                'timestamp' => time(),
                'autoConfigCompleted' => $this->config->getValueString('softwarecatalog', 'auto_config_completed', 'false') === 'true'
            ];
            
            $this->logger->info('SettingsController: Configuration status compiled', [
                'fullyConfigured' => $isFullyConfigured,
                'needsUpdate' => $versionInfo['needsUpdate'] ?? null,
                'versionsMatch' => $versionInfo['versionsMatch'] ?? null
            ]);
            
            return new JSONResponse($responseData);
        } catch (\Exception $e) {
            $this->logger->error('SettingsController: Failed to get configuration status', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return new JSONResponse([
                'error' => $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
    }




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
            if (!empty($configuration)) {
                $result = $this->settingsService->updateSettings($configuration);
                return new JSONResponse([
                    'success' => true,
                    'configuration' => $result
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'No matching registers or schemas found for auto-configuration'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to auto-configure settings', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            return new JSONResponse([
                'success' => true,
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get object counts statistics', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
            $this->logger->error('Failed to get debug information', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

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
            $data = $this->request->getParams();
            $email = $data['email'] ?? '';
            $emailSettings = $data['emailSettings'] ?? [];
            
            // Delegate all business logic (including validation) to service
            $result = $this->settingsService->sendTestEmail($email, $emailSettings);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message']
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: Failed to send test email in controller', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ], 500);
        }
    }

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
    public function getSyncStatus(int $minutesBack = 10): JSONResponse
    {
        $status = $this->organizationSyncService->getSyncStatusWithErrorHandling($minutesBack);
        return new JSONResponse($status);
    }

    /**
     * Perform manual organization synchronization
     *
     * @param int $minutesBack Number of minutes to look back for changes (default: 0 for full sync)
     *
     * @return JSONResponse JSON response containing sync results
     *
     * @NoCSRFRequired
     */
    public function performSync(int $minutesBack = 0): JSONResponse
    {
        $result = $this->organizationSyncService->performManualSync($minutesBack);
        
        if ($result['success']) {
            return new JSONResponse($result);
        } else {
            return new JSONResponse($result, 500);
        }
    }



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
            
            $this->logger->info('SettingsController: Version info retrieved', [
                'version_info' => $data
            ]);
            
            // Add timestamp for cache busting
            $data['timestamp'] = time();
            
            return new JSONResponse($data);
        } catch (\Exception $e) {
            $this->logger->error('SettingsController: Failed to get version info', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return new JSONResponse([
                'error' => $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
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
            $resetConfiguration = isset($params['resetConfiguration']) && $params['resetConfiguration'] === true;
            
            $result = $this->settingsService->resetAutoConfiguration($resetConfiguration);
            
            if ($result['success']) {
                return new JSONResponse($result);
            } else {
                return new JSONResponse($result, 400);
            }
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Reset failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
            $params = $this->request->getParams();
            $forceImport = isset($params['force']) && $params['force'] === true;
            
            $this->logger->info('SettingsController: Starting manual import', [
                'force' => $forceImport
            ]);
            
            $result = $this->settingsService->manualImport($forceImport);
            
            $this->logger->info('SettingsController: Manual import completed', [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'No message'
            ]);
            
            // Add timestamp for cache busting
            $result['timestamp'] = time();
            
            if ($result['success']) {
                return new JSONResponse($result);
            } else {
                return new JSONResponse($result, 400);
            }
        } catch (\Exception $e) {
            $this->logger->error('SettingsController: Manual import failed', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
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
            
            $this->logger->info('SettingsController: Force update completed', [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'No message'
            ]);
            
            // Add timestamp for cache busting
            $result['timestamp'] = time();
            
            return new JSONResponse($result, $result['success'] ? 200 : 500);
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsController: Force update failed', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Force update failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
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
            // Get force parameter from request
            $force = $this->request->getParam('force', false);
            
            // Delegate all business logic to the service
            $results = $this->settingsService->performConsolidatedAutoConfiguration($force);
            
            // Determine HTTP status based on results
            if (!$results['success']) {
                $httpStatus = !empty($results['errors']) ? 207 : 500; // Multi-status or Server Error
            } else {
                $httpStatus = 200; // Success
            }
            
            return new JSONResponse($results, $httpStatus);
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsController: Consolidated auto-configuration failed', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Auto-configuration failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'timestamp' => time()
            ], 500);
        }
    }//end consolidatedAutoConfigure()







    /**
     * Get current progress for an operation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $operationId The operation ID to get progress for
     * 
     * @return JSONResponse The current progress data
     */
    public function getProgress(string $operationId): JSONResponse
    {
        try {
            $progress = $this->progressTracker->getProgress($operationId);
            
            if ($progress === null) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Operation not found',
                    'error' => 'OPERATION_NOT_FOUND'
                ], 404);
            }

            return new JSONResponse([
                'success' => true,
                'progress' => $progress
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get progress', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get progress: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stream progress updates using Server-Sent Events
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $operationId The operation ID to stream progress for
     * 
     * @return Response SSE stream response
     */
    public function streamProgress(string $operationId): Response
    {
        // Set headers for Server-Sent Events
        $response = new class($operationId, $this->progressTracker, $this->logger) extends Response {
            public function __construct(
                private string $operationId,
                private ProgressTracker $progressTracker,
                private LoggerInterface $logger
            ) {
                parent::__construct();
                $this->addHeader('Content-Type', 'text/event-stream');
                $this->addHeader('Cache-Control', 'no-cache');
                $this->addHeader('Connection', 'keep-alive');
                $this->addHeader('Access-Control-Allow-Origin', '*');
                $this->addHeader('Access-Control-Allow-Headers', 'Cache-Control');
            }

            public function render(): string
            {
                // Enable output buffering and turn off compression
                if (ob_get_level()) {
                    ob_end_clean();
                }
                ob_implicit_flush(true);

                // Stream progress updates
                $lastProgress = null;
                $maxAttempts = 300; // 5 minutes with 1-second intervals
                $attempts = 0;

                while ($attempts < $maxAttempts) {
                    try {
                        $progress = $this->progressTracker->getProgress($this->operationId);
                        
                        if ($progress === null) {
                            // Operation not found, send error and close
                            echo "event: error\n";
                            echo "data: " . json_encode(['error' => 'Operation not found']) . "\n\n";
                            break;
                        }

                        // Only send update if progress changed
                        if ($progress !== $lastProgress) {
                            echo "event: progress\n";
                            echo "data: " . json_encode($progress) . "\n\n";
                            $lastProgress = $progress;
                            
                            // If operation completed, send final event and close
                            if ($progress['phase'] === 'completed') {
                                echo "event: completed\n";
                                echo "data: " . json_encode($progress) . "\n\n";
                                break;
                            }
                        }

                        // Send heartbeat every 10 seconds
                        if ($attempts % 10 === 0) {
                            echo "event: heartbeat\n";
                            echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
                        }

                        flush();
                        sleep(1);
                        $attempts++;

                    } catch (\Exception $e) {
                        $this->logger->error('Progress streaming error', [
                            'operation_id' => $this->operationId,
                            'error' => $e->getMessage()
                        ]);

                        echo "event: error\n";
                        echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
                        break;
                    }
                }

                // Send final close event
                echo "event: close\n";
                echo "data: " . json_encode(['reason' => 'Stream ended']) . "\n\n";
                flush();

                return '';
            }
        };

        return $response;
    }

    /**
     * Import ArchiMate file
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse Result of the import operation with progress tracking
     */
    public function importArchiMate(): JSONResponse
    {
        try {
            // Get JSON data from request body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            
            // Check if a file was uploaded (traditional file upload)
            $uploadedFiles = $this->request->getUploadedFile('archiMateFile');
            
            if ($uploadedFiles) {
                // Handle file upload
                $options = [
                    'updateExisting' => $this->request->getParam('updateExisting', 'true') === 'true',
                    'deleteOrphaned' => $this->request->getParam('deleteOrphaned', 'false') === 'true',
                    'preserveIds' => $this->request->getParam('preserveIds', 'true') === 'true',
                    'processingMode' => $this->request->getParam('processingMode', 'speed'),
                    'filePath' => $uploadedFiles['tmp_name'],
                    'fileName' => $uploadedFiles['name'],
                    'fileSize' => $uploadedFiles['size'] ?? filesize($uploadedFiles['tmp_name']),
                    'mimeType' => $uploadedFiles['type']
                ];
            } elseif ($data && isset($data['file_path'])) {
                // Handle file path from JSON payload
                $options = [
                    'updateExisting' => $data['updateExisting'] ?? true,
                    'deleteOrphaned' => $data['deleteOrphaned'] ?? false,
                    'preserveIds' => $data['preserveIds'] ?? true,
                    'processingMode' => $data['processingMode'] ?? 'speed',
                    'filePath' => $data['file_path'],
                    'fileName' => $data['fileName'] ?? basename($data['file_path']),
                    'fileSize' => $data['fileSize'] ?? (file_exists($data['file_path']) ? filesize($data['file_path']) : 0),
                    'mimeType' => $data['mimeType'] ?? 'text/xml'
                ];
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'No ArchiMate file uploaded or file path provided',
                    'error' => 'NO_FILE_UPLOADED_OR_PATH'
                ], 400);
            }

            // Call the ArchiMate service with file data instead of File object
            $result = $this->archiMateService->importArchiMateFileFromPath($options);

            return new JSONResponse($result);

        } catch (\Exception $e) {
            $this->logger->error('ArchiMate import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export to ArchiMate format - returns file directly for download
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return Response File download response or JSON error response
     */
    public function exportArchiMate(): Response
    {
        try {
            // Get JSON data from request parameters or body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Fallback to request parameters if JSON decode fails
                $data = [
                    'format' => $this->request->getParam('format', 'xml'),
                    'includeRelationships' => $this->request->getParam('includeRelationships', true),
                    'includeViews' => $this->request->getParam('includeViews', false),
                    'organizationSpecific' => $this->request->getParam('organizationSpecific', false),
                    'organizationId' => $this->request->getParam('organizationId', null),
                    'organizationFilter' => $this->request->getParam('organizationFilter', null),
                    'selectedSchemas' => $this->request->getParam('selectedSchemas', [])
                ];
            }

            // Get export criteria and options
            $criteria = [
                'organizationSpecific' => $data['organizationSpecific'] ?? false,
                'organizationId' => $data['organizationId'] ?? null,
                'organizationFilter' => $data['organizationFilter'] ?? null,
                'selectedSchemas' => $data['selectedSchemas'] ?? [],
                'includeRelationships' => $data['includeRelationships'] ?? true,
                'includeViews' => $data['includeViews'] ?? false
            ];

            $options = [
                'format' => $data['format'] ?? 'xml'
            ];

            // Call export service
            $result = $this->archiMateService->exportToArchiMate($criteria, $options);

            // Check if export was successful
            if (!$result['success']) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $result['error'] ?? 'Export failed',
                    'error' => $result['error'] ?? 'EXPORT_FAILED'
                ], 500);
            }

            // Return the XML file directly for download
            $fileName = $result['file_name'] ?? 'archimate_export_' . date('Y-m-d_H-i-s') . '.xml';
            $xmlContent = $result['xml_content'] ?? '<?xml version="1.0" encoding="UTF-8"?><model></model>';
            
            // Determine content type based on format
            $format = $options['format'];
            $contentType = match($format) {
                'json' => 'application/json',
                'xml', 'archimate' => 'application/xml',
                default => 'application/xml'
            };

            // Create direct download response
            $response = new class($xmlContent) extends Response {
                public function __construct(private string $content) {
                    parent::__construct();
                }
                
                public function render(): string {
                    return $this->content;
                }
            };
            
            $response->setStatus(200);
            $response->addHeader('Content-Type', $contentType);
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
            $response->addHeader('Content-Length', (string)strlen($xmlContent));
            $response->addHeader('Cache-Control', 'no-cache');
            
            $this->logger->info('ArchiMate export completed', [
                'fileName' => $fileName,
                'size' => strlen($xmlContent),
                'objects_exported' => $result['statistics']['objects_exported'] ?? 0
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('ArchiMate export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download ArchiMate file
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $fileName The filename to download
     * 
     * @return Response File download response
     */
    public function downloadArchiMate(string $fileName): Response
    {
        try {
            // Security: validate filename to prevent path traversal
            if (strpos($fileName, '..') !== false || strpos($fileName, '/') !== false) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Invalid filename',
                    'error' => 'INVALID_FILENAME'
                ], 400);
            }

            // Get user folder
            $userSession = $this->container->get(\OCP\IUserSession::class);
            $rootFolder = $this->container->get(\OCP\Files\IRootFolder::class);
            $userFolder = $rootFolder->getUserFolder($userSession->getUser()->getUID());

            // Check if file exists
            if (!$userFolder->nodeExists($fileName)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'File not found',
                    'error' => 'FILE_NOT_FOUND'
                ], 404);
            }

            $file = $userFolder->get($fileName);
            
            if (!($file instanceof \OCP\Files\File)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Invalid file type',
                    'error' => 'INVALID_FILE_TYPE'
                ], 400);
            }

            // Determine content type based on file extension
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'xml', 'archimate' => 'application/xml',
                'json' => 'application/json',
                default => 'application/octet-stream'
            };

            // Create download response
            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $contentType);
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
            $response->addHeader('Content-Length', (string)$file->getSize());

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('ArchiMate download failed', [
                'fileName' => $fileName,
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Download failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========================================================================
    // EMAIL MANAGEMENT METHODS
    // ========================================================================

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
            $data = $this->request->getParams();
            $emailSettings = $data['emailSettings'] ?? $data ?? [];
            
            $this->logger->info('SoftwareCatalog: Email connection test request data', [
                'has_email_settings' => !empty($emailSettings),
                'transport_type' => $emailSettings['transportType'] ?? 'not specified'
            ]);
            
            // Call the settings service to test the connection (without sending email)
            $result = $this->settingsService->testEmailConnection($emailSettings);
            
            $this->logger->info('SoftwareCatalog: Email connection test result from service', [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'no message'
            ]);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'details' => $result['details'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: Failed to test email connection', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to test email connection: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'emailSettings' => $emailSettings
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get email settings', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get email settings: ' . $e->getMessage()
            ], 500);
        }
    }

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
            $data = $this->request->getParams();
            $emailSettings = $data['emailSettings'] ?? $data;
            
            $result = $this->settingsService->updateEmailSettings($emailSettings);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Email settings updated successfully',
                'emailSettings' => $result['emailSettings'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update email settings', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to update email settings: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========================================================================
    // EMAIL TEMPLATE METHODS
    // ========================================================================

    /**
     * Get all email templates
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse List of available templates
     */
    public function getEmailTemplates(): JSONResponse
    {
        try {
            // Delegate all business logic to service
            $templates = $this->settingsService->getAllEmailTemplates();
            
            return new JSONResponse([
                'success' => true,
                'templates' => $templates
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get email templates', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get email templates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific email template
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $templateName Template name
     * @return JSONResponse Template content
     */
    public function getEmailTemplate(string $templateName): JSONResponse
    {
        try {
            $template = $this->settingsService->getEmailTemplate($templateName);
            
            return new JSONResponse([
                'success' => true,
                'template' => $template,
                'templateName' => $templateName
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to get email template {$templateName}", [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => "Failed to get email template {$templateName}: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update email template
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $templateName Template name
     * @return JSONResponse Update result
     */
    public function updateEmailTemplate(string $templateName): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $templateContent = $data['template'] ?? $data['content'] ?? '';
            
            if (empty($templateContent)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Template content is required'
                ], 400);
            }
            
            $success = $this->settingsService->updateEmailTemplate($templateName, $templateContent);
            
            return new JSONResponse([
                'success' => $success,
                'message' => $success ? "Template {$templateName} updated successfully" : "Failed to update template {$templateName}"
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to update email template {$templateName}", [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => "Failed to update email template {$templateName}: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get default email template
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $templateName Template name
     * @return JSONResponse Default template content
     */
    public function getEmailTemplateDefault(string $templateName): JSONResponse
    {
        try {
            $defaultTemplate = $this->settingsService->getDefaultEmailTemplate($templateName);
            
            return new JSONResponse([
                'success' => true,
                'template' => $defaultTemplate,
                'templateName' => $templateName
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to get default email template {$templateName}", [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => "Failed to get default email template {$templateName}: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get email template variables
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $templateName Template name
     * @return JSONResponse Available variables for template
     */
    public function getEmailTemplateVariables(string $templateName): JSONResponse
    {
        try {
            $variables = $this->settingsService->getEmailTemplateVariables($templateName);
            
            return new JSONResponse([
                'success' => true,
                'variables' => $variables,
                'templateName' => $templateName
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to get email template variables for {$templateName}", [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => "Failed to get email template variables for {$templateName}: " . $e->getMessage()
            ], 500);
        }
    }

    // ========================================================================
    // USER GROUPS MANAGEMENT METHODS
    // ========================================================================

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
            
            return new JSONResponse([
                'success' => true,
                'groups' => $groups
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get generic user groups', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get generic user groups: ' . $e->getMessage()
            ], 500);
        }
    }

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
            $data = $this->request->getParams();
            $groups = $data['groups'] ?? [];
            
            // Delegate all business logic (including validation) to service
            $result = $this->settingsService->updateGenericUserGroups($groups);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'groups' => $result['groups'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to set generic user groups', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to set generic user groups: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'groups' => $groups
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get organization admin groups', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get organization admin groups: ' . $e->getMessage()
            ], 500);
        }
    }

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
            $data = $this->request->getParams();
            $groups = $data['groups'] ?? [];
            
            // Delegate all business logic (including validation) to service
            $result = $this->settingsService->updateOrganizationAdminGroups($groups);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'groups' => $result['groups'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to set organization admin groups', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to set organization admin groups: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'groups' => $groups
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get super user groups', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get super user groups: ' . $e->getMessage()
            ], 500);
        }
    }

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
            $data = $this->request->getParams();
            $groups = $data['groups'] ?? [];
            
            // Delegate all business logic (including validation) to service
            $result = $this->settingsService->updateSuperUserGroups($groups);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'groups' => $result['groups'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to set super user groups', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to set super user groups: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'groups' => $allGroups
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get all groups', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get all groups: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========================================================================
    // ARCHIMATE STATUS MANAGEMENT METHODS
    // ========================================================================

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
            
            return new JSONResponse([
                'success' => true,
                'message' => 'ArchiMate import status cleared successfully',
                'details' => $result
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear ArchiMate import status', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to clear ArchiMate import status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force kill running ArchiMate import process and clear status
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse Kill result
     * @deprecated Use cancelArchiMateImport() instead
     */
    public function killArchiMateImport(): JSONResponse
    {
        try {
            $result = $this->settingsService->killArchiMateImport();
            
            return new JSONResponse([
                'success' => true,
                'message' => 'ArchiMate import termination completed',
                'details' => $result
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to kill ArchiMate import process', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to kill ArchiMate import process: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            $message = $result['cancelled'] 
                ? 'ArchiMate import cancelled successfully'
                : 'ArchiMate import cancellation failed';
            
            return new JSONResponse([
                'success' => $result['cancelled'],
                'message' => $message,
                'details' => $result
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel ArchiMate import', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to cancel ArchiMate import: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'message' => 'ArchiMate export status cleared successfully'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear ArchiMate export status', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to clear ArchiMate export status: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========================================================================
    // ARCHIMATE TESTING METHODS
    // ========================================================================



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
            
            // Call the ArchiMate service to perform round-trip test
            $result = $this->archiMateService->testRoundTrip();
            
            $this->logger->info('SoftwareCatalog: ArchiMate round-trip test completed', [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'no message'
            ]);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'details' => $result['details'] ?? null,
                'statistics' => $result['statistics'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: ArchiMate round-trip test failed', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Round-trip test failed: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'archimate' => $archimateStatus,
                'timestamp' => time()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get ArchiMate settings', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get ArchiMate settings: ' . $e->getMessage()
            ], 500);
        }
    }

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
            $objectCounts = $this->settingsService->getObjectCounts();
            
            return new JSONResponse([
                'success' => true,
                'objectCounts' => $objectCounts
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get object counts', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get object counts: ' . $e->getMessage()
            ], 500);
        }
    }


}//end class


