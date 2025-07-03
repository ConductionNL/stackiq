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
     * @param IAppConfig         $config     App configuration interface
     * @param IRequest           $request    Request interface
     * @param ContainerInterface $container  Container for dependency injection
     * @param IAppManager        $appManager App manager interface
     * @param LoggerInterface    $logger     Logger interface
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
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
        return $this->appManager->isEnabled(self::OPENREGISTER_APP_ID);
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
                'objectTypes' => ['organization'] // AMEF uses organization schema
            ],
            'voorzieningen' => [
                'name' => 'Voorzieningen', 
                'description' => 'Voorzieningen register for software catalog services',
                'objectTypes' => ['gebruiker', 'organisatie', 'contactgegevens'] // Voorzieningen uses gebruiker, organisatie, and contactgegevens schemas
            ]
        ];
        
        // For backward compatibility, keep the original object types structure
        $data['objectTypes'] = [
            'organization',
            'contact', 
            'gebruiker',
        ];
        
        $data['openRegisters'] = false;
        $data['availableRegisters'] = [];

        // Check if the OpenRegister service is available
        try {
            $openRegisters = $this->getObjectService();
            if ($openRegisters !== null) {
                $data['openRegisters'] = true;
                $data['availableRegisters'] = $openRegisters->getRegisters();
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
                $this->config->setValueString($this->_appName, $key, $value);
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
    public function autoConfigure(): array
    {
        try {
            $objectService = $this->getObjectService();
            $registers = $objectService->getRegisters();

            if (empty($registers)) {
                return [];
            }

            $configuration = [];
            foreach ($this->getSettings()['objectTypes'] as $type) {
                // Try to find a register with a matching name
                $matchingRegister = null;
                foreach ($registers as $register) {
                    if (stripos($register['title'], $type) !== false) {
                        $matchingRegister = $register;
                        break;
                    }
                }

                if ($matchingRegister !== null) {
                    $configuration["{$type}_register"] = $matchingRegister['id'];

                    // Try to find a matching schema
                    if (!empty($matchingRegister['schemas'])) {
                        foreach ($matchingRegister['schemas'] as $schema) {
                            if (stripos($schema['title'], $type) !== false) {
                                $configuration["{$type}_schema"] = $schema['id'];
                                break;
                            }
                        }
                    }
                }
            }

            $this->logger->info(
                'Auto-configuration completed',
                [
                    'configuration' => $configuration
                ]
            );

            return $configuration;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to auto-configure: ' . $e->getMessage());
        }
    }

    /**
     * Gets the configured schema ID for a specific object type
     *
     * @param string $objectType The object type (organization, contact, gebruiker, contactgegevens)
     *
     * @return int|null The schema ID or null if not configured
     */
    public function getSchemaIdForObjectType(string $objectType): ?int
    {
        // First try register-specific configuration
        // Check for AMEF register specific schemas
        if ($objectType === 'organization') {
            $schemaId = $this->config->getValueString($this->_appName, 'amef_organization_schema', '');
            if (!empty($schemaId)) {
                return (int) $schemaId;
            }
            
            // Also check voorzieningen register for organization/organisatie
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', '');
            if (!empty($schemaId)) {
                return (int) $schemaId;
            }
        }
        
        // Check for Voorzieningen register specific schemas
        if ($objectType === 'gebruiker') {
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_gebruiker_schema', '');
            if (!empty($schemaId)) {
                return (int) $schemaId;
            }
        }
        
        if ($objectType === 'organisatie') {
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', '');
            if (!empty($schemaId)) {
                return (int) $schemaId;
            }
        }
        
        if ($objectType === 'contactgegevens') {
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_contactgegevens_schema', '');
            if (!empty($schemaId)) {
                return (int) $schemaId;
            }
        }
        
        // Fall back to generic configuration for backward compatibility
        $schemaId = $this->config->getValueString($this->_appName, "{$objectType}_schema", '');
        return $schemaId ? (int) $schemaId : null;
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
     * Checks if all required object types are configured
     *
     * @return bool True if all object types have schemas configured
     */
    public function isFullyConfigured(): bool
    {
        $objectTypes = ['organization', 'contact', 'gebruiker'];
        
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
        $objectTypes = ['organization', 'contact', 'gebruiker'];
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
        $results = [
            'openRegister' => false,
            'autoConfigured' => false,
            'fullyConfigured' => false,
            'errors' => [],
        ];

        try {
            // Check if OpenRegister is installed and enabled
            if (!$this->isOpenRegisterInstalled($minOpenRegisterVersion)) {
                $results['errors'][] = 'OpenRegister is not installed or does not meet minimum version requirements';
                return $results;
            }

            if (!$this->isOpenRegisterEnabled()) {
                $results['errors'][] = 'OpenRegister is not enabled';
                return $results;
            }

            $results['openRegister'] = true;

            // Try auto-configuration if not already configured
            if (!$this->isFullyConfigured()) {
                try {
                    $configuration = $this->autoConfigure();
                    if (!empty($configuration)) {
                        $this->updateSettings($configuration);
                        $results['autoConfigured'] = true;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = 'Auto-configuration failed: ' . $e->getMessage();
                }
            }

            $results['fullyConfigured'] = $this->isFullyConfigured();

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Load settings from register configuration files
     *
     * @return array The loaded settings configuration
     *
     * @throws \RuntimeException If settings loading fails
     */
    public function loadSettings(): array
    {
        $results = [];
        
        try {
            // Load settings from voorzieningen_register.json
            $voorzieningenPath = __DIR__ . '/../Settings/voorzieningen_register.json';
            if (file_exists($voorzieningenPath)) {
                $voorzieningenContent = file_get_contents($voorzieningenPath);
                $voorzieningenSettings = json_decode($voorzieningenContent, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    $results['voorzieningen'] = $voorzieningenSettings;
                    
                    // Import via configuration service if available
                    try {
                        $configurationService = $this->getConfigurationService();
                        $configurationService->importFromJson($voorzieningenSettings, false);
                        $results['voorzieningen_imported'] = true;
                    } catch (\Exception $e) {
                        $results['voorzieningen_import_error'] = $e->getMessage();
                    }
                }
            }

            // Load settings from amef_register.json  
            $amefPath = __DIR__ . '/../Settings/amef_register.json';
            if (file_exists($amefPath)) {
                $amefContent = file_get_contents($amefPath);
                $amefSettings = json_decode($amefContent, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    $results['amef'] = $amefSettings;
                    
                    // Import via configuration service if available
                    try {
                        $configurationService = $this->getConfigurationService();
                        $configurationService->importFromJson($amefSettings, false);
                        $results['amef_imported'] = true;
                    } catch (\Exception $e) {
                        $results['amef_import_error'] = $e->getMessage();
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
} 