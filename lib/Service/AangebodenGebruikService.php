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
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'pages' => 0,
                    'limit' => 20,
                    'offset' => 0,
                    'message' => 'No current organization available'
                ];
            }

            // Get configuration for gebruiks register/schema
            $gebruiksConfig = $this->getGebruiksConfiguration();
            
            // Use the first schema for now (can be extended for multi-schema support)
            $schemaId = $gebruiksConfig['schemas'][0] ?? null;
            if (!$schemaId) {
                throw new Exception('No gebruik schema configured');
            }
            
            // Build query for afnemer filtering - search for objects where current org is afnemer
            // Note: We don't filter by organisation in @self since the objects are owned by leveranciers
            $query = [
                '@self' => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema' => $schemaId
                ],
                'afnemer' => $currentOrg // Filter by afnemer field instead of ownership
            ];
            
            // Add additional filters from options (pagination, etc.)
            $query = $this->addQueryFilters($query, $options);
            
            $this->logger->debug('AangebodenGebruikService: Executing search query', [
                'query' => $query,
                'schema_id' => $schemaId,
                'current_org' => $currentOrg
            ]);
            
            // Execute search with RBAC and multitenancy disabled to find cross-organisation objects
            // Return the searchObjectsPaginated result directly - it's already properly formatted
            $searchResult = $objectService->searchObjectsPaginated(
                query: $query,
                rbac: false,  // Disable RBAC to find cross-organisation objects
                multi: false  // Disable multitenancy to find objects from other organisations
            );
            
            $this->logger->debug('AangebodenGebruikService: Search completed', [
                'total' => $searchResult['total'] ?? 0,
                'results_count' => count($searchResult['results'] ?? []),
                'organisation' => $currentOrg
            ]);
            
            return $searchResult;

        } catch (Exception $e) {
            $this->logger->error('Failed to get afnemer gebruiks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 0,
                'limit' => 20,
                'offset' => 0,
                'error' => 'Failed to retrieve gebruiks: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all gebruiks objects (ignoring RBAC and multitenancy) - restricted to ambtenaar group
     * 
     * This method retrieves all gebruiks objects regardless of ownership or organization,
     * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
     * with the "ambtenaar" group.
     * 
     * @param array $options Additional query options (limit, offset, filters, etc.)
     * @return array searchObjectsPaginated result with all gebruiks
     * @throws Exception When OpenRegister service is not available
     */
    public function getAllGebruiksForAmbtenaar(array $options = []): array
    {
        $this->logger->info('Getting all gebruiks objects for ambtenaar (ignoring RBAC/multitenancy)', [
            'options' => $options
        ]);

        try {
            // Get ObjectService from OpenRegister
            $objectService = $this->getObjectService();
            
            // Get configuration for gebruiks register/schema
            $gebruiksConfig = $this->getGebruiksConfiguration();
            
            // Use the first schema for now (can be extended for multi-schema support)
            $schemaId = $gebruiksConfig['schemas'][0] ?? null;
            if (!$schemaId) {
                throw new Exception('No gebruik schema configured');
            }
            
            // Build query for all gebruiks - no organization filtering
            $query = [
                '@self' => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema' => $schemaId
                ]
            ];
            
            // Add additional filters from options (pagination, search, etc.)
            $query = $this->addQueryFilters($query, $options);
            
            // Force use of database source (not index/SOLR) like PublicationsController
            $query['_source'] = 'database';
            
            $this->logger->debug('AangebodenGebruikService: Executing ambtenaar search query', [
                'query' => $query,
                'schema_id' => $schemaId
            ]);
            
            // Execute search with RBAC and multitenancy disabled to get ALL objects
            // Use database source and include unpublished objects
            $searchResult = $objectService->searchObjectsPaginated(
                query: $query,
                rbac: false,  // Disable RBAC to access all objects
                multi: false, // Disable multitenancy to access objects from all organisations
                published: false,  // Include unpublished objects
                deleted: false     // Exclude deleted objects
            );
            
            $this->logger->debug('AangebodenGebruikService: Ambtenaar search completed', [
                'total' => $searchResult['total'] ?? 0,
                'results_count' => count($searchResult['results'] ?? [])
            ]);
            
            return $searchResult;

        } catch (Exception $e) {
            $this->logger->error('Failed to get all gebruiks for ambtenaar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 0,
                'limit' => 20,
                'offset' => 0,
                'error' => 'Failed to retrieve gebruiks: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all gebruiks objects belonging to a specific product ID (ignoring RBAC and multitenancy) - restricted to ambtenaar group
     * 
     * This method retrieves all gebruiks objects that belong to the specified product ID, 
     * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users 
     * with the "ambtenaar" group.
     * 
     * @param string $productId The ID of the product to get gebruiks for
     * @param array $options Additional query options (extend, fields, etc.)
     * @return array searchObjectsPaginated result with all gebruiks for the product
     * @throws Exception When OpenRegister service is not available
     */
    public function getSingleGebruikForAmbtenaar(string $productId, array $options = []): array
    {
        $this->logger->info('Getting all gebruiks for product ID for ambtenaar (ignoring RBAC/multitenancy)', [
            'product_id' => $productId,
            'options' => $options
        ]);

        try {
            // Validate input
            if (empty($productId)) {
                return [
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'pages' => 0,
                    'limit' => 20,
                    'offset' => 0,
                    'error' => 'Product ID is required'
                ];
            }

            // Get ObjectService from OpenRegister
            $objectService = $this->getObjectService();
            
            // Get configuration for gebruiks register/schema
            $gebruiksConfig = $this->getGebruiksConfiguration();
            
            // Use the first schema for now (can be extended for multi-schema support)
            $schemaId = $gebruiksConfig['schemas'][0] ?? null;
            if (!$schemaId) {
                throw new Exception('No gebruik schema configured');
            }
            
            // Build query for all gebruiks that reference the specified UUID in their relations
            // Follow the same pattern as PublicationsController.php used() method
            $query = [
                '@self' => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema' => $schemaId
                ]
            ];
            
            // Add additional filters from options (extend, fields, etc.)
            $query = $this->addQueryFilters($query, $options);
            
            // Force use of database source (not index/SOLR) like PublicationsController
            $query['_source'] = 'database';
            
            $this->logger->debug('AangebodenGebruikService: Executing uses-based query for ambtenaar', [
                'query' => $query,
                'schema_id' => $schemaId,
                'uses_uuid' => $productId
            ]);
            
            // Execute search following PublicationsController.php used() method pattern
            // Use database source and uses parameter for relationship filtering
            $searchResult = $objectService->searchObjectsPaginated(
                query: $query,
                rbac: false,  // Disable RBAC to access any object
                multi: false, // Disable multitenancy to access objects from any organisation
                published: false,  // Include unpublished objects
                deleted: false,    // Exclude deleted objects
                uses: $productId   // Find objects that have this UUID in their relations array
            );
            
            $this->logger->debug('AangebodenGebruikService: Uses-based query completed', [
                'total' => $searchResult['total'] ?? 0,
                'results_count' => count($searchResult['results'] ?? []),
                'uses_uuid' => $productId
            ]);
            
            return $searchResult;

        } catch (Exception $e) {
            $this->logger->error('Failed to get gebruiks by uses relationship', [
                'uses_uuid' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 0,
                'limit' => 20,
                'offset' => 0,
                'error' => 'Failed to retrieve gebruik: ' . $e->getMessage()
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

            // Get the existing gebruik object with RBAC and multitenancy disabled
            // since the object might be owned by a different organisation (leverancier)
            try {
                $existingGebruik = $objectService->find(
                    id: $gebruikId,
                    rbac: false,  // Disable RBAC to access cross-organisation objects
                    multi: false  // Disable multitenancy to access objects from other organisations
                );
            } catch (Exception $e) {
                $this->logger->warning('Failed to find gebruik object', [
                    'gebruik_id' => $gebruikId,
                    'error' => $e->getMessage()
                ]);
                $existingGebruik = null;
            }
            
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

            // Save the updated object with RBAC and multitenancy disabled
            // since we're updating an object that was originally created by another organisation
            $existingGebruik->setObject($gebruikData);
            $gebruiksConfig = $this->getGebruiksConfiguration();
            $updatedGebruik = $objectService->saveObject(
                object: $existingGebruik,
                register: $gebruiksConfig['register_id'],  // Provide register context
                schema: $gebruiksConfig['schemas'][0],     // Provide schema context
                uuid: $gebruikId,                          // Provide UUID for update
                rbac: false,  // Disable RBAC to allow cross-organisation updates
                multi: false  // Disable multitenancy to allow updates from different organisations
            );

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
        
        try {
            // Get the OpenRegister OrganisationService to get the active organisation
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
            $activeOrg = $organisationService->getActiveOrganisation();
            
            if ($activeOrg) {
                return $activeOrg->getUuid();
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get current organisation from OpenRegister', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
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
        // Try to get voorzieningen configuration from SettingsService
        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            
            $this->logger->debug('Retrieved voorzieningen configuration', [
                'config' => $voorzieningenConfig
            ]);
            
            $registerId = $voorzieningenConfig['register'] ?? null;
            $gebruikSchema = $voorzieningenConfig['gebruik_schema'] ?? null;
            
            // If configuration is available, use it
            if ($registerId && $gebruikSchema) {
                return [
                    'register_id' => $registerId,
                    'schemas' => [$gebruikSchema]
                ];
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to get voorzieningen configuration from SettingsService', [
                'error' => $e->getMessage()
            ]);
        }
        
        // No hardcoded fallback - configuration must be properly set
        $this->logger->error('Failed to get voorzieningen configuration - no fallback provided', [
            'registerId' => $registerId ?? 'null',
            'gebruikSchema' => $gebruikSchema ?? 'null',
            'voorzieningenConfig' => $voorzieningenConfig ?? 'null'
        ]);
        
        throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
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
        
        // Add source parameter if specified (for forcing database access)
        if (isset($options['_source']) && !empty($options['_source'])) {
            $baseQuery['_source'] = $options['_source'];
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

    /**
     * Delete (deny) a gebruik object with security validation
     * 
     * This method allows deleting a gebruik object, but only if the active organization
     * is the afnemer (consumer) for that gebruik. This implements the "deny" workflow
     * where a gemeente can reject a suggestion from a leverancier.
     * 
     * Security: Since we disable RBAC to access cross-organisation objects, we must
     * implement our own security checks to ensure only the afnemer can delete.
     * 
     * @param string $gebruikId The UUID of the gebruik object to delete
     * @param array $options Additional options for the operation
     * @return array Result array with success status and details
     */
    public function deleteGebruikAsAfnemer(string $gebruikId, array $options = []): array
    {
        $this->logger->info('Deleting gebruik object as afnemer', [
            'gebruik_id' => $gebruikId,
            'options' => $options
        ]);

        try {
            // Validate input
            if (empty($gebruikId)) {
                return [
                    'success' => false,
                    'error' => 'Gebruik ID is required',
                    'deleted' => false
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
                    'deleted' => false
                ];
            }

            // Get the existing gebruik object with RBAC and multitenancy disabled
            // since the object might be owned by a different organisation (leverancier)
            // Use searchObjectsPaginated since it works better for cross-organisation access
            $gebruiksConfig = $this->getGebruiksConfiguration();
            $searchQuery = [
                '@self' => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema' => $gebruiksConfig['schemas'][0],
                    'id' => $gebruikId
                ]
            ];
            
            try {
                $searchResult = $objectService->searchObjectsPaginated(
                    query: $searchQuery,
                    rbac: false,  // Disable RBAC to access cross-organisation objects
                    multi: false  // Disable multitenancy to access objects from other organisations
                );
                
                $existingGebruik = null;
                $gebruikData = null;
                
                if (isset($searchResult['results']) && count($searchResult['results']) > 0) {
                    $gebruikData = $searchResult['results'][0];
                    $this->logger->debug('Found gebruik object for deletion', [
                        'gebruik_id' => $gebruikId,
                        'afnemer' => $gebruikData['afnemer'] ?? 'unknown'
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->warning('Failed to find gebruik object for deletion', [
                    'gebruik_id' => $gebruikId,
                    'error' => $e->getMessage()
                ]);
                $gebruikData = null;
            }
            
            if (!$gebruikData) {
                return [
                    'success' => false,
                    'error' => 'Gebruik object not found',
                    'deleted' => false
                ];
            }

            // SECURITY CHECK: Verify that the active organization is the afnemer
            // This is critical since we're bypassing RBAC
            $afnemerInfo = $gebruikData['afnemer'] ?? null;
            
            // Check various ways the afnemer might be stored (UUID, object, or string)
            $afnemerId = null;
            if (is_array($afnemerInfo) && isset($afnemerInfo['id'])) {
                $afnemerId = $afnemerInfo['id'];
            } elseif (is_string($afnemerInfo)) {
                $afnemerId = $afnemerInfo;
            }

            if (!$afnemerId || $afnemerId !== $currentOrg) {
                $this->logger->warning('Unauthorized delete attempt - user is not afnemer', [
                    'gebruik_id' => $gebruikId,
                    'current_org' => $currentOrg,
                    'afnemer_in_object' => $afnemerInfo,
                    'resolved_afnemer_id' => $afnemerId
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Operation not allowed: active organization is not the afnemer',
                    'deleted' => false,
                    'debug' => [
                        'afnemer_in_object' => $afnemerInfo,
                        'resolved_afnemer_id' => $afnemerId,
                        'current_org' => $currentOrg
                    ]
                ];
            }

            // Delete the object with RBAC and multitenancy disabled
            // We need to disable these since the object might be owned by another organisation
            // First set the register and schema context
            $objectService->setRegister($gebruiksConfig['register_id']);
            $objectService->setSchema($gebruiksConfig['schemas'][0]);
            
            $deleteResult = $objectService->deleteObject(
                uuid: $gebruikId,
                rbac: false,  // Disable RBAC to allow cross-organisation deletion
                multi: false  // Disable multitenancy to allow deletion from different organisations
            );

            $this->logger->info('Successfully deleted gebruik object', [
                'gebruik_id' => $gebruikId,
                'organisation' => $currentOrg,
                'afnemer_verified' => $afnemerId,
                'delete_result' => $deleteResult
            ]);

            return [
                'success' => true,
                'message' => 'Gebruik object deleted successfully',
                'deleted' => true,
                'gebruik_id' => $gebruikId,
                'organisation' => $currentOrg
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to delete gebruik object', [
                'gebruik_id' => $gebruikId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to delete gebruik: ' . $e->getMessage(),
                'deleted' => false
            ];
        }
    }
}


