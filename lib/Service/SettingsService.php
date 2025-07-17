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
                'objectTypes' => ['organization'] // AMEF uses organization schema
            ],
            'voorzieningen' => [
                'name' => 'Voorzieningen', 
                'description' => 'Voorzieningen register for software catalog services',
                'objectTypes' => ['organisatie', 'contactpersoon'] // Voorzieningen uses organisatie and contactpersoon schemas
            ]
        ];
        
        // For backward compatibility, keep the original object types structure
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
        

        
        if ($objectType === 'organisatie') {
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_schema', '');
            if (!empty($schemaId)) {
                return (int) $schemaId;
            }
        }
        
        if ($objectType === 'contactpersoon') {
            $schemaId = $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_schema', '');
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
     * Gets the configured register ID for the voorzieningen register
     *
     * @return int|null The register ID or null if not configured
     */
    public function getVoorzieningenRegisterId(): ?int
    {
        // Try voorzieningen-specific configuration first
        $registerId = $this->config->getValueString($this->_appName, 'voorzieningen_organisatie_register', '');
        if (!empty($registerId)) {
            return (int) $registerId;
        }
        
        // Also try contactpersoon as fallback
        $registerId = $this->config->getValueString($this->_appName, 'voorzieningen_contactpersoon_register', '');
        if (!empty($registerId)) {
            return (int) $registerId;
        }
        
        // Fall back to organization register for backward compatibility
        return $this->getRegisterIdForObjectType('organization');
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
        return [
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
            ],
        ];
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
    private function getDefaultEmailTemplate(string $templateName): string
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
     * Tests email sending functionality
     *
     * @param string $testEmail Email address to send test email to
     *
     * @return array Test results
     */
    public function testEmailSending(string $testEmail): array
    {
        try {
            // Get SymfonyEmailService
            $emailService = $this->container->get(\OCA\SoftwareCatalog\Service\SymfonyEmailService::class);
            
            $success = $emailService->sendTestEmail($testEmail);
            
            return [
                'success' => $success,
                'message' => $success ? 'Test email sent successfully using Symfony Mailer!' : 'Failed to send test email using Symfony Mailer',
                'testEmail' => $testEmail
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to test email sending: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'testEmail' => $testEmail
            ];
        }
    }
} 