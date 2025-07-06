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
     * @param string             $appName         The name of the app
     * @param IRequest           $request         The request object
     * @param IAppConfig         $config          The app configuration
     * @param ContainerInterface $container       The container
     * @param IAppManager        $appManager      The app manager
     * @param SettingsService    $settingsService The settings service
     * @param LoggerInterface    $logger          The logger instance
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
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
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSettings($data);
            return new JSONResponse($result);
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
     * Get generic user groups configuration
     *
     * @return JSONResponse JSON response containing the generic user groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getGenericUserGroups(): JSONResponse
    {
        try {
            $groups = $this->settingsService->getGenericUserGroups();
            return new JSONResponse([
                'groups' => $groups,
                'allGroups' => $this->settingsService->getAllGroups()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get generic user groups', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update generic user groups configuration
     *
     * @return JSONResponse JSON response containing the update results
     *
     * @NoCSRFRequired
     */
    public function updateGenericUserGroups(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $groups = $data['groups'] ?? [];

            // Validate groups
            $validation = $this->settingsService->validateGroups($groups);
            
            if (!empty($validation['invalid'])) {
                return new JSONResponse([
                    'error' => 'Invalid group names provided',
                    'validation' => $validation
                ], 400);
            }

            // Update the groups
            $this->settingsService->setGenericUserGroups($validation['valid']);
            
            return new JSONResponse([
                'success' => true,
                'groups' => $validation['valid'],
                'message' => 'Generic user groups updated successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update generic user groups', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate generic user groups
     *
     * @return JSONResponse JSON response containing the validation results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function validateGenericUserGroups(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $groups = $data['groups'] ?? [];

            $validation = $this->settingsService->validateGroups($groups);
            
            return new JSONResponse($validation);
        } catch (\Exception $e) {
            $this->logger->error('Failed to validate generic user groups', [
                'exception' => $e->getMessage(),
                'requestData' => $this->request->getParams()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Ensure generic user groups exist
     *
     * @return JSONResponse JSON response containing the results
     *
     * @NoCSRFRequired
     */
    public function ensureGenericUserGroups(): JSONResponse
    {
        try {
            // For this to work, we need access to the group management functionality
            // This might require additional service integration
            return new JSONResponse([
                'message' => 'Group creation requires group management service integration',
                'groups' => $this->settingsService->getGenericUserGroups()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to ensure generic user groups', [
                'exception' => $e->getMessage()
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

}//end class
