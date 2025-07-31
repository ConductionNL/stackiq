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
        private readonly ProgressTracker $progressTracker,
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
     * Send a test email
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    public function sendTestEmail(): JSONResponse
    {
        $this->logger->info('SoftwareCatalog: Test email endpoint called');
        
        try {
            $data = $this->request->getParams();
            $email = $data['email'] ?? '';
            $emailSettings = $data['emailSettings'] ?? [];
            
            $this->logger->info('SoftwareCatalog: Test email request data', [
                'email' => $email,
                'has_email_settings' => !empty($emailSettings),
                'transport_type' => $emailSettings['transportType'] ?? 'not specified'
            ]);
            
            if (empty($email)) {
                $this->logger->warning('SoftwareCatalog: Test email request missing email address');
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Email address is required'
                ], 400);
            }
            
            $this->logger->info('SoftwareCatalog: Delegating to SettingsService.sendTestEmail');
            $result = $this->settingsService->sendTestEmail($email, $emailSettings);
            
            $this->logger->info('SoftwareCatalog: Test email result from service', [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'no message'
            ]);
            
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
            $data = $this->settingsService->getVersionInfo();
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
            
            $result = $this->settingsService->manualImport($forceImport);
            
            if ($result['success']) {
                return new JSONResponse($result);
            } else {
                return new JSONResponse($result, 400);
            }
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }//end manualImport()







    /**
     * Get AMEF register configuration settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse The current AMEF configuration settings
     */
    public function getAmefSettings(): JSONResponse
    {
        try {
            $settings = [
                'registerId' => $this->config->getValueString('softwarecatalog', 'amef_register_id', ''),
                'elementsSchema' => $this->config->getValueString('softwarecatalog', 'amef_elements_schema', ''),
                'organizationsSchema' => $this->config->getValueString('softwarecatalog', 'amef_organizations_schema', ''),
                'relationshipsSchema' => $this->config->getValueString('softwarecatalog', 'amef_relationships_schema', ''),
                'viewsSchema' => $this->config->getValueString('softwarecatalog', 'amef_views_schema', ''),
            ];

            // Convert empty strings to null for better UI handling
            foreach ($settings as $key => $value) {
                if (empty($value)) {
                    $settings[$key] = null;
                }
            }

            return new JSONResponse([
                'success' => true,
                'settings' => $settings
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get AMEF settings', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get AMEF settings: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save AMEF register configuration settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse Result of saving the AMEF configuration
     */
    public function saveAmefSettings(): JSONResponse
    {
        try {
            $data = json_decode($this->request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Invalid JSON data',
                    'error' => 'INVALID_JSON'
                ], 400);
            }

            // Save each setting, using empty string for null values
            $settingsMap = [
                'registerId' => 'amef_register_id',
                'elementsSchema' => 'amef_elements_schema',
                'organizationsSchema' => 'amef_organizations_schema', 
                'relationshipsSchema' => 'amef_relationships_schema',
                'viewsSchema' => 'amef_views_schema'
            ];

            foreach ($settingsMap as $jsonKey => $configKey) {
                $value = $data[$jsonKey] ?? null;
                $this->config->setValueString('softwarecatalog', $configKey, (string)($value ?? ''));
            }

            $this->logger->info('AMEF settings saved successfully', [
                'settings' => $data
            ]);

            return new JSONResponse([
                'success' => true,
                'message' => 'AMEF settings saved successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to save AMEF settings', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to save AMEF settings: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Auto-configure AMEF register settings
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse Auto-configuration results
     */
    public function autoConfigureAmef(): JSONResponse
    {
        try {
            $configured = [];
            $errors = [];

            // Get the actual registers and schemas from OpenRegister
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('OpenRegister service not available');
            }

            $registers = $objectService->getRegisters();
            if (empty($registers)) {
                throw new \RuntimeException('No registers available');
            }

            // Look for the vng-gemma register (AMEF schemas)
            $vngGemmaRegister = null;
            $voorzieningenRegister = null;
            
            foreach ($registers as $register) {
                $registerSlug = strtolower($register['slug'] ?? '');
                $registerTitle = strtolower($register['title'] ?? '');
                
                if ($registerSlug === 'vng-gemma' || $registerTitle === 'vng gemma') {
                    $vngGemmaRegister = $register;
                } elseif ($registerSlug === 'voorzieningen' || $registerTitle === 'voorzieningen') {
                    $voorzieningenRegister = $register;
                }
            }

            // If vng-gemma register is not found, try to import it from softwarecatalogus_register.json
            if (!$vngGemmaRegister) {
                $this->logger->info('vng-gemma register not found, attempting to import from softwarecatalogus_register.json');
                
                try {
                    $configurationService = $this->getConfigurationService();
                    if ($configurationService) {
                        $softwareCatalogPath = __DIR__ . '/../Settings/softwarecatalogus_register.json';
                        if (file_exists($softwareCatalogPath)) {
                            $softwareCatalogContent = file_get_contents($softwareCatalogPath);
                            $softwareCatalogSettings = json_decode($softwareCatalogContent, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $currentAppVersion = $this->appManager->getAppVersion(\OCA\SoftwareCatalog\AppInfo\Application::APP_ID);
                                
                                $importResult = $configurationService->importFromJson(
                                    data: $softwareCatalogSettings,
                                    owner: null,
                                    appId: \OCA\SoftwareCatalog\AppInfo\Application::APP_ID,
                                    version: $currentAppVersion,
                                    force: true
                                );
                                
                                $this->logger->info('Imported softwarecatalogus_register.json', ['result' => $importResult]);
                                
                                // Refresh registers after import
                                $registers = $objectService->getRegisters();
                                foreach ($registers as $register) {
                                    $registerSlug = strtolower($register['slug'] ?? '');
                                    if ($registerSlug === 'vng-gemma') {
                                        $vngGemmaRegister = $register;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to import softwarecatalogus_register.json', ['error' => $e->getMessage()]);
                    $errors[] = 'Failed to import vng-gemma register: ' . $e->getMessage();
                }
            }

            // Define AMEF schema mappings with priority order
            $amefSchemaMappings = [
                'elementsSchema' => [
                    'primary' => ['element', 'archimate-element'],
                    'fallback' => ['voorziening', 'product', 'component']
                ],
                'organizationsSchema' => [
                    'primary' => ['organization', 'organisatie'],
                    'fallback' => ['contactpersoon', 'stakeholder']
                ],
                'relationshipsSchema' => [
                    'primary' => ['relation', 'relationship'],
                    'fallback' => ['koppeling', 'connection']
                ],
                'viewsSchema' => [
                    'primary' => ['view', 'diagram', 'extendview'],
                    'fallback' => ['model', 'visualization']
                ]
            ];

            // First try to find schemas in vng-gemma register (AMEF specific)
            if ($vngGemmaRegister && !empty($vngGemmaRegister['schemas'])) {
                $this->logger->info('Found vng-gemma register, configuring AMEF schemas', [
                    'register_id' => $vngGemmaRegister['id'],
                    'schemas' => array_column($vngGemmaRegister['schemas'], 'slug')
                ]);

                // Debug: Log all schema details
                $this->logger->info('Full vng-gemma schema details', [
                    'register_id' => $vngGemmaRegister['id'],
                    'register_title' => $vngGemmaRegister['title'] ?? 'unknown',
                    'register_slug' => $vngGemmaRegister['slug'] ?? 'unknown',
                    'all_schemas' => $vngGemmaRegister['schemas']
                ]);

                foreach ($amefSchemaMappings as $settingKey => $patterns) {
                    if (isset($configured[$settingKey])) {
                        continue; // Already configured
                    }

                    // Try primary patterns first
                    foreach ($patterns['primary'] as $pattern) {
                        foreach ($vngGemmaRegister['schemas'] as $schema) {
                            $schemaSlug = strtolower($schema['slug'] ?? '');
                            $schemaTitle = strtolower($schema['title'] ?? '');
                            
                            if ($schemaSlug === $pattern || $schemaTitle === $pattern || 
                                strpos($schemaSlug, $pattern) !== false || strpos($schemaTitle, $pattern) !== false) {
                                $configured[$settingKey] = $schema['id'];
                                $configKey = 'amef_' . strtolower(str_replace('Schema', '', $settingKey)) . '_schema';
                                $this->config->setValueString('softwarecatalog', $configKey, (string)$schema['id']);
                                
                                $this->logger->info("Configured AMEF schema: {$settingKey} -> {$schema['id']} ({$schema['title']}) [slug: {$schema['slug']}]");
                                break 2;
                            }
                        }
                    }
                }
            }

            // Fallback to voorzieningen register schemas
            if ($voorzieningenRegister && !empty($voorzieningenRegister['schemas'])) {
                $this->logger->info('Using voorzieningen register as fallback for AMEF schemas', [
                    'register_id' => $voorzieningenRegister['id'],
                    'schemas' => array_column($voorzieningenRegister['schemas'], 'slug')
                ]);

                foreach ($amefSchemaMappings as $settingKey => $patterns) {
                    if (isset($configured[$settingKey])) {
                        continue; // Already configured from vng-gemma
                    }

                    // Try fallback patterns
                    foreach ($patterns['fallback'] as $pattern) {
                        foreach ($voorzieningenRegister['schemas'] as $schema) {
                            $schemaSlug = strtolower($schema['slug'] ?? '');
                            $schemaTitle = strtolower($schema['title'] ?? '');
                            
                            if ($schemaSlug === $pattern || $schemaTitle === $pattern || 
                                strpos($schemaSlug, $pattern) !== false || strpos($schemaTitle, $pattern) !== false) {
                                $configured[$settingKey] = $schema['id'];
                                $configKey = 'amef_' . strtolower(str_replace('Schema', '', $settingKey)) . '_schema';
                                $this->config->setValueString('softwarecatalog', $configKey, (string)$schema['id']);
                                
                                $this->logger->info("Configured AMEF fallback schema: {$settingKey} -> {$schema['id']} ({$schema['title']}) [slug: {$schema['slug']}]");
                                break 2;
                            }
                        }
                    }
                }
            }

            // Set default register ID for AMEF operations
            if ($vngGemmaRegister) {
                $this->config->setValueString('softwarecatalog', 'amef_register_id', (string)$vngGemmaRegister['id']);
                $configured['registerId'] = $vngGemmaRegister['id'];
                $this->logger->info("Set AMEF register to vng-gemma: {$vngGemmaRegister['id']}");
            } elseif ($voorzieningenRegister) {
                $this->config->setValueString('softwarecatalog', 'amef_register_id', (string)$voorzieningenRegister['id']);
                $configured['registerId'] = $voorzieningenRegister['id'];
                $this->logger->info("Set AMEF register to voorzieningen: {$voorzieningenRegister['id']}");
            }

            // Check what's missing
            $missingSchemas = [];
            foreach (array_keys($amefSchemaMappings) as $schemaType) {
                if (!isset($configured[$schemaType])) {
                    $missingSchemas[] = $schemaType;
                }
            }

            if (!empty($missingSchemas)) {
                $errors[] = 'Could not find schemas for: ' . implode(', ', $missingSchemas);
                
                if ($vngGemmaRegister && !empty($vngGemmaRegister['schemas'])) {
                    $vngGemmaSchemas = array_map(function($schema) {
                        return $schema['slug'] . ' (' . $schema['title'] . ')';
                    }, $vngGemmaRegister['schemas']);
                    $errors[] = 'Available schemas in vng-gemma: ' . implode(', ', $vngGemmaSchemas);
                } else {
                    $errors[] = 'Available schemas in vng-gemma: none (register not found or no schemas)';
                }
                
                if ($voorzieningenRegister && !empty($voorzieningenRegister['schemas'])) {
                    $voorzieningenSchemas = array_map(function($schema) {
                        return $schema['slug'] . ' (' . $schema['title'] . ')';
                    }, $voorzieningenRegister['schemas']);
                    $errors[] = 'Available schemas in voorzieningen: ' . implode(', ', $voorzieningenSchemas);
                } else {
                    $errors[] = 'Available schemas in voorzieningen: none (register not found or no schemas)';
                }
                
                $errors[] = 'Expected vng-gemma schemas: element, model, organization, property-definition, relation, view, extendview';
                $errors[] = 'Try running manual import to ensure softwarecatalogus_register.json is properly imported into OpenRegister';
            }

            $this->logger->info('AMEF auto-configuration completed', [
                'configured' => $configured,
                'errors' => $errors,
                'vng_gemma_register' => $vngGemmaRegister ? $vngGemmaRegister['id'] : null,
                'voorzieningen_register' => $voorzieningenRegister ? $voorzieningenRegister['id'] : null
            ]);

            return new JSONResponse([
                'success' => !empty($configured),
                'message' => !empty($configured) ? 'AMEF configuration updated successfully' : 'No suitable schemas found',
                'configured' => $configured,
                'errors' => $errors,
                'register_info' => [
                    'vng_gemma_found' => $vngGemmaRegister !== null,
                    'voorzieningen_found' => $voorzieningenRegister !== null
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('AMEF auto-configuration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Auto-configuration failed: ' . $e->getMessage(),
                'errors' => [$e->getMessage()]
            ], 500);
        }
    }

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
            // Check if a file was uploaded
            $uploadedFiles = $this->request->getUploadedFile('archiMateFile');
            if (!$uploadedFiles) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'No ArchiMate file uploaded',
                    'error' => 'NO_FILE_UPLOADED'
                ], 400);
            }

            // Start progress tracking
            $operationId = $this->progressTracker->startOperation('import', [
                'filename' => $uploadedFiles['name'],
                'filesize' => $uploadedFiles['size'] ?? filesize($uploadedFiles['tmp_name'])
            ]);

            // Create mock file object for the service
            $tempPath = $uploadedFiles['tmp_name'];
            $fileName = $uploadedFiles['name'];
            $mimeType = $uploadedFiles['type'];
            
            // Create a mock file object
            $mockFile = new class($tempPath, $fileName, $mimeType) implements \OCP\Files\File {
                public function __construct(
                    private string $path,
                    private string $name,
                    private string $mimeType
                ) {}
                
                public function getName() { return $this->name; }
                public function getSize($includeMounts = true) { return filesize($this->path); }
                public function getMimeType() { return $this->mimeType; }
                public function getContent() { return file_get_contents($this->path); }
                
                // Stub implementations for required interface methods
                public function putContent($data) { return file_put_contents($this->path, $data); }
                public function fopen($mode) { return fopen($this->path, $mode); }
                public function hash($type, $raw = false) { return hash_file($type, $this->path, $raw); }
                public function getId() { return 0; }
                public function stat() { return stat($this->path); }
                public function getMTime() { return filemtime($this->path); }
                public function getEtag() { return md5_file($this->path); }
                public function getPermissions() { return 0644; }
                public function isReadable() { return is_readable($this->path); }
                public function isUpdateable() { return is_writable($this->path); }
                public function isDeletable() { return is_writable(dirname($this->path)); }
                public function isShareable() { return true; }
                public function getParent() { throw new \Exception('Not implemented'); }
                public function getPath() { return $this->path; }
                public function getInternalPath() { return $this->path; }
                public function getStorage() { throw new \Exception('Not implemented'); }
                public function getStorageId() { return 'local'; }
                public function touch($mtime = null) { if ($mtime) touch($this->path, $mtime); }
                public function getChecksum() { return ''; }
                public function delete() { unlink($this->path); }
                public function copy($targetPath) { throw new \Exception('Not implemented'); }
                public function move($targetPath) { throw new \Exception('Not implemented'); }
                public function lock($type) {}
                public function changeLock($targetType) {}
                public function unlock($type) {}
                public function getOwner() { return null; }
                public function getCreationTime(): int { return filectime($this->path); }
                public function getUploadTime(): int { return $this->getMTime(); }
                public function isEncrypted() { return false; }
                public function getMountPoint() { throw new \Exception('Not implemented'); }
                public function getFileInfo() { throw new \Exception('Not implemented'); }
                public function getExtension(): string { return pathinfo($this->path, PATHINFO_EXTENSION); }
                public function getMimePart() { return $this->mimeType; }
                public function getType() { return \OCP\Files\FileInfo::TYPE_FILE; }
                public function getDirectoryListing() { return []; }
            };

            // Get import options from request
            $options = [
                'updateExisting' => $this->request->getParam('updateExisting', 'true') === 'true',
                'preserveIds' => $this->request->getParam('preserveIds', 'true') === 'true',
                'organizationFilter' => $this->request->getParam('organizationFilter', null),
                'progressTracker' => $this->progressTracker
            ];

            $this->progressTracker->setPhase('validating');

            // Call the ArchiMate service
            $result = $this->archiMateService->importArchiMateFile($mockFile, $options);

            // Complete the operation
            $this->progressTracker->completeOperation($result['statistics'] ?? []);

            // Return result with operation ID for progress tracking
            $result['operation_id'] = $operationId;
            return new JSONResponse($result);

        } catch (\Exception $e) {
            // Log error in progress tracker if available
            if (isset($operationId)) {
                $this->progressTracker->addError('Import failed: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $this->logger->error('ArchiMate import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'operation_id' => $operationId ?? null
            ], 500);
        }
    }

    /**
     * Export to ArchiMate format
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @return JSONResponse Result of the export operation with progress tracking
     */
    public function exportArchiMate(): JSONResponse
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
                    'organizationId' => $this->request->getParam('organizationId', null)
                ];
            }

            // Start progress tracking
            $operationId = $this->progressTracker->startOperation('export', [
                'format' => $data['format'] ?? 'xml',
                'organization_id' => $data['organizationId'] ?? null
            ]);

            // Get export criteria and options
            $criteria = [
                'organizationId' => $data['organizationId'] ?? null,
                'includeRelationships' => $data['includeRelationships'] ?? true,
                'includeViews' => $data['includeViews'] ?? false
            ];

            $options = [
                'format' => $data['format'] ?? 'xml',
                'organizationSpecific' => $data['organizationSpecific'] ?? false,
                'progressTracker' => $this->progressTracker
            ];

            $this->progressTracker->setPhase('analyzing');

            // Call export service
            $result = $this->archiMateService->exportToArchiMate($criteria, $options);

            // Complete the operation
            $this->progressTracker->completeOperation($result['statistics'] ?? []);

            // Return result with operation ID
            $result['operation_id'] = $operationId;
            return new JSONResponse($result);

        } catch (\Exception $e) {
            // Log error in progress tracker if available
            if (isset($operationId)) {
                $this->progressTracker->addError('Export failed: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $this->logger->error('ArchiMate export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'operation_id' => $operationId ?? null
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


}//end class


