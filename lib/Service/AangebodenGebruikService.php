<?php

declare(strict_types=1);

/**
 * AangebodenGebruik Service for SoftwareCatalog
 * 
 * Handles operations related to offered usage (aangeboden gebruik) including
 * filtering gebruiks objects where the active organization is involved as
 * afnemer (consumer) or in deelnemers (participants).
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * Service for managing offered usage (aangeboden gebruik) operations
 * 
 * This service provides operations for querying gebruiks objects where the active
 * organization is involved either as the afnemer (consumer) or in the deelnemers
 * (participants) array, and for updating the @self property of gebruiks objects.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class AangebodenGebruikService
{
    /**
     * Constructor for AangebodenGebruikService
     * 
     * @param IAppConfig $config Nextcloud app configuration service
     * @param IAppManager $appManager App manager service for checking available apps
     * @param ContainerInterface $container PSR-11 container interface for dependency injection
     * @param LoggerInterface $logger Logger service for debugging and error reporting
     * @param SettingsService $settingsService Settings service for retrieving configuration
     * @param IUserSession $userSession User session service for current user context
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession
    ) {
    }

    /**
     * Get all gebruiks objects where the active organization is the afnemer (consumer)
     * 
     * This method retrieves all gebruiks objects where the active organization
     * appears as the afnemer using standard RBAC filtering.
     * 
     * @param array $options Additional query options (limit, offset, filters, etc.)
     * @return array Array with success status, gebruiks data, and metadata
     * @throws Exception When OpenRegister service is not available
     */
    public function getGebruiksWhereAfnemer(array $options = []): array
    {
        $this->logger->info('Getting gebruiks objects where active org is afnemer', [
            'options' => $options
        ]);

        try {
            // Get ObjectService from OpenRegister
            $objectService = $this->getObjectService();
            
            // Get current organization
            $currentOrg = $this->getCurrentOrganisation();
            if (!$currentOrg) {
                $this->logger->warning('No current organization available for afnemer filtering');
                return [
                    'success' => true,
                    'gebruiks' => [],
                    'count' => 0,
                    'message' => 'No current organization available'
                ];
            }

            // Get configuration for gebruiks register/schema
            $gebruiksConfig = $this->getGebruiksConfiguration();
            
            $allGebruiks = [];
            
            // Search each configured schema for gebruiks where org is afnemer
            foreach ($gebruiksConfig['schemas'] as $schemaId) {
                if (!$schemaId) continue;
                
                try {
                    // Build query for afnemer filtering with RBAC enabled
                    $query = [
                        '@self' => [
                            'register' => $gebruiksConfig['register_id'],
                            'schema' => $schemaId,
                            'organisation' => $currentOrg // Standard RBAC filtering
                        ]
                    ];
                    
                    // Add additional filters from options
                    $query = $this->addQueryFilters($query, $options);
                    
                    // Execute search with RBAC enabled (default behavior)
                    $gebruikItems = $objectService->searchObjects($query);
                    
                    // Process and add to results
                    foreach ($gebruikItems as $gebruik) {
                        $gebruik['_filter_type'] = 'afnemer';
                        $gebruik['_schema_id'] = $schemaId;
                        $allGebruiks[] = $gebruik;
                    }
                    
                    $this->logger->debug('Retrieved afnemer gebruiks from schema', [
                        'schema_id' => $schemaId,
                        'count' => count($gebruikItems),
                        'organisation' => $currentOrg
                    ]);
                    
                } catch (Exception $e) {
                    $this->logger->warning('Failed to get afnemer gebruiks from schema', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'success' => true,
                'gebruiks' => $allGebruiks,
                'count' => count($allGebruiks),
                'filter_type' => 'afnemer',
                'organisation' => $currentOrg
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get afnemer gebruiks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve gebruiks: ' . $e->getMessage(),
                'gebruiks' => [],
                'count' => 0
            ];
        }
    }

    /**
     * Get all gebruiks objects where the active organization is in deelnemers (participants)
     * 
     * This method retrieves all gebruiks objects where the active organization
     * appears in the deelnemers array, using RBAC-disabled search.
     * 
     * @param array $options Additional query options (limit, offset, filters, etc.)
     * @return array Array with success status, gebruiks data, and metadata
     * @throws Exception When OpenRegister service is not available
     */
    public function getGebruiksWhereDeelnemers(array $options = []): array
    {
        $this->logger->info('Getting gebruiks objects where active org is in deelnemers', [
            'options' => $options
        ]);

        try {
            // Get ObjectService from OpenRegister
            $objectService = $this->getObjectService();
            
            // Get current organization
            $currentOrg = $this->getCurrentOrganisation();
            if (!$currentOrg) {
                $this->logger->warning('No current organization available for deelnemers filtering');
                return [
                    'success' => true,
                    'gebruiks' => [],
                    'count' => 0,
                    'message' => 'No current organization available'
                ];
            }

            // Get configuration for gebruiks register/schema
            $gebruiksConfig = $this->getGebruiksConfiguration();
            
            $allGebruiks = [];
            
            // Search each configured schema for gebruiks where org is in deelnemers
            foreach ($gebruiksConfig['schemas'] as $schemaId) {
                if (!$schemaId) continue;
                
                try {
                    // Build query for deelnemers filtering
                    $query = [
                        '@self' => [
                            'register' => $gebruiksConfig['register_id'],
                            'schema' => $schemaId
                        ],
                        'deelnemers' => $currentOrg // Search where current org is in deelnemers
                    ];
                    
                    // Add additional filters from options
                    $query = $this->addQueryFilters($query, $options);
                    
                    // Execute search with RBAC disabled to find deelnemers
                    $gebruikItems = $objectService->searchObjects($query, rbac: false);
                    
                    // Process and add to results
                    foreach ($gebruikItems as $gebruik) {
                        $gebruik['_filter_type'] = 'deelnemers';
                        $gebruik['_schema_id'] = $schemaId;
                        $allGebruiks[] = $gebruik;
                    }
                    
                    $this->logger->debug('Retrieved deelnemers gebruiks from schema', [
                        'schema_id' => $schemaId,
                        'count' => count($gebruikItems),
                        'organisation_in_deelnemers' => $currentOrg
                    ]);
                    
                } catch (Exception $e) {
                    $this->logger->warning('Failed to get deelnemers gebruiks from schema', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'success' => true,
                'gebruiks' => $allGebruiks,
                'count' => count($allGebruiks),
                'filter_type' => 'deelnemers',
                'organisation' => $currentOrg
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to get deelnemers gebruiks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve gebruiks: ' . $e->getMessage(),
                'gebruiks' => [],
                'count' => 0
            ];
        }
    }

    /**
     * Set the @self property of a gebruik to the active organization
     * 
     * This method updates the @self.organisation property of a specific gebruik
     * object, but only if the active organization is the afnemer for that gebruik.
     * 
     * @param string $gebruikId The UUID of the gebruik object to update
     * @param array $options Additional update options
     * @return array Result with success status and updated object data
     * @throws Exception When OpenRegister service is not available or operation fails
     */
    public function setGebruikSelfToActiveOrg(string $gebruikId, array $options = []): array
    {
        $this->logger->info('Setting gebruik @self property to active organisation', [
            'gebruik_id' => $gebruikId,
            'options' => $options
        ]);

        try {
            // Validate input
            if (empty($gebruikId)) {
                return [
                    'success' => false,
                    'error' => 'Gebruik ID is required',
                    'gebruik' => null
                ];
            }

            // Get ObjectService from OpenRegister
            $objectService = $this->getObjectService();
            
            // Get current organization
            $currentOrg = $this->getCurrentOrganisation();
            if (!$currentOrg) {
                return [
                    'success' => false,
                    'error' => 'No current organization available',
                    'gebruik' => null
                ];
            }

            // Get the existing gebruik object
            $existingGebruik = $objectService->getObject($gebruikId);
            if (!$existingGebruik) {
                return [
                    'success' => false,
                    'error' => 'Gebruik object not found',
                    'gebruik' => null
                ];
            }

            // Verify that the active organization is the afnemer
            $gebruikData = $existingGebruik->getObject();
            $afnemerInfo = $gebruikData['afnemer'] ?? null;
            
            // Check various ways the afnemer might be stored (UUID, object, or string)
            $afnemerId = null;
            if (is_array($afnemerInfo) && isset($afnemerInfo['id'])) {
                $afnemerId = $afnemerInfo['id'];
            } elseif (is_string($afnemerInfo)) {
                $afnemerId = $afnemerInfo;
            }

            if (!$afnemerId || $afnemerId !== $currentOrg) {
                return [
                    'success' => false,
                    'error' => 'Operation not allowed: active organization is not the afnemer',
                    'gebruik' => null,
                    'debug' => [
                        'afnemer_in_object' => $afnemerInfo,
                        'resolved_afnemer_id' => $afnemerId,
                        'current_org' => $currentOrg
                    ]
                ];
            }

            // Update the @self.organisation property
            $selfData = $gebruikData['@self'] ?? [];
            $selfData['organisation'] = $currentOrg;
            $gebruikData['@self'] = $selfData;

            // Save the updated object
            $existingGebruik->setObject($gebruikData);
            $updatedGebruik = $objectService->saveObject($existingGebruik);

            $this->logger->info('Successfully updated gebruik @self property', [
                'gebruik_id' => $gebruikId,
                'organisation' => $currentOrg,
                'afnemer_verified' => $afnemerId
            ]);

            return [
                'success' => true,
                'message' => 'Gebruik @self property updated successfully',
                'gebruik' => $updatedGebruik->getObject(),
                'updated_fields' => ['@self.organisation']
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to update gebruik @self property', [
                'gebruik_id' => $gebruikId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to update gebruik: ' . $e->getMessage(),
                'gebruik' => null
            ];
        }
    }

    /**
     * Get current active organisation for filtering
     * 
     * @return string|null Current organisation identifier or null if no user session
     */
    private function getCurrentOrganisation(): ?string
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }
        
        // Get user's organization from configuration or session
        // This follows the same pattern as ViewService
        $userOrg = $this->config->getUserValue(
            $user->getUID(), 
            'softwarecatalog', 
            'organisation', 
            null
        );
        
        return $userOrg;
    }

    /**
     * Get ObjectService from OpenRegister app
     * 
     * @return ObjectService The OpenRegister object service
     * @throws Exception When OpenRegister service is not available
     */
    private function getObjectService(): ObjectService
    {
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            throw new Exception('OpenRegister app is not installed');
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Exception $e) {
            throw new Exception('Failed to get OpenRegister service: ' . $e->getMessage());
        }
    }

    /**
     * Get configuration for gebruiks objects (register ID and schema IDs)
     * 
     * @return array Configuration with register_id and schemas array
     */
    private function getGebruiksConfiguration(): array
    {
        // Get AMEF configuration which includes gebruiks schemas
        $amefConfig = $this->settingsService->getAmefConfig();
        
        return [
            'register_id' => $amefConfig['register_id'] ?? null,
            'schemas' => $amefConfig['gebruik_schemas'] ?? []
        ];
    }

    /**
     * Add query filters from options to the base query
     * 
     * This method processes additional filter options and adds them to the query.
     * Supported filters: limit, offset, status, product, etc.
     * 
     * @param array $baseQuery The base query to extend
     * @param array $options Filter options to apply
     * @return array Extended query with additional filters
     */
    private function addQueryFilters(array $baseQuery, array $options): array
    {
        // Add limit if specified
        if (isset($options['limit']) && is_numeric($options['limit'])) {
            $baseQuery['@limit'] = (int)$options['limit'];
        }
        
        // Add offset if specified
        if (isset($options['offset']) && is_numeric($options['offset'])) {
            $baseQuery['@offset'] = (int)$options['offset'];
        }
        
        // Add status filter if specified
        if (isset($options['status']) && !empty($options['status'])) {
            $baseQuery['status'] = $options['status'];
        }
        
        // Add product filter if specified
        if (isset($options['product']) && !empty($options['product'])) {
            $baseQuery['product'] = $options['product'];
        }
        
        // Add date filters if specified
        if (isset($options['startDate']) && !empty($options['startDate'])) {
            $baseQuery['startDate'] = $options['startDate'];
        }
        
        if (isset($options['endDate']) && !empty($options['endDate'])) {
            $baseQuery['endDate'] = $options['endDate'];
        }
        
        return $baseQuery;
    }
}


