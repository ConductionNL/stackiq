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
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use Psr\Log\LoggerInterface;

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
            $data = $this->settingsService->getSettings();
            
            // Add user group configurations
            $data['userGroups'] = [
                'generic' => $this->settingsService->getGenericUserGroups(),
                'organizationAdmin' => $this->settingsService->getOrganizationAdminGroups(),
                'superUser' => $this->settingsService->getSuperUserGroups()
            ];
            
            // Add email settings
            $data['emailSettings'] = $this->settingsService->getEmailSettings();
            
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
            $status = $this->settingsService->getConfigurationStatus();
            $isFullyConfigured = $this->settingsService->isFullyConfigured();
            
            return new JSONResponse([
                'status' => $status,
                'fullyConfigured' => $isFullyConfigured
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get configuration status', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
     * Send test email
     *
     * @return JSONResponse JSON response containing test email results
     *
     * @NoCSRFRequired
     */
    public function sendTestEmail(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $email = $data['email'] ?? '';
            $emailSettings = $data['emailSettings'] ?? [];
            
            if (empty($email)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Email address is required'
                ], 400);
            }
            
            $result = $this->settingsService->sendTestEmail($email, $emailSettings);
            
            return new JSONResponse([
                'success' => $result['success'],
                'message' => $result['message']
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send test email', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get organization synchronization status
     *
     * @return JSONResponse JSON response containing sync status information
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getSyncStatus(): JSONResponse
    {
        try {
            $status = $this->organizationSyncService->getSyncStatus();
            return new JSONResponse($status);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get sync status', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse([
                'configured' => false,
                'message' => 'Error getting sync status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform manual organization synchronization
     *
     * @return JSONResponse JSON response containing sync results
     *
     * @NoCSRFRequired
     */
    public function performSync(): JSONResponse
    {
        try {
            $this->logger->info('Manual organization synchronization started via API');
            
            $syncResults = $this->organizationSyncService->performFullSync();
            
            // Record the sync time
            $this->organizationSyncService->recordSyncTime();
            
            $this->logger->info('Manual organization synchronization completed via API', [
                'organizationsProcessed' => $syncResults['organizationsProcessed'],
                'entitiesCreated' => $syncResults['entitiesCreated'],
                'entitiesUpdated' => $syncResults['entitiesUpdated'],
                'usersCreated' => $syncResults['usersCreated'],
                'errorCount' => count($syncResults['errors'])
            ]);
            
            return new JSONResponse([
                'success' => true,
                'results' => $syncResults,
                'message' => 'Synchronization completed successfully'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Manual organization synchronization failed via API', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Synchronization failed: ' . $e->getMessage()
            ], 500);
        }
    }





}//end class
