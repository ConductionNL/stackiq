<?php

/**
 * View Service for SoftwareCatalog
 *
 * Handles view-specific operations including querying, enrichment with products,
 * and usage data (gebruik) integration.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\ICache;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * View Service for managing ArchiMate views with enrichment capabilities
 *
 * This service provides operations for querying and enriching views with additional
 * data such as products, usage information (gebruik), and related data.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 */
class ViewService
{

    /**
     * Distributed cache for views responses.
     *
     * @var ICache
     */
    private ICache $viewsCache;

    /**
     * Cache TTL in seconds (30 minutes).
     */
    private const CACHE_TTL = 1800;

    /**
     * Constructor for ViewService
     *
     * @param IAppConfig         $config          Nextcloud app configuration service
     * @param IAppManager        $appManager      App manager service
     * @param ContainerInterface $container       PSR-11 container interface
     * @param LoggerInterface    $logger          Logger service
     * @param SettingsService    $settingsService Settings service for configuration
     * @param IUserSession       $userSession     User session service for current user context
     * @param ICacheFactory      $cacheFactory    Cache factory for distributed caching
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        ICacheFactory $cacheFactory
    ) {
        $this->viewsCache = $cacheFactory->createDistributed(prefix: 'softwarecatalog_views');
    }//end __construct()

    /**
     * Get all views from the system.
     *
     * @param array $options Query options including enrichment flags.
     *
     * @return array Array of view objects with optional enrichments.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-8
     */
    public function getAllViews(array $options=[]): array
    {
        $this->logger->info(
                'Getting all views',
                [
                    'options' => $options,
                ]
                );

        try {
            // Get all view objects from OpenRegister.
            $views = $this->getViewsFromRegister();

            // Transform views to include critical API fields.
            $views = $this->transformViews(views: $views);

            // Apply enrichments based on options.
            if (empty($options) === false) {
                $views = $this->enrichViews(views: $views, options: $options);
            }

            // Apply module-to-viewNode expansion if modules are enabled.
            if (isset($options['include_modules']) === true && $options['include_modules'] === true) {
                $views = $this->expandModulesToViewNodes(views: $views);
            }

            return [
                'success'             => true,
                'views'               => $views,
                'count'               => count($views),
                'enrichments_applied' => $this->getAppliedEnrichments(options: $options),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get all views',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'views'   => [],
                'count'   => 0,
            ];
        }//end try
    }//end getAllViews()

    /**
     * Get a specific view by ID.
     *
     * @param string $viewId  The view identifier.
     * @param array  $options Query options including enrichment flags.
     *
     * @return array View object with optional enrichments or error response.
     * @spec   openspec/changes/retrofit-2026-05-26-dashboard-views-api/tasks.md#task-2
     */
    public function getView(string $viewId, array $options=[]): array
    {
        $this->logger->info(
                'Getting specific view',
                [
                    'view_id' => $viewId,
                    'options' => $options,
                ]
                );

        try {
            // Get specific view from OpenRegister.
            $view = $this->getViewFromRegister(viewId: $viewId);

            if ($view === null) {
                return [
                    'success' => false,
                    'error'   => "View with ID '$viewId' not found",
                    'view'    => null,
                ];
            }

            // Transform view to include critical API fields.
            $view = $this->transformView(view: $view);

            // Apply enrichments based on options.
            if (empty($options) === false) {
                $view = $this->enrichView(view: $view, options: $options);

                // Apply module-to-viewNode expansion if modules are enabled.
                if (isset($options['include_modules']) === true && $options['include_modules'] === true) {
                    $views = $this->expandModulesToViewNodes(views: [$view]);
                    // Get the expanded view back.
                    $view = $views[0] ?? $view;
                }
            }

            return [
                'success'             => true,
                'view'                => $view,
                'enrichments_applied' => $this->getAppliedEnrichments(options: $options),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get view',
                    [
                        'view_id' => $viewId,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'view'    => null,
            ];
        }//end try
    }//end getView()

    /**
     * Get views from OpenRegister system with response-level caching.
     *
     * Results are cached for 30 minutes to prevent cold-start delays.
     *
     * @return array Array of view objects
     */
    private function getViewsFromRegister(): array
    {
        // Check cache first.
        $cacheKey = 'views_list';
        $cached   = $this->viewsCache->get(key: $cacheKey);
        if ($cached !== null) {
            $this->logger->debug(
                message: 'Returning cached views',
                context: ['views_count' => count(value: $cached)]
            );
            return $cached;
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException(message: 'OpenRegister ObjectService not available');
        }

        // Get AMEF configuration for view schema.
        $amefConfig   = $this->settingsService->getAmefConfig();
        $registerId   = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;
        $viewSchemaId = $amefConfig['view_schema'] ?? null;

        if ($registerId === null || $viewSchemaId === null) {
            throw new \RuntimeException(message: 'AMEF configuration not found for views');
        }

        // Query for all view objects.
        $query = [
            '@self' => [
                'register' => $registerId,
                'schema'   => $viewSchemaId,
            ],
        ];

        $viewEntities = $objectService->searchObjects($query);

        // Serialize ObjectEntity instances to arrays.
        $views = array_map(
            callback: function ($view) {
                if (is_array(value: $view) === false
                    && method_exists(object_or_class: $view, method: 'jsonSerialize') === true
                ) {
                    return $view->jsonSerialize();
                }

                return $view;
            },
            array: $viewEntities
        );

        // Cache the result.
        $this->viewsCache->set(key: $cacheKey, value: $views, ttl: self::CACHE_TTL);

        $this->logger->debug(
            message: 'Retrieved and cached views from register',
            context: [
                'register_id'    => $registerId,
                'view_schema_id' => $viewSchemaId,
                'views_count'    => count(value: $views),
            ]
        );

        return $views;

    }//end getViewsFromRegister()

    /**
     * Get a specific view from OpenRegister system.
     *
     * @param string $viewId The view identifier.
     *
     * @return array|null View object or null if not found.
     */
    private function getViewFromRegister(string $viewId): ?array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException(message: 'OpenRegister ObjectService not available');
        }

        // Get AMEF configuration for view schema.
        $amefConfig   = $this->settingsService->getAmefConfig();
        $registerId   = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;
        $viewSchemaId = $amefConfig['view_schema'] ?? null;

        if ($registerId === null || $viewSchemaId === null) {
            throw new \RuntimeException(message: 'AMEF configuration not found for views');
        }

        try {
            // Get specific view object by ID.
            $view = $objectService->find(
                id: $viewId,
                register: $registerId,
                schema: $viewSchemaId,
                _rbac: false,
                _multitenancy: false
            );

            // Serialize ObjectEntity to array if needed.
            if (is_array(value: $view) === false
                && $view !== null
                && method_exists(object_or_class: $view, method: 'jsonSerialize') === true
            ) {
                $view = $view->jsonSerialize();
            }

            $this->logger->debug(
                message: 'Retrieved specific view from register',
                context: [
                    'view_id'        => $viewId,
                    'register_id'    => $registerId,
                    'view_schema_id' => $viewSchemaId,
                    'found'          => $view !== null,
                ]
            );

            return $view;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'View not found in register',
                context: [
                    'view_id' => $viewId,
                    'error'   => $e->getMessage(),
                ]
            );
            return null;
        }//end try

    }//end getViewFromRegister()

    /**
     * Enrich multiple views with additional data.
     *
     * @param array $views   Array of view objects.
     * @param array $options Enrichment options.
     *
     * @return array Array of enriched view objects.
     */
    private function enrichViews(array $views, array $options): array
    {
        $enrichedViews = [];

        foreach ($views as $view) {
            $enrichedViews[] = $enrichedViews[] = $this->enrichView(view: $view, options: $options);
        }

        return $enrichedViews;
    }//end enrichViews()

    /**
     * Enrich a single view with additional data.
     *
     * @param array $view    View object to enrich.
     * @param array $options Enrichment options.
     *
     * @return array Enriched view object.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-8
     */
    private function enrichView(array $view, array $options): array
    {
        $enrichedView = $view;

        // Enrich viewNodes if present.
        if (isset($view['viewNodes']) === true && is_array(value: $view['viewNodes']) === true) {
            $enrichedView['viewNodes'] = $this->enrichViewNodes(
                viewNodes: $view['viewNodes'],
                options: $options
            );
        }

        // TODO: Add view-level enrichments here if needed.
        return $enrichedView;
    }//end enrichView()

    /**
     * Enrich view nodes with additional data based on options.
     *
     * @param array $viewNodes Array of view nodes.
     * @param array $options   Enrichment options.
     *
     * @return array Array of enriched view nodes.
     */
    private function enrichViewNodes(array $viewNodes, array $options): array
    {
        $enrichedNodes = [];

        // Get enrichment data upfront if requested.
        $productsData         = [];
        $modulesData          = [];
        $gebruikData          = [];
        $deelnamesGebruikData = [];

        if ($this->shouldIncludeProducts(options: $options) === true) {
            $productsData = $this->getProductsData();
        }

        if ($this->shouldIncludeModules(options: $options) === true) {
            $modulesData = $this->getModulesData();
        }

        if ($this->shouldIncludeGebruik(options: $options) === true) {
            $gebruikData = $this->getGebruikData(options: $options);
        }

        if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
            $deelnamesGebruikData = $this->getDeelnamesGebruikData();
        }

        foreach ($viewNodes as $node) {
            $enrichedNode = $node;

            // Apply enrichments based on the node's modelNodeId (element reference).
            $modelNodeId = $node['modelNodeId'] ?? null;

            if ($modelNodeId !== null) {
                if ($this->shouldIncludeProducts(options: $options) === true) {
                    $enrichedNode['products'] = $this->getNodeProducts(
                        modelNodeId: $modelNodeId,
                        productsData: $productsData
                    );
                }

                if ($this->shouldIncludeModules(options: $options) === true) {
                    $enrichedNode['modules'] = $this->getNodeModules(
                        modelNodeId: $modelNodeId,
                        modulesData: $modulesData
                    );
                }

                if ($this->shouldIncludeGebruik(options: $options) === true) {
                    $enrichedNode['gebruik'] = $this->getNodeGebruik(
                        modelNodeId: $modelNodeId,
                        gebruikData: $gebruikData
                    );
                }

                if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
                    $enrichedNode['deelnamesGebruik'] = $enrichedNode['deelnamesGebruik'] = $this->getNodeDeelnamesGebruik(
                        modelNodeId: $modelNodeId,
                        deelnamesGebruikData: $deelnamesGebruikData
                    );
                }
            }//end if

            $enrichedNodes[] = $enrichedNode;
        }//end foreach

        return $enrichedNodes;
    }//end enrichViewNodes()

    /**
     * Check if products should be included in enrichment.
     *
     * @param array $options Enrichment options.
     *
     * @return bool True if products should be included.
     */
    private function shouldIncludeProducts(array $options): bool
    {
        return isset($options['include_products']) === true && $options['include_products'] === true;
    }//end shouldIncludeProducts()

    /**
     * Check if modules should be included in enrichment.
     *
     * @param array $options Enrichment options.
     *
     * @return bool True if modules should be included.
     */
    private function shouldIncludeModules(array $options): bool
    {
        return isset($options['include_modules']) === true && $options['include_modules'] === true;
    }//end shouldIncludeModules()

    /**
     * Check if gebruik should be included in enrichment.
     *
     * @param array $options Enrichment options.
     *
     * @return bool True if gebruik should be included.
     */
    private function shouldIncludeGebruik(array $options): bool
    {
        return isset($options['include_gebruik']) === true && $options['include_gebruik'] === true;
    }//end shouldIncludeGebruik()

    /**
     * Check if deelnames gebruik should be included in enrichment.
     *
     * @param array $options Enrichment options.
     *
     * @return bool True if deelnames gebruik should be included.
     */
    private function shouldIncludeDeelnamesGebruik(array $options): bool
    {
        return isset($options['include_deelnames_gebruik']) === true && $options['include_deelnames_gebruik'] === true;
    }//end shouldIncludeDeelnamesGebruik()

    /**
     * Get applied enrichments list for response metadata.
     *
     * @param array $options Enrichment options.
     *
     * @return array List of applied enrichments.
     */
    private function getAppliedEnrichments(array $options): array
    {
        $enrichments = [];

        if ($this->shouldIncludeProducts(options: $options) === true) {
            $enrichments[] = 'products';
        }

        if ($this->shouldIncludeModules(options: $options) === true) {
            $enrichments[] = 'modules';
        }

        if ($this->shouldIncludeGebruik(options: $options) === true) {
            $enrichments[] = 'gebruik';
        }

        if ($this->shouldIncludeDeelnamesGebruik(options: $options) === true) {
            $enrichments[] = 'deelnames_gebruik';
        }

        return $enrichments;
    }//end getAppliedEnrichments()

    /**
     * Get products data for enrichment (placeholder implementation).
     *
     * @return array Products data.
     */
    private function getProductsData(): array
    {
        // TODO: Implement actual products data retrieval.
        $this->logger->debug('Getting products data for enrichment');
        return [];
    }//end getProductsData()

    /**
     * Get current active organisation for filtering.
     *
     * @return string|null Current organisation identifier.
     */
    private function getCurrentOrganisation(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        // TODO: Implement proper organisation determination logic.
        // This could be based on user groups, settings, or other mechanisms.
        // For now, return a placeholder that would need actual implementation.
        $userId = $user->getUID();
        $this->logger->debug(
                'Getting current organisation for user',
                [
                    'user_id' => $userId,
                ]
                );

        // Placeholder - needs actual implementation based on your organisation structure.
        // Replace with actual organisation logic.
        return 'default';
    }//end getCurrentOrganisation()

    /**
     * Get modules data for enrichment based on elementRef linkage.
     *
     * Modules are directly linked to nodes based on their elementRef.
     * This method retrieves all module data from OpenRegister, filtered by current organisation.
     *
     * @return array Modules data indexed by elementRef.
     */
    private function getModulesData(): array
    {
        $this->logger->debug('Getting modules data for enrichment');

        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return [];
            }

            // Get current organisation for filtering.
            $currentOrg = $this->getCurrentOrganisation();

            // Get configuration for modules register/schema.
            $amefConfig = $this->settingsService->getAmefConfig();
            $registerId = $amefConfig['register_id'] ?? null;

            // Modules could be in various schemas - check common ones.
            $moduleSchemas = [
                $amefConfig['module_schema'] ?? null,
                $amefConfig['component_schema'] ?? null,
                $amefConfig['element_schema'] ?? null,
            ];

            $allModules = [];

            foreach ($moduleSchemas as $schemaId) {
                if ($schemaId === null) {
                    continue;
                }

                try {
                    $query = [
                        '@self' => [
                            'register' => $registerId,
                            'schema'   => $schemaId,
                        ],
                    ];

                    // Add organisation filter if current organisation is available.
                    if ($currentOrg !== null) {
                        $query['@self']['organisation'] = $currentOrg;
                    }

                    $modules = $objectService->searchObjects($query);

                    foreach ($modules as $module) {
                        // Additional check for organisation in metadata if not caught by query.
                        $moduleOrg      = $module['@self']['organisation'] ?? null;
                        $hasOrgMismatch = $currentOrg !== null
                            && isset($module['@self']['organisation']) === true
                            && $moduleOrg !== $currentOrg;
                        if ($hasOrgMismatch === true) {
                            continue;
                        }

                        // Index by elementRef or identifier for quick lookup.
                        $elementRef = $module['elementRef'] ?? $module['identifier'] ?? null;
                        if ($elementRef !== null) {
                            $allModules[$elementRef] = $module;
                        }
                    }

                    $this->logger->debug(
                            'Retrieved modules from schema',
                            [
                                'schema_id'     => $schemaId,
                                'modules_count' => count($modules),
                            ]
                            );
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get modules from schema',
                            [
                                'schema_id' => $schemaId,
                                'error'     => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            $this->logger->debug(
                    'Total modules retrieved for enrichment',
                    [
                        'total_modules' => count($allModules),
                    ]
                    );

            return $allModules;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get modules data',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try
    }//end getModulesData()

    /**
     * Get gebruik data for enrichment based on elementRef linkage.
     *
     * Usage data is linked to nodes based on their elementRef.
     * This method retrieves usage statistics from OpenRegister, filtered by organisation.
     * Also handles deelnames logic when enabled.
     *
     * @param array $options Query options for deelnames handling.
     *
     * @return array Gebruik data indexed by elementRef with extended modules.
     */
    private function getGebruikData(array $options=[]): array
    {
        $this->logger->debug('Getting gebruik data for enrichment with options', ['options' => array_keys($options)]);

        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return [];
            }

            // Get current organisation for filtering.
            $currentOrg = $this->getCurrentOrganisation();

            // Get configuration for usage register/schema.
            $amefConfig = $this->settingsService->getAmefConfig();
            $registerId = $amefConfig['register_id'] ?? null;

            // Usage data could be in various schemas.
            $gebruikSchemas = [
                $amefConfig['gebruik_schema'] ?? null,
                $amefConfig['usage_schema'] ?? null,
                $amefConfig['statistics_schema'] ?? null,
            ];

            $allGebruik = [];

            // STEP 1: Get regular gebruik data filtered by current organisation.
            foreach ($gebruikSchemas as $schemaId) {
                if ($schemaId === null) {
                    continue;
                }

                try {
                    $query = [
                        '@self' => [
                            'register' => $registerId,
                            'schema'   => $schemaId,
                        ],
                    ];

                    // Add organisation filter for regular gebruik.
                    if ($currentOrg !== null) {
                        $query['@self']['organisation'] = $currentOrg;
                    }

                    $gebruikItems = $objectService->searchObjects($query);
                    $this->processGebruikItems(
                        gebruikItems: $gebruikItems,
                        allGebruik: $allGebruik,
                        currentOrg: $currentOrg,
                        type: 'regular'
                    );

                    $this->logger->debug(
                            'Retrieved regular gebruik from schema',
                            [
                                'schema_id'     => $schemaId,
                                'gebruik_count' => count($gebruikItems),
                                'organisation'  => $currentOrg,
                            ]
                            );
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get regular gebruik from schema',
                            [
                                'schema_id' => $schemaId,
                                'error'     => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            // STEP 2: If deelnames is enabled, get additional gebruik with RBAC off.
            $includeDeelnames = isset($options['include_deelnames_gebruik']) === true
                && $options['include_deelnames_gebruik'] === true;
            if ($includeDeelnames === true && $currentOrg !== null) {
                $this->logger->debug('Processing deelnames gebruik with RBAC disabled');

                foreach ($gebruikSchemas as $schemaId) {
                    if ($schemaId === null) {
                        continue;
                    }

                    try {
                        // Search with RBAC disabled to find gebruik where current org is in deelnemers.
                        $query = [
                            '@self'      => [
                                'register' => $registerId,
                                'schema'   => $schemaId,
                            ],
                            // Current org in deelnemers field.
                            'deelnemers' => $currentOrg,
                        ];

                        // Call with RBAC disabled.
                        $deelnamesGebruikItems = $objectService->searchObjects($query, _rbac: false);
                                                $this->processGebruikItems(
                            gebruikItems: $deelnamesGebruikItems,
                            allGebruik: $allGebruik,
                            currentOrg: $currentOrg,
                            type: 'deelnames'
                        );

                        $this->logger->debug(
                                'Retrieved deelnames gebruik from schema',
                                [
                                    'schema_id'                  => $schemaId,
                                    'deelnames_gebruik_count'    => count($deelnamesGebruikItems),
                                    'organisation_in_deelnemers' => $currentOrg,
                                ]
                                );
                    } catch (\Exception $e) {
                        $this->logger->warning(
                                'Failed to get deelnames gebruik from schema',
                                [
                                    'schema_id' => $schemaId,
                                    'error'     => $e->getMessage(),
                                ]
                                );
                    }//end try
                }//end foreach
            }//end if

            // STEP 3: Extend gebruik with modules data.
            $allGebruik = $this->extendGebruikWithModules(allGebruik: $allGebruik);

            $deelnamesEnabled = isset($options['include_deelnames_gebruik']) === true
                && $options['include_deelnames_gebruik'] === true;
            $this->logger->debug(
                    'Total gebruik retrieved and processed',
                    [
                        'total_element_refs_with_gebruik' => count($allGebruik),
                        'current_organisation'            => $currentOrg,
                        'deelnames_enabled'               => $deelnamesEnabled,
                    ]
                    );

            return $allGebruik;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get gebruik data',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try
    }//end getGebruikData()

    /**
     * Process gebruik items and group them by elementRef.
     *
     * @param array  $gebruikItems Array of gebruik objects.
     * @param array  $allGebruik   Array to add items to (by reference).
     * @param string $currentOrg   Current organisation identifier.
     * @param string $type         Type identifier ('regular' or 'deelnames').
     *
     * @return void
     */
    private function processGebruikItems(array $gebruikItems, array &$allGebruik, string $currentOrg, string $type): void
    {
        foreach ($gebruikItems as $gebruik) {
            // Group by elementRef for quick lookup.
            $elementRef = $gebruik['elementRef'] ?? $gebruik['componentRef'] ?? $gebruik['moduleRef'] ?? null;

            if ($elementRef !== null) {
                if (isset($allGebruik[$elementRef]) === false) {
                    $allGebruik[$elementRef] = [];
                }

                // Add type indicator and processing info.
                $gebruik['_type'] = $type;
                $gebruik['_processed_for_org'] = $currentOrg;

                $allGebruik[$elementRef][] = $gebruik;
            }
        }
    }//end processGebruikItems()

    /**
     * Extend gebruik data with modules information.
     *
     * @param array $allGebruik Gebruik data indexed by elementRef.
     *
     * @return array Extended gebruik data with modules.
     */
    private function extendGebruikWithModules(array $allGebruik): array
    {
        $this->logger->debug('Extending gebruik data with modules information');

        // Get modules data for extension.
        $modulesData = $this->getModulesData();

        foreach ($allGebruik as $elementRef => &$gebruikList) {
            // Check if we have a module for this elementRef.
            if (isset($modulesData[$elementRef]) === true) {
                $module = $modulesData[$elementRef];

                // Add module data to each gebruik item for this elementRef.
                foreach ($gebruikList as &$gebruik) {
                    $gebruik['_linked_module'] = $module;

                    $this->logger->debug(
                            'Linked module to gebruik',
                            [
                                'element_ref' => $elementRef,
                                'gebruik_id'  => $gebruik['id'] ?? $gebruik['identifier'] ?? 'unknown',
                                'module_id'   => $module['id'] ?? $module['identifier'] ?? 'unknown',
                                'module_name' => $module['name'] ?? 'unnamed',
                            ]
                            );
                }

                // Clean up reference.
                unset($gebruik);
            }//end if
        }//end foreach

        // Clean up reference.
        unset($gebruikList);
        return $allGebruik;
    }//end extendGebruikWithModules()

    /**
     * Expand modules to viewNodes based on referentiecomponent relationships.
     *
     * For each module that has referentiecomponent relationships, add new nodes
     * to viewNodes with the module as parent.
     *
     * @param array $views Array of views to process.
     *
     * @return array Views with expanded module nodes.
     */
    private function expandModulesToViewNodes(array $views): array
    {
        $this->logger->debug('Expanding modules to viewNodes');

        // Get modules data for expansion.
        $modulesData = $this->getModulesData();

        foreach ($views as &$view) {
            if (isset($view['viewNodes']) === false || is_array(value: $view['viewNodes']) === false) {
                continue;
            }

            $originalNodes = $view['viewNodes'];
            $expandedNodes = $originalNodes;

            // Create a lookup of existing nodes by modelNodeId for quick parent matching.
            $nodesByModelId = [];
            foreach ($originalNodes as $node) {
                $modelNodeId = $node['modelNodeId'] ?? null;
                if ($modelNodeId !== null) {
                    $nodesByModelId[$modelNodeId] = $node;
                }
            }

            // Process each module for expansion.
            foreach ($modulesData as $elementRef => $module) {
                $expandedNodes = $this->expandModuleToNodes(
                    module: $module,
                    existingNodes: $expandedNodes,
                    nodesByModelId: $nodesByModelId
                );
            }

            // Update the view with expanded nodes.
            $view['viewNodes'] = $expandedNodes;

            $addedNodesCount = count($expandedNodes) - count($originalNodes);
            if ($addedNodesCount > 0) {
                $this->logger->debug(
                        'Added module nodes to view',
                        [
                            'view_id'        => $view['id'] ?? 'unknown',
                            'original_nodes' => count($originalNodes),
                            'expanded_nodes' => count($expandedNodes),
                            'added_nodes'    => $addedNodesCount,
                        ]
                        );
            }
        }//end foreach

        // Clean up reference.
        unset($view);
        return $views;
    }//end expandModulesToViewNodes()

    /**
     * Expand a single module to nodes based on its referentiecomponent relationships.
     *
     * @param array $module         Module data.
     * @param array $existingNodes  Current view nodes.
     * @param array $nodesByModelId Lookup of nodes by modelNodeId.
     *
     * @return array Updated nodes array with module expansions.
     */
    private function expandModuleToNodes(array $module, array $existingNodes, array $nodesByModelId): array
    {
        $expandedNodes = $existingNodes;

        // Look for referentiecomponent relationships in the module.
        $referentieComponenten = $this->extractReferentieComponenten(module: $module);

        foreach ($referentieComponenten as $referentieComponentId) {
            // Find if there's an existing node for this referentiecomponent.
            $parentNode = $nodesByModelId[$referentieComponentId] ?? null;

            if ($parentNode !== null) {
                // Create a new node for this module as child of the referentiecomponent.
                $moduleNode      = $this->createModuleNode(
                    module: $module,
                    parentNode: $parentNode,
                    referentieComponentId: $referentieComponentId
                );
                $expandedNodes[] = $moduleNode;

                $this->logger->debug(
                        'Created module node',
                        [
                            'module_id'               => $module['id'] ?? $module['identifier'] ?? 'unknown',
                            'module_name'             => $module['name'] ?? 'unnamed',
                            'parent_node_id'          => $parentNode['viewNodeId'] ?? 'unknown',
                            'referentie_component_id' => $referentieComponentId,
                        ]
                        );
            }
        }//end foreach

        return $expandedNodes;
    }//end expandModuleToNodes()

    /**
     * Extract referentiecomponent IDs from module data.
     *
     * @param array $module Module data.
     *
     * @return array Array of referentiecomponent identifiers.
     */
    private function extractReferentieComponenten(array $module): array
    {
        $referentieComponenten = [];

        // Look for referentiecomponent relationships in various possible locations.
        $possibleFields = [
            'referentiecomponenten',
            'referentieComponenten',
            'relatedComponents',
            'components',
            'relationships',
        ];

        foreach ($possibleFields as $field) {
            if (isset($module[$field]) === true) {
                $value = $module[$field];

                if (is_array(value: $value) === true) {
                    // Handle array of components.
                    foreach ($value as $component) {
                        if (is_string(value: $component) === true) {
                            $referentieComponenten[] = $component;
                        } else if (is_array(value: $component) === true && isset($component['id']) === true) {
                            $referentieComponenten[] = $component['id'];
                        } else if (is_array(value: $component) === true && isset($component['identifier']) === true) {
                            $referentieComponenten[] = $component['identifier'];
                        }
                    }
                } else if (is_string(value: $value) === true) {
                    // Handle single component ID.
                    $referentieComponenten[] = $value;
                }
            }
        }//end foreach

        return array_unique($referentieComponenten);
    }//end extractReferentieComponenten()

    /**
     * Create a module node as child of a referentiecomponent node.
     *
     * @param array  $module                Module data.
     * @param array  $parentNode            Parent referentiecomponent node.
     * @param string $referentieComponentId Referentiecomponent identifier.
     *
     * @return array New module node.
     */
    private function createModuleNode(array $module, array $parentNode, string $referentieComponentId): array
    {
        $moduleId   = $module['id'] ?? $module['identifier'] ?? uniqid('module_');
        $moduleName = $module['name'] ?? 'Unnamed Module';

        // Position the module node relative to parent (slightly offset).
        $parentX     = $parentNode['x'] ?? 0;
        $parentY     = $parentNode['y'] ?? 0;
        $parentWidth = $parentNode['width'] ?? 100;

        return [
            'modelNodeId'                => $moduleId,
            'viewNodeId'                 => 'module-'.$moduleId.'-'.uniqid(),
            // Position to the right of parent.
            'x'                          => $parentX + $parentWidth + 20,
            'y'                          => $parentY,
            'width'                      => 150,
            'height'                     => 50,
            'parent'                     => $parentNode['viewNodeId'] ?? null,
            'name'                       => $moduleName,
            'type'                       => 'module',
            // Light green for modules.
            'color'                      => 'rgb(200, 255, 200)',
            'borderColor'                => 'rgb(0, 150, 0)',
            'font'                       => [
                'name'  => 'Segoe UI, Arial',
                'size'  => 11,
                'style' => 'normal',
                'color' => 'rgb(0, 0, 0)',
            ],
            'description'                => $module['description'] ?? $module['summary'] ?? null,
            'elementRef'                 => $moduleId,
            '_isModuleExpansion'         => true,
            '_parentReferentieComponent' => $referentieComponentId,
            '_moduleData'                => $module,
        ];
    }//end createModuleNode()

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
            if ($objectService === null) {
                return [];
            }

            $currentOrg = $this->getCurrentOrganisation();
            if ($currentOrg === null) {
                return [];
            }

            $amefConfig = $this->settingsService->getAmefConfig();
            $registerId = $amefConfig['register_id'] ?? null;

            $gebruikSchemas = [
                $amefConfig['gebruik_schema'] ?? null,
                $amefConfig['usage_schema'] ?? null,
                $amefConfig['statistics_schema'] ?? null,
            ];

            $allDeelnames = [];

            foreach ($gebruikSchemas as $schemaId) {
                if ($schemaId === null) {
                    continue;
                }

                try {
                    $query = [
                        '@self'      => [
                            'register' => $registerId,
                            'schema'   => $schemaId,
                        ],
                        'deelnemers' => $currentOrg,
                    ];

                    $deelnamesItems = $objectService->searchObjects($query, _rbac: false);
                    $this->processGebruikItems(
                        gebruikItems: $deelnamesItems,
                        allGebruik: $allDeelnames,
                        currentOrg: $currentOrg,
                        type: 'deelnames'
                    );

                    $this->logger->debug(
                            'Retrieved deelnames gebruik data',
                            [
                                'schema_id'       => $schemaId,
                                'deelnames_count' => count($deelnamesItems),
                            ]
                            );
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'Failed to get deelnames gebruik data',
                            [
                                'schema_id' => $schemaId,
                                'error'     => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            $allDeelnames = $this->extendGebruikWithModules(allGebruik: $allDeelnames);

            $this->logger->debug(
                    'Total deelnames gebruik retrieved',
                    [
                        'total_element_refs' => count($allDeelnames),
                    ]
                    );

            return $allDeelnames;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to get deelnames gebruik data',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try
    }//end getDeelnamesGebruikData()

    /**
     * Get products for a specific node (placeholder implementation).
     *
     * @param string $modelNodeId  The model node identifier.
     * @param array  $productsData Products data to search in.
     *
     * @return array Products related to the node.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $productsData reserved for future node products matching
     */
    private function getNodeProducts(string $modelNodeId, array $productsData): array
    {
        // TODO: Implement actual node products matching logic.
        $this->logger->debug('Getting products for node', ['model_node_id' => $modelNodeId]);
        return [];
    }//end getNodeProducts()

    /**
     * Get modules for a specific node based on elementRef linkage.
     *
     * @param string $modelNodeId The model node identifier (elementRef).
     * @param array  $modulesData Modules data indexed by elementRef.
     *
     * @return array Modules related to the node.
     */
    private function getNodeModules(string $modelNodeId, array $modulesData): array
    {
        $this->logger->debug('Getting modules for node', ['model_node_id' => $modelNodeId]);

        // Direct lookup by elementRef.
        if (isset($modulesData[$modelNodeId]) === true) {
            $module = $modulesData[$modelNodeId];

            $this->logger->debug(
                    'Found module for node',
                    [
                        'model_node_id' => $modelNodeId,
                        'module_id'     => $module['id'] ?? $module['identifier'] ?? 'unknown',
                        'module_name'   => $module['name'] ?? 'unnamed',
                    ]
                    );

            // Return as array for consistency.
            return [$module];
        }

        // No module found for this node.
        return [];
    }//end getNodeModules()

    /**
     * Get gebruik for a specific node based on elementRef linkage.
     *
     * @param string $modelNodeId The model node identifier (elementRef).
     * @param array  $gebruikData Gebruik data indexed by elementRef.
     *
     * @return array Gebruik related to the node.
     */
    private function getNodeGebruik(string $modelNodeId, array $gebruikData): array
    {
        $this->logger->debug('Getting gebruik for node', ['model_node_id' => $modelNodeId]);

        // Direct lookup by elementRef.
        if (isset($gebruikData[$modelNodeId]) === true) {
            $gebruikList = $gebruikData[$modelNodeId];

            $this->logger->debug(
                    'Found gebruik for node',
                    [
                        'model_node_id' => $modelNodeId,
                        'gebruik_count' => count($gebruikList),
                    ]
                    );

            return $gebruikList;
        }

        // No usage data found for this node.
        return [];
    }//end getNodeGebruik()

    /**
     * Get deelnames gebruik for a specific node based on elementRef linkage.
     *
     * @param string $modelNodeId          The model node identifier (elementRef).
     * @param array  $deelnamesGebruikData Deelnames gebruik data indexed by elementRef.
     *
     * @return array Deelnames gebruik related to the node.
     */
    private function getNodeDeelnamesGebruik(string $modelNodeId, array $deelnamesGebruikData): array
    {
        $this->logger->debug('Getting deelnames gebruik for node', ['model_node_id' => $modelNodeId]);

        if (isset($deelnamesGebruikData[$modelNodeId]) === true) {
            $deelnamesList = $deelnamesGebruikData[$modelNodeId];

            $this->logger->debug(
                    'Found deelnames gebruik for node',
                    [
                        'model_node_id'   => $modelNodeId,
                        'deelnames_count' => count($deelnamesList),
                    ]
                    );

            return $deelnamesList;
        }

        return [];
    }//end getNodeDeelnamesGebruik()

    /**
     * Get ObjectService from container.
     *
     * @return ObjectService|null ObjectService instance or null if not available.
     */
    private function getObjectService(): ?ObjectService
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            return $this->container->get(ObjectService::class);
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to get ObjectService',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return null;
        }
    }//end getObjectService()

    /**
     * Transform multiple views to include critical API fields.
     *
     * @param array $views Array of view objects.
     *
     * @return array Array of transformed view objects.
     */
    private function transformViews(array $views): array
    {
        $transformedViews = [];

        foreach ($views as $view) {
            $transformedViews[] = $this->transformView(view: $view);
        }

        return $transformedViews;
    }//end transformViews()

    /**
     * Transform a single view to include critical API fields.
     *
     * @param array $view View object to transform.
     *
     * @return array Transformed view object with critical API fields.
     */
    private function transformView(array $view): array
    {
        $transformedView = $view;

        // Add view documentation from XML if available.
        if (isset($view['xml']['documentation']) === true) {
            $transformedView['documentation'] = $view['xml']['documentation'];
        }

        // Transform properties from XML to required API format.
        $hasProperties = isset($view['xml']['properties']['property']) === true
            && is_array(value: $view['xml']['properties']['property']) === true;
        if ($hasProperties === true) {
            $transformedView['properties'] = $this->transformViewProperties(
                properties: $view['xml']['properties']['property']
            );
        }

        // Transform viewNodes to include critical fields.
        $hasViewNodes = isset($view['xml']['viewNodes']) === true
            && is_array(value: $view['xml']['viewNodes']) === true;
        if ($hasViewNodes === true) {
            $transformedView['viewNodes'] = $this->transformViewNodes(
                viewNodes: $view['xml']['viewNodes']
            );
        }

        // Transform viewRelationships to include critical fields.
        $hasRelationships = isset($view['xml']['viewRelationships']) === true
            && is_array(value: $view['xml']['viewRelationships']) === true;
        if ($hasRelationships === true) {
            $transformedView['viewRelationships'] = $this->transformViewRelationships(
                viewRelationships: $view['xml']['viewRelationships']
            );
        }

        return $transformedView;
    }//end transformView()

    /**
     * Transform view properties to required API format.
     *
     * @param array $properties Array of property objects from XML.
     *
     * @return array Array of transformed properties.
     */
    private function transformViewProperties(array $properties): array
    {
        $transformedProperties = [];

        foreach ($properties as $property) {
            $propertyRef         = $property['_propertyDefinitionRef'] ?? $property['___propertyDefinitionRef'] ?? null;
            $transformedProperty = [
                'propertyDefinitionRef' => $propertyRef,
                'value'                 => $property['value']['_value'] ?? null,
            ];

            if ($transformedProperty['propertyDefinitionRef'] !== null && $transformedProperty['value'] !== null) {
                $transformedProperties[] = $transformedProperty;
            }
        }

        return $transformedProperties;
    }//end transformViewProperties()

    /**
     * Transform viewNodes to include critical API fields.
     *
     * @param array $viewNodes Array of viewNode objects from XML.
     *
     * @return array Array of transformed viewNodes.
     */
    private function transformViewNodes(array $viewNodes): array
    {
        $transformedNodes = [];

        foreach ($viewNodes as $node) {
            // Keep all existing fields.
            $transformedNode = $node;
            // Add critical API fields.
            $transformedNode['identifier']    = $node['viewNodeId'] ?? null;
            $transformedNode['documentation'] = $node['description'] ?? null;

            // Add position with width and height.
            $transformedNode['position'] = [
                'x' => $node['x'] ?? null,
                'y' => $node['y'] ?? null,
                'w' => $node['width'] ?? null,
                'h' => $node['height'] ?? null,
            ];

            // Add style information.
            if (isset($node['color']) === true || isset($node['borderColor']) === true || isset($node['font']) === true) {
                $transformedNode['style'] = [
                    'fillColor' => $this->parseColor(colorString: $node['color'] ?? null),
                    'lineColor' => $this->parseColor(colorString: $node['borderColor'] ?? null),
                    'font'      => $node['font'] ?? null,
                ];
            }

            $transformedNodes[] = $transformedNode;
        }//end foreach

        return $transformedNodes;
    }//end transformViewNodes()

    /**
     * Transform viewRelationships to include critical API fields.
     *
     * @param array $viewRelationships Array of viewRelationship objects from XML.
     *
     * @return array Array of transformed viewRelationships.
     */
    private function transformViewRelationships(array $viewRelationships): array
    {
        $transformedRelationships = [];

        foreach ($viewRelationships as $relationship) {
            // Keep all existing fields.
            $transformedRelationship = $relationship;
            // Add critical API fields.
            $transformedRelationship['identifier'] = $relationship['viewRelationshipId'] ?? null;

            // Add properties if available (check for relationship properties).
            // Default: create properties array with relationship name if available.
            $properties = [];
            if (isset($relationship['label']) === true) {
                $properties[] = [
                    'propertyDefinitionRef' => 'propid-62',
                    'value'                 => $relationship['label'],
                ];
            }

            $transformedRelationship['properties'] = $properties;
            if (isset($relationship['properties']) === true) {
                $transformedRelationship['properties'] = $relationship['properties'];
            }

            // Ensure bendpoints are properly formatted.
            $transformedRelationship['bendpoints'] = $relationship['bendpoints'] ?? [];

            $transformedRelationships[] = $transformedRelationship;
        }//end foreach

        return $transformedRelationships;
    }//end transformViewRelationships()

    /**
     * Parse color string to extract RGB values.
     *
     * @param string|null $colorString Color string in format "rgb(r, g, b)" or null.
     *
     * @return array|null Array with r, g, b, a values or null.
     */
    private function parseColor(?string $colorString): ?array
    {
        if ($colorString === null) {
            return null;
        }

        // Handle rgb(r, g, b) format.
        if (preg_match(pattern: '/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', subject: $colorString, matches: $matches) === 1) {
            return [
                'r' => (int) $matches[1],
                'g' => (int) $matches[2],
                'b' => (int) $matches[3],
                'a' => 100,
            ];
        }

        return null;
    }//end parseColor()
}//end class
