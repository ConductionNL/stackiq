<?php

/**
 * Service for handling settings-related operations in the SoftwareCatalog.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCP\IAppConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use OC_App;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

/**
 * Service for handling settings-related operations in the SoftwareCatalog.
 *
 * Provides functionality for retrieving, saving, and loading settings,
 * as well as managing configuration for different object types.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class SettingsService
{
    /**
     * The application name for identification and configuration purposes
     *
     * @var string The name of the app
     */
    private string $_appName;

    /**
     * The unique identifier for the OpenRegister application
     *
     * @var string The ID of the OpenRegister app
     */
    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * The minimum version of the OpenRegister application required
     *
     * @var string The minimum required version of OpenRegister
     */
    private const MIN_OPENREGISTER_VERSION = '0.1.7';

    /**
     * SettingsService constructor
     *
     * @param IAppConfig         $config       App configuration interface
     * @param IRequest           $request      Request interface
     * @param ContainerInterface $container    Container for dependency injection
     * @param IAppManager        $appManager   App manager interface
     * @param LoggerInterface    $logger       Logger interface
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
        $this->_appName = 'softwarecatalog';
    }

    /**
     * Checks if OpenRegister is installed and meets version requirements
     *
     * @param string|null $minVersion Minimum required version
     *
     * @return bool True if OpenRegister is installed and meets version requirements
     */
    public function isOpenRegisterInstalled(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool
    {
        if (!$this->appManager->isInstalled(self::OPENREGISTER_APP_ID)) {
            return false;
        }

        if ($minVersion === null) {
            return true;
        }

        $currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
        return version_compare($currentVersion, $minVersion, '>=');
    }

    /**
     * Checks if OpenRegister is enabled
     *
     * @return bool True if OpenRegister is enabled
     */
    public function isOpenRegisterEnabled(): bool
    {
        return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID);
    }

    /**
     * Attempts to retrieve the OpenRegister service from the container
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available
     *
     * @throws \RuntimeException If the service is not available
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps())) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }

    /**
     * Attempts to retrieve the Configuration service from the container
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available
     *
     * @throws \RuntimeException If the service is not available
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array('openregister', $this->appManager->getInstalledApps())) {
            return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
        }

        throw new \RuntimeException('Configuration service is not available.');
    }

    /**
     * Retrieve the current settings
     *
     * @return array The current settings configuration
     *
     * @throws \RuntimeException If settings retrieval fails
     */
    public function getSettings(): array
    {
        // Initialize the data array
        $data = [];
        
        // Define the register-specific configuration
        $data['registerTypes'] = [
            'amef' => [
                'name' => 'AMEF',
                'description' => 'AMEF register for architectural elements',
                'objectTypes' => ['organization', 'element', 'relationship', 'view', 'model', 'property'] // Complete AMEF object types
            ],
            'voorzieningen' => [
                'name' => 'Voorzieningen', 
                'description' => 'Voorzieningen register for software catalog services',
                'objectTypes' => ['organisatie', 'contactpersoon'] // Voorzieningen uses organisatie and contactpersoon schemas
            ]
        ];
        
        // Deprecated: For backward compatibility only - use registerTypes instead
        $data['objectTypes'] = [
            'organization',
            'contact',
        ];
        
        $data['openRegisters'] = false;
        $data['availableRegisters'] = [];

        // Check if the OpenRegister service is available
        try {
            $openRegisters = $this->getObjectService();
            if ($openRegisters !== null) {
                $data['openRegisters'] = true;
                $rawRegisters = $openRegisters->getRegisters();
                
                // Filter schemas to remove properties field for cleaner response
                $data['availableRegisters'] = array_map(function($register) {
                    if (isset($register['schemas']) && is_array($register['schemas'])) {
                        $register['schemas'] = array_map(function($schema) {
                            // Keep only essential schema fields, remove properties
                            return array_filter($schema, function($key) {
                                return !in_array($key, ['properties']);
                            }, ARRAY_FILTER_USE_KEY);
                        }, $register['schemas']);
                    }
                    return $register;
                }, $rawRegisters);
            }
        } catch (\RuntimeException $e) {
            // Service not available, continue with default values
            $this->logger->info(
                'OpenRegister service not available',
                [
                    'exception' => $e->getMessage()
                ]
            );
        }

        // Build defaults array dynamically based on register types and their object types
        $defaults = [];
        foreach ($data['registerTypes'] as $registerType => $config) {
            foreach ($config['objectTypes'] as $objectType) {
                // Always use openregister as source
                $defaults["{$registerType}_{$objectType}_source"] = 'openregister';
                $defaults["{$registerType}_{$objectType}_schema"] = '';
                $defaults["{$registerType}_{$objectType}_register"] = '';
            }
        }
        
        // Also maintain backward compatibility for the old structure
        foreach ($data['objectTypes'] as $type) {
            $defaults["{$type}_source"] = 'openregister';
            $defaults["{$type}_schema"] = '';
            $defaults["{$type}_register"] = '';
        }
        
        // Note: Old individual config keys are no longer used
        // They are maintained only for backward compatibility during migration

        // Get the current values from the configuration
        try {
            foreach ($defaults as $key => $defaultValue) {
                $data['configuration'][$key] = $this->config->getValueString($this->_appName, $key, $defaultValue);
            }

            return $data;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to retrieve settings: ' . $e->getMessage());
        }
    }

    /**
     * Update the settings configuration
     *
     * @param array $data The settings data to update
     *
     * @return array The updated settings configuration
     *
     * @throws \RuntimeException If settings update fails
     */
    public function updateSettings(array $data): array
    {
        try {
            // Update each setting in the configuration
            foreach ($data as $key => $value) {
                // Ensure value is converted to string as required by setValueString
                $stringValue = is_string($value) ? $value : (string) $value;
                $this->config->setValueString($this->_appName, $key, $stringValue);
                // Retrieve the updated value to confirm the change
                $data[$key] = $this->config->getValueString($this->_appName, $key);
            }

            $this->logger->info(
                'Settings updated successfully',
                [
                    'updatedKeys' => array_keys($data)
                ]
            );

            return $data;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update settings: ' . $e->getMessage());
        }
    }

    /**
     * Attempts to auto-configure registers and schemas
     *
     * @return array The updated configuration
     *
     * @throws \RuntimeException If auto-configuration fails
     */
    /**
     * Auto-configure settings based on available registers and schemas
     * This method now uses the consolidated auto-configuration logic
     *
     * @param bool $force Whether to force reload regardless of version
     * @return array The auto-configuration results
     *
     * @throws \RuntimeException If auto-configuration fails
     */
    public function autoConfigure(bool $force = false): array
    {
        return $this->performConsolidatedAutoConfiguration($force);
    }

    /**
     * Auto-configures settings specifically after importing the softwarecatalogus_register.json
     * 
     * This method looks for the voorzieningen register and automatically configures
     * the organisatie and contactpersoon schemas, and creates required user groups.
     *
     * @return array The updated configuration
     *
     * @throws \RuntimeException If auto-configuration fails
     */
    public function autoConfigureAfterImport(): array
    {
        try {
            // Check if auto-configuration has already been completed
            $autoConfigCompleted = $this->config->getValueString($this->_appName, 'auto_config_completed', 'false') === 'true';
            if ($autoConfigCompleted) {
                $this->logger->info('Auto-configuration already completed, skipping');
                return [];
            }

            $objectService = $this->getObjectService();
            $registers = $objectService->getRegisters();

            if (empty($registers)) {
                $this->logger->info('No registers available for auto-configuration after import');
                return [];
            }

            $configuration = [];
            
            // Step 1: Create required user groups
            $this->logger->info('Creating required user groups');
            $this->createRequiredUserGroups();
            $this->logger->info('User groups created successfully');
            
            // Look for the voorzieningen register
            $voorzieningenRegister = null;
            foreach ($registers as $register) {
                $registerTitle = strtolower($register['title'] ?? '');
                $registerSlug = strtolower($register['slug'] ?? '');
                
                if (stripos($registerTitle, 'voorzieningen') !== false || 
                    stripos($registerSlug, 'voorzieningen') !== false ||
                    $registerTitle === 'voorzieningen' ||
                    $registerSlug === 'voorzieningen') {
                    $voorzieningenRegister = $register;
                    break;
                }
            }

            if ($voorzieningenRegister === null) {
                $this->logger->info('No voorzieningen register found for auto-configuration after import');
                return [];
            }

            $this->logger->info('Found voorzieningen register for auto-configuration', [
                'register_id' => $voorzieningenRegister['id'],
                'register_title' => $voorzieningenRegister['title'],
                'schemas_count' => count($voorzieningenRegister['schemas'] ?? [])
            ]);

            // Configure schemas within the voorzieningen register
            if (!empty($voorzieningenRegister['schemas'])) {
                foreach ($voorzieningenRegister['schemas'] as $schema) {
                    $schemaTitle = strtolower($schema['title'] ?? '');
                    $schemaSlug = strtolower($schema['slug'] ?? '');
                    
                    // Look for organisatie schema
                    if (stripos($schemaTitle, 'organisatie') !== false || 
                        stripos($schemaSlug, 'organisatie') !== false ||
                        $schemaTitle === 'organisatie' ||
                        $schemaSlug === 'organisatie') {
                        
                                                                         // Set voorzieningen_organisatie configuration
                        $configuration['voorzieningen_organisatie_source'] = 'openregister';
                        $configuration['voorzieningen_organisatie_register'] = (string) $voorzieningenRegister['id'];
                        $configuration['voorzieningen_organisatie_schema'] = (string) $schema['id'];
                        
                        // Set sync-compatible configuration (OrganizationSyncService expects this key)
                        $configuration['voorzieningen_register'] = (string) $voorzieningenRegister['id'];
                        
                        // Also set backward compatibility organization configuration
                        $configuration['organization_source'] = 'openregister';
                        $configuration['organization_register'] = (string) $voorzieningenRegister['id'];
                        $configuration['organization_schema'] = (string) $schema['id'];
                        
                        $this->logger->info('Configured organisatie schema', [
                            'schema_id' => $schema['id'],
                            'schema_title' => $schema['title']
                        ]);
                    }
                    // Look for contactpersoon schema
                    else if (stripos($schemaTitle, 'contactpersoon') !== false || 
                             stripos($schemaSlug, 'contactpersoon') !== false ||
                             $schemaTitle === 'contactpersoon' ||
                             $schemaSlug === 'contactpersoon') {
                        
                                                                         // Set voorzieningen_contactpersoon configuration
                        $configuration['voorzieningen_contactpersoon_source'] = 'openregister';
                        $configuration['voorzieningen_contactpersoon_register'] = (string) $voorzieningenRegister['id'];
                        $configuration['voorzieningen_contactpersoon_schema'] = (string) $schema['id'];
                        
                        // Set sync-compatible configuration (OrganizationSyncService expects this key)
                        $configuration['voorzieningen_register'] = (string) $voorzieningenRegister['id'];
                        
                        // Also set backward compatibility contact configuration
                        $configuration['contact_source'] = 'openregister';
                        $configuration['contact_register'] = (string) $voorzieningenRegister['id'];
                        $configuration['contact_schema'] = (string) $schema['id'];
                        
                        $this->logger->info('Configured contactpersoon schema', [
                            'schema_id' => $schema['id'],
                            'schema_title' => $schema['title']
                        ]);
                    }
                }
            }

            if (empty($configuration)) {
                $this->logger->info('No matching schemas found in voorzieningen register for auto-configuration');
            } else {
                $this->logger->info('Auto-configuration after import completed successfully', [
                    'configuration_keys' => array_keys($configuration),
                    'register_used' => $voorzieningenRegister['title']
                ]);
            }

            // Mark auto-configuration as completed
            $this->config->setValueString($this->_appName, 'auto_config_completed', 'true');
            $this->logger->info('Auto-configuration marked as completed');

            return $configuration;
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to auto-configure after import: ' . $e->getMessage());
        }
    }

    /**
     * Gets the configured schema ID for a specific object type
     *
     * @param string $objectType The object type (organization, contact, gebruiker, contactpersoon)
     *
     * @return int|null The schema ID or null if not configured
     */
    public function getSchemaIdForObjectType(string $objectType): ?int
    {
        $startTime = microtime(true);
        
        $this->logger->debug("SettingsService: Starting schema ID lookup", [
            'objectType' => $objectType,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        // First try register-specific configuration
        // Check for AMEF register specific schemas
        if ($objectType === 'organization') {
            $this->logger->debug("SettingsService: Checking AMEF organization schema", [
                'objectType' => $objectType
            ]);
            
            $schemaId = $this->config->getValueString($this->_appName, 'amef_organization_schema', '');
            
            $this->logger->debug("SettingsService: AMEF organization schema result", [
                'objectType' => $objectType,
                'configKey' => 'amef_organization_schema',
                'rawValue' => $schemaId,
                'isEmpty' => empty($schemaId)
            ]);
            
            if (!empty($schemaId)) {
                $result = (int) $schemaId;
                $this->logger->info("SettingsService: Found AMEF organization schema", [
                    'objectType' => $objectType,
                    'schemaId' => $result,
                    'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
                return $result;
            }
            
            // Also check voorzieningen register for organization/organisatie
            $this->logger->debug("SettingsService: Checking voorzieningen organisatie schema for organization", [
                'objectType' => $objectType
            ]);
            
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', '');
            
            $this->logger->debug("SettingsService: Voorzieningen organisatie schema result", [
                'objectType' => $objectType,
                'configKey' => 'voorzieningen_organisatie_schema',
                'rawValue' => $schemaId,
                'isEmpty' => empty($schemaId)
            ]);
            
            if (!empty($schemaId)) {
                $result = (int) $schemaId;
                $this->logger->info("SettingsService: Found voorzieningen organisatie schema for organization", [
                    'objectType' => $objectType,
                    'schemaId' => $result,
                    'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
                return $result;
            }
        }
        
        if ($objectType === 'organisatie') {
            $this->logger->debug("SettingsService: Checking voorzieningen organisatie schema", [
                'objectType' => $objectType
            ]);
            
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', '');
            
            $this->logger->debug("SettingsService: Voorzieningen organisatie schema result", [
                'objectType' => $objectType,
                'configKey' => 'voorzieningen_organisatie_schema',
                'rawValue' => $schemaId,
                'isEmpty' => empty($schemaId)
            ]);
            
            if (!empty($schemaId)) {
                $result = (int) $schemaId;
                $this->logger->info("SettingsService: Found voorzieningen organisatie schema", [
                    'objectType' => $objectType,
                    'schemaId' => $result,
                    'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
                return $result;
            }
        }
        
        if ($objectType === 'contactpersoon') {
            $this->logger->debug("SettingsService: Checking voorzieningen contactpersoon schema", [
                'objectType' => $objectType
            ]);
            
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_schema', '');
            
            $this->logger->debug("SettingsService: Voorzieningen contactpersoon schema result", [
                'objectType' => $objectType,
                'configKey' => 'voorzieningen_contactpersoon_schema',
                'rawValue' => $schemaId,
                'isEmpty' => empty($schemaId)
            ]);
            
            if (!empty($schemaId)) {
                $result = (int) $schemaId;
                $this->logger->info("SettingsService: Found voorzieningen contactpersoon schema", [
                    'objectType' => $objectType,
                    'schemaId' => $result,
                    'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
                return $result;
            }
        }
        
        // Fall back to generic configuration for backward compatibility
        $this->logger->debug("SettingsService: Checking generic configuration", [
            'objectType' => $objectType,
            'configKey' => "{$objectType}_schema"
        ]);
        
        $schemaId = $this->config->getValueString($this->_appName, "{$objectType}_schema", '');
        
        $this->logger->debug("SettingsService: Generic configuration result", [
            'objectType' => $objectType,
            'configKey' => "{$objectType}_schema",
            'rawValue' => $schemaId,
            'isEmpty' => empty($schemaId)
        ]);
        
        if ($schemaId) {
            $result = (int) $schemaId;
            $this->logger->info("SettingsService: Found generic schema configuration", [
                'objectType' => $objectType,
                'schemaId' => $result,
                'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            return $result;
        }
        
        $this->logger->warning("SettingsService: No schema ID found for object type", [
            'objectType' => $objectType,
            'checkedConfigurations' => [
                'amef_organization_schema' => ($objectType === 'organization'),
                'voorzieningen_organisatie_schema' => ($objectType === 'organization' || $objectType === 'organisatie'),
                'voorzieningen_contactpersoon_schema' => ($objectType === 'contactpersoon'),
                "{$objectType}_schema" => true
            ],
            'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ]);
        
        return null;
    }

    /**
     * Gets the configured register ID for a specific object type
     *
     * @param string $objectType The object type (organization, contact, gebruiker)
     *
     * @return int|null The register ID or null if not configured
     */
    public function getRegisterIdForObjectType(string $objectType): ?int
    {
        $registerId = $this->config->getValueString($this->_appName, "{$objectType}_register", '');
        return $registerId ? (int) $registerId : null;
    }

    /**
     * Gets the configured register ID for the voorzieningen register
     *
     * @return int|null The register ID or null if not configured
     */
    public function getVoorzieningenRegisterId(): ?int
    {
        $startTime = microtime(true);
        
        $this->logger->debug("SettingsService: Starting voorzieningen register ID lookup", [
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        // Try voorzieningen-specific configuration first
        $this->logger->debug("SettingsService: Checking voorzieningen organisatie register", [
            'configKey' => 'voorzieningen_organisatie_register'
        ]);
        
        $registerId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_register', '');
        
        $this->logger->debug("SettingsService: Voorzieningen organisatie register result", [
            'configKey' => 'voorzieningen_organisatie_register',
            'rawValue' => $registerId,
            'isEmpty' => empty($registerId)
        ]);
        
        if (!empty($registerId)) {
            $result = (int) $registerId;
            $this->logger->info("SettingsService: Found voorzieningen organisatie register", [
                'registerId' => $result,
                'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            return $result;
        }
        
        // Also try contactpersoon as fallback
        $this->logger->debug("SettingsService: Checking voorzieningen contactpersoon register", [
            'configKey' => 'voorzieningen_contactpersoon_register'
        ]);
        
        $registerId = $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_register', '');
        
        $this->logger->debug("SettingsService: Voorzieningen contactpersoon register result", [
            'configKey' => 'voorzieningen_contactpersoon_register',
            'rawValue' => $registerId,
            'isEmpty' => empty($registerId)
        ]);
        
        if (!empty($registerId)) {
            $result = (int) $registerId;
            $this->logger->info("SettingsService: Found voorzieningen contactpersoon register", [
                'registerId' => $result,
                'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            return $result;
        }
        
        // Fall back to organization register for backward compatibility
        $this->logger->debug("SettingsService: Checking organization register for backward compatibility", [
            'configKey' => 'organization_register'
        ]);
        
        $result = $this->getRegisterIdForObjectType('organization');
        
        if ($result !== null) {
            $this->logger->info("SettingsService: Found organization register for backward compatibility", [
                'registerId' => $result,
                'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            return $result;
        }
        
        $this->logger->warning("SettingsService: No register ID found for voorzieningen", [
            'checkedConfigurations' => [
                'voorzieningen_organisatie_register' => true,
                'voorzieningen_contactpersoon_register' => true,
                'organization_register' => true
            ],
            'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ]);
        
        return null;
    }

    /**
     * Checks if all required object types are configured
     *
     * @return bool True if all object types have schemas configured
     */
    public function isFullyConfigured(): bool
    {
        $objectTypes = ['organization', 'contact'];
        
        foreach ($objectTypes as $type) {
            $schemaId = $this->getSchemaIdForObjectType($type);
            if (!$schemaId) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Gets configuration status for each object type
     *
     * @return array Configuration status information
     */
    public function getConfigurationStatus(): array
    {
        $objectTypes = ['organization', 'contact'];
        $status = [];
        
        foreach ($objectTypes as $type) {
            $schemaId = $this->getSchemaIdForObjectType($type);
            $registerId = $this->getRegisterIdForObjectType($type);
            
            $status[$type] = [
                'configured' => !empty($schemaId) && !empty($registerId),
                'schemaId' => $schemaId,
                'registerId' => $registerId,
            ];
        }
        
        return $status;
    }

    /**
     * Initializes the app with all required components
     *
     * @param string|null $minOpenRegisterVersion Minimum required OpenRegister version
     *
     * @return array The initialization results
     */
    public function initialize(?string $minOpenRegisterVersion = self::MIN_OPENREGISTER_VERSION): array
    {
        $startTime = microtime(true);
        $results = [
            'openRegister' => false,
            'autoConfigured' => false,
            'fullyConfigured' => false,
            'settingsLoaded' => false,
            'configurationImported' => false,
            'autoConfigAfterImport' => false,
            'errors' => [],
            'warnings' => [],
            'timing' => []
        ];

        $this->logger->info('SettingsService: Starting initialization', [
            'minOpenRegisterVersion' => $minOpenRegisterVersion
        ]);

        try {
            // Check if OpenRegister is installed and enabled
            $checkStart = microtime(true);
            
            if (!$this->isOpenRegisterInstalled($minOpenRegisterVersion)) {
                $error = 'OpenRegister is not installed or does not meet minimum version requirements';
                $results['errors'][] = $error;
                $this->logger->error('SettingsService: ' . $error);
                return $results;
            }

            if (!$this->isOpenRegisterEnabled()) {
                $error = 'OpenRegister is not enabled';
                $results['errors'][] = $error;
                $this->logger->error('SettingsService: ' . $error);
                return $results;
            }

            $results['openRegister'] = true;
            $results['timing']['openregister_check'] = round((microtime(true) - $checkStart) * 1000, 2) . 'ms';
            
            $this->logger->info('SettingsService: OpenRegister is available');

            // Load settings from file if needed (do this first)
            $loadStart = microtime(true);
            try {
                if ($this->shouldLoadSettings()) {
                    $this->logger->info('SettingsService: Loading settings from file');
                    $loadResult = $this->loadSettings();
                    $results['settingsLoaded'] = true;
                    $results['configurationImported'] = !empty($loadResult['softwarecatalog_imported']);
                    $this->logger->info('SettingsService: Settings loaded successfully', [
                        'imported' => $results['configurationImported']
                    ]);
                } else {
                    $results['settingsLoaded'] = true; // Already up to date
                    $this->logger->info('SettingsService: Settings already up to date');
                }
            } catch (\Exception $e) {
                $error = 'Settings loading failed: ' . $e->getMessage();
                $results['errors'][] = $error;
                $this->logger->error('SettingsService: ' . $error, [
                    'exception' => $e
                ]);
            }
            $results['timing']['settings_load'] = round((microtime(true) - $loadStart) * 1000, 2) . 'ms';

            // Try auto-configuration after import if not already configured
            $autoConfigStart = microtime(true);
            if (!$this->isFullyConfigured()) {
                $this->logger->info('SettingsService: App not fully configured, attempting auto-configuration');
                
                try {
                    // First try the post-import auto-configuration (more specific)
                    $configuration = $this->autoConfigureAfterImport();
                    if (!empty($configuration)) {
                        $this->updateSettings($configuration);
                        $results['autoConfigAfterImport'] = true;
                        $results['autoConfigured'] = true;
                        $this->logger->info('SettingsService: Auto-configuration after import successful', [
                            'configuration' => array_keys($configuration)
                        ]);
                    } else {
                        // Fallback to general auto-configuration
                        $this->logger->info('SettingsService: Post-import auto-config yielded no results, trying general auto-config');
                        $configuration = $this->autoConfigure();
                        if (!empty($configuration)) {
                            $this->updateSettings($configuration);
                            $results['autoConfigured'] = true;
                            $this->logger->info('SettingsService: General auto-configuration successful', [
                                'configuration' => array_keys($configuration)
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $error = 'Auto-configuration failed: ' . $e->getMessage();
                    $results['errors'][] = $error;
                    $this->logger->error('SettingsService: ' . $error, [
                        'exception' => $e
                    ]);
                }
            } else {
                $this->logger->info('SettingsService: App is already fully configured');
            }
            $results['timing']['auto_config'] = round((microtime(true) - $autoConfigStart) * 1000, 2) . 'ms';

            // Final configuration status check
            $results['fullyConfigured'] = $this->isFullyConfigured();
            
            if (!$results['fullyConfigured']) {
                $warning = 'App is not fully configured after initialization. Manual configuration may be required.';
                $results['warnings'][] = $warning;
                $this->logger->warning('SettingsService: ' . $warning, [
                    'configStatus' => $this->getConfigurationStatus()
                ]);
            }

            $results['timing']['total'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';
            
            $this->logger->info('SettingsService: Initialization completed', [
                'results' => [
                    'openRegister' => $results['openRegister'],
                    'autoConfigured' => $results['autoConfigured'], 
                    'fullyConfigured' => $results['fullyConfigured'],
                    'settingsLoaded' => $results['settingsLoaded'],
                    'errors' => count($results['errors']),
                    'warnings' => count($results['warnings'])
                ],
                'timing' => $results['timing']
            ]);

        } catch (\Exception $e) {
            $error = 'Initialization failed: ' . $e->getMessage();
            $results['errors'][] = $error;
            $this->logger->error('SettingsService: ' . $error, [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Load settings from register configuration files
     *
     * @param bool $force Whether to force the import regardless of version checks.
     *
     * @return array The loaded settings configuration
     *
     * @throws \RuntimeException If settings loading fails
     */
    public function loadSettings(bool $force = false): array
    {
        $results = [];
        
        try {
            // Load settings from merged softwarecatalogus_register.json
            $softwareCatalogPath = __DIR__ . '/../Settings/softwarecatalogus_register.json';
            if (file_exists($softwareCatalogPath)) {
                $softwareCatalogContent = file_get_contents($softwareCatalogPath);
                $softwareCatalogSettings = json_decode($softwareCatalogContent, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    $results['softwarecatalog'] = $softwareCatalogSettings;
                    
                    // Import via configuration service if available with version checking
                    try {
                        $configurationService = $this->getConfigurationService();
                        
                        // Get the current app version dynamically
                        $currentAppVersion = $this->appManager->getAppVersion(\OCA\SoftwareCatalog\AppInfo\Application::APP_ID);
                        
                        $importResult = $configurationService->importFromJson(
                            data: $softwareCatalogSettings,
                            owner: null,
                            appId: \OCA\SoftwareCatalog\AppInfo\Application::APP_ID,
                            version: $currentAppVersion,
                            force: $force
                        );
                        
                        $results['softwarecatalog_imported'] = true;
                        $results['import_result'] = $importResult;
                    } catch (\Exception $e) {
                        $results['softwarecatalog_import_error'] = $e->getMessage();
                        $this->logger->error('Failed to import softwarecatalog settings: ' . $e->getMessage(), [
                            'exception' => $e
                        ]);
                    }
                }
            }

            if (empty($results)) {
                throw new \Exception('No register configuration files found');
            }

            return $results;
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to load settings: ' . $e->getMessage());
        }
    }

    /**
     * Gets the list of generic user groups from configuration
     *
     * @return array Array of generic user groups
     */
    public function getGenericUserGroups(): array
    {
        $groupsJson = $this->config->getValueString($this->_appName, 'generic_user_groups', '');
        
        if (empty($groupsJson)) {
            // Return default groups if no configuration exists
            return [
                'beheerder',
                'inkoper',
                'ambtenaar',
                'software-catalog-users'
            ];
        }

        $groups = json_decode($groupsJson, true);
        return is_array($groups) ? $groups : [];
    }

    /**
     * Sets the list of generic user groups in configuration
     *
     * @param array $groups Array of generic user groups
     * 
     * @return void
     */
    public function setGenericUserGroups(array $groups): void
    {
        $groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
        $this->config->setValueString($this->_appName, 'generic_user_groups', $groupsJson);
        
        $this->logger->info(
            'Updated generic user groups configuration',
            [
                'groups' => $groups
            ]
        );
    }

    /**
     * Gets the list of organization admin groups from configuration
     *
     * @return array Array of organization admin groups
     */
    public function getOrganizationAdminGroups(): array
    {
        $groupsJson = $this->config->getValueString($this->_appName, 'organization_admin_groups', '');
        
        if (empty($groupsJson)) {
            // Return default groups if no configuration exists
            return [
                'organisaties-beheerder'
            ];
        }

        $groups = json_decode($groupsJson, true);
        return is_array($groups) ? $groups : [];
    }

    /**
     * Sets the list of organization admin groups in configuration
     *
     * @param array $groups Array of organization admin groups
     * 
     * @return void
     */
    public function setOrganizationAdminGroups(array $groups): void
    {
        $groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
        $this->config->setValueString($this->_appName, 'organization_admin_groups', $groupsJson);
        
        $this->logger->info(
            'Updated organization admin groups configuration',
            [
                'groups' => $groups
            ]
        );
    }

    /**
     * Gets the list of super user groups from configuration
     *
     * @return array Array of super user groups
     */
    public function getSuperUserGroups(): array
    {
        $groupsJson = $this->config->getValueString($this->_appName, 'super_user_groups', '');
        
        if (empty($groupsJson)) {
            // Return default groups if no configuration exists
            return [
                'admin',
                'software-catalog-admins'
            ];
        }

        $groups = json_decode($groupsJson, true);
        return is_array($groups) ? $groups : [];
    }

    /**
     * Sets the list of super user groups in configuration
     *
     * @param array $groups Array of super user groups
     * 
     * @return void
     */
    public function setSuperUserGroups(array $groups): void
    {
        $groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
        $this->config->setValueString($this->_appName, 'super_user_groups', $groupsJson);
        
        $this->logger->info(
            'Updated super user groups configuration',
            [
                'groups' => $groups
            ]
        );
    }

    /**
     * Validates a list of group names
     *
     * @param array $groups Array of group names to validate
     * 
     * @return array Array with validation results
     */
    public function validateGroups(array $groups): array
    {
        $results = [
            'valid' => [],
            'invalid' => [],
            'errors' => []
        ];
        
        foreach ($groups as $groupName) {
            if (empty($groupName) || !is_string($groupName)) {
                $results['invalid'][] = $groupName;
                $results['errors'][] = 'Group name cannot be empty';
                continue;
            }
            
            // Check for invalid characters
            if (preg_match('/[^a-zA-Z0-9._-]/', $groupName)) {
                $results['invalid'][] = $groupName;
                $results['errors'][] = "Group name '{$groupName}' contains invalid characters";
                continue;
            }
            
            $results['valid'][] = $groupName;
        }
        
        return $results;
    }

    /**
     * Creates and configures required user groups for the software catalog
     *
     * This is the public method that creates user groups and returns status information.
     *
     * @return array Results of user group creation and configuration
     */
    public function createAndConfigureUserGroups(): array
    {
        try {
            $this->logger->info('SettingsService: Starting user group creation and configuration');
            
            $result = [
                'success' => true,
                'message' => 'User groups configured successfully',
                'created' => [],
                'existing' => [],
                'total' => 0
            ];
            
            // Get the group manager
            $groupManager = \OC::$server->getGroupManager();
            
            // Define the required groups (matching role-based system)
            $requiredGroups = [
                // Role-based user groups (exact match with ContactPersoon roles)
                'aanbod-beheerder' => 'Manages software offerings and catalog content',
                'gebruik-beheerder' => 'Manages software usage and procurement',
                'gebruik-raadpleger' => 'Views software usage and procurement data',
                'functioneel-beheerder' => 'Manages functional aspects of the system',
                'vng-raadpleger' => 'Views VNG-related information',
                'organisatie-beheerder' => 'Manages organization data and settings',
                
                // Plural form for organization contacts
                'organisaties-beheerder' => 'Organization administrators (plural)',
                
                // Special groups
                'ambtenaar' => 'Civil servants from Gemeente organizations',
                'software-catalog-users' => 'General software catalog users',
                
                // Super user groups
                'software-catalog-admins' => 'Software catalog system administrators'
            ];
            
            foreach ($requiredGroups as $groupId => $description) {
                $this->logger->debug("SettingsService: Processing group: {$groupId}");
                
                // Check if group already exists
                if ($groupManager->groupExists($groupId)) {
                    $result['existing'][] = $groupId;
                    $this->logger->debug("SettingsService: Group {$groupId} already exists");
                    continue;
                }
                
                // Create the group
                $group = $groupManager->createGroup($groupId);
                if ($group !== false) {
                    $result['created'][] = $groupId;
                    $this->logger->info("SettingsService: Created user group: {$groupId}");
                } else {
                    $this->logger->warning("SettingsService: Failed to create user group: {$groupId}");
                    $result['success'] = false;
                }
            }
            
            $result['total'] = count($requiredGroups);
            
            // Update the configuration with the correct role-based groups
            $this->setGenericUserGroups([
                'aanbod-beheerder',
                'gebruik-beheerder', 
                'gebruik-raadpleger',
                'functioneel-beheerder',
                'vng-raadpleger',
                'organisatie-beheerder',
                'ambtenaar',
                'software-catalog-users'
            ]);
            
            $this->setOrganizationAdminGroups([
                'organisaties-beheerder',
                'organisatie-beheerder'
            ]);
            
            $this->setSuperUserGroups([
                'admin', // Keep existing admin group
                'software-catalog-admins'
            ]);
            
            $createdCount = count($result['created']);
            $existingCount = count($result['existing']);
            
            if ($createdCount > 0) {
                $result['message'] = "Created {$createdCount} new groups, {$existingCount} already existed";
            } else {
                $result['message'] = "All {$existingCount} required groups already exist";
            }
            
            $this->logger->info('SettingsService: User group creation and configuration completed', [
                'created_groups' => $result['created'],
                'existing_groups' => $result['existing'],
                'total_required' => $result['total'],
                'success' => $result['success']
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to create and configure user groups', [
                'exception' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Failed to create user groups: ' . $e->getMessage(),
                'created' => [],
                'existing' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Creates required user groups for the software catalog
     *
     * This method creates the default user groups needed for proper operation:
     * - Generic user groups for general access
     * - Organization admin groups for managing organizations  
     * - Super user groups for system administration
     *
     * @return void
     *
     * @throws \RuntimeException If group creation fails
     */
    private function createRequiredUserGroups(): void
    {
        try {
            $this->logger->info('Starting creation of required user groups');
            
            // Get the group manager
            $groupManager = \OC::$server->getGroupManager();
            
            // Define the required groups (matching role-based system)
            $requiredGroups = [
                // Role-based user groups (exact match with ContactPersoon roles)
                'aanbod-beheerder' => 'Manages software offerings and catalog content',
                'gebruik-beheerder' => 'Manages software usage and procurement',
                'gebruik-raadpleger' => 'Views software usage and procurement data',
                'functioneel-beheerder' => 'Manages functional aspects of the system',
                'vng-raadpleger' => 'Views VNG-related information',
                'organisatie-beheerder' => 'Manages organization data and settings',
                
                // Plural form for organization contacts
                'organisaties-beheerder' => 'Organization administrators (plural)',
                
                // Special groups
                'ambtenaar' => 'Civil servants from Gemeente organizations',
                'software-catalog-users' => 'General software catalog users',
                
                // Super user groups
                'software-catalog-admins' => 'Software catalog system administrators'
            ];
            
            $createdGroups = [];
            $existingGroups = [];
            
            foreach ($requiredGroups as $groupId => $description) {
                $this->logger->debug("Processing group: {$groupId}");
                
                // Check if group already exists
                if ($groupManager->groupExists($groupId)) {
                    $existingGroups[] = $groupId;
                    $this->logger->debug("Group {$groupId} already exists, skipping");
                    continue;
                }
                
                // Create the group
                $group = $groupManager->createGroup($groupId);
                if ($group !== false) {
                    $createdGroups[] = $groupId;
                    $this->logger->info("Created user group: {$groupId}");
                } else {
                    $this->logger->warning("Failed to create user group: {$groupId}");
                }
            }
            
            // Update the configuration with the correct role-based groups
            $this->setGenericUserGroups([
                'aanbod-beheerder',
                'gebruik-beheerder', 
                'gebruik-raadpleger',
                'functioneel-beheerder',
                'vng-raadpleger',
                'organisatie-beheerder',
                'ambtenaar',
                'software-catalog-users'
            ]);
            
            $this->setOrganizationAdminGroups([
                'organisaties-beheerder',
                'organisatie-beheerder'
            ]);
            
            $this->setSuperUserGroups([
                'admin', // Keep existing admin group
                'software-catalog-admins'
            ]);
            
            $this->logger->info('User group creation completed', [
                'created_groups' => $createdGroups,
                'existing_groups' => $existingGroups,
                'total_required' => count($requiredGroups)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create required user groups: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw new \RuntimeException('Failed to create required user groups: ' . $e->getMessage());
        }
    }

    /**
     * Gets all available groups with their information
     *
     * @return array Array of group information
     */
    public function getAllGroups(): array
    {
        $groups = [];
        
        // Get group manager if possible
        if ($this->appManager->isInstalled('user_management')) {
            try {
                $groupManager = \OC::$server->getGroupManager();
                $allGroups = $groupManager->search('');
                
                foreach ($allGroups as $group) {
                    $groups[] = [
                        'id' => $group->getGID(),
                        'displayName' => $group->getDisplayName(),
                        'memberCount' => count($group->getUsers()),
                        'isGeneric' => in_array($group->getGID(), $this->getGenericUserGroups())
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to get all groups: ' . $e->getMessage());
            }
        }
        
        return $groups;
    }

    /**
     * Gets email configuration settings
     *
     * @return array Email settings configuration
     */
    public function getEmailSettings(): array
    {
        $this->logger->debug('SoftwareCatalog: Loading email settings from configuration');
        
        $settings = [
            'enabled' => $this->config->getValueString($this->_appName, 'email_enabled', 'false') === 'true',
            'senderEmail' => $this->config->getValueString($this->_appName, 'sender_email', 'noreply@softwarecatalogus.nl'),
            'senderName' => $this->config->getValueString($this->_appName, 'sender_name', 'Software Catalogus'),
            'testReceiverOverride' => $this->config->getValueString($this->_appName, 'test_receiver_override', ''),
            'organizationRegistrationEnabled' => $this->config->getValueString($this->_appName, 'email_org_registration_enabled', 'true') === 'true',
            'organizationActivationEnabled' => $this->config->getValueString($this->_appName, 'email_org_activation_enabled', 'true') === 'true',
            'userCreationEnabled' => $this->config->getValueString($this->_appName, 'email_user_creation_enabled', 'true') === 'true',
            'userPasswordEnabled' => $this->config->getValueString($this->_appName, 'email_user_password_enabled', 'true') === 'true',
            
            // Symfony Mailer transport configuration
            'transportType' => $this->config->getValueString($this->_appName, 'email_transport_type', 'smtp'),
            
            // SMTP configuration
            'smtpHost' => $this->config->getValueString($this->_appName, 'email_smtp_host', 'localhost'),
            'smtpPort' => (int) $this->config->getValueString($this->_appName, 'email_smtp_port', '587'),
            'smtpEncryption' => $this->config->getValueString($this->_appName, 'email_smtp_encryption', 'tls'),
            'smtpUsername' => $this->config->getValueString($this->_appName, 'email_smtp_username', ''),
            'smtpPassword' => $this->config->getValueString($this->_appName, 'email_smtp_password', ''),
            
            // SendGrid configuration
            'sendgridApiKey' => $this->config->getValueString($this->_appName, 'email_sendgrid_api_key', ''),
            
            // Mailgun configuration
            'mailgunApiKey' => $this->config->getValueString($this->_appName, 'email_mailgun_api_key', ''),
            'mailgunDomain' => $this->config->getValueString($this->_appName, 'email_mailgun_domain', ''),
            
            // Postmark configuration
            'postmarkApiKey' => $this->config->getValueString($this->_appName, 'email_postmark_api_key', ''),
            
            // Amazon SES configuration
            'sesAccessKey' => $this->config->getValueString($this->_appName, 'email_ses_access_key', ''),
            'sesSecretKey' => $this->config->getValueString($this->_appName, 'email_ses_secret_key', ''),
            'sesRegion' => $this->config->getValueString($this->_appName, 'email_ses_region', 'us-east-1'),
            
            // Mailjet configuration
            'mailjetApiKey' => $this->config->getValueString($this->_appName, 'email_mailjet_api_key', ''),
            'mailjetSecretKey' => $this->config->getValueString($this->_appName, 'email_mailjet_secret_key', ''),
            
            // Templates
            'templates' => [
                'organization_registration' => $this->getEmailTemplate('organization_registration'),
                'organization_activation' => $this->getEmailTemplate('organization_activation'),
                'user_creation' => $this->getEmailTemplate('user_creation'),
                'user_password' => $this->getEmailTemplate('user_password'),
            ]
        ];
        
        $this->logger->info('SoftwareCatalog: Email settings loaded from configuration', [
            'enabled' => $settings['enabled'],
            'transport_type' => $settings['transportType'],
            'sender_email' => $settings['senderEmail'],
            'has_mailjet_api_key' => !empty($settings['mailjetApiKey']),
            'mailjet_api_key_length' => strlen($settings['mailjetApiKey']),
            'has_mailjet_secret_key' => !empty($settings['mailjetSecretKey']),
            'mailjet_secret_key_length' => strlen($settings['mailjetSecretKey']),
            'test_receiver_override' => $settings['testReceiverOverride']
        ]);
        
        return $settings;
    }

    /**
     * Updates email configuration settings
     *
     * @param array $emailSettings Email settings to update
     *
     * @return array Updated email settings
     */
    public function updateEmailSettings(array $emailSettings): array
    {
        $allowedSettings = [
            'enabled' => 'email_enabled',
            'senderEmail' => 'sender_email',
            'senderName' => 'sender_name',
            'testReceiverOverride' => 'test_receiver_override',
            'organizationRegistrationEnabled' => 'email_org_registration_enabled',
            'organizationActivationEnabled' => 'email_org_activation_enabled',
            'userCreationEnabled' => 'email_user_creation_enabled',
            'userPasswordEnabled' => 'email_user_password_enabled',
            
            // Symfony Mailer transport configuration
            'transportType' => 'email_transport_type',
            
            // SMTP configuration
            'smtpHost' => 'email_smtp_host',
            'smtpPort' => 'email_smtp_port',
            'smtpEncryption' => 'email_smtp_encryption',
            'smtpUsername' => 'email_smtp_username',
            'smtpPassword' => 'email_smtp_password',
            
            // SendGrid configuration
            'sendgridApiKey' => 'email_sendgrid_api_key',
            
            // Mailgun configuration
            'mailgunApiKey' => 'email_mailgun_api_key',
            'mailgunDomain' => 'email_mailgun_domain',
            
            // Postmark configuration
            'postmarkApiKey' => 'email_postmark_api_key',
            
            // Amazon SES configuration
            'sesAccessKey' => 'email_ses_access_key',
            'sesSecretKey' => 'email_ses_secret_key',
            'sesRegion' => 'email_ses_region',
            
            // Mailjet configuration
            'mailjetApiKey' => 'email_mailjet_api_key',
            'mailjetSecretKey' => 'email_mailjet_secret_key',
        ];

        $updatedSettings = [];
        
        foreach ($allowedSettings as $settingKey => $configKey) {
            if (array_key_exists($settingKey, $emailSettings)) {
                $value = $emailSettings[$settingKey];
                
                // Convert boolean values to strings
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                
                $this->config->setValueString($this->_appName, $configKey, (string) $value);
                $updatedSettings[$settingKey] = $this->config->getValueString($this->_appName, $configKey);
            }
        }

        $this->logger->info(
            'Email settings updated successfully',
            [
                'updatedKeys' => array_keys($updatedSettings)
            ]
        );

        return $updatedSettings;
    }

    /**
     * Gets email template content for a specific template
     *
     * @param string $templateName The template name (organization_registration, organization_activation, user_creation)
     *
     * @return string The template content
     */
    public function getEmailTemplate(string $templateName): string
    {
        $configKey = "email_template_{$templateName}";
        $defaultTemplate = $this->getDefaultEmailTemplate($templateName);
        
        return $this->config->getValueString($this->_appName, $configKey, $defaultTemplate);
    }

    /**
     * Updates email template content
     *
     * @param string $templateName    The template name
     * @param string $templateContent The template content
     *
     * @return bool True if update was successful
     */
    public function updateEmailTemplate(string $templateName, string $templateContent): bool
    {
        try {
            $configKey = "email_template_{$templateName}";
            $this->config->setValueString($this->_appName, $configKey, $templateContent);
            
            $this->logger->info(
                'Email template updated successfully',
                [
                    'templateName' => $templateName
                ]
            );
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to update email template: ' . $e->getMessage(),
                [
                    'templateName' => $templateName
                ]
            );
            return false;
        }
    }

    /**
     * Gets default email template content
     *
     * @param string $templateName The template name
     *
     * @return string Default template content
     */
    public function getDefaultEmailTemplate(string $templateName): string
    {
        $templates = [
            'organization_registration' => '
<h1>Welkom bij de Software Catalogus!</h1>
<p>Beste {{ organization.name }},</p>
<p>Hartelijk welkom bij de Software Catalogus! Uw organisatie is succesvol geregistreerd.</p>
<p>Met de Software Catalogus kunt u:</p>
<ul>
    <li>Uw software overzichtelijk beheren</li>
    <li>Software delen met andere organisaties</li>
    <li>Ontdekken welke software andere organisaties gebruiken</li>
</ul>
<p>Uw organisatie heeft de status "{{ organization.beoordeling }}" en kan nu gebruik maken van alle functionaliteiten.</p>
<p>Heeft u vragen? Neem dan contact met ons op.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
            'organization_activation' => '
<h1>Uw organisatie is geactiveerd!</h1>
<p>Beste {{ organization.name }},</p>
<p>Goed nieuws! Uw organisatie is zojuist geactiveerd in de Software Catalogus.</p>
<p>Dit betekent dat u nu volledig kunt deelnemen aan:</p>
<ul>
    <li>Het delen van software informatie</li>
    <li>Samenwerking met andere organisaties</li>
    <li>Toegang tot alle catalogus functionaliteiten</li>
</ul>
<p>Status: {{ organization.beoordeling }}</p>
<p>U kunt nu inloggen en gebruik maken van alle beschikbare functionaliteiten.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
            'user_creation' => '
<h1>Welkom {{ user.name }}!</h1>
<p>Beste {{ user.name }},</p>
<p>Er is een gebruikersaccount voor u aangemaakt in de Software Catalogus.</p>
<p>Uw accountgegevens:</p>
<ul>
    <li>E-mailadres: {{ user.email }}</li>
    <li>Gebruikersnaam: {{ user.username }}</li>
    <li>Organisatie: {{ user.organization.name if user.organization }}</li>
</ul>
<p>U kunt nu inloggen op het platform en gebruik maken van alle functionaliteiten.</p>
<p>Heeft u vragen over uw account? Neem dan contact met ons op.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
            'user_password' => '
<h1>Uw wachtwoord voor de Software Catalogus</h1>
<p>Beste {{ user.name }},</p>
<p>Uw wachtwoord voor de Software Catalogus is aangepast.</p>
<p>Uw logingegevens:</p>
<ul>
    <li>E-mailadres: {{ user.email }}</li>
    <li>Gebruikersnaam: {{ user.username }}</li>
    <li>Nieuw wachtwoord: {{ user.password }}</li>
</ul>
<p>U kunt nu inloggen met uw nieuwe wachtwoord.</p>
<p>We raden u aan om uw wachtwoord te wijzigen na het eerste inloggen.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            '
        ];

        return $templates[$templateName] ?? '';
    }

    /**
     * Gets available email template variables for a specific template
     *
     * @param string $templateName The template name
     *
     * @return array Available template variables
     */
    public function getEmailTemplateVariables(string $templateName): array
    {
        $variables = [
            'organization_registration' => [
                'organization.name' => 'Organization name',
                'organization.beoordeling' => 'Organization status (e.g., Actief)',
                'organization.type' => 'Organization type (e.g., Leverancier)',
                'organization.website' => 'Organization website',
            ],
            'organization_activation' => [
                'organization.name' => 'Organization name',
                'organization.beoordeling' => 'Organization status (e.g., Actief)',
                'organization.type' => 'Organization type',
                'organization.website' => 'Organization website',
            ],
            'user_creation' => [
                'user.name' => 'User display name',
                'user.email' => 'User email address',
                'user.username' => 'Username',
                'user.organization.name' => 'Organization name (if applicable)',
            ],
            'user_password' => [
                'user.name' => 'User display name',
                'user.email' => 'User email address',
                'user.username' => 'Username',
                'user.password' => 'Auto-generated password',
                'user.organization.name' => 'Organization name (if applicable)',
            ]
        ];

        return $variables[$templateName] ?? [];
    }

    /**
     * Gets debug information for settings
     *
     * @return array Debug information
     */
    public function getDebugInfo(): array
    {
        $debugInfo = [];
        
        try {
            // Get current configuration values
            $debugInfo['configuration'] = [];
            $configKeys = [
                'amef_organization_source',
                'amef_organization_register', 
                'amef_organization_schema',
                'voorzieningen_organisatie_source',
                'voorzieningen_organisatie_register',
                'voorzieningen_organisatie_schema',
                'voorzieningen_contactpersoon_source',
                'voorzieningen_contactpersoon_register',
                'voorzieningen_contactpersoon_schema',
                'voorzieningen_register', // Sync service expects this key
                'organization_source',
                'organization_register',
                'organization_schema',
                'contact_source',
                'contact_register',
                'contact_schema'
            ];
            
            foreach ($configKeys as $key) {
                $value = $this->config->getValueString($this->_appName, $key, '');
                $debugInfo['configuration'][$key] = empty($value) ? '' : $value;
            }
            
            // Get group configurations
            $debugInfo['userGroups'] = [
                'generic' => $this->getGenericUserGroups(),
                'organizationAdmin' => $this->getOrganizationAdminGroups(),
                'superUser' => $this->getSuperUserGroups()
            ];
            
            // Get email settings (without sensitive data)
            $emailSettings = $this->getEmailSettings();
            unset($emailSettings['smtpPassword']);
            unset($emailSettings['sendgridApiKey']);
            unset($emailSettings['mailgunApiKey']);
            unset($emailSettings['postmarkApiKey']);
            unset($emailSettings['sesSecretKey']);
            unset($emailSettings['mailjetSecretKey']);
            $debugInfo['emailSettings'] = $emailSettings;
            
            // Get OpenRegister status
            $debugInfo['openRegister'] = [
                'installed' => $this->isOpenRegisterInstalled(),
                'enabled' => $this->isOpenRegisterEnabled(),
                'availableRegisters' => []
            ];
            
            if ($debugInfo['openRegister']['installed'] && $debugInfo['openRegister']['enabled']) {
                try {
                    $objectService = $this->getObjectService();
                    $debugInfo['openRegister']['availableRegisters'] = $objectService->getRegisters();
                } catch (\Exception $e) {
                    $debugInfo['openRegister']['error'] = $e->getMessage();
                }
            }
            
        } catch (\Exception $e) {
            $debugInfo['error'] = $e->getMessage();
        }
        
        return $debugInfo;
    }

    /**
     * Sends a test email
     *
     * @param string $email         The email address to send to
     * @param array  $emailSettings The email settings to use
     * 
     * @return array Result of the test email
     */
    public function sendTestEmail(string $email, array $emailSettings = []): array
    {
        // Validate email address first (business logic moved from controller)
        if (empty($email)) {
            $this->logger->warning('SoftwareCatalog: Test email request missing email address');
            return [
                'success' => false,
                'message' => 'Email address is required'
            ];
        }
        
        $this->logger->info('SoftwareCatalog: Starting sendTestEmail process', [
            'recipient' => $email,
            'has_email_settings' => !empty($emailSettings)
        ]);
        
        try {
            // Ensure vendor autoloader is loaded
            include_once __DIR__ . '/../../vendor/autoload.php';
            $this->logger->debug('SoftwareCatalog: Vendor autoloader loaded');
            
            // Use provided settings or fall back to stored settings
            if (empty($emailSettings)) {
                $emailSettings = $this->getEmailSettings();
                $this->logger->info('SoftwareCatalog: Loaded email settings from storage');
            } else {
                $this->logger->info('SoftwareCatalog: Using provided email settings');
            }
            
            // Log the email configuration (without sensitive data)
            $this->logger->info('SoftwareCatalog: Email configuration', [
                'enabled' => $emailSettings['enabled'] ?? false,
                'transport_type' => $emailSettings['transportType'] ?? 'unknown',
                'sender_email' => $emailSettings['senderEmail'] ?? 'not set',
                'sender_name' => $emailSettings['senderName'] ?? 'not set',
                'has_mailjet_api_key' => !empty($emailSettings['mailjetApiKey']),
                'has_mailjet_secret_key' => !empty($emailSettings['mailjetSecretKey']),
            ]);
            
            // Check if email is enabled
            if (!($emailSettings['enabled'] ?? false)) {
                $this->logger->warning('SoftwareCatalog: Email notifications are disabled');
                return [
                    'success' => false,
                    'message' => 'Email notifications are disabled'
                ];
            }
            
            // Use test receiver override if configured
            $recipient = $emailSettings['testReceiverOverride'] ?? $email;
            $this->logger->info('SoftwareCatalog: Final recipient determined', [
                'original_recipient' => $email,
                'final_recipient' => $recipient,
                'using_override' => !empty($emailSettings['testReceiverOverride'])
            ]);
            
            // Create transport based on configuration
            $this->logger->info('SoftwareCatalog: Creating email transport');
            $transport = $this->createEmailTransport($emailSettings);
            $this->logger->info('SoftwareCatalog: Email transport created successfully');
            
            $mailer = new Mailer($transport);
            $this->logger->info('SoftwareCatalog: Mailer instance created');
            
            // Create test email
            $senderEmail = $emailSettings['senderEmail'] ?? 'noreply@softwarecatalogus.nl';
            $senderName = $emailSettings['senderName'] ?? 'Software Catalogus';
            $transportType = $emailSettings['transportType'] ?? 'smtp';
            
            $this->logger->info('SoftwareCatalog: Creating email message', [
                'sender_email' => $senderEmail,
                'sender_name' => $senderName,
                'transport_type' => $transportType,
                'recipient' => $recipient
            ]);
            
            $email = (new Email())
                ->from(new Address($senderEmail, $senderName))
                ->to($recipient)
                ->subject('Software Catalogus - Test Email')
                ->html('
                    <h1>Test Email - Software Catalogus</h1>
                    <p>Dit is een test email van de Software Catalogus.</p>
                    <p>Als u deze email ontvangt, werkt het email systeem correct.</p>
                    <p><strong>Transport Type:</strong> ' . htmlspecialchars($transportType) . '</p>
                    <p><strong>Datum:</strong> ' . date('Y-m-d H:i:s') . '</p>
                    <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
                ');
            
            $this->logger->info('SoftwareCatalog: Email message created, attempting to send');
            
            // Send the email
            $mailer->send($email);
            
            $this->logger->info('SoftwareCatalog: Email sent successfully via Symfony Mailer', [
                'recipient' => $recipient,
                'transport' => $transportType,
                'sender' => $senderEmail
            ]);
            
            return [
                'success' => true,
                'message' => "Test email sent successfully to {$recipient} via {$transportType}"
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: Failed to send test email', [
                'recipient' => $email,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test email connection without sending an actual email
     *
     * @param array $emailSettings The email settings to test
     * 
     * @return array Result of the connection test
     */
    public function testEmailConnection(array $emailSettings = []): array
    {
        $this->logger->info('SoftwareCatalog: Starting email connection test', [
            'has_email_settings' => !empty($emailSettings)
        ]);
        
        try {
            // Ensure vendor autoloader is loaded
            include_once __DIR__ . '/../../vendor/autoload.php';
            $this->logger->debug('SoftwareCatalog: Vendor autoloader loaded');
            
            // Use provided settings or fall back to stored settings
            if (empty($emailSettings)) {
                $emailSettings = $this->getEmailSettings();
                $this->logger->info('SoftwareCatalog: Loaded email settings from storage');
            } else {
                $this->logger->info('SoftwareCatalog: Using provided email settings');
            }
            
            // Log the email configuration (without sensitive data)
            $this->logger->info('SoftwareCatalog: Email configuration for connection test', [
                'enabled' => $emailSettings['enabled'] ?? false,
                'transport_type' => $emailSettings['transportType'] ?? 'unknown',
                'sender_email' => $emailSettings['senderEmail'] ?? 'not set',
                'sender_name' => $emailSettings['senderName'] ?? 'not set',
                'has_credentials' => $this->hasValidCredentials($emailSettings)
            ]);
            
            // Check if email is enabled
            if (!($emailSettings['enabled'] ?? false)) {
                $this->logger->warning('SoftwareCatalog: Email notifications are disabled');
                return [
                    'success' => false,
                    'message' => 'Email notifications are disabled'
                ];
            }
            
            // Validate basic settings
            $transportType = $emailSettings['transportType'] ?? 'smtp';
            $senderEmail = $emailSettings['senderEmail'] ?? '';
            
            if (empty($senderEmail)) {
                return [
                    'success' => false,
                    'message' => 'Sender email address is required'
                ];
            }
            
            // Create transport based on configuration (this tests the connection)
            $this->logger->info('SoftwareCatalog: Creating email transport for connection test');
            $transport = $this->createEmailTransport($emailSettings);
            $this->logger->info('SoftwareCatalog: Email transport created successfully');
            
            // Test the connection by creating a mailer instance
            $mailer = new Mailer($transport);
            $this->logger->info('SoftwareCatalog: Mailer instance created for connection test');
            
            // For some transports, we can test the connection more directly
            $connectionDetails = $this->getConnectionDetails($emailSettings);
            
            $this->logger->info('SoftwareCatalog: Email connection test completed successfully', [
                'transport' => $transportType,
                'sender' => $senderEmail
            ]);
            
            return [
                'success' => true,
                'message' => "Email connection test successful for {$transportType}",
                'details' => $connectionDetails
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: Email connection test failed', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Email connection test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if email settings have valid credentials for the transport type
     *
     * @param array $emailSettings Email settings
     * @return bool True if credentials are present
     */
    private function hasValidCredentials(array $emailSettings): bool
    {
        $transportType = $emailSettings['transportType'] ?? 'smtp';
        
        switch ($transportType) {
            case 'smtp':
                return !empty($emailSettings['smtpHost']) && !empty($emailSettings['smtpPort']);
            case 'mailjet':
                return !empty($emailSettings['mailjetApiKey']) && !empty($emailSettings['mailjetSecretKey']);
            case 'sendgrid':
                return !empty($emailSettings['sendgridApiKey']);
            case 'mailgun':
                return !empty($emailSettings['mailgunApiKey']) && !empty($emailSettings['mailgunDomain']);
            case 'postmark':
                return !empty($emailSettings['postmarkApiKey']);
            case 'ses':
                return !empty($emailSettings['sesAccessKey']) && !empty($emailSettings['sesSecretKey']);
            default:
                return false;
        }
    }

    /**
     * Get connection details for the email transport
     *
     * @param array $emailSettings Email settings
     * @return array Connection details
     */
    private function getConnectionDetails(array $emailSettings): array
    {
        $transportType = $emailSettings['transportType'] ?? 'smtp';
        
        switch ($transportType) {
            case 'smtp':
                return [
                    'type' => 'SMTP',
                    'host' => $emailSettings['smtpHost'] ?? '',
                    'port' => $emailSettings['smtpPort'] ?? '',
                    'encryption' => $emailSettings['smtpEncryption'] ?? 'none',
                    'username' => !empty($emailSettings['smtpUsername']) ? '***' : 'none'
                ];
            case 'mailjet':
                return [
                    'type' => 'Mailjet API',
                    'has_api_key' => !empty($emailSettings['mailjetApiKey']),
                    'has_secret_key' => !empty($emailSettings['mailjetSecretKey'])
                ];
            case 'sendgrid':
                return [
                    'type' => 'SendGrid API',
                    'has_api_key' => !empty($emailSettings['sendgridApiKey'])
                ];
            case 'mailgun':
                return [
                    'type' => 'Mailgun API',
                    'has_api_key' => !empty($emailSettings['mailgunApiKey']),
                    'domain' => $emailSettings['mailgunDomain'] ?? ''
                ];
            case 'postmark':
                return [
                    'type' => 'Postmark API',
                    'has_api_key' => !empty($emailSettings['postmarkApiKey'])
                ];
            case 'ses':
                return [
                    'type' => 'Amazon SES',
                    'has_access_key' => !empty($emailSettings['sesAccessKey']),
                    'has_secret_key' => !empty($emailSettings['sesSecretKey']),
                    'region' => $emailSettings['sesRegion'] ?? 'us-east-1'
                ];
            default:
                return ['type' => $transportType];
        }
    }
    
    /**
     * Creates an email transport based on configuration
     *
     * @param array $emailSettings Email settings
     * @return \Symfony\Component\Mailer\Transport\TransportInterface
     * @throws \Exception If transport configuration is invalid
     */
    private function createEmailTransport(array $emailSettings): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $transportType = $emailSettings['transportType'] ?? 'smtp';
        
        $this->logger->info('SoftwareCatalog: Creating transport', [
            'transport_type' => $transportType
        ]);
        
        switch ($transportType) {
            case 'mailjet':
                $this->logger->info('SoftwareCatalog: Creating Mailjet transport');
                return $this->createMailjetTransport($emailSettings);
            case 'smtp':
                $this->logger->info('SoftwareCatalog: Creating SMTP transport');
                return $this->createSmtpTransport($emailSettings);
            default:
                $this->logger->error('SoftwareCatalog: Unsupported transport type', [
                    'transport_type' => $transportType
                ]);
                throw new \InvalidArgumentException("Unsupported transport type: {$transportType}");
        }
    }
    
    /**
     * Creates a Mailjet transport
     *
     * @param array $settings Email settings
     * @return \Symfony\Component\Mailer\Transport\TransportInterface
     */
    private function createMailjetTransport(array $settings): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $apiKey = $settings['mailjetApiKey'] ?? '';
        $secretKey = $settings['mailjetSecretKey'] ?? '';
        
        $this->logger->info('SoftwareCatalog: Mailjet transport configuration', [
            'has_api_key' => !empty($apiKey),
            'api_key_length' => strlen($apiKey),
            'has_secret_key' => !empty($secretKey),
            'secret_key_length' => strlen($secretKey)
        ]);
        
        if (empty($apiKey) || empty($secretKey)) {
            $this->logger->error('SoftwareCatalog: Mailjet API key and secret key are required', [
                'api_key_empty' => empty($apiKey),
                'secret_key_empty' => empty($secretKey)
            ]);
            throw new \InvalidArgumentException('Mailjet API key and secret key are required');
        }

        $dsn = sprintf(
            'mailjet+api://%s:%s@default',
            urlencode($apiKey),
            urlencode($secretKey)
        );
        
        $this->logger->info('SoftwareCatalog: Creating Mailjet transport with DSN', [
            'dsn_pattern' => 'mailjet+api://***:***@default'
        ]);
        
        try {
            $transport = Transport::fromDsn($dsn);
            $this->logger->info('SoftwareCatalog: Mailjet transport created successfully', [
                'transport_class' => get_class($transport)
            ]);
            return $transport;
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: Failed to create Mailjet transport', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Creates an SMTP transport
     *
     * @param array $settings Email settings
     * @return \Symfony\Component\Mailer\Transport\TransportInterface
     */
    private function createSmtpTransport(array $settings): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $host = $settings['smtpHost'] ?? 'localhost';
        $port = $settings['smtpPort'] ?? 587;
        $encryption = $settings['smtpEncryption'] ?? 'tls';
        $username = $settings['smtpUsername'] ?? '';
        $password = $settings['smtpPassword'] ?? '';
        
        $this->logger->info('SoftwareCatalog: SMTP transport configuration', [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'has_username' => !empty($username),
            'has_password' => !empty($password)
        ]);
        
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            urlencode($username),
            urlencode($password),
            $host,
            $port
        );
        
        if ($encryption && $encryption !== 'none') {
            $dsn .= '?encryption=' . $encryption;
        }
        
        $this->logger->info('SoftwareCatalog: Creating SMTP transport with DSN', [
            'dsn_pattern' => sprintf('smtp://***:***@%s:%d%s', $host, $port, $encryption && $encryption !== 'none' ? '?encryption=' . $encryption : '')
        ]);
        
        try {
            $transport = Transport::fromDsn($dsn);
            $this->logger->info('SoftwareCatalog: SMTP transport created successfully', [
                'transport_class' => get_class($transport)
            ]);
            return $transport;
        } catch (\Exception $e) {
            $this->logger->error('SoftwareCatalog: Failed to create SMTP transport', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if settings should be loaded based on version comparison.
     *
     * This method compares the current app version with the stored configuration
     * version to determine if a settings import is needed.
     *
     * @return bool True if settings should be loaded, false otherwise.
     */
    private function shouldLoadSettings(): bool
    {
        try {
            // Get the current app version
            $currentAppVersion = $this->appManager->getAppVersion(\OCA\SoftwareCatalog\AppInfo\Application::APP_ID);
            
            $this->logger->info('SettingsService: Checking if settings should be loaded', [
                'current_app_version' => $currentAppVersion
            ]);
            
            // Get the configuration service to check stored version
            $configurationService = $this->getConfigurationService();
            $storedVersion = $configurationService->getConfiguredAppVersion(\OCA\SoftwareCatalog\AppInfo\Application::APP_ID);
            
            $this->logger->info('SettingsService: Version comparison details', [
                'current_app_version' => $currentAppVersion,
                'stored_config_version' => $storedVersion,
                'stored_version_is_null' => $storedVersion === null
            ]);
            
            // If no stored version exists, we need to load settings
            if ($storedVersion === null) {
                $this->logger->info('SettingsService: No stored version found, settings should be loaded');
                return true;
            }
            
            // Compare versions using semantic versioning
            // Load settings if current version is newer than stored version
            $shouldLoad = version_compare($currentAppVersion, $storedVersion, '>');
            
            $this->logger->info('SettingsService: Version comparison result', [
                'current_version' => $currentAppVersion,
                'stored_version' => $storedVersion,
                'should_load' => $shouldLoad,
                'version_compare_result' => version_compare($currentAppVersion, $storedVersion)
            ]);
            
            return $shouldLoad;
            
        } catch (\Exception $e) {
            // If we can't determine versions, err on the side of loading settings
            $this->logger->warning('Failed to check if settings should be loaded: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return true;
        }
    }

    /**
     * Get version information for the app and configuration.
     *
     * This method returns version information including the current app version
     * and the stored configuration version in OpenRegister.
     *
     * @return array Version information with app and configuration versions.
     * @throws \RuntimeException If version retrieval fails.
     */
    public function getVersionInfo(): array
    {
        try {
            // Get the current app version
            $currentAppVersion = $this->appManager->getAppVersion(\OCA\SoftwareCatalog\AppInfo\Application::APP_ID);
            
            $this->logger->debug('SettingsService: Getting version information', [
                'current_app_version' => $currentAppVersion
            ]);
            
            // Get the configuration service to check stored version
            $configurationService = $this->getConfigurationService();
            $storedConfigVersion = null;
            
            try {
                $storedConfigVersion = $configurationService->getConfiguredAppVersion(\OCA\SoftwareCatalog\AppInfo\Application::APP_ID);
            } catch (\Exception $e) {
                $this->logger->warning('SettingsService: Could not retrieve stored configuration version', [
                    'exception_message' => $e->getMessage()
                ]);
                // Continue with null stored version
            }
            
            // Determine if versions match
            $versionsMatch = $storedConfigVersion !== null && 
                           version_compare($currentAppVersion, $storedConfigVersion, '=');
            
            $needsUpdate = $storedConfigVersion === null || 
                          version_compare($currentAppVersion, $storedConfigVersion, '>');
            
            // Check OpenRegister status
            $openRegisterInstalled = $this->isOpenRegisterInstalled();
            $openRegisterEnabled = $openRegisterInstalled && $this->isOpenRegisterEnabled();
            
            $versionInfo = [
                'appName' => 'SoftwareCatalog',
                'appVersion' => $currentAppVersion,
                'configuredVersion' => $storedConfigVersion,
                'versionsMatch' => $versionsMatch,
                'needsUpdate' => $needsUpdate,
                'versionComparison' => $storedConfigVersion !== null ? version_compare($currentAppVersion, $storedConfigVersion) : null,
                'isFullyConfigured' => $this->isFullyConfigured(),
                'autoConfigCompleted' => $this->config->getValueString($this->_appName, 'auto_config_completed', 'false') === 'true',
                'openRegisterInstalled' => $openRegisterInstalled,
                'openRegisterEnabled' => $openRegisterEnabled
            ];
            
            $this->logger->info('SettingsService: Version information compiled', $versionInfo);
            
            return $versionInfo;
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get version information', [
                'exception' => $e
            ]);
            throw new \RuntimeException('Failed to get version information: ' . $e->getMessage());
        }
    }

    /**
     * Forces a complete configuration update regardless of version checks
     *
     * This method forces a complete reconfiguration by resetting all relevant
     * flags and configurations, then performs import and auto-configuration.
     *
     * @return array The force update results
     */
    public function forceUpdate(): array
    {
        try {
            $this->logger->info('SettingsService: Starting force update');
            
            // Reset auto-configuration flag
            $this->config->setValueString($this->_appName, 'auto_config_completed', 'false');
            
            // Perform forced import
            $importResult = $this->manualImport(true);
            
            if (!$importResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Force update failed during import: ' . ($importResult['message'] ?? 'Unknown error'),
                    'importResult' => $importResult
                ];
            }
            
            // Verify configuration after force update
            $finalVersionInfo = $this->getVersionInfo();
            $finalConfigStatus = $this->getConfigurationStatus();
            
            $success = $finalVersionInfo['versionsMatch'] || !$finalVersionInfo['needsUpdate'];
            
            $this->logger->info('SettingsService: Force update completed', [
                'success' => $success,
                'final_version_info' => $finalVersionInfo,
                'final_config_status' => $finalConfigStatus
            ]);
            
            return [
                'success' => $success,
                'message' => $success ? 'Force update completed successfully' : 'Force update completed but configuration may need attention',
                'importResult' => $importResult,
                'finalVersionInfo' => $finalVersionInfo,
                'finalConfigStatus' => $finalConfigStatus
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Force update failed', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return [
                'success' => false,
                'message' => 'Force update failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Resets the auto-configuration to allow it to run again
     *
     * This method clears the auto-configuration completion flag and
     * optionally resets schema/register configurations for testing.
     *
     * @param bool $resetConfiguration Whether to also clear schema/register settings
     *
     * @return array The reset results
     */
    public function resetAutoConfiguration(bool $resetConfiguration = false): array
    {
        try {
            $this->logger->info('Resetting auto-configuration', [
                'reset_configuration' => $resetConfiguration
            ]);
            
            // Reset the auto-configuration completion flag
            $this->config->setValueString($this->_appName, 'auto_config_completed', 'false');
            
            $resetItems = ['auto_config_completed_flag'];
            
            if ($resetConfiguration) {
                // Reset schema and register configurations
                $configKeysToReset = [
                    'voorzieningen_organisatie_source',
                    'voorzieningen_organisatie_register',
                    'voorzieningen_organisatie_schema',
                    'voorzieningen_contactpersoon_source',
                    'voorzieningen_contactpersoon_register',
                    'voorzieningen_contactpersoon_schema',
                    'organization_source',
                    'organization_register',
                    'organization_schema',
                    'contact_source',
                    'contact_register',
                    'contact_schema'
                ];
                
                foreach ($configKeysToReset as $key) {
                    $this->config->setValueString($this->_appName, $key, '');
                }
                
                $resetItems[] = 'schema_register_configurations';
            }
            
            $this->logger->info('Auto-configuration reset completed', [
                'reset_items' => $resetItems
            ]);
            
            return [
                'success' => true,
                'message' => 'Auto-configuration reset successfully',
                'reset_items' => $resetItems
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset auto-configuration: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to reset auto-configuration: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Manually trigger configuration import from JSON.
     *
     * This method allows system administrators to manually trigger the import
     * process, bypassing version checks.
     *
     * @param bool $forceImport Whether to force import regardless of version.
     *
     * @return array The import results with success/error information.
     */
    public function manualImport(bool $forceImport = false): array
    {
        try {
            $this->logger->info('SettingsService: Starting manual import', [
                'force_import' => $forceImport
            ]);
            
            // Get version info first
            $versionInfo = $this->getVersionInfo();
            
            $this->logger->info('SettingsService: Pre-import version info', $versionInfo);
            
            // Check if import is needed (unless forced)
            if (!$forceImport && $versionInfo['versionsMatch'] && $versionInfo['isFullyConfigured']) {
                $this->logger->info('SettingsService: Import not needed - versions match and fully configured');
                return [
                    'success' => false,
                    'message' => 'Configuration is already up to date. Use force import if you want to reimport.',
                    'versionInfo' => $versionInfo
                ];
            }
            
            // If force import is requested or auto-config not completed, reset auto-configuration flag
            if ($forceImport || !$versionInfo['autoConfigCompleted']) {
                $this->config->setValueString($this->_appName, 'auto_config_completed', 'false');
                $this->logger->info('SettingsService: Reset auto-configuration flag', [
                    'reason' => $forceImport ? 'force_import' : 'auto_config_not_completed'
                ]);
            }
            
            // Perform the import
            $this->logger->info('SettingsService: Starting settings import');
            $importResult = $this->loadSettings($forceImport);
            $this->logger->info('SettingsService: Settings import completed', [
                'import_result' => $importResult
            ]);
            
            // Auto-configure after successful import
            $autoConfigResult = null;
            try {
                $this->logger->info('SettingsService: Starting auto-configuration after import');
                $autoConfigResult = $this->autoConfigureAfterImport();
                if (!empty($autoConfigResult)) {
                    $this->logger->info('SettingsService: Updating settings with auto-configuration result');
                    $this->updateSettings($autoConfigResult);
                    $this->logger->info('SettingsService: Auto-configuration completed after import', [
                        'configuration' => array_keys($autoConfigResult)
                    ]);
                } else {
                    $this->logger->info('SettingsService: Auto-configuration yielded no results');
                }
            } catch (\Exception $e) {
                $this->logger->warning('SettingsService: Auto-configuration failed after import', [
                    'exception_message' => $e->getMessage(),
                    'exception' => $e
                ]);
                // Don't fail the entire import if auto-configuration fails
            }
            
            // Wait a moment for any async operations to complete
            usleep(100000); // 0.1 seconds
            
            // Get updated version info - this should now reflect the changes
            $this->logger->info('SettingsService: Getting updated version info after import');
            $updatedVersionInfo = $this->getVersionInfo();
            $this->logger->info('SettingsService: Post-import version info', $updatedVersionInfo);
            
            $message = 'Configuration imported successfully';
            if (!empty($autoConfigResult)) {
                $message .= ' and auto-configured';
            }
            if ($forceImport) {
                $message .= ' (forced import)';
            }
            
            return [
                'success' => true,
                'message' => $message,
                'importResult' => $importResult,
                'autoConfigResult' => $autoConfigResult,
                'versionInfo' => $updatedVersionInfo,
                'configurationStatus' => $this->getConfigurationStatus()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Manual import failed', [
                'exception_message' => $e->getMessage(),
                'exception' => $e
            ]);
            return [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'versionInfo' => $this->getVersionInfo()
            ];
        }
    }

    /**
     * Perform consolidated auto-configuration with clean separation of concerns
     *
     * This method orchestrates the complete auto-configuration process:
     * 1. Configuration file loading
     * 2. Voorzieningen register configuration  
     * 3. AMEF register configuration
     * 4. User groups configuration
     *
     * @param bool $force Whether to force configuration loading
     * @return array Consolidated configuration results
     */
    public function performConsolidatedAutoConfiguration(bool $force = false): array
    {
        $this->logger->info('SettingsService: Starting consolidated auto-configuration', [
            'force' => $force
        ]);
        
        $results = [
            'success' => true,
            'message' => 'Auto-configuration completed successfully',
            'steps' => [],
            'errors' => [],
            'timestamp' => time(),
            'force' => $force
        ];
        
        // Step 1: Load configuration files
        $this->logger->info('SettingsService: Step 1 - Loading configuration');
        $configResult = $this->loadConfiguration($force);
        $results['steps']['configurationLoad'] = $configResult;
        $this->addStepResult($results, $configResult, 'Configuration loading');
        
        // Step 2: Configure Voorzieningen (Dutch register system)
        $this->logger->info('SettingsService: Step 2 - Configuring Voorzieningen');
        $voorzieningenResult = $this->configureVoorzieningen();
        $results['steps']['voorzieningenConfiguration'] = $voorzieningenResult;
        $this->addStepResult($results, $voorzieningenResult, 'Voorzieningen configuration');
        
        // Step 3: Configure AMEF (ArchiMate/English register system)
        $this->logger->info('SettingsService: Step 3 - Configuring AMEF');
        $amefResult = $this->configureAmef();
        $results['steps']['amefConfiguration'] = $amefResult;
        $this->addStepResult($results, $amefResult, 'AMEF configuration');
        
        // Step 4: Configure User Groups
        $this->logger->info('SettingsService: Step 4 - Configuring User Groups');
        $groupsResult = $this->configureGroups();
        $results['steps']['groupsConfiguration'] = $groupsResult;
        $this->addStepResult($results, $groupsResult, 'User groups configuration');
        
        // Determine overall success
        $results['success'] = empty($results['errors']);
        if (!$results['success']) {
            $results['message'] = 'Auto-configuration completed with some issues';
        }
        
        $this->logger->info('SettingsService: Consolidated auto-configuration completed', [
            'success' => $results['success'],
            'errors_count' => count($results['errors'])
        ]);
        
        return $results;
    }

    /**
     * Load configuration files
     *
     * @param bool $force Whether to force reload regardless of version
     * @return array Configuration loading result
     */
    private function loadConfiguration(bool $force): array
    {
        try {
            $importResult = $this->manualImport($force);
            
            return [
                'success' => $importResult['success'],
                'message' => $importResult['message'] ?? 'Configuration loaded',
                'details' => $importResult
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Configuration loading failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Configure Voorzieningen register and schemas
     *
     * @return array Voorzieningen configuration result
     */
    private function configureVoorzieningen(): array
    {
        try {
            // The voorzieningen configuration is already handled by autoConfigureAfterImport
            // in the manualImport() call, so we just verify it was successful
            $voorzieningenRegister = $this->config->getValueString($this->_appName, 'voorzieningen_register', '');
            $organisatieSchema = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', '');
            $contactSchema = $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_schema', '');
            
            $configured = [];
            if (!empty($voorzieningenRegister)) {
                $configured['register'] = $voorzieningenRegister;
            }
            if (!empty($organisatieSchema)) {
                $configured['organisatieSchema'] = $organisatieSchema;
            }
            if (!empty($contactSchema)) {
                $configured['contactSchema'] = $contactSchema;
            }
            
            $success = !empty($voorzieningenRegister) && !empty($organisatieSchema);
            
            return [
                'success' => $success,
                'message' => $success ? 'Voorzieningen configured successfully' : 'Voorzieningen configuration incomplete',
                'configured' => $configured
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Voorzieningen configuration failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Configure AMEF register and schemas
     *
     * @return array AMEF configuration result
     */
    private function configureAmef(): array
    {
        try {
            // Get available registers
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [
                    'success' => false,
                    'message' => 'OpenRegister service not available'
                ];
            }

            $registers = $objectService->getRegisters();
            if (empty($registers)) {
                return [
                    'success' => false,
                    'message' => 'No registers available'
                ];
            }

            // Find vng-gemma register (strict requirement for AMEF)
            $vngGemmaRegister = null;
            foreach ($registers as $register) {
                $slug = strtolower($register['slug'] ?? '');
                if ($slug === 'vng-gemma') {
                    $vngGemmaRegister = $register;
                    break;
                }
            }

            if (!$vngGemmaRegister) {
                return [
                    'success' => false,
                    'message' => 'VNG-GEMMA register not found - required for AMEF configuration'
                ];
            }

            // Configure AMEF schemas (English only, strict)
            $amefSchemaMappings = [
                'organizationsSchema' => ['organization'],  // Only English for AMEF
                'modelsSchema' => ['model'],
                'propertiesSchema' => ['property', 'property-definition']
            ];

            $configured = [];
            $errors = [];

            foreach ($amefSchemaMappings as $settingKey => $schemaNames) {
                $schemaFound = false;
                
                foreach ($vngGemmaRegister['schemas'] as $schema) {
                    $schemaSlug = strtolower($schema['slug'] ?? '');
                    
                    if (in_array($schemaSlug, $schemaNames)) {
                        // Save to the correct keys
                        if ($settingKey === 'organizationsSchema') {
                            $this->config->setValueString($this->_appName, 'amef_organization_source', 'openregister');
                            $this->config->setValueString($this->_appName, 'amef_organization_register', (string)$vngGemmaRegister['id']);
                            $this->config->setValueString($this->_appName, 'amef_organization_schema', (string)$schema['id']);
                        }
                        
                        // Also save to the AMEF-specific endpoint keys
                        $configKey = 'amef_' . strtolower(str_replace('Schema', '', $settingKey)) . '_schema';
                        $this->config->setValueString($this->_appName, $configKey, (string)$schema['id']);
                        
                        $configured[$settingKey] = $schema['id'];
                        $schemaFound = true;
                        break;
                    }
                }
                
                if (!$schemaFound) {
                    $errors[] = "No suitable schema found for {$settingKey} in VNG-GEMMA register";
                }
            }

            // Set register ID for AMEF operations
            $this->config->setValueString($this->_appName, 'amef_register_id', (string)$vngGemmaRegister['id']);
            $configured['registerId'] = $vngGemmaRegister['id'];

            return [
                'success' => empty($errors),
                'message' => empty($errors) ? 'AMEF configuration completed successfully' : 'AMEF configuration completed with errors',
                'configured' => $configured,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'AMEF configuration failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Configure required user groups
     *
     * @return array User groups configuration result
     */
    private function configureGroups(): array
    {
        try {
            // Call the method to create required user groups
            $result = $this->createAndConfigureUserGroups();
            
            return [
                'success' => $result['success'],
                'message' => $result['message'],
                'created' => $result['created'] ?? [],
                'existing' => $result['existing'] ?? [],
                'total' => $result['total'] ?? 0
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'User groups configuration failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Add step result to overall results and handle errors
     *
     * @param array &$results The results array (passed by reference)
     * @param array $stepResult The result of a configuration step
     * @param string $stepName The name of the step for error reporting
     * @return void
     */
    private function addStepResult(array &$results, array $stepResult, string $stepName): void
    {
        if (!$stepResult['success']) {
            $results['errors'][] = $stepName . ' failed: ' . ($stepResult['message'] ?? 'Unknown error');
            $this->logger->warning("SettingsService: {$stepName} failed", [
                'error' => $stepResult['message'] ?? 'Unknown error'
            ]);
        } else {
            $this->logger->info("SettingsService: {$stepName} successful");
        }
    }

    /**
     * Get consolidated configuration as JSON objects
     *
     * @return array The consolidated configuration
     */
    public function getConsolidatedConfiguration(): array
    {
        // Get email config and include templates
        $emailConfig = $this->getEmailConfig();
        $emailConfig['templates'] = [
            'organization_registration' => $this->getEmailTemplate('organization_registration'),
            'organization_activation' => $this->getEmailTemplate('organization_activation'),
            'user_creation' => $this->getEmailTemplate('user_creation'),
            'user_password' => $this->getEmailTemplate('user_password'),
        ];
        
        // Get Voorzieningen and AMEF configs (without object counts for performance)
        $voorzieningenConfig = $this->getVoorzieningenConfig();
        $amefConfig = $this->getAmefConfig();
        
        return [
            'voorzieningen' => $voorzieningenConfig,
            'amef' => $amefConfig,
            'email' => $emailConfig,
            'archimate' => $this->getArchiMateStatus(),
            'userGroups' => [
                'generic' => $this->getGenericUserGroups(),
                'organizationAdmin' => $this->getOrganizationAdminGroups(),
                'superUser' => $this->getSuperUserGroups()
            ]
        ];
    }

    /**
     * Get Voorzieningen configuration as JSON object
     *
     * @return array The voorzieningen configuration
     */
    public function getVoorzieningenConfig(): array
    {
        $config = $this->config->getValueString($this->_appName, 'voorzieningen_config', '{}');
        $decoded = json_decode($config, true);
        
        if (!is_array($decoded)) {
            // Fallback to individual config values for backward compatibility
            $decoded = [
                'register' => $this->config->getValueString($this->_appName, 'voorzieningen_register', ''),
                'organisatie_schema' => $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', ''),
                'contactpersoon_schema' => $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_schema', ''),
            ];
        }
        
        return $decoded;
    }

    /**
     * Set Voorzieningen configuration as JSON object
     *
     * @param array $config The voorzieningen configuration
     * @return void
     */
    public function setVoorzieningenConfig(array $config): void
    {
        $jsonConfig = json_encode($config, JSON_PRETTY_PRINT);
        $this->config->setValueString($this->_appName, 'voorzieningen_config', $jsonConfig);
    }

    /**
     * Gets AMEF configuration using ArchiMateService to avoid code duplication
     *
     * This method delegates to ArchiMateService's getAmefConfig method
     * to ensure consistency and avoid code duplication.
     *
     * @return array The AMEF configuration
     */
    public function getAmefConfig(): array
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            // Use reflection to access the private getAmefConfig method
            $reflection = new \ReflectionClass($archiMateService);
            $method = $reflection->getMethod('getAmefConfig');
            $method->setAccessible(true);
            
            return $method->invoke($archiMateService);
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get AMEF config from ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to direct config access if ArchiMateService is not available
            $config = $this->config->getValueString($this->_appName, 'amef_config', '{}');
            $decoded = json_decode($config, true);
            
            if (!is_array($decoded)) {
                // Fallback to individual config values for backward compatibility
                $decoded = [
                    'register_id' => $this->config->getValueString($this->_appName, 'amef_register_id', ''),
                    'organizations_schema' => $this->config->getValueString($this->_appName, 'amef_organizations_schema', ''),
                    'elements_schema' => $this->config->getValueString($this->_appName, 'amef_elements_schema', ''),
                    'relationships_schema' => $this->config->getValueString($this->_appName, 'amef_relationships_schema', ''),
                    'views_schema' => $this->config->getValueString($this->_appName, 'amef_views_schema', ''),
                    'models_schema' => $this->config->getValueString($this->_appName, 'amef_models_schema', ''),
                    'properties_schema' => $this->config->getValueString($this->_appName, 'amef_properties_schema', '')
                ];
            }
            
            return $decoded;
        }
    }

    /**
     * Set AMEF configuration as JSON object
     *
     * @param array $config The AMEF configuration
     * @return void
     */
    public function setAmefConfig(array $config): void
    {
        $jsonConfig = json_encode($config, JSON_PRETTY_PRINT);
        $this->config->setValueString($this->_appName, 'amef_config', $jsonConfig);
    }

    /**
     * Get Email configuration as JSON object
     *
     * @return array The email configuration
     */
    public function getEmailConfig(): array
    {
        $config = $this->config->getValueString($this->_appName, 'email_config', '{}');
        $decoded = json_decode($config, true);
        
        if (!is_array($decoded)) {
            // Fallback to individual config values for backward compatibility
            $decoded = [
                'enabled' => $this->config->getValueString($this->_appName, 'email_enabled', 'false') === 'true',
                'transport_type' => $this->config->getValueString($this->_appName, 'email_transport_type', 'smtp'),
                'smtp_host' => $this->config->getValueString($this->_appName, 'email_smtp_host', ''),
                'smtp_port' => $this->config->getValueString($this->_appName, 'email_smtp_port', '587'),
                'smtp_username' => $this->config->getValueString($this->_appName, 'email_smtp_username', ''),
                'smtp_password' => $this->config->getValueString($this->_appName, 'email_smtp_password', ''),
                'smtp_encryption' => $this->config->getValueString($this->_appName, 'email_smtp_encryption', 'tls'),
                'sender_email' => $this->config->getValueString($this->_appName, 'sender_email', ''),
                'sender_name' => $this->config->getValueString($this->_appName, 'sender_name', ''),
                'mailjet_api_key' => $this->config->getValueString($this->_appName, 'email_mailjet_api_key', ''),
                'mailjet_secret_key' => $this->config->getValueString($this->_appName, 'email_mailjet_secret_key', '')
            ];
        }
        
        return $decoded;
    }

    /**
     * Set Email configuration as JSON object
     *
     * @param array $config The email configuration
     * @return void
     */
    public function setEmailConfig(array $config): void
    {
        $jsonConfig = json_encode($config, JSON_PRETTY_PRINT);
        $this->config->setValueString($this->_appName, 'email_config', $jsonConfig);
    }

    /**
     * Get ArchiMate import/export status and AMEF object counts
     *
     * This method delegates to ArchiMateService to avoid code duplication
     * and ensure consistency in ArchiMate status management.
     *
     * @return array The ArchiMate status with object counts
     */
    public function getArchiMateStatus(): array
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            return $archiMateService->getArchiMateStatus();
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get ArchiMate status from ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to direct config access if ArchiMateService is not available
            $importStatus = $this->config->getValueString($this->_appName, 'archimate_import_status', '{}');
            $exportStatus = $this->config->getValueString($this->_appName, 'archimate_export_status', '{}');
            
            $importDecoded = json_decode($importStatus, true);
            $exportDecoded = json_decode($exportStatus, true);
            
            // Get AMEF object counts
            $amefObjectCounts = $this->getAmefObjectCounts();
            
            return [
                'import' => is_array($importDecoded) ? $importDecoded : [],
                'export' => is_array($exportDecoded) ? $exportDecoded : [],
                'totalElementObjects' => $amefObjectCounts['totalElementObjects'],
                'totalOrganizationObjects' => $amefObjectCounts['totalOrganizationObjects'],
                'totalViewObjects' => $amefObjectCounts['totalViewObjects'],
                'totalRelationshipsObjects' => $amefObjectCounts['totalRelationshipsObjects']
            ];
        }
    }

    /**
     * Get Voorzieningen object counts for statistics
     *
     * @return array Object counts for Voorzieningen schemas
     */
    private function getVoorzieningenObjectCounts(): array
    {
        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return [
                    'totalOrganisatieObjects' => 0,
                    'totalContactpersoonObjects' => 0
                ];
            }
            
            $voorzieningenConfig = $this->getVoorzieningenConfig();
            $registerId = $voorzieningenConfig['register'] ?? null;
            $organisatieSchemaId = $voorzieningenConfig['organisatie_schema'] ?? null;
            $contactpersoonSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
            
            $organisatieCount = 0;
            $contactpersoonCount = 0;
            
            if ($registerId && $organisatieSchemaId) {
                try {
                    $organisatieQuery = [
                        '@self' => [
                            'register' => (int) $registerId,
                            'schema' => (int) $organisatieSchemaId
                        ]
                    ];
                    $organisatieObjects = $objectService->searchObjects($organisatieQuery);
                    $organisatieCount = count($organisatieObjects);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to get organisatie count', ['error' => $e->getMessage()]);
                }
            }
            
            if ($registerId && $contactpersoonSchemaId) {
                try {
                    $contactpersoonQuery = [
                        '@self' => [
                            'register' => (int) $registerId,
                            'schema' => (int) $contactpersoonSchemaId
                        ]
                    ];
                    $contactpersoonObjects = $objectService->searchObjects($contactpersoonQuery);
                    $contactpersoonCount = count($contactpersoonObjects);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to get contactpersoon count', ['error' => $e->getMessage()]);
                }
            }
            
            $this->logger->debug('SettingsService: Retrieved Voorzieningen object counts', [
                'organisatieCount' => $organisatieCount,
                'contactpersoonCount' => $contactpersoonCount
            ]);
            
            return [
                'totalOrganisatieObjects' => $organisatieCount,
                'totalContactpersoonObjects' => $contactpersoonCount
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get Voorzieningen object counts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'totalOrganisatieObjects' => 0,
                'totalContactpersoonObjects' => 0
            ];
        }
    }

    /**
     * Gets AMEF object counts for consolidated configuration
     *
     * This method retrieves counts of all AMEF object types using the ArchiMateService
     * and returns them in a format suitable for the consolidated configuration.
     *
     * @return array AMEF object counts
     */
    private function getAmefObjectCounts(): array
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            // Get object counts using ArchiMateService methods
            $elementObjects = $archiMateService->getElementObjects();
            $organizationObjects = $archiMateService->getOrganizationObjects();
            $viewObjects = $archiMateService->getViewObjects();
            $relationshipObjects = $archiMateService->getRelationshipObjects();
            $modelObjects = $archiMateService->getModelObjects();
            $propertyObjects = $archiMateService->getPropertyObjects();

            $this->logger->debug('SettingsService: Retrieved AMEF object counts', [
                'elementObjects' => count($elementObjects),
                'organizationObjects' => count($organizationObjects),
                'viewObjects' => count($viewObjects),
                'relationshipObjects' => count($relationshipObjects),
                'modelObjects' => count($modelObjects),
                'propertyObjects' => count($propertyObjects)
            ]);

            return [
                'totalElementObjects' => count($elementObjects),
                'totalOrganizationObjects' => count($organizationObjects),
                'totalViewObjects' => count($viewObjects),
                'totalRelationshipsObjects' => count($relationshipObjects),
                'totalModelObjects' => count($modelObjects),
                'totalPropertyObjects' => count($propertyObjects)
            ];

        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get AMEF object counts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return zero counts on error to prevent API failures
            return [
                'totalElementObjects' => 0,
                'totalOrganizationObjects' => 0,
                'totalViewObjects' => 0,
                'totalRelationshipsObjects' => 0,
                'totalModelObjects' => 0,
                'totalPropertyObjects' => 0
            ];
        }
    }

    /**
     * Set ArchiMate import status
     *
     * This method delegates to ArchiMateService to avoid code duplication
     * and ensure consistency in ArchiMate status management.
     *
     * @param array $status The import status
     * @return void
     */
    public function setArchiMateImportStatus(array $status): void
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            $archiMateService->setArchiMateImportStatus($status);
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to set ArchiMate import status via ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to direct config access if ArchiMateService is not available
            $jsonStatus = json_encode($status, JSON_PRETTY_PRINT);
            $this->config->setValueString($this->_appName, 'archimate_import_status', $jsonStatus);
        }
    }

    /**
     * Set ArchiMate export status
     *
     * This method delegates to ArchiMateService to avoid code duplication
     * and ensure consistency in ArchiMate status management.
     *
     * @param array $status The export status
     * @return void
     */
    public function setArchiMateExportStatus(array $status): void
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            $archiMateService->setArchiMateExportStatus($status);
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to set ArchiMate export status via ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to direct config access if ArchiMateService is not available
            $jsonStatus = json_encode($status, JSON_PRETTY_PRINT);
            $this->config->setValueString($this->_appName, 'archimate_export_status', $jsonStatus);
        }
    }

    /**
     * Clear ArchiMate import status
     *
     * This method delegates to ArchiMateService to avoid code duplication
     * and ensure consistency in ArchiMate status management.
     *
     * @return array Clear operation result
     */
    public function clearArchiMateImportStatus(): array
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            return $archiMateService->clearArchiMateImportStatus();
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to clear ArchiMate import status via ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to direct config access if ArchiMateService is not available
            $this->config->deleteKey($this->_appName, 'archimate_import_status');
            
            return [
                'cleared' => true,
                'process_killed' => false,
                'process_id' => null,
                'was_running' => false,
                'messages' => ['Import status cleared via fallback method']
            ];
        }
    }

    /**
     * Force kill running ArchiMate import process and clear status
     *
     * This method delegates to ArchiMateService to handle process termination
     * and status cleanup.
     *
     * @return array Kill operation result
     * @deprecated Use cancelArchiMateImport() instead
     */
    public function killArchiMateImport(): array
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            return $archiMateService->clearArchiMateImportStatus(true); // killProcess = true
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to kill ArchiMate import process via ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to just clearing config if ArchiMateService is not available
            $this->config->deleteKey($this->_appName, 'archimate_import_status');
            
            return [
                'cleared' => true,
                'process_killed' => false,
                'process_id' => null,
                'was_running' => false,
                'messages' => ['Import status cleared via fallback method - could not kill process']
            ];
        }
    }

    /**
     * Cancel a running ArchiMate import
     *
     * This method combines force clearing and process killing for a complete
     * import cancellation. It delegates to ArchiMateService for the actual work.
     *
     * @return array Cancellation result with detailed status
     */
    public function cancelArchiMateImport(): array
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            return $archiMateService->cancelArchiMateImport();
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to cancel ArchiMate import via ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to just clearing config if ArchiMateService is not available
            $this->config->deleteKey($this->_appName, 'archimate_import_status');
            
            return [
                'cancelled' => true,
                'was_running' => false,
                'process_id' => null,
                'process_killed' => false,
                'status_cleared' => true,
                'cancellation_time' => date('Y-m-d H:i:s'),
                'messages' => ['Import status cleared via fallback method - ArchiMateService not available']
            ];
        }
    }

    /**
     * Clear ArchiMate export status
     *
     * This method delegates to ArchiMateService to avoid code duplication
     * and ensure consistency in ArchiMate status management.
     *
     * @return void
     */
    public function clearArchiMateExportStatus(): void
    {
        try {
            // Get ArchiMateService from container to avoid circular dependency
            $archiMateService = $this->container->get(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
            
            $archiMateService->clearArchiMateExportStatus();
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to clear ArchiMate export status via ArchiMateService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to direct config access if ArchiMateService is not available
            $this->config->deleteKey($this->_appName, 'archimate_export_status');
        }
    }

    /**
     * Compact existing individual configuration values to JSON format
     * This method reorganizes all the scattered config values into organized JSON objects
     *
     * @return array Compaction results
     */
    public function compactToJsonConfiguration(): array
    {
        $results = [
            'success' => true,
            'migrated' => [],
            'errors' => []
        ];

        try {
            // 1. Migrate Voorzieningen configuration
            $voorzieningenConfig = [
                'register' => $this->config->getValueString($this->_appName, 'voorzieningen_register', ''),
                'organisatie_schema' => $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', ''),
                'contactpersoon_schema' => $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_schema', ''),
                'organisatie_source' => $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_source', 'openregister'),
                'contactpersoon_source' => $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_source', 'openregister'),
                'organisatie_register' => $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_register', ''),
                'contactpersoon_register' => $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_register', ''),
            ];

            $this->setVoorzieningenConfig($voorzieningenConfig);
            $results['migrated']['voorzieningen'] = $voorzieningenConfig;

            // 2. Migrate AMEF configuration
            $amefConfig = [
                'register_id' => $this->config->getValueString($this->_appName, 'amef_register_id', ''),
                'organizations_schema' => $this->config->getValueString($this->_appName, 'amef_organizations_schema', ''),
                'elements_schema' => $this->config->getValueString($this->_appName, 'amef_elements_schema', ''),
                'relationships_schema' => $this->config->getValueString($this->_appName, 'amef_relationships_schema', ''),
                'views_schema' => $this->config->getValueString($this->_appName, 'amef_views_schema', ''),
                'models_schema' => $this->config->getValueString($this->_appName, 'amef_models_schema', ''),
                'properties_schema' => $this->config->getValueString($this->_appName, 'amef_properties_schema', ''),
                'organization_source' => $this->config->getValueString($this->_appName, 'amef_organization_source', 'openregister'),
                'organization_register' => $this->config->getValueString($this->_appName, 'amef_organization_register', ''),
                'organization_schema' => $this->config->getValueString($this->_appName, 'amef_organization_schema', ''),
                // Note: These duplicated entries with typos are kept for backward compatibility but should be cleaned up
                'elementss_schema' => $this->config->getValueString($this->_appName, 'amef_elementss_schema', ''),
                'organizationss_schema' => $this->config->getValueString($this->_appName, 'amef_organizationss_schema', ''),
                'relationshipss_schema' => $this->config->getValueString($this->_appName, 'amef_relationshipss_schema', '')
            ];

            $this->setAmefConfig($amefConfig);
            $results['migrated']['amef'] = $amefConfig;

            // 3. Migrate Email configuration
            $emailConfig = [
                'enabled' => $this->config->getValueString($this->_appName, 'email_enabled', 'false') === 'true',
                'transport_type' => $this->config->getValueString($this->_appName, 'email_transport_type', 'smtp'),
                'smtp_host' => $this->config->getValueString($this->_appName, 'email_smtp_host', ''),
                'smtp_port' => $this->config->getValueString($this->_appName, 'email_smtp_port', '587'),
                'smtp_username' => $this->config->getValueString($this->_appName, 'email_smtp_username', ''),
                'smtp_password' => $this->config->getValueString($this->_appName, 'email_smtp_password', ''),
                'smtp_encryption' => $this->config->getValueString($this->_appName, 'email_smtp_encryption', 'tls'),
                'sender_email' => $this->config->getValueString($this->_appName, 'sender_email', ''),
                'sender_name' => $this->config->getValueString($this->_appName, 'sender_name', ''),
                'mailjet_api_key' => $this->config->getValueString($this->_appName, 'email_mailjet_api_key', ''),
                'mailjet_secret_key' => $this->config->getValueString($this->_appName, 'email_mailjet_secret_key', ''),
                'sendgrid_api_key' => $this->config->getValueString($this->_appName, 'email_sendgrid_api_key', ''),
                'mailgun_api_key' => $this->config->getValueString($this->_appName, 'email_mailgun_api_key', ''),
                'mailgun_domain' => $this->config->getValueString($this->_appName, 'email_mailgun_domain', ''),
                'postmark_api_key' => $this->config->getValueString($this->_appName, 'email_postmark_api_key', ''),
                'ses_access_key' => $this->config->getValueString($this->_appName, 'email_ses_access_key', ''),
                'ses_secret_key' => $this->config->getValueString($this->_appName, 'email_ses_secret_key', ''),
                'ses_region' => $this->config->getValueString($this->_appName, 'email_ses_region', 'us-east-1'),
                'org_registration_enabled' => $this->config->getValueString($this->_appName, 'email_org_registration_enabled', 'true') === 'true',
                'org_activation_enabled' => $this->config->getValueString($this->_appName, 'email_org_activation_enabled', 'true') === 'true',
                'user_creation_enabled' => $this->config->getValueString($this->_appName, 'email_user_creation_enabled', 'true') === 'true',
                'user_password_enabled' => $this->config->getValueString($this->_appName, 'email_user_password_enabled', 'true') === 'true',
                'test_receiver_override' => $this->config->getValueString($this->_appName, 'test_receiver_override', '')
            ];

            $this->setEmailConfig($emailConfig);
            $results['migrated']['email'] = $emailConfig;

            $this->logger->info('Configuration compaction to JSON format completed successfully', [
                'compacted_sections' => array_keys($results['migrated'])
            ]);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Compaction failed: ' . $e->getMessage();
            $this->logger->error('Configuration compaction to JSON format failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Clean up old individual configuration values after compaction
     * This method removes the old scattered config values after successful compaction
     *
     * @return array Cleanup results
     */
    public function cleanupOldConfiguration(): array
    {
        $results = [
            'success' => true,
            'cleaned' => [],
            'errors' => []
        ];

        try {
            // List of old configuration keys to remove
            $oldKeys = [
                // Voorzieningen keys
                'voorzieningen_register',
                'voorzieningen_organisatie_schema',
                'voorzieningen_contactpersoon_schema',
                'voorzieningen_gebruiker_schema', // Deprecated - no longer used
                'voorzieningen_contactgegevens_schema', // Deprecated - no longer used
                'voorzieningen_organisatie_source',
                'voorzieningen_contactpersoon_source',
                'voorzieningen_gebruiker_source', // Deprecated - no longer used
                'voorzieningen_contactgegevens_source', // Deprecated - no longer used
                'voorzieningen_organisatie_register',
                'voorzieningen_contactpersoon_register',
                'voorzieningen_gebruiker_register', // Deprecated - no longer used
                'voorzieningen_contactgegevens_register', // Deprecated - no longer used
                
                // AMEF keys
                'amef_register_id',
                'amef_organizations_schema',
                'amef_elements_schema',
                'amef_relationships_schema',
                'amef_views_schema',
                'amef_organization_source',
                'amef_organization_register',
                'amef_organization_schema',
                'amef_elementss_schema',
                'amef_organizationss_schema',
                'amef_relationshipss_schema',
                
                // Email keys
                'email_enabled',
                'email_transport_type',
                'email_smtp_host',
                'email_smtp_port',
                'email_smtp_username',
                'email_smtp_password',
                'email_smtp_encryption',
                'sender_email',
                'sender_name',
                'email_mailjet_api_key',
                'email_mailjet_secret_key',
                'email_sendgrid_api_key',
                'email_mailgun_api_key',
                'email_mailgun_domain',
                'email_postmark_api_key',
                'email_ses_access_key',
                'email_ses_secret_key',
                'email_ses_region',
                'email_org_registration_enabled',
                'email_org_activation_enabled',
                'email_user_creation_enabled',
                'email_user_password_enabled',
                'test_receiver_override'
            ];

            foreach ($oldKeys as $key) {
                try {
                    $this->config->deleteKey($this->_appName, $key);
                    $results['cleaned'][] = $key;
                } catch (\Exception $e) {
                    $results['errors'][] = "Failed to delete key '{$key}': " . $e->getMessage();
                }
            }

            $this->logger->info('Old configuration cleanup completed', [
                'cleaned_keys' => count($results['cleaned']),
                'errors' => count($results['errors'])
            ]);

        } catch (\Exception $e) {
            $results['success'] = false;
            $results['errors'][] = 'Cleanup failed: ' . $e->getMessage();
            $this->logger->error('Old configuration cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    // ========================================================================
    // CONTROLLER BUSINESS LOGIC METHODS
    // ========================================================================

    /**
     * Get all settings including user groups and email settings
     * This aggregates all settings data for the main settings endpoint
     *
     * @return array Complete settings data
     */
    public function getAllSettings(): array
    {
        try {
            $data = $this->getSettings();
            
            // Use the proper consolidated configuration structure that frontend expects
            $data['consolidatedConfig'] = $this->getConsolidatedConfiguration();
            
            // Remove deprecated properties from root level
            unset($data['configuration']); // Moved to consolidatedConfig
            unset($data['userGroups']); // Now in consolidatedConfig
            unset($data['emailSettings']); // Now in consolidatedConfig
            
            return $data;
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get all settings', [
                'exception' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get object counts statistics for all configured registers
     *
     * @return array Statistics for all registers with object counts
     */
    public function getObjectCountsStatistics(): array
    {
        try {
            $statistics = [
                'voorzieningen' => [],
                'amef' => [],
                'timestamp' => time()
            ];
            
            // Get Voorzieningen statistics
            try {
                $voorzieningenConfig = $this->getVoorzieningenConfig();
                $voorzieningenCounts = $this->getVoorzieningenObjectCounts();
                
                $statistics['voorzieningen'] = [
                    'config' => $voorzieningenConfig,
                    'object_counts' => $voorzieningenCounts,
                    'configured' => !empty($voorzieningenConfig['register']) && !empty($voorzieningenConfig['organisatie_schema'])
                ];
            } catch (\Exception $e) {
                $this->logger->error('Failed to get Voorzieningen statistics', ['error' => $e->getMessage()]);
                $statistics['voorzieningen'] = [
                    'config' => [],
                    'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
                    'configured' => false,
                    'error' => $e->getMessage()
                ];
            }
            
            // Get AMEF statistics
            try {
                $amefConfig = $this->getAmefConfig();
                $amefCounts = $this->getAmefObjectCounts();
                
                $statistics['amef'] = [
                    'config' => $amefConfig,
                    'object_counts' => $amefCounts,
                    'configured' => !empty($amefConfig['register_id']) && !empty($amefConfig['elements_schema'])
                ];
            } catch (\Exception $e) {
                $this->logger->error('Failed to get AMEF statistics', ['error' => $e->getMessage()]);
                $statistics['amef'] = [
                    'config' => [],
                    'object_counts' => [
                        'totalElementObjects' => 0,
                        'totalOrganizationObjects' => 0,
                        'totalViewObjects' => 0,
                        'totalRelationshipsObjects' => 0,
                        'totalModelObjects' => 0,
                        'totalPropertyObjects' => 0
                    ],
                    'configured' => false,
                    'error' => $e->getMessage()
                ];
            }
            
            return $statistics;
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to get object counts statistics', [
                'exception' => $e->getMessage()
            ]);
            return [
                'voorzieningen' => [
                    'config' => [],
                    'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
                    'configured' => false,
                    'error' => $e->getMessage()
                ],
                'amef' => [
                    'config' => [],
                    'object_counts' => [
                        'totalElementObjects' => 0,
                        'totalOrganizationObjects' => 0,
                        'totalViewObjects' => 0,
                        'totalRelationshipsObjects' => 0,
                        'totalModelObjects' => 0,
                        'totalPropertyObjects' => 0
                    ],
                    'configured' => false,
                    'error' => $e->getMessage()
                ],
                'timestamp' => time(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get all email templates with error handling
     * This handles template iteration and individual failures
     *
     * @return array All email templates
     */
    public function getAllEmailTemplates(): array
    {
        $templateTypes = ['organization_registration', 'organization_activation', 'user_creation', 'user_password'];
        $templates = [];
        
        foreach ($templateTypes as $templateName) {
            try {
                $templates[$templateName] = $this->getEmailTemplate($templateName);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to get template {$templateName}", ['error' => $e->getMessage()]);
                $templates[$templateName] = null;
            }
        }
        
        return $templates;
    }

    /**
     * Update generic user groups with validation
     *
     * @param array $groups Groups to set
     * @return array Update result with validation
     */
    public function updateGenericUserGroups(array $groups): array
    {
        try {
            $validation = $this->validateGroups($groups);
            
            if (!empty($validation['invalid'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid group names provided',
                    'validation' => $validation
                ];
            }
            
            $this->setGenericUserGroups($validation['valid']);
            
            return [
                'success' => true,
                'message' => 'Generic user groups updated successfully',
                'groups' => $validation['valid']
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to update generic user groups', [
                'exception' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Failed to update generic user groups: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update organization admin groups with validation
     *
     * @param array $groups Groups to set
     * @return array Update result with validation
     */
    public function updateOrganizationAdminGroups(array $groups): array
    {
        try {
            $validation = $this->validateGroups($groups);
            
            if (!empty($validation['invalid'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid group names provided',
                    'validation' => $validation
                ];
            }
            
            $this->setOrganizationAdminGroups($validation['valid']);
            
            return [
                'success' => true,
                'message' => 'Organization admin groups updated successfully',
                'groups' => $validation['valid']
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to update organization admin groups', [
                'exception' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Failed to update organization admin groups: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update super user groups with validation
     *
     * @param array $groups Groups to set
     * @return array Update result with validation
     */
    public function updateSuperUserGroups(array $groups): array
    {
        try {
            $validation = $this->validateGroups($groups);
            
            if (!empty($validation['invalid'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid group names provided',
                    'validation' => $validation
                ];
            }
            
            $this->setSuperUserGroups($validation['valid']);
            
            return [
                'success' => true,
                'message' => 'Super user groups updated successfully',
                'groups' => $validation['valid']
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SettingsService: Failed to update super user groups', [
                'exception' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Failed to update super user groups: ' . $e->getMessage()
            ];
        }
    }

} 