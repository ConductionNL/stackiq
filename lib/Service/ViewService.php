<?php

/**
 * View Service for SoftwareCatalog
 * 
 * Handles view-specific operations including querying, enrichment with products,
 * and usage data (gebruik) integration.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   SoftwareCatalog Team
 * @license  AGPL-3.0
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * View Service for managing ArchiMate views with enrichment capabilities
 * 
 * This service provides operations for querying and enriching views with additional
 * data such as products, usage information (gebruik), and related data.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   SoftwareCatalog Team
 * @license  AGPL-3.0
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */
class ViewService
{
    /**
     * Constructor for ViewService
     * 
     * @param IAppConfig $config Nextcloud app configuration service
     * @param IAppManager $appManager App manager service
     * @param ContainerInterface $container PSR-11 container interface
     * @param LoggerInterface $logger Logger service
     * @param SettingsService $settingsService Settings service for configuration
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
     * Get all views from the system
     * 
     * @param array $options Query options including enrichment flags
     * @return array Array of view objects with optional enrichments
     */
    public function getAllViews(array $options = []): array
    {
        $this->logger->info('Getting all views', [
            'options' => $options
        ]);

        try {
            // Get all view objects from OpenRegister
            $views = $this->getViewsFromRegister();
            
            // Transform views to include critical API fields
            $views = $this->transformViews($views);
            
                    // Apply enrichments based on options
        if (!empty($options)) {
            $views = $this->enrichViews($views, $options);
        }
        
        // Apply module-to-viewNode expansion if modules are enabled
        if (isset($options['include_modules']) && $options['include_modules']) {
            $views = $this->expandModulesToViewNodes($views, $options);
        }
            
            return [
                'success' => true,
                'views' => $views,
                'count' => count($views),
                'enrichments_applied' => $this->getAppliedEnrichments($options)
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get all views', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'views' => [],
                'count' => 0
            ];
        }
    }

    /**
     * Get a specific view by ID
     * 
     * @param string $viewId The view identifier
     * @param array $options Query options including enrichment flags
     * @return array View object with optional enrichments or error response
     */
    public function getView(string $viewId, array $options = []): array
    {
        $this->logger->info('Getting specific view', [
            'view_id' => $viewId,
            'options' => $options
        ]);

        try {
            // Get specific view from OpenRegister
            $view = $this->getViewFromRegister($viewId);
            
            if (!$view) {
                return [
                    'success' => false,
                    'error' => "View with ID '$viewId' not found",
                    'view' => null
                ];
            }
            
            // Transform view to include critical API fields
            $view = $this->transformView($view);
            
            // Apply enrichments based on options
            if (!empty($options)) {
                $view = $this->enrichView($view, $options);
                
                // Apply module-to-viewNode expansion if modules are enabled
                if (isset($options['include_modules']) && $options['include_modules']) {
                    $views = $this->expandModulesToViewNodes([$view], $options);
                    $view = $views[0] ?? $view; // Get the expanded view back
                }
            }
            
            return [
                'success' => true,
                'view' => $view,
                'enrichments_applied' => $this->getAppliedEnrichments($options)
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get view', [
                'view_id' => $viewId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'view' => null
            ];
        }
    }

    /**
     * Get views from OpenRegister system
     * 
     * @return array Array of view objects
     */
    private function getViewsFromRegister(): array
    {
        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('OpenRegister ObjectService not available');
        }

        // Get AMEF configuration for view schema
        $amefConfig = $this->settingsService->getAmefConfig();
        $registerId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;
        $viewSchemaId = $amefConfig['view_schema'] ?? null;

        if (!$registerId || !$viewSchemaId) {
            throw new \RuntimeException('AMEF configuration not found for views');
        }

        // Query for all view objects
        $query = [
            '@self' => [
                'register' => $registerId,
                'schema' => $viewSchemaId
            ]
        ];

        $views = $objectService->searchObjects($query);

        $this->logger->debug('Retrieved views from register', [
            'register_id' => $registerId,
            'view_schema_id' => $viewSchemaId,
            'views_count' => count($views)
        ]);

        return $views;
    }

    /**
     * Get a specific view from OpenRegister system
     * 
     * @param string $viewId The view identifier
     * @return array|null View object or null if not found
     */
    private function getViewFromRegister(string $viewId): ?array
    {
        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('OpenRegister ObjectService not available');
        }

        // Get AMEF configuration for view schema
        $amefConfig = $this->settingsService->getAmefConfig();
        $registerId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;
        $viewSchemaId = $amefConfig['view_schema'] ?? null;

        if (!$registerId || !$viewSchemaId) {
            throw new \RuntimeException('AMEF configuration not found for views');
        }

        try {
            // Get specific view object by ID
            $view = $objectService->getObject($registerId, $viewSchemaId, $viewId);

            $this->logger->debug('Retrieved specific view from register', [
                'view_id' => $viewId,
                'register_id' => $registerId,
                'view_schema_id' => $viewSchemaId,
                'found' => $view !== null
            ]);

            return $view;
            
        } catch (\Exception $e) {
            $this->logger->warning('View not found in register', [
                'view_id' => $viewId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Enrich multiple views with additional data
     * 
     * @param array $views Array of view objects
     * @param array $options Enrichment options
     * @return array Array of enriched view objects
     */
    private function enrichViews(array $views, array $options): array
    {
        $enrichedViews = [];
        
        foreach ($views as $view) {
            $enrichedViews[] = $this->enrichView($view, $options);
        }
        
        return $enrichedViews;
    }

    /**
     * Enrich a single view with additional data
     * 
     * @param array $view View object to enrich
     * @param array $options Enrichment options
     * @return array Enriched view object
     */
    private function enrichView(array $view, array $options): array
    {
        $enrichedView = $view;
        
        // Enrich viewNodes if present
        if (isset($view['viewNodes']) && is_array($view['viewNodes'])) {
            $enrichedView['viewNodes'] = $this->enrichViewNodes($view['viewNodes'], $options);
        }
        
        // TODO: Add view-level enrichments here if needed
        
        return $enrichedView;
    }

    /**
     * Enrich view nodes with additional data based on options
     * 
     * @param array $viewNodes Array of view nodes
     * @param array $options Enrichment options
     * @return array Array of enriched view nodes
     */
    private function enrichViewNodes(array $viewNodes, array $options): array
    {
        $enrichedNodes = [];
        
        // Get enrichment data upfront if requested
        $productsData = [];
        $modulesData = [];
        $gebruikData = [];
        $deelnamesGebruikData = [];
        
        if ($this->shouldIncludeProducts($options)) {
            $productsData = $this->getProductsData();
        }
        
        if ($this->shouldIncludeModules($options)) {
            $modulesData = $this->getModulesData();
        }
        
        if ($this->shouldIncludeGebruik($options)) {
            $gebruikData = $this->getGebruikData($options);
        }
        
        if ($this->shouldIncludeDeelnamesGebruik($options)) {
            $deelnamesGebruikData = $this->getDeelnamesGebruikData();
        }
        
        foreach ($viewNodes as $node) {
            $enrichedNode = $node;
            
            // Apply enrichments based on the node's modelNodeId (element reference)
            $modelNodeId = $node['modelNodeId'] ?? null;
            
            if ($modelNodeId) {
                if ($this->shouldIncludeProducts($options)) {
                    $enrichedNode['products'] = $this->getNodeProducts($modelNodeId, $productsData);
                }
                
                if ($this->shouldIncludeModules($options)) {
                    $enrichedNode['modules'] = $this->getNodeModules($modelNodeId, $modulesData);
                }
                
                if ($this->shouldIncludeGebruik($options)) {
                    $enrichedNode['gebruik'] = $this->getNodeGebruik($modelNodeId, $gebruikData);
                }
                
                if ($this->shouldIncludeDeelnamesGebruik($options)) {
                    $enrichedNode['deelnamesGebruik'] = $this->getNodeDeelnamesGebruik($modelNodeId, $deelnamesGebruikData);
                }
            }
            
            $enrichedNodes[] = $enrichedNode;
        }
        
        return $enrichedNodes;
    }

    /**
     * Check if products should be included in enrichment
     * 
     * @param array $options Enrichment options
     * @return bool True if products should be included
     */
    private function shouldIncludeProducts(array $options): bool
    {
        return isset($options['include_products']) && $options['include_products'] === true;
    }

    /**
     * Check if modules should be included in enrichment
     * 
     * @param array $options Enrichment options
     * @return bool True if modules should be included
     */
    private function shouldIncludeModules(array $options): bool
    {
        return isset($options['include_modules']) && $options['include_modules'] === true;
    }

    /**
     * Check if gebruik should be included in enrichment
     * 
     * @param array $options Enrichment options
     * @return bool True if gebruik should be included
     */
    private function shouldIncludeGebruik(array $options): bool
    {
        return isset($options['include_gebruik']) && $options['include_gebruik'] === true;
    }

    /**
     * Check if deelnames gebruik should be included in enrichment
     * 
     * @param array $options Enrichment options
     * @return bool True if deelnames gebruik should be included
     */
    private function shouldIncludeDeelnamesGebruik(array $options): bool
    {
        return isset($options['include_deelnames_gebruik']) && $options['include_deelnames_gebruik'] === true;
    }

    /**
     * Get applied enrichments list for response metadata
     * 
     * @param array $options Enrichment options
     * @return array List of applied enrichments
     */
    private function getAppliedEnrichments(array $options): array
    {
        $enrichments = [];
        
        if ($this->shouldIncludeProducts($options)) {
            $enrichments[] = 'products';
        }
        
        if ($this->shouldIncludeModules($options)) {
            $enrichments[] = 'modules';
        }
        
        if ($this->shouldIncludeGebruik($options)) {
            $enrichments[] = 'gebruik';
        }
        
        if ($this->shouldIncludeDeelnamesGebruik($options)) {
            $enrichments[] = 'deelnames_gebruik';
        }
        
        return $enrichments;
    }

    /**
     * Get products data for enrichment (placeholder implementation)
     * 
     * @return array Products data
     */
    private function getProductsData(): array
    {
        // TODO: Implement actual products data retrieval
        $this->logger->debug('Getting products data for enrichment');
        return [];
    }

    /**
     * Get current active organisation for filtering
     * 
     * @return string|null Current organisation identifier
     */
    private function getCurrentOrganisation(): ?string
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }
        
        // TODO: Implement proper organisation determination logic
        // This could be based on user groups, settings, or other mechanisms
        // For now, return a placeholder that would need actual implementation
        
        $userId = $user->getUID();
        $this->logger->debug('Getting current organisation for user', [
            'user_id' => $userId
        ]);
        
        // Placeholder - needs actual implementation based on your organisation structure
        return 'default'; // Replace with actual organisation logic
    }

    /**
     * Get modules data for enrichment based on elementRef linkage
     * 
     * Modules are directly linked to nodes based on their elementRef.
     * This method retrieves all module data from OpenRegister, filtered by current organisation.
     * 
     * @return array Modules data indexed by elementRef
     */
    private function getModulesData(): array
    {
        $this->logger->debug('Getting modules data for enrichment');
        
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [];
            }

            // Get current organisation for filtering
            $currentOrg = $this->getCurrentOrganisation();
            
            // Get configuration for modules register/schema
            $amefConfig = $this->settingsService->getAmefConfig();
            $registerId = $amefConfig['register_id'] ?? null;
            
            // Modules could be in various schemas - check common ones
            $moduleSchemas = [
                $amefConfig['module_schema'] ?? null,
                $amefConfig['component_schema'] ?? null, 
                $amefConfig['element_schema'] ?? null
            ];

            $allModules = [];
            
            foreach ($moduleSchemas as $schemaId) {
                if (!$schemaId) continue;
                
                try {
                    $query = [
                        '@self' => [
                            'register' => $registerId,
                            'schema' => $schemaId
                        ]
                    ];
                    
                    // Add organisation filter if current organisation is available
                    if ($currentOrg) {
                        $query['@self']['organisation'] = $currentOrg;
                    }
                    
                    $modules = $objectService->searchObjects($query);
                    
                    foreach ($modules as $module) {
                        // Additional check for organisation in metadata if not caught by query
                        if ($currentOrg && isset($module['@self']['organisation']) && $module['@self']['organisation'] !== $currentOrg) {
                            continue;
                        }
                        
                        // Index by elementRef or identifier for quick lookup
                        $elementRef = $module['elementRef'] ?? $module['identifier'] ?? null;
                        if ($elementRef) {
                            $allModules[$elementRef] = $module;
                        }
                    }
                    
                    $this->logger->debug('Retrieved modules from schema', [
                        'schema_id' => $schemaId,
                        'modules_count' => count($modules)
                    ]);
                    
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to get modules from schema', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->logger->debug('Total modules retrieved for enrichment', [
                'total_modules' => count($allModules)
            ]);
            
            return $allModules;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get modules data', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get gebruik data for enrichment based on elementRef linkage
     * 
     * Usage data is linked to nodes based on their elementRef.
     * This method retrieves usage statistics from OpenRegister, filtered by organisation.
     * Also handles deelnames logic when enabled.
     * 
     * @param array $options Query options for deelnames handling
     * @return array Gebruik data indexed by elementRef with extended modules
     */
    private function getGebruikData(array $options = []): array
    {
        $this->logger->debug('Getting gebruik data for enrichment with options', ['options' => array_keys($options)]);
        
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [];
            }

            // Get current organisation for filtering
            $currentOrg = $this->getCurrentOrganisation();
            
            // Get configuration for usage register/schema
            $amefConfig = $this->settingsService->getAmefConfig();
            $registerId = $amefConfig['register_id'] ?? null;
            
            // Usage data could be in various schemas
            $gebruikSchemas = [
                $amefConfig['gebruik_schema'] ?? null,
                $amefConfig['usage_schema'] ?? null,
                $amefConfig['statistics_schema'] ?? null
            ];

            $allGebruik = [];
            
            // STEP 1: Get regular gebruik data filtered by current organisation
            foreach ($gebruikSchemas as $schemaId) {
                if (!$schemaId) continue;
                
                try {
                    $query = [
                        '@self' => [
                            'register' => $registerId,
                            'schema' => $schemaId
                        ]
                    ];
                    
                    // Add organisation filter for regular gebruik
                    if ($currentOrg) {
                        $query['@self']['organisation'] = $currentOrg;
                    }
                    
                    $gebruikItems = $objectService->searchObjects($query);
                    $this->processGebruikItems($gebruikItems, $allGebruik, $currentOrg, 'regular');
                    
                    $this->logger->debug('Retrieved regular gebruik from schema', [
                        'schema_id' => $schemaId,
                        'gebruik_count' => count($gebruikItems),
                        'organisation' => $currentOrg
                    ]);
                    
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to get regular gebruik from schema', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // STEP 2: If deelnames is enabled, get additional gebruik with RBAC off
            if (isset($options['include_deelnames_gebruik']) && $options['include_deelnames_gebruik'] && $currentOrg) {
                $this->logger->debug('Processing deelnames gebruik with RBAC disabled');
                
                foreach ($gebruikSchemas as $schemaId) {
                    if (!$schemaId) continue;
                    
                    try {
                        // Search with RBAC disabled to find gebruik where current org is in deelnemers
                        $query = [
                            '@self' => [
                                'register' => $registerId,
                                'schema' => $schemaId
                            ],
                            'deelnemers' => $currentOrg // Current org in deelnemers field
                        ];
                        
                        // Call with RBAC disabled
                        $deelnamesGebruikItems = $objectService->searchObjects($query, _rbac: false);
                        $this->processGebruikItems($deelnamesGebruikItems, $allGebruik, $currentOrg, 'deelnames');
                        
                        $this->logger->debug('Retrieved deelnames gebruik from schema', [
                            'schema_id' => $schemaId,
                            'deelnames_gebruik_count' => count($deelnamesGebruikItems),
                            'organisation_in_deelnemers' => $currentOrg
                        ]);
                        
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to get deelnames gebruik from schema', [
                            'schema_id' => $schemaId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // STEP 3: Extend gebruik with modules data
            $allGebruik = $this->extendGebruikWithModules($allGebruik);
            
            $this->logger->debug('Total gebruik retrieved and processed', [
                'total_element_refs_with_gebruik' => count($allGebruik),
                'current_organisation' => $currentOrg,
                'deelnames_enabled' => isset($options['include_deelnames_gebruik']) && $options['include_deelnames_gebruik']
            ]);
            
            return $allGebruik;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get gebruik data', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Process gebruik items and group them by elementRef
     * 
     * @param array $gebruikItems Array of gebruik objects
     * @param array &$allGebruik Array to add items to (by reference)  
     * @param string $type Type identifier ('regular' or 'deelnames')
     * @return void
     */
    private function processGebruikItems(array $gebruikItems, array &$allGebruik, string $currentOrg, string $type): void
    {
        foreach ($gebruikItems as $gebruik) {
            // Group by elementRef for quick lookup
            $elementRef = $gebruik['elementRef'] ?? $gebruik['componentRef'] ?? $gebruik['moduleRef'] ?? null;
            
            if ($elementRef) {
                if (!isset($allGebruik[$elementRef])) {
                    $allGebruik[$elementRef] = [];
                }
                
                // Add type indicator and processing info
                $gebruik['_type'] = $type;
                $gebruik['_processed_for_org'] = $currentOrg;
                
                $allGebruik[$elementRef][] = $gebruik;
            }
        }
    }

    /**
     * Extend gebruik data with modules information
     * 
     * @param array $allGebruik Gebruik data indexed by elementRef
     * @return array Extended gebruik data with modules
     */
    private function extendGebruikWithModules(array $allGebruik): array
    {
        $this->logger->debug('Extending gebruik data with modules information');
        
        // Get modules data for extension
        $modulesData = $this->getModulesData();
        
        foreach ($allGebruik as $elementRef => &$gebruikList) {
            // Check if we have a module for this elementRef
            if (isset($modulesData[$elementRef])) {
                $module = $modulesData[$elementRef];
                
                // Add module data to each gebruik item for this elementRef
                foreach ($gebruikList as &$gebruik) {
                    $gebruik['_linked_module'] = $module;
                    
                    $this->logger->debug('Linked module to gebruik', [
                        'element_ref' => $elementRef,
                        'gebruik_id' => $gebruik['id'] ?? $gebruik['identifier'] ?? 'unknown',
                        'module_id' => $module['id'] ?? $module['identifier'] ?? 'unknown',
                        'module_name' => $module['name'] ?? 'unnamed'
                    ]);
                }
                unset($gebruik); // Clean up reference
            }
        }
        unset($gebruikList); // Clean up reference
        
        return $allGebruik;
    }

    /**
     * Expand modules to viewNodes based on referentiecomponent relationships
     * 
     * For each module that has referentiecomponent relationships, add new nodes
     * to viewNodes with the module as parent.
     * 
     * @param array $views Array of views to process
     * @return array Views with expanded module nodes
     */
    private function expandModulesToViewNodes(array $views): array
    {
        $this->logger->debug('Expanding modules to viewNodes');
        
        // Get modules data for expansion
        $modulesData = $this->getModulesData();
        
        foreach ($views as &$view) {
            if (!isset($view['viewNodes']) || !is_array($view['viewNodes'])) {
                continue;
            }
            
            $originalNodes = $view['viewNodes'];
            $expandedNodes = $originalNodes;
            
            // Create a lookup of existing nodes by modelNodeId for quick parent matching
            $nodesByModelId = [];
            foreach ($originalNodes as $node) {
                $modelNodeId = $node['modelNodeId'] ?? null;
                if ($modelNodeId) {
                    $nodesByModelId[$modelNodeId] = $node;
                }
            }
            
            // Process each module for expansion
            foreach ($modulesData as $elementRef => $module) {
                $expandedNodes = $this->expandModuleToNodes($module, $expandedNodes, $nodesByModelId);
            }
            
            // Update the view with expanded nodes
            $view['viewNodes'] = $expandedNodes;
            
            $addedNodesCount = count($expandedNodes) - count($originalNodes);
            if ($addedNodesCount > 0) {
                $this->logger->debug('Added module nodes to view', [
                    'view_id' => $view['id'] ?? 'unknown',
                    'original_nodes' => count($originalNodes),
                    'expanded_nodes' => count($expandedNodes),
                    'added_nodes' => $addedNodesCount
                ]);
            }
        }
        unset($view); // Clean up reference
        
        return $views;
    }

    /**
     * Expand a single module to nodes based on its referentiecomponent relationships
     * 
     * @param array $module Module data
     * @param array $existingNodes Current view nodes
     * @param array $nodesByModelId Lookup of nodes by modelNodeId
     * @return array Updated nodes array with module expansions
     */
    private function expandModuleToNodes(array $module, array $existingNodes, array $nodesByModelId): array
    {
        $expandedNodes = $existingNodes;
        
        // Look for referentiecomponent relationships in the module
        $referentieComponenten = $this->extractReferentieComponenten($module);
        
        foreach ($referentieComponenten as $referentieComponentId) {
            // Find if there's an existing node for this referentiecomponent
            $parentNode = $nodesByModelId[$referentieComponentId] ?? null;
            
            if ($parentNode) {
                // Create a new node for this module as child of the referentiecomponent
                $moduleNode = $this->createModuleNode($module, $parentNode, $referentieComponentId);
                $expandedNodes[] = $moduleNode;
                
                $this->logger->debug('Created module node', [
                    'module_id' => $module['id'] ?? $module['identifier'] ?? 'unknown',
                    'module_name' => $module['name'] ?? 'unnamed',
                    'parent_node_id' => $parentNode['viewNodeId'] ?? 'unknown',
                    'referentie_component_id' => $referentieComponentId
                ]);
            }
        }
        
        return $expandedNodes;
    }

    /**
     * Extract referentiecomponent IDs from module data
     * 
     * @param array $module Module data
     * @return array Array of referentiecomponent identifiers
     */
    private function extractReferentieComponenten(array $module): array
    {
        $referentieComponenten = [];
        
        // Look for referentiecomponent relationships in various possible locations
        $possibleFields = [
            'referentiecomponenten',
            'referentieComponenten', 
            'relatedComponents',
            'components',
            'relationships'
        ];
        
        foreach ($possibleFields as $field) {
            if (isset($module[$field])) {
                $value = $module[$field];
                
                if (is_array($value)) {
                    // Handle array of components
                    foreach ($value as $component) {
                        if (is_string($component)) {
                            $referentieComponenten[] = $component;
                        } elseif (is_array($component) && isset($component['id'])) {
                            $referentieComponenten[] = $component['id'];
                        } elseif (is_array($component) && isset($component['identifier'])) {
                            $referentieComponenten[] = $component['identifier'];
                        }
                    }
                } elseif (is_string($value)) {
                    // Handle single component ID
                    $referentieComponenten[] = $value;
                }
            }
        }
        
        return array_unique($referentieComponenten);
    }

    /**
     * Create a module node as child of a referentiecomponent node
     * 
     * @param array $module Module data
     * @param array $parentNode Parent referentiecomponent node
     * @param string $referentieComponentId Referentiecomponent identifier
     * @return array New module node
     */
    private function createModuleNode(array $module, array $parentNode, string $referentieComponentId): array
    {
        $moduleId = $module['id'] ?? $module['identifier'] ?? uniqid('module_');
        $moduleName = $module['name'] ?? 'Unnamed Module';
        
        // Position the module node relative to parent (slightly offset)
        $parentX = $parentNode['x'] ?? 0;
        $parentY = $parentNode['y'] ?? 0;
        $parentWidth = $parentNode['width'] ?? 100;
        
        return [
            'modelNodeId' => $moduleId,
            'viewNodeId' => 'module-' . $moduleId . '-' . uniqid(),
            'x' => $parentX + $parentWidth + 20, // Position to the right of parent
            'y' => $parentY,
            'width' => 150,
            'height' => 50,
            'parent' => $parentNode['viewNodeId'] ?? null,
            'name' => $moduleName,
            'type' => 'module',
            'color' => 'rgb(200, 255, 200)', // Light green for modules
            'borderColor' => 'rgb(0, 150, 0)',
            'font' => [
                'name' => 'Segoe UI, Arial',
                'size' => 11,
                'style' => 'normal',
                'color' => 'rgb(0, 0, 0)'
            ],
            'description' => $module['description'] ?? $module['summary'] ?? null,
            'elementRef' => $moduleId,
            '_isModuleExpansion' => true,
            '_parentReferentieComponent' => $referentieComponentId,
            '_moduleData' => $module
        ];
    }

    /**
     * Get deelnames gebruik data for enrichment.
     * Queries gebruik objects where the current organisation is in the deelnemers field (RBAC off).
     *
     * @return array Deelnames gebruik data indexed by elementRef
     */
    private function getDeelnamesGebruikData(): array
    {
        $this->logger->debug('Getting deelnames gebruik data for enrichment');

        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [];
            }

            $currentOrg = $this->getCurrentOrganisation();
            if (!$currentOrg) {
                return [];
            }

            $amefConfig = $this->settingsService->getAmefConfig();
            $registerId = $amefConfig['register_id'] ?? null;

            $gebruikSchemas = [
                $amefConfig['gebruik_schema'] ?? null,
                $amefConfig['usage_schema'] ?? null,
                $amefConfig['statistics_schema'] ?? null
            ];

            $allDeelnames = [];

            foreach ($gebruikSchemas as $schemaId) {
                if (!$schemaId) continue;

                try {
                    $query = [
                        '@self' => [
                            'register' => $registerId,
                            'schema' => $schemaId
                        ],
                        'deelnemers' => $currentOrg
                    ];

                    $deelnamesItems = $objectService->searchObjects($query, _rbac: false);
                    $this->processGebruikItems($deelnamesItems, $allDeelnames, $currentOrg, 'deelnames');

                    $this->logger->debug('Retrieved deelnames gebruik data', [
                        'schema_id' => $schemaId,
                        'deelnames_count' => count($deelnamesItems)
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to get deelnames gebruik data', [
                        'schema_id' => $schemaId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $allDeelnames = $this->extendGebruikWithModules($allDeelnames);

            $this->logger->debug('Total deelnames gebruik retrieved', [
                'total_element_refs' => count($allDeelnames)
            ]);

            return $allDeelnames;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get deelnames gebruik data', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get products for a specific node (placeholder implementation)
     * 
     * @param string $modelNodeId The model node identifier
     * @param array $productsData Products data to search in
     * @return array Products related to the node
     */
    private function getNodeProducts(string $modelNodeId, array $productsData): array
    {
        // TODO: Implement actual node products matching logic
        $this->logger->debug('Getting products for node', ['model_node_id' => $modelNodeId]);
        return [];
    }

    /**
     * Get modules for a specific node based on elementRef linkage
     * 
     * @param string $modelNodeId The model node identifier (elementRef)
     * @param array $modulesData Modules data indexed by elementRef
     * @return array Modules related to the node
     */
    private function getNodeModules(string $modelNodeId, array $modulesData): array
    {
        $this->logger->debug('Getting modules for node', ['model_node_id' => $modelNodeId]);
        
        // Direct lookup by elementRef
        if (isset($modulesData[$modelNodeId])) {
            $module = $modulesData[$modelNodeId];
            
            $this->logger->debug('Found module for node', [
                'model_node_id' => $modelNodeId,
                'module_id' => $module['id'] ?? $module['identifier'] ?? 'unknown',
                'module_name' => $module['name'] ?? 'unnamed'
            ]);
            
            return [$module]; // Return as array for consistency
        }
        
        // No module found for this node
        return [];
    }

    /**
     * Get gebruik for a specific node based on elementRef linkage
     * 
     * @param string $modelNodeId The model node identifier (elementRef)
     * @param array $gebruikData Gebruik data indexed by elementRef
     * @return array Gebruik related to the node
     */
    private function getNodeGebruik(string $modelNodeId, array $gebruikData): array
    {
        $this->logger->debug('Getting gebruik for node', ['model_node_id' => $modelNodeId]);
        
        // Direct lookup by elementRef
        if (isset($gebruikData[$modelNodeId])) {
            $gebruikList = $gebruikData[$modelNodeId];
            
            $this->logger->debug('Found gebruik for node', [
                'model_node_id' => $modelNodeId,
                'gebruik_count' => count($gebruikList)
            ]);
            
            return $gebruikList;
        }
        
        // No usage data found for this node
        return [];
    }

    /**
     * Get deelnames gebruik for a specific node based on elementRef linkage.
     *
     * @param string $modelNodeId The model node identifier (elementRef)
     * @param array $deelnamesGebruikData Deelnames gebruik data indexed by elementRef
     * @return array Deelnames gebruik related to the node
     */
    private function getNodeDeelnamesGebruik(string $modelNodeId, array $deelnamesGebruikData): array
    {
        $this->logger->debug('Getting deelnames gebruik for node', ['model_node_id' => $modelNodeId]);

        if (isset($deelnamesGebruikData[$modelNodeId])) {
            $deelnamesList = $deelnamesGebruikData[$modelNodeId];

            $this->logger->debug('Found deelnames gebruik for node', [
                'model_node_id' => $modelNodeId,
                'deelnames_count' => count($deelnamesList)
            ]);

            return $deelnamesList;
        }

        return [];
    }

    /**
     * Get ObjectService from container
     * 
     * @return ObjectService|null ObjectService instance or null if not available
     */
    private function getObjectService(): ?ObjectService
    {
        if (!$this->appManager->isInstalled('openregister')) {
            return null;
        }

        try {
            return $this->container->get(ObjectService::class);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get ObjectService', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Transform multiple views to include critical API fields
     * 
     * @param array $views Array of view objects
     * @return array Array of transformed view objects
     */
    private function transformViews(array $views): array
    {
        $transformedViews = [];
        
        foreach ($views as $view) {
            $transformedViews[] = $this->transformView($view);
        }
        
        return $transformedViews;
    }

    /**
     * Transform a single view to include critical API fields
     * 
     * @param array $view View object to transform
     * @return array Transformed view object with critical API fields
     */
    private function transformView(array $view): array
    {
        $transformedView = $view;
        
        // Add view documentation from XML if available
        if (isset($view['xml']['documentation'])) {
            $transformedView['documentation'] = $view['xml']['documentation'];
        }
        
        // Transform properties from XML to required API format
        if (isset($view['xml']['properties']['property']) && is_array($view['xml']['properties']['property'])) {
            $transformedView['properties'] = $this->transformViewProperties($view['xml']['properties']['property']);
        }
        
        // Transform viewNodes to include critical fields
        if (isset($view['xml']['viewNodes']) && is_array($view['xml']['viewNodes'])) {
            $transformedView['viewNodes'] = $this->transformViewNodes($view['xml']['viewNodes']);
        }
        
        // Transform viewRelationships to include critical fields  
        if (isset($view['xml']['viewRelationships']) && is_array($view['xml']['viewRelationships'])) {
            $transformedView['viewRelationships'] = $this->transformViewRelationships($view['xml']['viewRelationships']);
        }
        
        return $transformedView;
    }

    /**
     * Transform view properties to required API format
     * 
     * @param array $properties Array of property objects from XML
     * @return array Array of transformed properties
     */
    private function transformViewProperties(array $properties): array
    {
        $transformedProperties = [];
        
        foreach ($properties as $property) {
            $transformedProperty = [
                'propertyDefinitionRef' => $property['_propertyDefinitionRef'] ?? $property['___propertyDefinitionRef'] ?? null,
                'value' => $property['value']['_value'] ?? null
            ];
            
            if ($transformedProperty['propertyDefinitionRef'] && $transformedProperty['value']) {
                $transformedProperties[] = $transformedProperty;
            }
        }
        
        return $transformedProperties;
    }

    /**
     * Transform viewNodes to include critical API fields
     * 
     * @param array $viewNodes Array of viewNode objects from XML
     * @return array Array of transformed viewNodes
     */
    private function transformViewNodes(array $viewNodes): array
    {
        $transformedNodes = [];
        
        foreach ($viewNodes as $node) {
            $transformedNode = $node; // Keep all existing fields
            
            // Add critical API fields
            $transformedNode['identifier'] = $node['viewNodeId'] ?? null;
            $transformedNode['documentation'] = $node['description'] ?? null;
            
            // Add position with width and height
            $transformedNode['position'] = [
                'x' => $node['x'] ?? null,
                'y' => $node['y'] ?? null,
                'w' => $node['width'] ?? null,
                'h' => $node['height'] ?? null
            ];
            
            // Add style information
            if (isset($node['color']) || isset($node['borderColor']) || isset($node['font'])) {
                $transformedNode['style'] = [
                    'fillColor' => $this->parseColor($node['color'] ?? null),
                    'lineColor' => $this->parseColor($node['borderColor'] ?? null),
                    'font' => $node['font'] ?? null
                ];
            }
            
            $transformedNodes[] = $transformedNode;
        }
        
        return $transformedNodes;
    }

    /**
     * Transform viewRelationships to include critical API fields
     * 
     * @param array $viewRelationships Array of viewRelationship objects from XML
     * @return array Array of transformed viewRelationships
     */
    private function transformViewRelationships(array $viewRelationships): array
    {
        $transformedRelationships = [];
        
        foreach ($viewRelationships as $relationship) {
            $transformedRelationship = $relationship; // Keep all existing fields
            
            // Add critical API fields
            $transformedRelationship['identifier'] = $relationship['viewRelationshipId'] ?? null;
            
            // Add properties if available (check for relationship properties)
            if (isset($relationship['properties'])) {
                $transformedRelationship['properties'] = $relationship['properties'];
            } else {
                // Create properties array with relationship name if available
                $properties = [];
                if (isset($relationship['label'])) {
                    $properties[] = [
                        'propertyDefinitionRef' => 'propid-62',
                        'value' => $relationship['label']
                    ];
                }
                $transformedRelationship['properties'] = $properties;
            }
            
            // Ensure bendpoints are properly formatted
            $transformedRelationship['bendpoints'] = $relationship['bendpoints'] ?? [];
            
            $transformedRelationships[] = $transformedRelationship;
        }
        
        return $transformedRelationships;
    }

    /**
     * Parse color string to extract RGB values
     * 
     * @param string|null $colorString Color string in format "rgb(r, g, b)" or null
     * @return array|null Array with r, g, b, a values or null
     */
    private function parseColor(?string $colorString): ?array
    {
        if (!$colorString) {
            return null;
        }
        
        // Handle rgb(r, g, b) format
        if (preg_match('/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', $colorString, $matches)) {
            return [
                'r' => (int)$matches[1],
                'g' => (int)$matches[2], 
                'b' => (int)$matches[3],
                'a' => 100
            ];
        }
        
        return null;
    }
}
