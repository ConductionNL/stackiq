<?php
/**
 * AangebodenGebruik Service for SoftwareCatalog
 *
 * Handles operations related to offered usage (aangeboden gebruik) including
 * filtering gebruiks objects where the active organization is involved as
 * afnemer (consumer) or in deelnemers (participants).
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

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
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */
class AangebodenGebruikService
{
    /**
     * Constructor for AangebodenGebruikService
     *
     * @param IAppConfig         $config          Nextcloud app configuration service
     * @param IAppManager        $appManager      App manager service for checking available apps
     * @param ContainerInterface $container       PSR-11 container interface for dependency injection
     * @param LoggerInterface    $logger          Logger service for debugging and error reporting
     * @param SettingsService    $settingsService Settings service for retrieving configuration
     * @param IUserSession       $userSession     User session service for current user context
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession
    ) {
    }//end __construct()

    /**
     * Get all gebruiks, koppelingen, and other objects where the active organization is the afnemer (consumer)
     *
     * This method retrieves all objects (gebruiks, koppelingen, modules, etc.) where the active organization
     * appears as the afnemer using standard RBAC filtering. It excludes objects where '@self.organisation'
     * equals the currently active organisation, meaning only offered objects that haven't been accepted
     * (overnomen) by this organisation yet are returned.
     *
     * @param array $options Additional query options (limit, offset, filters, etc.).
     *
     * @return array Array with success status, objects data, and metadata.
     *
     * @throws Exception When OpenRegister service is not available.
     */
    public function getGebruiksWhereAfnemer(array $options=[]): array
    {
        $this->logger->info(
                'Getting gebruiks objects where active org is afnemer',
                [
                    'options' => $options,
                ]
                );

        try {
            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get current organization.
            $currentOrg = $this->getCurrentOrganisation();
            if ($currentOrg === null) {
                $this->logger->warning('No current organization available for afnemer filtering');
                return [
                    'results' => [],
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 0,
                    'limit'   => 20,
                    'offset'  => 0,
                    'message' => 'No current organization available',
                ];
            }

            // Get configuration for gebruiks register/schema.
            $gebruiksConfig = $this->getGebruiksConfiguration();

            // Use the first schema for now (can be extended for multi-schema support).
            $schemaId = $gebruiksConfig['schemas'][0] ?? null;
            if ($schemaId === null) {
                throw new Exception('No gebruik schema configured');
            }

            // Build query for afnemer filtering - search for objects where current org is afnemer.
            // Note: We don't filter by organisation in @self since the objects are owned by leveranciers.
            $query = [
                '@self'   => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema'   => $schemaId,
                ],
                // Filter by afnemer field instead of ownership.
                'afnemer' => $currentOrg,
            ];

            // Store original pagination parameters.
            $requestedLimit = $options['_limit'] ?? 20;
            $requestedPage  = $options['_page'] ?? 1;

            // Calculate offset from page or use explicit offset.
            if (isset($options['_offset']) === true) {
                $requestedOffset = $options['_offset'];
            } else {
                // Calculate offset from page number.
                $requestedOffset = ($requestedPage - 1) * $requestedLimit;
            }

            // Fetch a large batch for filtering (since we filter post-fetch).
            // We need to fetch more than requested because some will be filtered out.
            $fetchOptions = $options;
            // Fetch a large batch.
            $fetchOptions['_limit'] = 1000;
            // Always start from beginning for now.
            $fetchOptions['_offset'] = 0;
            // Remove page parameter.
            unset($fetchOptions['_page']);
            // Add additional filters from options (search, extend, etc.).
            $query = $this->addQueryFilters(baseQuery: $query, options: $fetchOptions);

            $this->logger->debug(
                    'AangebodenGebruikService: Executing search query',
                    [
                        'query'            => $query,
                        'schema_id'        => $schemaId,
                        'current_org'      => $currentOrg,
                        'fetch_limit'      => 1000,
                        'requested_limit'  => $requestedLimit,
                        'requested_offset' => $requestedOffset,
                    ]
                    );

            // Execute search with RBAC and multitenancy disabled to find cross-organisation objects.
            // Fetch a large batch that we'll filter and paginate afterward.
            $searchResult = $objectService->searchObjectsPaginated(
                query: $query,
                // Disable RBAC to find cross-organisation objects.
                _rbac: false,
                // Disable multitenancy to find objects from other organisations.
                _multitenancy: false
            );

            $this->logger->debug(
                    'AangebodenGebruikService: Search completed before filtering',
                    [
                        'total'         => $searchResult['total'] ?? 0,
                        'results_count' => count($searchResult['results'] ?? []),
                        'organisation'  => $currentOrg,
                    ]
                    );

            // Filter out objects where @self.organisation equals the currently active organisation.
            // Only return objects that are offered TO this org but not yet claimed/accepted BY this org.
            // This excludes gebruiks, koppelingen, and other objects that have already been accepted (overnomen).
            // Note: @self.organisation is never empty, so we check if it equals the current org.
            $filteredResults = [];
            foreach ($searchResult['results'] ?? [] as $result) {
                // Convert ObjectEntity to array if needed.
                if (is_array(value: $result) === true) {
                    $resultData = $result;
                } else {
                    $resultData = $result->getObject();
                }

                $selfOrg = $resultData['@self']['organisation'] ?? null;

                // Only include if @self.organisation is NOT set to the current organisation.
                // (meaning it hasn't been accepted by this organisation yet).
                if ($selfOrg !== $currentOrg) {
                    $filteredResults[] = $result;
                }
            }

            $this->logger->debug(
                    'AangebodenGebruikService: Filtering completed',
                    [
                        'original_count' => count($searchResult['results'] ?? []),
                        'filtered_count' => count($filteredResults),
                        'removed_count'  => (count($searchResult['results'] ?? []) - count($filteredResults)),
                    ]
                    );

            // Apply pagination to filtered results.
            $totalFiltered    = count($filteredResults);
            $paginatedResults = array_slice(array: $filteredResults, offset: $requestedOffset, length: $requestedLimit);

            // Calculate pagination metadata.
            if ($requestedLimit > 0) {
                $totalPages = (int) ceil(num: $totalFiltered / $requestedLimit);
            } else {
                $totalPages = 1;
            }

            if ($requestedOffset > 0) {
                $currentPage = (int) floor(num: $requestedOffset / $requestedLimit) + 1;
            } else {
                $currentPage = $requestedPage;
            }

            // Build next/previous links.
            $nextLink    = null;
            $prevLink    = null;
            $afnemerPath = '/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer';
            if ($currentPage < $totalPages) {
                $nextPage = $currentPage + 1;
                $nextLink = "{$afnemerPath}?_limit={$requestedLimit}&_source=database&page={$nextPage}";
            }

            if ($currentPage > 1) {
                $prevPage = $currentPage - 1;
                $prevLink = "{$afnemerPath}?_limit={$requestedLimit}&_source=database&page={$prevPage}";
            }

            $this->logger->debug(
                    'AangebodenGebruikService: Pagination applied',
                    [
                        'total_filtered'   => $totalFiltered,
                        'requested_limit'  => $requestedLimit,
                        'requested_offset' => $requestedOffset,
                        'current_page'     => $currentPage,
                        'total_pages'      => $totalPages,
                        'returned_count'   => count($paginatedResults),
                    ]
                    );

            // Update the result with paginated filtered data.
            $searchResult['results'] = $paginatedResults;
            $searchResult['total']   = $totalFiltered;
            $searchResult['pages']   = $totalPages;
            $searchResult['page']    = $currentPage;
            $searchResult['limit']   = $requestedLimit;
            $searchResult['offset']  = $requestedOffset;
            if ($nextLink !== null) {
                $searchResult['next'] = $nextLink;
            } else {
                unset($searchResult['next']);
            }

            if ($prevLink !== null) {
                $searchResult['previous'] = $prevLink;
            }

            return $searchResult;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get afnemer gebruiks',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'results' => [],
                'total'   => 0,
                'page'    => 1,
                'pages'   => 0,
                'limit'   => 20,
                'offset'  => 0,
                'error'   => 'Failed to retrieve gebruiks: '.$e->getMessage(),
            ];
        }//end try
    }//end getGebruiksWhereAfnemer()

    /**
     * Get koppelingen and gebruiks for a specific organisation, application, or module UUID
     *
     * This method retrieves koppelingen and gebruiks related to a specific UUID based on:
     * - If user has ambtenaar role: return all related objects (optionally filtered by organization)
     * - If user's organization owns the application/module: return all related usage
     * - Otherwise: return empty result
     *
     * The UUID can be:
     * - An organisation UUID: returns all gebruiks/koppelingen for that organisation
     * - An application/suite UUID: returns all gebruiks/koppelingen that reference that suite
     * - A module UUID: returns all gebruiks/koppelingen that reference that module
     *
     * @param string $uuid        The UUID of the organisation, application, or module.
     * @param array  $options     Additional query options (limit, offset, filters, organisation, etc.).
     * @param bool   $isAmbtenaar Whether the user has ambtenaar privileges.
     *
     * @return array searchObjectsPaginated result with koppelingen and gebruiks for the UUID.
     *
     * @throws Exception When OpenRegister service is not available.
     */
    public function getKoppelingenGebruikByUuid(string $uuid, array $options=[], bool $isAmbtenaar=false): array
    {
        $this->logger->info(
                'Getting koppelingen and gebruiks for UUID with extended access',
                [
                    'uuid'        => $uuid,
                    'options'     => $options,
                    'isAmbtenaar' => $isAmbtenaar,
                ]
                );

        try {
            // Validate input.
            if (empty($uuid) === true) {
                return [
                    'results' => [],
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 0,
                    'limit'   => 20,
                    'offset'  => 0,
                    'error'   => 'UUID is required',
                ];
            }

            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get voorzieningen configuration.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $registerId          = $voorzieningenConfig['register'] ?? null;
            $gebruikSchema       = $voorzieningenConfig['gebruik_schema'] ?? null;
            $koppeligenSchema    = $voorzieningenConfig['koppeling_schema'] ?? null;

            if ($registerId === null || $gebruikSchema === null || $koppeligenSchema === null) {
                throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
            }

            // Check access permissions.
            $currentOrg = $this->getCurrentOrganisation();
            $hasAccess  = false;

            if ($isAmbtenaar === true) {
                // Ambtenaar always has access.
                $hasAccess = true;
            } else if ($currentOrg !== null) {
                // Check if the application/module is owned by user's organization.
                try {
                    $appObject = $objectService->find(id: $uuid, _rbac: false, _multitenancy: false);
                    if ($appObject !== null) {
                        $appData   = $appObject->getObject();
                        $ownerOrg  = $appData['@self']['organisation'] ?? null;
                        $hasAccess = ($ownerOrg === $currentOrg);
                    }
                } catch (Exception $e) {
                    $this->logger->warning(
                            'Failed to check ownership for UUID',
                            [
                                'uuid'  => $uuid,
                                'error' => $e->getMessage(),
                            ]
                            );
                }
            }//end if

            if ($hasAccess === false) {
                return [
                    'results' => [],
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 0,
                    'limit'   => 20,
                    'offset'  => 0,
                ];
            }

            // Get organization filter if provided (for ambtenaar).
            if ($isAmbtenaar === true && isset($options['organisation']) === true) {
                $organisationFilter = $options['organisation'];
            } else {
                $organisationFilter = null;
            }

            // Build search query using ObjectService's buildSearchQuery.
            $searchQuery = $objectService->buildSearchQuery($options);

            // Add register and schema filters.
            $searchQuery['@self']['register'] = $registerId;
            $searchQuery['@self']['schema']   = [$gebruikSchema, $koppeligenSchema];

            // Force database source.
            $searchQuery['_source'] = 'database';

            // Check if UUID is an organisation UUID by trying to fetch it and checking its schema.
            $isOrganisationUuid = false;
            try {
                $uuidObject = $objectService->find(id: $uuid, _rbac: false, _multitenancy: false);
                if ($uuidObject !== null) {
                    $uuidData   = $uuidObject->getObject();
                    $uuidSchema = $uuidData['@self']['schema'] ?? null;
                    $organisationSchemaId = $voorzieningenConfig['organisatie_schema'] ?? '15';
                    $isOrganisationUuid   = ($uuidSchema == $organisationSchemaId);
                }
            } catch (Exception $e) {
                $this->logger->debug(
                        'Could not fetch UUID object, assuming it is not an organisation',
                        [
                            'uuid' => $uuid,
                        ]
                        );
            }

            // Handle organisation UUID filtering differently from suite/module UUIDs.
            if ($isOrganisationUuid === true) {
                // For organisation UUIDs, filter by @self.organisation.
                $searchQuery['@self']['organisation'] = $uuid;

                // Apply additional organisation filter if provided by ambtenaar.
                if ($organisationFilter !== null) {
                    $this->logger->warning(
                            'Organisation filter parameter is ignored when UUID is already an organisation',
                            [
                                'uuid'   => $uuid,
                                'filter' => $organisationFilter,
                            ]
                            );
                }

                $this->logger->debug(
                        'Executing koppelingen-gebruik by organisation UUID',
                        [
                            'uuid'  => $uuid,
                            'query' => $searchQuery,
                        ]
                        );

                // Execute paginated search without 'uses' parameter.
                $searchResult = $objectService->searchObjectsPaginated(
                    query: $searchQuery,
                    _rbac: false,
                    _multitenancy: false,
                    published: false,
                    deleted: false
                );
            } else {
                // For suite/module UUIDs, use 'uses' parameter to filter by relations.
                // Add organization filter if provided.
                if ($organisationFilter !== null) {
                    $searchQuery['@self']['organisation'] = $organisationFilter;
                }

                $this->logger->debug(
                        'Executing koppelingen-gebruik by suite/module UUID',
                        [
                            'uuid'  => $uuid,
                            'query' => $searchQuery,
                        ]
                        );

                // Execute paginated search using 'uses' parameter to filter by UUID in relations.
                $searchResult = $objectService->searchObjectsPaginated(
                    query: $searchQuery,
                    _rbac: false,
                    _multitenancy: false,
                    published: false,
                    deleted: false,
                    uses: $uuid
                );
            }//end if

            $this->logger->debug(
                    'Koppelingen-gebruik by UUID search completed',
                    [
                        'uuid'          => $uuid,
                        'total'         => $searchResult['total'] ?? 0,
                        'results_count' => count($searchResult['results'] ?? []),
                    ]
                    );

            return $searchResult;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get koppelingen-gebruik by UUID',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'results' => [],
                'total'   => 0,
                'page'    => 1,
                'pages'   => 0,
                'limit'   => 20,
                'offset'  => 0,
                'error'   => 'Failed to retrieve objects: '.$e->getMessage(),
            ];
        }//end try
    }//end getKoppelingenGebruikByUuid()

    /**
     * Get all gebruiks objects (ignoring RBAC and multitenancy) - restricted to ambtenaar group
     *
     * This method retrieves all gebruiks objects regardless of ownership or organization,
     * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
     * with the "ambtenaar" group.
     *
     * @param array $options Additional query options (limit, offset, filters, etc.).
     *
     * @deprecated Use getKoppelingenGebruik() instead.
     *
     * @return array searchObjectsPaginated result with all gebruiks.
     *
     * @throws Exception When OpenRegister service is not available.
     */
    public function getAllGebruiksForAmbtenaar(array $options=[]): array
    {
        $this->logger->info(
                'Getting all gebruiks objects for ambtenaar (ignoring RBAC/multitenancy)',
                [
                    'options' => $options,
                ]
                );

        try {
            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get configuration for gebruiks register/schema.
            $gebruiksConfig = $this->getGebruiksConfiguration();

            // Use the first schema for now (can be extended for multi-schema support).
            $schemaId = $gebruiksConfig['schemas'][0] ?? null;
            if ($schemaId === null) {
                throw new Exception('No gebruik schema configured');
            }

            // Build query for all gebruiks - no organization filtering.
            $query = [
                '@self' => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema'   => $schemaId,
                ],
            ];

            // Add additional filters from options (pagination, search, etc.).
            $query = $this->addQueryFilters(query: $query, options: $options);

            // Force use of database source (not index/SOLR) like PublicationsController.
            $query['_source'] = 'database';

            $this->logger->debug(
                    'AangebodenGebruikService: Executing ambtenaar search query',
                    [
                        'query'     => $query,
                        'schema_id' => $schemaId,
                    ]
                    );

            // Execute search with RBAC and multitenancy disabled to get ALL objects.
            // Use database source and include unpublished objects.
            $searchResult = $objectService->searchObjectsPaginated(
                query: $query,
                // Disable RBAC to access all objects.
                _rbac: false,
                // Disable multitenancy to access objects from all organisations.
                _multitenancy: false,
                // Include unpublished objects.
                published: false,
                // Exclude deleted objects.
                deleted: false
            );

            $this->logger->debug(
                    'AangebodenGebruikService: Ambtenaar search completed',
                    [
                        'total'         => $searchResult['total'] ?? 0,
                        'results_count' => count($searchResult['results'] ?? []),
                    ]
                    );

            return $searchResult;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get all gebruiks for ambtenaar',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'results' => [],
                'total'   => 0,
                'page'    => 1,
                'pages'   => 0,
                'limit'   => 20,
                'offset'  => 0,
                'error'   => 'Failed to retrieve gebruiks: '.$e->getMessage(),
            ];
        }//end try
    }//end getAllGebruiksForAmbtenaar()

    /**
     * Get all gebruiks objects belonging to a specific suite ID (ignoring RBAC and multitenancy) - restricted to ambtenaar group
     *
     * This method retrieves all gebruiks objects that belong to the specified suite ID,
     * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
     * with the "ambtenaar" group.
     *
     * @param string $suiteId The ID of the suite to get gebruiks for
     * @param array  $options Additional query options (extend, fields, etc.)
     *
     * @return array searchObjectsPaginated result with all gebruiks for the suite
     *
     * @throws Exception When OpenRegister service is not available
     */
    public function getSingleGebruikForAmbtenaar(string $suiteId, array $options=[]): array
    {
        $this->logger->info(
                'Getting all gebruiks for suite ID for ambtenaar (ignoring RBAC/multitenancy)',
                [
                    'suite_id' => $suiteId,
                    'options'  => $options,
                ]
                );

        try {
            // Validate input.
            if (empty($suiteId) === true) {
                return [
                    'results' => [],
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 0,
                    'limit'   => 20,
                    'offset'  => 0,
                    'error'   => 'Suite ID is required',
                ];
            }

            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get configuration for gebruiks register/schema.
            $gebruiksConfig = $this->getGebruiksConfiguration();

            // Use the first schema for now (can be extended for multi-schema support).
            $schemaId = $gebruiksConfig['schemas'][0] ?? null;
            if ($schemaId === null) {
                throw new Exception('No gebruik schema configured');
            }

            // Build query for all gebruiks that reference the specified UUID in their relations.
            // Follow the same pattern as PublicationsController.php used() method.
            $query = [
                '@self' => [
                    'register' => $gebruiksConfig['register_id'],
                    'schema'   => $schemaId,
                ],
            ];

            // Add additional filters from options (extend, fields, etc.).
            $query = $this->addQueryFilters(baseQuery: $query, options: $options);

            // Force use of database source (not index/SOLR) like PublicationsController.
            $query['_source'] = 'database';

            $this->logger->debug(
                    'AangebodenGebruikService: Executing uses-based query for ambtenaar',
                    [
                        'query'     => $query,
                        'schema_id' => $schemaId,
                        'uses_uuid' => $suiteId,
                    ]
                    );

            // Execute search following PublicationsController.php used() method pattern.
            // Use database source and uses parameter for relationship filtering.
            $searchResult = $objectService->searchObjectsPaginated(
                query: $query,
                _rbac: false,
            // Disable RBAC to access any object.
                _multitenancy: false,
            // Disable multitenancy to access objects from any organisation.
                published: false,
            // Include unpublished objects.
                deleted: false,
            // Exclude deleted objects.
                uses: $suiteId
            // Find objects that have this UUID in their relations array.
            );

            $this->logger->debug(
                    'AangebodenGebruikService: Uses-based query completed',
                    [
                        'total'         => $searchResult['total'] ?? 0,
                        'results_count' => count($searchResult['results'] ?? []),
                        'uses_uuid'     => $suiteId,
                    ]
                    );

            return $searchResult;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get gebruiks by uses relationship',
                    [
                        'uses_uuid' => $suiteId,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            return [
                'results' => [],
                'total'   => 0,
                'page'    => 1,
                'pages'   => 0,
                'limit'   => 20,
                'offset'  => 0,
                'error'   => 'Failed to retrieve gebruik: '.$e->getMessage(),
            ];
        }//end try
    }//end getSingleGebruikForAmbtenaar()

    /**
     * Get all gebruiks objects where the active organization is in deelnemers (participants)
     *
     * This method retrieves all gebruiks objects where the active organization
     * appears in the deelnemers array, using RBAC-disabled search.
     *
     * @param array $options Additional query options (limit, offset, filters, etc.)
     *
     * @return array Array with success status, gebruiks data, and metadata
     *
     * @throws Exception When OpenRegister service is not available
     */
    public function getGebruiksWhereDeelnemers(array $options=[]): array
    {
        $this->logger->info(
                'Getting gebruiks objects where active org is in deelnemers',
                [
                    'options' => $options,
                ]
                );

        try {
            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get current organization.
            $currentOrg = $this->getCurrentOrganisation();
            if ($currentOrg === null) {
                $this->logger->warning('No current organization available for deelnemers filtering');
                return [
                    'success'  => true,
                    'gebruiks' => [],
                    'count'    => 0,
                    'message'  => 'No current organization available',
                ];
            }

            // Get configuration for gebruiks register/schema.
            $gebruiksConfig = $this->getGebruiksConfiguration();

            $allGebruiks = [];

            // Search each configured schema for gebruiks where org is in deelnemers.
            foreach ($gebruiksConfig['schemas'] as $schemaId) {
                if ($schemaId === null) {
                    continue;
                }

                try {
                    // Build query for deelnemers filtering.
                    $query = [
                        '@self'      => [
                            'register' => $gebruiksConfig['register_id'],
                            'schema'   => $schemaId,
                        ],
                        'deelnemers' => $currentOrg,
                        // Search where current org is in deelnemers.
                    ];

                    // Add additional filters from options.
                    $query = $this->addQueryFilters(baseQuery: $query, options: $options);

                    // Execute search with RBAC disabled to find deelnemers.
                    $gebruikItems = $objectService->searchObjects($query, _rbac: false);

                    // Process and add to results.
                    foreach ($gebruikItems as $gebruik) {
                        $gebruik['_filter_type'] = 'deelnemers';
                        $gebruik['_schema_id']   = $schemaId;
                        $allGebruiks[]           = $gebruik;
                    }

                    $this->logger->debug(
                            'Retrieved deelnemers gebruiks from schema',
                            [
                                'schema_id'                  => $schemaId,
                                'count'                      => count($gebruikItems),
                                'organisation_in_deelnemers' => $currentOrg,
                            ]
                            );
                } catch (Exception $e) {
                    $this->logger->warning(
                            'Failed to get deelnemers gebruiks from schema',
                            [
                                'schema_id' => $schemaId,
                                'error'     => $e->getMessage(),
                            ]
                            );
                }//end try
            }//end foreach

            return [
                'success'      => true,
                'gebruiks'     => $allGebruiks,
                'count'        => count($allGebruiks),
                'filter_type'  => 'deelnemers',
                'organisation' => $currentOrg,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get deelnemers gebruiks',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success'  => false,
                'error'    => 'Failed to retrieve gebruiks: '.$e->getMessage(),
                'gebruiks' => [],
                'count'    => 0,
            ];
        }//end try
    }//end getGebruiksWhereDeelnemers()

    /**
     * Set the @self property of a gebruik to the active organization
     *
     * This method updates the @self.organisation property of a specific gebruik
     * or koppeling object, but only if the active organization is the afnemer
     * (consumer) or aanbieder (provider) for that object.
     *
     * @param string $gebruikId The UUID of the gebruik or koppeling object to update
     * @param array  $options   Additional update options
     *
     * @return array Result with success status and updated object data
     *
     * @throws Exception When OpenRegister service is not available or operation fails
     */
    public function setGebruikSelfToActiveOrg(string $gebruikId, array $options=[]): array
    {
        $this->logger->info(
                'Setting gebruik @self property to active organisation',
                [
                    'gebruik_id' => $gebruikId,
                    'options'    => $options,
                ]
                );

        try {
            // Validate input.
            if (empty($gebruikId) === true) {
                return [
                    'success' => false,
                    'error'   => 'Gebruik ID is required',
                    'gebruik' => null,
                ];
            }

            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get current organization.
            $currentOrg = $this->getCurrentOrganisation();
            if ($currentOrg === null) {
                return [
                    'success' => false,
                    'error'   => 'No current organization available',
                    'gebruik' => null,
                ];
            }

            // Find the gebruik or koppeling object across possible schemas.
            // Register/schema context is required to search magic tables.
            $existingGebruik = $this->findGebruikOrKoppeling(objectService: $objectService, objectId: $gebruikId);

            if ($existingGebruik === null) {
                return [
                    'success' => false,
                    'error'   => 'Gebruik object not found',
                    'gebruik' => null,
                ];
            }

            // Verify that the active organization is either the afnemer or aanbieder.
            $gebruikData   = $existingGebruik->getObject();
            $afnemerInfo   = $gebruikData['afnemer'] ?? null;
            $aanbiederInfo = $gebruikData['aanbieder'] ?? null;

            // Check various ways the afnemer might be stored (UUID, object, or string).
            $afnemerId = null;
            if (is_array(value: $afnemerInfo) === true && isset($afnemerInfo['id']) === true) {
                $afnemerId = $afnemerInfo['id'];
            } else if (is_string(value: $afnemerInfo) === true) {
                $afnemerId = $afnemerInfo;
            }

            // Check various ways the aanbieder might be stored (UUID, object, or string).
            $aanbiederId = null;
            if (is_array(value: $aanbiederInfo) === true && isset($aanbiederInfo['id']) === true) {
                $aanbiederId = $aanbiederInfo['id'];
            } else if (is_string(value: $aanbiederInfo) === true) {
                $aanbiederId = $aanbiederInfo;
            }

            // Allow operation if current org is either afnemer or aanbieder.
            $isAfnemer   = ($afnemerId !== null && $afnemerId === $currentOrg);
            $isAanbieder = ($aanbiederId !== null && $aanbiederId === $currentOrg);

            if ($isAfnemer === false && $isAanbieder === false) {
                return [
                    'success' => false,
                    'error'   => 'Operation not allowed: active organization is not the afnemer or aanbieder',
                    'gebruik' => null,
                    'debug'   => [
                        'afnemer_in_object'     => $afnemerInfo,
                        'resolved_afnemer_id'   => $afnemerId,
                        'aanbieder_in_object'   => $aanbiederInfo,
                        'resolved_aanbieder_id' => $aanbiederId,
                        'current_org'           => $currentOrg,
                    ],
                ];
            }

            // Update the @self.organisation and @self.owner properties.
            // Both must be set so the accepting user has permission to read/update the object.
            $currentUser = $this->userSession->getUser();
            $selfData    = ['organisation' => $currentOrg];
            if ($currentUser !== null) {
                $selfData['owner'] = $currentUser->getUID();
            }

            $gebruikData['@self'] = $selfData;

            // Update geregistreerdDoor based on the accepting organisation's type.
            $gebruikData = $this->updateGeregistreerdDoor(
                objectService: $objectService,
                objectData: $gebruikData,
                organisationUuid: $currentOrg
            );

            // Save the updated object with RBAC and multitenancy disabled.
            // Use register/schema from the found entity for correct table routing.
            $existingGebruik->setObject($gebruikData);
            $updatedGebruik = $objectService->saveObject(
                object: $existingGebruik,
                register: $existingGebruik->getRegister(),
                schema: $existingGebruik->getSchema(),
                uuid: $gebruikId,
                _rbac: false,
                _multitenancy: false
            );

            $this->logger->info(
                    'Successfully updated gebruik @self property',
                    [
                        'gebruik_id'   => $gebruikId,
                        'organisation' => $currentOrg,
                        'owner'        => $currentUser?->getUID(),
                        'is_afnemer'   => $isAfnemer,
                        'is_aanbieder' => $isAanbieder,
                        'afnemer_id'   => $afnemerId,
                        'aanbieder_id' => $aanbiederId,
                    ]
                    );

            return [
                'success'        => true,
                'message'        => 'Gebruik @self property updated successfully',
                'gebruik'        => $updatedGebruik->getObject(),
                'updated_fields' => ['@self.organisation', '@self.owner'],
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to update gebruik @self property',
                    [
                        'gebruik_id' => $gebruikId,
                        'error'      => $e->getMessage(),
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => 'Failed to update gebruik: '.$e->getMessage(),
                'gebruik' => null,
            ];
        }//end try
    }//end setGebruikSelfToActiveOrg()

    /**
     * Find a gebruik or koppeling object by UUID across possible schemas
     *
     * Searches gebruik and koppeling schemas because the object could be
     * either type. Register/schema context is required to find objects
     * stored in magic tables.
     *
     * @param ObjectService $objectService The OpenRegister object service
     * @param string        $objectId      The UUID of the object to find
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The found object or null
     */
    private function findGebruikOrKoppeling(ObjectService $objectService, string $objectId): ?\OCA\OpenRegister\Db\ObjectEntity
    {
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $registerId          = $voorzieningenConfig['register'] ?? null;

        if ($registerId === null) {
            $this->logger->warning('Cannot find object: no register configured');
            return null;
        }

        // Search across gebruik and koppeling schemas.
        $schemasToTry = array_filter(
                [
                    $voorzieningenConfig['gebruik_schema'] ?? null,
                    $voorzieningenConfig['koppeling_schema'] ?? null,
                ]
                );

        foreach ($schemasToTry as $schemaId) {
            try {
                $object = $objectService->find(
                    id: $objectId,
                    register: $registerId,
                    schema: $schemaId,
                    _rbac: false,
                    _multitenancy: false
                );
                if ($object !== null) {
                    return $object;
                }
            } catch (Exception $e) {
                // Object not found in this schema, try next.
                continue;
            }
        }

        $this->logger->warning(
                'Object not found in any gebruik/koppeling schema',
                [
                    'object_id'     => $objectId,
                    'schemas_tried' => $schemasToTry,
                ]
                );

        return null;
    }//end findGebruikOrKoppeling()

    /**
     * Get current active organisation for filtering
     *
     * @return string|null Current organisation identifier or null if no user session
     */
    private function getCurrentOrganisation(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        try {
            // Get the OpenRegister OrganisationService to get the active organisation.
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
            $activeOrg           = $organisationService->getActiveOrganisation();

            if ($activeOrg !== null) {
                return $activeOrg->getUuid();
            }

            return null;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get current organisation from OpenRegister',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return null;
        }
    }//end getCurrentOrganisation()

    /**
     * Get ObjectService from OpenRegister app
     *
     * @return ObjectService The OpenRegister object service
     * @throws Exception When OpenRegister service is not available
     */
    private function getObjectService(): ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
            throw new Exception('OpenRegister app is not installed');
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Exception $e) {
            throw new Exception('Failed to get OpenRegister service: '.$e->getMessage());
        }
    }//end getObjectService()

    /**
     * Get configuration for gebruiks objects (register ID and schema IDs)
     *
     * @return array Configuration with register_id and schemas array
     * @throws Exception When configuration is not available
     */
    private function getGebruiksConfiguration(): array
    {
        // Try to get voorzieningen configuration from SettingsService.
        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();

            $this->logger->debug(
                    'Retrieved voorzieningen configuration',
                    [
                        'config' => $voorzieningenConfig,
                    ]
                    );

            $registerId    = $voorzieningenConfig['register'] ?? null;
            $gebruikSchema = $voorzieningenConfig['gebruik_schema'] ?? null;

            // If configuration is available, use it.
            if ($registerId !== null && $gebruikSchema !== null) {
                return [
                    'register_id' => $registerId,
                    'schemas'     => [$gebruikSchema],
                ];
            }
        } catch (Exception $e) {
            $this->logger->warning(
                    'Failed to get voorzieningen configuration from SettingsService',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }//end try

        // No hardcoded fallback - configuration must be properly set.
        $this->logger->error(
                'Failed to get voorzieningen configuration - no fallback provided',
                [
                    'registerId'          => $registerId ?? 'null',
                    'gebruikSchema'       => $gebruikSchema ?? 'null',
                    'voorzieningenConfig' => $voorzieningenConfig ?? 'null',
                ]
                );

        throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
    }//end getGebruiksConfiguration()

    /**
     * Get configuration for koppelingen objects (register ID and schema IDs)
     *
     * @return array Configuration with register_id and schemas array
     * @throws Exception When configuration is not available
     */
    private function getKoppelingenConfiguration(): array
    {
        // Try to get voorzieningen configuration from SettingsService.
        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();

            $this->logger->debug(
                    'Retrieved voorzieningen configuration for koppelingen',
                    [
                        'config' => $voorzieningenConfig,
                    ]
                    );

            $registerId       = $voorzieningenConfig['register'] ?? null;
            $koppeligenSchema = $voorzieningenConfig['koppeling_schema'] ?? null;

            // If configuration is available, use it.
            if ($registerId !== null && $koppeligenSchema !== null) {
                return [
                    'register_id' => $registerId,
                    'schemas'     => [$koppeligenSchema],
                ];
            }
        } catch (Exception $e) {
            $this->logger->warning(
                    'Failed to get koppelingen configuration from SettingsService',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }//end try

        // No hardcoded fallback - configuration must be properly set.
        $this->logger->error(
                'Failed to get koppelingen configuration - no fallback provided',
                [
                    'registerId'          => $registerId ?? 'null',
                    'koppeligenSchema'    => $koppeligenSchema ?? 'null',
                    'voorzieningenConfig' => $voorzieningenConfig ?? 'null',
                ]
                );

        throw new Exception('Koppelingen configuration not found. Please configure the schemas in the admin panel.');
    }//end getKoppelingenConfiguration()

    /**
     * Get all objects for a specific schema using paginated search, optionally filtered by organization
     *
     * Uses ObjectService's buildSearchQuery() for proper query construction
     *
     * @param ObjectService $objectService      The OpenRegister object service
     * @param string        $registerId         The register ID
     * @param string        $schemaId           The schema ID
     * @param array         $options            Query options (includes request parameters for buildSearchQuery)
     * @param string|null   $organisationFilter Optional organization UUID to filter by
     *
     * @return array Paginated result from searchObjectsPaginated
     *
     * @throws Exception When query fails
     */
    private function getAllObjectsForSchema(
        ObjectService $objectService,
        string $registerId,
        string $schemaId,
        array $options=[],
        ?string $organisationFilter=null
    ): array {
        // Use ObjectService's buildSearchQuery to properly handle request parameters.
        $searchQuery = $objectService->buildSearchQuery($options);

        // Add schema and register filters.
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['@self']['register'] = $registerId;

        // Add organization filter if provided.
        if ($organisationFilter !== null) {
            $searchQuery['@self']['organisation'] = $organisationFilter;
        }

        // Force database source for real-time data.
        $searchQuery['_source'] = 'database';

        $this->logger->debug(
                'Getting all objects for schema (paginated)',
                [
                    'register'            => $registerId,
                    'schema'              => $schemaId,
                    'organisation_filter' => $organisationFilter,
                    'query'               => $searchQuery,
                ]
                );

        // Execute search with RBAC and multitenancy disabled using paginated search.
        $searchResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false,
            deleted: false
        );

        return $searchResult;
    }//end getAllObjectsForSchema()

    /**
     * Get all applications/modules owned by an organization
     *
     * @param ObjectService $objectService    The OpenRegister object service
     * @param string        $organisationUuid The organization UUID
     *
     * @return array Array of application/module UUIDs
     *
     * @throws Exception When query fails
     */
    private function getApplicationsOwnedByOrganisation(
        ObjectService $objectService,
        string $organisationUuid
    ): array {
        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $registerId          = $voorzieningenConfig['register'] ?? null;

            if ($registerId === null) {
                return [];
            }

            $appUuids = [];

            // Check suite schema (applications).
            if (isset($voorzieningenConfig['suite_schema']) === true) {
                $suiteQuery = [
                    '@self'   => [
                        'register'     => $registerId,
                        'schema'       => $voorzieningenConfig['suite_schema'],
                        'organisation' => $organisationUuid,
                    ],
                    '_source' => 'database',
                ];

                $suites = $objectService->searchObjects(
                    query: $suiteQuery,
                    _rbac: false,
                    _multitenancy: false
                );

                foreach ($suites as $suite) {
                    if (is_array(value: $suite) === true) {
                        $suiteData = $suite;
                    } else {
                        $suiteData = $suite->getObject();
                    }

                    $appUuids[] = $suiteData['uuid'] ?? $suiteData['id'] ?? null;
                }
            }//end if

            // Check module schema.
            if (isset($voorzieningenConfig['module_schema']) === true) {
                $moduleQuery = [
                    '@self'   => [
                        'register'     => $registerId,
                        'schema'       => $voorzieningenConfig['module_schema'],
                        'organisation' => $organisationUuid,
                    ],
                    '_source' => 'database',
                ];

                $modules = $objectService->searchObjects(
                    query: $moduleQuery,
                    _rbac: false,
                    _multitenancy: false
                );

                foreach ($modules as $module) {
                    if (is_array(value: $module) === true) {
                        $moduleData = $module;
                    } else {
                        $moduleData = $module->getObject();
                    }

                    $appUuids[] = $moduleData['uuid'] ?? $moduleData['id'] ?? null;
                }
            }//end if

            // Filter out nulls.
            $appUuids = array_filter($appUuids);

            $this->logger->debug(
                    'Found applications owned by organization',
                    [
                        'organisation' => $organisationUuid,
                        'count'        => count($appUuids),
                    ]
                    );

            return $appUuids;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to get applications owned by organization',
                    [
                        'organisation' => $organisationUuid,
                        'error'        => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try
    }//end getApplicationsOwnedByOrganisation()

    /**
     * Get objects related to a specific UUID via the uses relationship using paginated search
     *
     * Uses ObjectService's buildSearchQuery() for proper query construction
     *
     * @param ObjectService $objectService      The OpenRegister object service
     * @param string        $registerId         The register ID
     * @param string        $schemaId           The schema ID
     * @param string        $relatedUuid        The UUID to find relationships for
     * @param array         $options            Query options (includes request parameters for buildSearchQuery)
     * @param string|null   $organisationFilter Optional organization UUID to filter by
     *
     * @return array Paginated result from searchObjectsPaginated
     *
     * @throws Exception When query fails
     */
    private function getObjectsRelatedToUuid(
        ObjectService $objectService,
        string $registerId,
        string $schemaId,
        string $relatedUuid,
        array $options=[],
        ?string $organisationFilter=null
    ): array {
        // Use ObjectService's buildSearchQuery to properly handle request parameters.
        $searchQuery = $objectService->buildSearchQuery($options);

        // Add schema and register filters.
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['@self']['register'] = $registerId;

        // Add organization filter if provided.
        if ($organisationFilter !== null) {
            $searchQuery['@self']['organisation'] = $organisationFilter;
        }

        // Force database source for real-time data.
        $searchQuery['_source'] = 'database';

        $this->logger->debug(
                'Getting objects related to UUID (paginated)',
                [
                    'register'            => $registerId,
                    'schema'              => $schemaId,
                    'related_uuid'        => $relatedUuid,
                    'organisation_filter' => $organisationFilter,
                    'query'               => $searchQuery,
                ]
                );

        // Execute search using the uses parameter to find relationships with pagination.
        $searchResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false,
            deleted: false,
            uses: $relatedUuid
        );

        return $searchResult;
    }//end getObjectsRelatedToUuid()

    /**
     * Check if an organization owns a specific application/module
     *
     * @param ObjectService $objectService    The OpenRegister object service
     * @param string        $appUuid          The application/module UUID
     * @param string        $organisationUuid The organization UUID
     *
     * @return bool True if organization owns the application/module
     */
    private function checkOrganisationOwnership(
        ObjectService $objectService,
        string $appUuid,
        string $organisationUuid
    ): bool {
        try {
            // Get the application/module object.
            $appObject = $objectService->find(
                id: $appUuid,
                _rbac: false,
                _multitenancy: false
            );

            if ($appObject === null) {
                return false;
            }

            // Check if the organization owns it.
            $appData  = $appObject->getObject();
            $ownerOrg = $appData['@self']['organisation'] ?? null;

            $isOwner = ($ownerOrg === $organisationUuid);

            $this->logger->debug(
                    'Checked organization ownership',
                    [
                        'app_uuid'     => $appUuid,
                        'organisation' => $organisationUuid,
                        'owner_org'    => $ownerOrg,
                        'is_owner'     => $isOwner,
                    ]
                    );

            return $isOwner;
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to check organization ownership',
                    [
                        'app_uuid'     => $appUuid,
                        'organisation' => $organisationUuid,
                        'error'        => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end checkOrganisationOwnership()

    /**
     * Mapping from organisatie.type to geregistreerdDoor value
     */
    private const TYPE_MAP = [
        'Gemeente'     => 'Gemeente',
        'Leverancier'  => 'Leverancier',
        'Samenwerking' => 'Samenwerking',
        'Community'    => 'Community',
    ];

    /**
     * Update geregistreerdDoor on object data based on the organisation's type
     *
     * Looks up the organisation object by UUID, reads its type, and maps it
     * to the appropriate geregistreerdDoor value using TYPE_MAP.
     *
     * @param ObjectService $objectService    The OpenRegister object service
     * @param array         $objectData       The object data to update
     * @param string        $organisationUuid The UUID of the organisation to look up
     *
     * @return array The updated object data
     */
    private function updateGeregistreerdDoor(
        ObjectService $objectService,
        array $objectData,
        string $organisationUuid
    ): array {
        try {
            $organisatieSchemaId = $this->settingsService->getSchemaIdForObjectType('organisatie');
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $registerId          = $voorzieningenConfig['register'] ?? null;

            if ($organisatieSchemaId === null || $registerId === null) {
                return $objectData;
            }

            $organisatieObject = $objectService->find(
                id: $organisationUuid,
                register: (int) $registerId,
                schema: (int) $organisatieSchemaId,
                _rbac: false,
                _multitenancy: false
            );

            if ($organisatieObject === null) {
                return $objectData;
            }

            $organisatieData = $organisatieObject->getObject();
            $orgType         = $organisatieData['type'] ?? null;

            if ($orgType !== null && isset(self::TYPE_MAP[$orgType]) === true) {
                $objectData['geregistreerdDoor'] = self::TYPE_MAP[$orgType];

                $this->logger->info(
                        'Updated geregistreerdDoor during transfer',
                        [
                            'organisationUuid'  => $organisationUuid,
                            'orgType'           => $orgType,
                            'geregistreerdDoor' => self::TYPE_MAP[$orgType],
                        ]
                        );
            }
        } catch (Exception $e) {
            $this->logger->warning(
                    'Failed to update geregistreerdDoor during transfer',
                    [
                        'organisationUuid' => $organisationUuid,
                        'error'            => $e->getMessage(),
                    ]
                    );
        }//end try

        return $objectData;
    }//end updateGeregistreerdDoor()

    /**
     * Add query filters from options to the base query
     *
     * This method processes additional filter options and adds them to the query.
     * Supported filters: limit, offset, status, suite, etc.
     *
     * @param array $baseQuery The base query to extend
     * @param array $options   Filter options to apply
     *
     * @return array Extended query with additional filters
     */
    private function addQueryFilters(array $baseQuery, array $options): array
    {
        // Add limit if specified (handle both 'limit' and '_limit').
        $limit = $options['_limit'] ?? $options['limit'] ?? null;
        if ($limit !== null && is_numeric(value: $limit) === true) {
            $baseQuery['_limit'] = (int) $limit;
        }

        // Add offset if specified (handle both 'offset' and '_offset').
        $offset = $options['_offset'] ?? $options['offset'] ?? null;
        if ($offset !== null && is_numeric(value: $offset) === true) {
            $baseQuery['_offset'] = (int) $offset;
        }

        // Add page if specified (alternative to offset).
        if (isset($options['_page']) === true && is_numeric(value: $options['_page']) === true) {
            $baseQuery['_page'] = (int) $options['_page'];
        }

        // Add source parameter if specified (for forcing database access).
        if (isset($options['_source']) === true && empty($options['_source']) === false) {
            $baseQuery['_source'] = $options['_source'];
        }

        // Add status filter if specified.
        if (isset($options['status']) === true && empty($options['status']) === false) {
            $baseQuery['status'] = $options['status'];
        }

        // Add suite filter if specified.
        if (isset($options['suite']) === true && empty($options['suite']) === false) {
            $baseQuery['suite'] = $options['suite'];
        }

        // Add date filters if specified.
        if (isset($options['startDate']) === true && empty($options['startDate']) === false) {
            $baseQuery['startDate'] = $options['startDate'];
        }

        if (isset($options['endDate']) === true && empty($options['endDate']) === false) {
            $baseQuery['endDate'] = $options['endDate'];
        }

        return $baseQuery;
    }//end addQueryFilters()

    /**
     * Delete (deny) a gebruik or koppeling object with security validation
     *
     * This method allows deleting a gebruik or koppeling object, but only if the active
     * organization is the afnemer (consumer) or aanbieder (provider) for that object.
     * This implements the "deny" workflow where a gemeente can reject a suggestion from
     * a leverancier, or a leverancier can reject/delete their own koppelingen.
     *
     * Security: Since we disable RBAC to access cross-organisation objects, we must
     * implement our own security checks to ensure only the afnemer or aanbieder can delete.
     *
     * @param string $gebruikId The UUID of the gebruik or koppeling object to delete
     * @param array  $options   Additional options for the operation
     *
     * @return array Result array with success status and details
     */
    public function deleteGebruikAsAfnemer(string $gebruikId, array $options=[]): array
    {
        $this->logger->info(
                'Deleting gebruik object as afnemer or aanbieder',
                [
                    'gebruik_id' => $gebruikId,
                    'options'    => $options,
                ]
                );

        try {
            // Validate input.
            if (empty($gebruikId) === true) {
                return [
                    'success' => false,
                    'error'   => 'Gebruik ID is required',
                    'deleted' => false,
                ];
            }

            // Get ObjectService from OpenRegister.
            $objectService = $this->getObjectService();

            // Get current organization.
            $currentOrg = $this->getCurrentOrganisation();
            if ($currentOrg === null) {
                return [
                    'success' => false,
                    'error'   => 'No current organization available',
                    'deleted' => false,
                ];
            }

            // Find the gebruik or koppeling object across possible schemas.
            // Register/schema context is required to search magic tables.
            $existingGebruik = $this->findGebruikOrKoppeling(objectService: $objectService, objectId: $gebruikId);

            if ($existingGebruik === null) {
                return [
                    'success' => false,
                    'error'   => 'Gebruik object not found',
                    'deleted' => false,
                ];
            }

            $gebruikData = $existingGebruik->getObject();

            // SECURITY CHECK: Verify that the active organization is either the afnemer or aanbieder.
            // This is critical since we're bypassing RBAC.
            $afnemerInfo   = $gebruikData['afnemer'] ?? null;
            $aanbiederInfo = $gebruikData['aanbieder'] ?? null;

            // Check various ways the afnemer might be stored (UUID, object, or string).
            $afnemerId = null;
            if (is_array(value: $afnemerInfo) === true && isset($afnemerInfo['id']) === true) {
                $afnemerId = $afnemerInfo['id'];
            } else if (is_string(value: $afnemerInfo) === true) {
                $afnemerId = $afnemerInfo;
            }

            // Check various ways the aanbieder might be stored (UUID, object, or string).
            $aanbiederId = null;
            if (is_array(value: $aanbiederInfo) === true && isset($aanbiederInfo['id']) === true) {
                $aanbiederId = $aanbiederInfo['id'];
            } else if (is_string(value: $aanbiederInfo) === true) {
                $aanbiederId = $aanbiederInfo;
            }

            // Allow operation if current org is either afnemer or aanbieder.
            $isAfnemer   = ($afnemerId !== null && $afnemerId === $currentOrg);
            $isAanbieder = ($aanbiederId !== null && $aanbiederId === $currentOrg);

            if ($isAfnemer === false && $isAanbieder === false) {
                $this->logger->warning(
                        'Unauthorized delete attempt - user is not afnemer or aanbieder',
                        [
                            'gebruik_id'            => $gebruikId,
                            'current_org'           => $currentOrg,
                            'afnemer_in_object'     => $afnemerInfo,
                            'resolved_afnemer_id'   => $afnemerId,
                            'aanbieder_in_object'   => $aanbiederInfo,
                            'resolved_aanbieder_id' => $aanbiederId,
                        ]
                        );

                return [
                    'success' => false,
                    'error'   => 'Operation not allowed: active organization is not the afnemer or aanbieder',
                    'deleted' => false,
                    'debug'   => [
                        'afnemer_in_object'     => $afnemerInfo,
                        'resolved_afnemer_id'   => $afnemerId,
                        'aanbieder_in_object'   => $aanbiederInfo,
                        'resolved_aanbieder_id' => $aanbiederId,
                        'current_org'           => $currentOrg,
                    ],
                ];
            }//end if

            // Delete the object with RBAC and multitenancy disabled.
            // Use register/schema from the found entity for correct table routing.
            $objectService->setRegister($existingGebruik->getRegister());
            $objectService->setSchema($existingGebruik->getSchema());

            $deleteResult = $objectService->deleteObject(
                uuid: $gebruikId,
                _rbac: false,
            // Disable RBAC to allow cross-organisation deletion.
                _multitenancy: false
            // Disable multitenancy to allow deletion from different organisations.
            );

            $this->logger->info(
                    'Successfully deleted gebruik object',
                    [
                        'gebruik_id'    => $gebruikId,
                        'organisation'  => $currentOrg,
                        'is_afnemer'    => $isAfnemer,
                        'is_aanbieder'  => $isAanbieder,
                        'afnemer_id'    => $afnemerId,
                        'aanbieder_id'  => $aanbiederId,
                        'delete_result' => $deleteResult,
                    ]
                    );

            return [
                'success'      => true,
                'message'      => 'Gebruik object deleted successfully',
                'deleted'      => true,
                'gebruik_id'   => $gebruikId,
                'organisation' => $currentOrg,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    'Failed to delete gebruik object',
                    [
                        'gebruik_id' => $gebruikId,
                        'error'      => $e->getMessage(),
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => 'Failed to delete gebruik: '.$e->getMessage(),
                'deleted' => false,
            ];
        }//end try
    }//end deleteGebruikAsAfnemer()
}//end class
