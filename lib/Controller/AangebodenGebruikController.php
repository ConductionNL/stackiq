<?php

declare(strict_types=1);

/*
 * AangebodenGebruik Controller for SoftwareCatalog
 *
 * Handles HTTP requests for offered usage (aangeboden gebruik) operations including
 * retrieving gebruiks objects where the active organization is involved as afnemer
 * or in deelnemers, and updating the @self property of gebruiks objects.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\SoftwareCatalog\Service\AangebodenGebruikService;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling offered usage (aangeboden gebruik) API operations
 *
 * This controller provides REST API endpoints for managing gebruiks objects where
 * the active organization is involved either as afnemer (consumer) or in deelnemers
 * (participants), and for updating the @self property of gebruiks objects.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */
class AangebodenGebruikController extends Controller
{
    /**
     * Constructor for AangebodenGebruikController
     *
     * @param string                   $appName                  The name of the app
     * @param IRequest                 $request                  The HTTP request object
     * @param IUserSession             $userSession              The user session service for getting the current user
     * @param AangebodenGebruikService $aangebodenGebruikService The business logic service
     * @param LoggerInterface          $logger                   The logger service for debugging and error reporting
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly AangebodenGebruikService $aangebodenGebruikService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Get all gebruiks objects where the active organization is the afnemer (consumer)
     *
     * API Endpoint: GET /api/aangeboden-gebruik/afnemer
     *
     * Query Parameters:
     * - limit (int): Maximum number of results to return
     * - offset (int): Number of results to skip for pagination
     * - status (string): Filter by usage status
     * - product (string): Filter by product ID
     * - startDate (string): Filter by start date (ISO 8601 format)
     * - endDate (string): Filter by end date (ISO 8601 format)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @PublicPage
     *
     * @return JSONResponse JSON response with gebruiks array where org is afnemer
     */
    public function getGebruiksWhereAfnemer(): JSONResponse
    {
        $this->logger->info(
                'API: Getting gebruiks where active org is afnemer',
                [
                    'endpoint'     => '/api/aangeboden-gebruik/afnemer',
                    'method'       => 'GET',
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Parse query parameters for filtering options (force database source)
            // Don't include product filter for "get all" endpoint
            $options = $this->parseQueryOptions();

            // Get gebruiks from service where org is afnemer
            $result = $this->aangebodenGebruikService->getGebruiksWhereAfnemer($options);

            // Determine HTTP status code based on whether there's an error
            $statusCode = isset($result['error']) ? 500 : 200;

            $this->logger->info(
                    'API: Afnemer gebruiks request completed',
                    [
                        'total'         => $result['total'] ?? 0,
                        'results_count' => count($result['results'] ?? []),
                        'has_error'     => isset($result['error']),
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get afnemer gebruiks',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 0,
                        'limit'   => 20,
                        'offset'  => 0,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getGebruiksWhereAfnemer()

    /**
     * Get koppelingen and gebruiks for a specific application/module UUID
     *
     * This endpoint returns both koppelingen and gebruiks related to a specific application/module.
     * Access rules:
     * - Users with "ambtenaar" group can see all related objects
     * - Users whose organization owns the application/module can see all related usage
     * - Supports filtering by organization UUID via query parameter for ambtenaar users
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @param  string $uuid The UUID of the application/module
     * @return JSONResponse Koppelingen and gebruiks objects for the specified UUID
     */
    public function getKoppelingenGebruikByUuid(string $uuid): JSONResponse
    {
        $this->logger->info(
                'API: Getting koppelingen and gebruiks for specific UUID',
                [
                    'endpoint'     => '/api/koppelingen-gebruik/{uuid}',
                    'method'       => 'GET',
                    'uuid'         => $uuid,
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Check if user is in admin or ambtenaar group
            $isAmbtenaar = $this->isUserInGroup('admin') || $this->isUserInGroup('ambtenaar');

            // Get organization filter if provided (only for ambtenaar users)
            $organisationFilter = $this->request->getParam('organisation');

            // Parse query parameters for filtering options
            $options = $this->parseQueryOptions();

            // Add organization filter if provided and user is ambtenaar
            if ($isAmbtenaar && $organisationFilter) {
                $options['organisation'] = $organisationFilter;
            }

            // Get koppelingen and gebruiks for UUID from service
            $result = $this->aangebodenGebruikService->getKoppelingenGebruikByUuid($uuid, $options, $isAmbtenaar);

            // Determine HTTP status code based on whether there's an error
            $statusCode = isset($result['error']) ? 500 : 200;

            $this->logger->info(
                    'API: Koppelingen-gebruik by UUID request completed',
                    [
                        'uuid'          => $uuid,
                        'total'         => $result['total'] ?? 0,
                        'results_count' => count($result['results'] ?? []),
                        'has_error'     => isset($result['error']),
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get koppelingen-gebruik by UUID',
                    [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 0,
                        'limit'   => 20,
                        'offset'  => 0,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getKoppelingenGebruikByUuid()

    /**
     * Get all gebruiks objects (ignoring RBAC and multitenancy) - restricted to ambtenaar group
     *
     * This endpoint returns all gebruiks objects regardless of ownership or organization,
     * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
     * with the "ambtenaar" group.
     *
     * @deprecated      Use getKoppelingenGebruik() instead
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse All gebruiks objects in standard searchObjectsPaginated format
     */
    public function getAllGebruiksForAmbtenaar(): JSONResponse
    {
        $this->logger->info(
                'API: Getting all gebruiks for ambtenaar (ignoring RBAC/multitenancy)',
                [
                    'endpoint'     => '/api/aangeboden-gebruik/ambtenaar',
                    'method'       => 'GET',
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Check if user is in admin or ambtenaar group
            if (!$this->isUserInGroup('admin') && !$this->isUserInGroup('ambtenaar')) {
                // Get user ID for logging (may be null if not authenticated)
                $user   = $this->userSession->getUser();
                $userId = $user ? $user->getUID() : 'null';

                $this->logger->info(
                        'API: Returning empty results - user not in admin or ambtenaar group',
                        [
                            'endpoint' => '/api/aangeboden-gebruik/ambtenaar',
                            'user'     => $userId,
                        ]
                        );

                // Return empty results with 200 status (not an error)
                return new JSONResponse(
                        [
                            'results' => [],
                            'total'   => 0,
                            'page'    => 1,
                            'pages'   => 1,
                            'limit'   => 20,
                            'offset'  => 0,
                        ],
                        200
                        );
            }//end if

            // Parse query parameters for filtering options (force database source)
            // Don't include product filter for "get all" endpoint
            $options = $this->parseQueryOptions();

            // Get all gebruiks from service (ignoring RBAC/multitenancy)
            $result = $this->aangebodenGebruikService->getAllGebruiksForAmbtenaar($options);

            // Determine HTTP status code based on whether there's an error
            $statusCode = isset($result['error']) ? 500 : 200;

            $this->logger->info(
                    'API: Ambtenaar all gebruiks request completed',
                    [
                        'total'         => $result['total'] ?? 0,
                        'results_count' => count($result['results'] ?? []),
                        'has_error'     => isset($result['error']),
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get all gebruiks for ambtenaar',
                    [
                        'error'    => $e->getMessage(),
                        'endpoint' => '/api/aangeboden-gebruik/ambtenaar',
                    ]
                    );

            return new JSONResponse(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 0,
                        'limit'   => 20,
                        'offset'  => 0,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getAllGebruiksForAmbtenaar()

    /**
     * Get a single gebruiks object by ID (ignoring RBAC and multitenancy) - restricted to ambtenaar group
     *
     * This endpoint returns a specific gebruiks object by its ID, bypassing normal RBAC
     * and multitenancy restrictions. Access is restricted to users with the "ambtenaar" group.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @PublicPage
     *
     * @param  string $gebruikId The ID of the gebruik object to retrieve
     * @return JSONResponse Single gebruik object in standard searchObjectsPaginated format
     */
    public function getSingleGebruikForAmbtenaar(string $gebruikId): JSONResponse
    {
        $this->logger->info(
                'API: Getting single gebruik for ambtenaar (ignoring RBAC/multitenancy)',
                [
                    'endpoint'     => '/api/aangeboden-gebruik/ambtenaar/{gebruikId}',
                    'method'       => 'GET',
                    'gebruik_id'   => $gebruikId,
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Check if user is in admin or ambtenaar group
            if (!$this->isUserInGroup('admin') && !$this->isUserInGroup('ambtenaar')) {
                // Get user ID for logging (may be null if not authenticated)
                $user   = $this->userSession->getUser();
                $userId = $user ? $user->getUID() : 'null';

                $this->logger->info(
                        'API: Returning empty results - user not in admin or ambtenaar group',
                        [
                            'endpoint'   => '/api/aangeboden-gebruik/ambtenaar/{gebruikId}',
                            'gebruik_id' => $gebruikId,
                            'user'       => $userId,
                        ]
                        );

                // Return empty results with 200 status (not an error)
                return new JSONResponse(
                        [
                            'results' => [],
                            'total'   => 0,
                            'page'    => 1,
                            'pages'   => 1,
                            'limit'   => 20,
                            'offset'  => 0,
                        ],
                        200
                        );
            }//end if

            // Parse query parameters for filtering options (force database source)
            // Don't include product filter - we use uses parameter instead
            $options = $this->parseQueryOptions();

            // Get single gebruik from service (ignoring RBAC/multitenancy)
            $result = $this->aangebodenGebruikService->getSingleGebruikForAmbtenaar($gebruikId, $options);

            // Determine HTTP status code based on whether there's an error
            $statusCode = isset($result['error']) ? 500 : 200;

            $this->logger->info(
                    'API: Ambtenaar single gebruik request completed',
                    [
                        'gebruik_id'    => $gebruikId,
                        'total'         => $result['total'] ?? 0,
                        'results_count' => count($result['results'] ?? []),
                        'has_error'     => isset($result['error']),
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get single gebruik for ambtenaar',
                    [
                        'gebruik_id' => $gebruikId,
                        'error'      => $e->getMessage(),
                        'endpoint'   => '/api/aangeboden-gebruik/ambtenaar/{gebruikId}',
                    ]
                    );

            return new JSONResponse(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 0,
                        'limit'   => 20,
                        'offset'  => 0,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end getSingleGebruikForAmbtenaar()

    /**
     * Check if the current user is in a specific group
     *
     * @param  string $groupName The name of the group to check
     * @return bool True if user is in the group, false otherwise
     */
    private function isUserInGroup(string $groupName): bool
    {
        try {
            // Get the current user from the session
            $user = $this->userSession->getUser();
            if (!$user) {
                $this->logger->debug(
                        'No user in session for group check',
                        [
                            'group' => $groupName,
                        ]
                        );
                return false;
            }

            $userId       = $user->getUID();
            $groupManager = \OC::$server->getGroupManager();

            $group = $groupManager->get($groupName);
            if (!$group) {
                $this->logger->warning('Group does not exist', ['group' => $groupName]);
                return false;
            }

            $isInGroup = $group->inGroup($user);

            $this->logger->debug(
                    'User group membership check',
                    [
                        'user'     => $userId,
                        'group'    => $groupName,
                        'isMember' => $isInGroup,
                    ]
                    );

            return $isInGroup;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to check user group membership',
                    [
                        'group' => $groupName,
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end isUserInGroup()

    /**
     * Get all gebruiks objects where the active organization is in deelnemers (participants)
     *
     * API Endpoint: GET /api/aangeboden-gebruik/deelnemers
     *
     * Query Parameters:
     * - limit (int): Maximum number of results to return
     * - offset (int): Number of results to skip for pagination
     * - status (string): Filter by usage status
     * - product (string): Filter by product ID
     * - startDate (string): Filter by start date (ISO 8601 format)
     * - endDate (string): Filter by end date (ISO 8601 format)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @PublicPage
     *
     * @return JSONResponse JSON response with gebruiks array where org is in deelnemers
     */
    public function getGebruiksWhereDeelnemers(): JSONResponse
    {
        $this->logger->info(
                'API: Getting gebruiks where active org is in deelnemers',
                [
                    'endpoint'     => '/api/aangeboden-gebruik/deelnemers',
                    'method'       => 'GET',
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Parse query parameters for filtering options
            // Don't include product filter for deelnemers endpoint
            $options = $this->parseQueryOptions(false);

            // Get gebruiks from service where org is in deelnemers
            $result = $this->aangebodenGebruikService->getGebruiksWhereDeelnemers($options);

            // Determine appropriate HTTP status code
            $statusCode = $result['success'] ? 200 : 500;

            $this->logger->info(
                    'API: Deelnemers gebruiks request completed',
                    [
                        'success'        => $result['success'],
                        'gebruiks_count' => $result['count'] ?? 0,
                        'organisation'   => $result['organisation'] ?? 'unknown',
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get deelnemers gebruiks',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success'  => false,
                        'error'    => 'Internal server error: '.$e->getMessage(),
                        'gebruiks' => [],
                        'count'    => 0,
                    ],
                    500
                    );
        }//end try
    }//end getGebruiksWhereDeelnemers()

    /**
     * Set the @self property of a gebruik or koppeling to the active organization
     *
     * API Endpoint: PUT /api/aangeboden-gebruik/{gebruikId}/set-self
     *
     * This endpoint allows setting the @self.organisation property of a specific gebruik
     * or koppeling object to the active organization, but only if the active organization
     * is the afnemer (consumer) or aanbieder (provider) for that object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @PublicPage
     *
     * @param  string $gebruikId The UUID of the gebruik or koppeling object to update
     * @return JSONResponse JSON response with success status and updated object
     */
    public function setGebruikSelfToActiveOrg(string $gebruikId): JSONResponse
    {
        $this->logger->info(
                'API: Setting gebruik @self property to active org',
                [
                    'endpoint'   => "/api/aangeboden-gebruik/{$gebruikId}/set-self",
                    'method'     => 'PUT',
                    'gebruik_id' => $gebruikId,
                ]
                );

        try {
            // Validate input
            if (empty($gebruikId)) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'error'   => 'Gebruik ID is required',
                            'gebruik' => null,
                        ],
                        400
                        );
            }

            // Parse any additional options from request body
            $options     = [];
            $requestBody = $this->request->getParams();
            if (!empty($requestBody)) {
                $options = array_filter(
                        $requestBody,
                        function ($key) {
                            return !in_array($key, ['gebruikId']);
                            // Exclude path parameters
                        },
                        ARRAY_FILTER_USE_KEY
                        );
            }

            // Update gebruik @self property via service
            $result = $this->aangebodenGebruikService->setGebruikSelfToActiveOrg($gebruikId, $options);

            // Determine appropriate HTTP status code
            $statusCode = $result['success'] ? 200 : ($result['error'] === 'Gebruik object not found' ? 404 : (strpos($result['error'] ?? '', 'Operation not allowed') !== false ? 403 : 500));

            $this->logger->info(
                    'API: Set gebruik @self property request completed',
                    [
                        'gebruik_id'  => $gebruikId,
                        'success'     => $result['success'],
                        'status_code' => $statusCode,
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to set gebruik @self property',
                    [
                        'gebruik_id' => $gebruikId,
                        'error'      => $e->getMessage(),
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                        'gebruik' => null,
                    ],
                    500
                    );
        }//end try
    }//end setGebruikSelfToActiveOrg()

    /**
     * Delete (deny) a gebruik or koppeling object as afnemer or aanbieder
     *
     * API Endpoint: DELETE /api/aangeboden-gebruik/{gebruikId}/deny
     *
     * This endpoint allows deleting a specific gebruik or koppeling object, but only
     * if the active organization is the afnemer (consumer) or aanbieder (provider) for
     * that object. This implements the "deny" workflow where a gemeente can reject a
     * suggestion from a leverancier, or a leverancier can reject/delete their own koppelingen.
     *
     * Security: Implements custom security checks since RBAC is disabled to access
     * cross-organisation objects. Only the afnemer or aanbieder can delete the object.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @PublicPage
     *
     * @param  string $gebruikId The UUID of the gebruik or koppeling object to delete
     * @return JSONResponse JSON response with success status and deletion details
     */
    public function deleteGebruikAsAfnemer(string $gebruikId): JSONResponse
    {
        $this->logger->info(
                'API: Deleting gebruik object as afnemer',
                [
                    'endpoint'   => "/api/aangeboden-gebruik/{$gebruikId}/deny",
                    'method'     => 'DELETE',
                    'gebruik_id' => $gebruikId,
                ]
                );

        try {
            // Validate input
            if (empty($gebruikId)) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'error'   => 'Gebruik ID is required',
                            'deleted' => false,
                        ],
                        400
                        );
            }

            // Parse any additional options from request body
            $options     = [];
            $requestBody = $this->request->getParams();
            if (!empty($requestBody)) {
                $options = array_filter(
                        $requestBody,
                        function ($key) {
                            return !in_array($key, ['gebruikId']);
                            // Exclude path parameters
                        },
                        ARRAY_FILTER_USE_KEY
                        );
            }

            // Delete gebruik object via service
            $result = $this->aangebodenGebruikService->deleteGebruikAsAfnemer($gebruikId, $options);

            // Determine appropriate HTTP status code
            $statusCode = $result['success'] ? 200 : ($result['error'] === 'Gebruik object not found' ? 404 : (strpos($result['error'] ?? '', 'Operation not allowed') !== false ? 403 : 500));

            $this->logger->info(
                    'API: Delete gebruik as afnemer request completed',
                    [
                        'gebruik_id'  => $gebruikId,
                        'success'     => $result['success'],
                        'deleted'     => $result['deleted'] ?? false,
                        'status_code' => $statusCode,
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to delete gebruik as afnemer',
                    [
                        'gebruik_id' => $gebruikId,
                        'error'      => $e->getMessage(),
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                        'deleted' => false,
                    ],
                    500
                    );
        }//end try
    }//end deleteGebruikAsAfnemer()

    /**
     * Get API documentation for AangebodenGebruik endpoints
     *
     * API Endpoint: GET /api/aangeboden-gebruik/docs
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @PublicPage
     *
     * @return JSONResponse JSON response with API documentation
     */
    public function getApiDocumentation(): JSONResponse
    {
        $documentation = [
            'api_version' => '2.0.0',
            'description' => 'SoftwareCatalog AangebodenGebruik API - Manage gebruiks and koppelingen objects with extended access control',
            'base_url'    => '/api/aangeboden-gebruik',
            'endpoints'   => [
                [
                    'method'           => 'GET',
                    'path'             => '/api/aangeboden-gebruik/afnemer',
                    'description'      => 'Get all gebruiks objects where the active organization is the afnemer (consumer)',
                    'parameters'       => [
                        [
                            'name'        => 'limit',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Maximum number of results to return',
                        ],
                        [
                            'name'        => 'offset',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Number of results to skip for pagination',
                        ],
                        [
                            'name'        => 'status',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by usage status',
                        ],
                        [
                            'name'        => 'product',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by product ID',
                        ],
                        [
                            'name'        => 'startDate',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by start date (ISO 8601 format)',
                        ],
                        [
                            'name'        => 'endDate',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by end date (ISO 8601 format)',
                        ],
                    ],
                    'response_example' => [
                        'success'      => true,
                        'gebruiks'     => [
                            [
                                'id'           => 'usage-uuid-123',
                                'afnemer'      => 'org-uuid',
                                'product'      => 'product-uuid',
                                'status'       => 'actief',
                                '_filter_type' => 'afnemer',
                                '_schema_id'   => 'schema-id',
                            ],
                        ],
                        'count'        => 1,
                        'filter_type'  => 'afnemer',
                        'organisation' => 'org-uuid',
                    ],
                ],
                [
                    'method'           => 'GET',
                    'path'             => '/api/aangeboden-gebruik/deelnemers',
                    'description'      => 'Get all gebruiks objects where the active organization is in deelnemers (participants)',
                    'parameters'       => [
                        [
                            'name'        => 'limit',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Maximum number of results to return',
                        ],
                        [
                            'name'        => 'offset',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Number of results to skip for pagination',
                        ],
                        [
                            'name'        => 'status',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by usage status',
                        ],
                        [
                            'name'        => 'product',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by product ID',
                        ],
                        [
                            'name'        => 'startDate',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by start date (ISO 8601 format)',
                        ],
                        [
                            'name'        => 'endDate',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by end date (ISO 8601 format)',
                        ],
                    ],
                    'response_example' => [
                        'success'      => true,
                        'gebruiks'     => [
                            [
                                'id'           => 'usage-uuid-456',
                                'afnemer'      => 'other-org-uuid',
                                'deelnemers'   => ['org-uuid', 'another-org-uuid'],
                                'product'      => 'product-uuid',
                                'status'       => 'actief',
                                '_filter_type' => 'deelnemers',
                                '_schema_id'   => 'schema-id',
                            ],
                        ],
                        'count'        => 1,
                        'filter_type'  => 'deelnemers',
                        'organisation' => 'org-uuid',
                    ],
                ],
                [
                    'method'           => 'PUT',
                    'path'             => '/api/aangeboden-gebruik/{gebruikId}/set-self',
                    'description'      => 'Set the @self property of a gebruik or koppeling to the active organization (only allowed if active org is afnemer or aanbieder)',
                    'parameters'       => [
                        [
                            'name'        => 'gebruikId',
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'The UUID of the gebruik object to update (in URL path)',
                        ],
                    ],
                    'response_example' => [
                        'success'        => true,
                        'message'        => 'Gebruik @self property updated successfully',
                        'gebruik'        => [
                            'id'      => 'usage-uuid-123',
                            'afnemer' => 'org-uuid',
                            '@self'   => [
                                'organisation' => 'org-uuid',
                                'register'     => 'register-id',
                                'schema'       => 'schema-id',
                            ],
                        ],
                        'updated_fields' => ['@self.organisation'],
                    ],
                ],
                [
                    'method'           => 'GET',
                    'path'             => '/api/aangeboden-gebruik/docs',
                    'description'      => 'Get this API documentation',
                    'parameters'       => [],
                    'response_example' => '(this response)',
                ],
                [
                    'method'           => 'GET',
                    'path'             => '/api/koppelingen-gebruik',
                    'description'      => 'Get all koppelingen and gebruiks objects with extended access control. Access rules: Users with ambtenaar group can see all objects (optionally filtered by organization). Users whose organization owns an application/module can see all related usage.',
                    'parameters'       => [
                        [
                            'name'        => 'organisation',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by organization UUID (only for ambtenaar users)',
                        ],
                        [
                            'name'        => 'limit',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Maximum number of results to return',
                        ],
                        [
                            'name'        => 'offset',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Number of results to skip for pagination',
                        ],
                    ],
                    'response_example' => [
                        'results' => [
                            [
                                'id'    => 'uuid-123',
                                'type'  => 'gebruik',
                                '@self' => [
                                    'organisation' => 'org-uuid',
                                    'register'     => 'register-id',
                                    'schema'       => 'schema-id',
                                ],
                            ],
                            [
                                'id'    => 'uuid-456',
                                'type'  => 'koppeling',
                                '@self' => [
                                    'organisation' => 'org-uuid',
                                    'register'     => 'register-id',
                                    'schema'       => 'schema-id',
                                ],
                            ],
                        ],
                        'total'   => 2,
                        'page'    => 1,
                        'pages'   => 1,
                        'limit'   => 20,
                        'offset'  => 0,
                    ],
                ],
                [
                    'method'           => 'GET',
                    'path'             => '/api/koppelingen-gebruik/{uuid}',
                    'description'      => 'Get koppelingen and gebruiks for a specific application/module UUID. Access rules: Users with ambtenaar group can see all related objects. Users whose organization owns the application/module can see all related usage.',
                    'parameters'       => [
                        [
                            'name'        => 'uuid',
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'The UUID of the application/module (in URL path)',
                        ],
                        [
                            'name'        => 'organisation',
                            'type'        => 'string',
                            'required'    => false,
                            'description' => 'Filter by organization UUID (only for ambtenaar users)',
                        ],
                        [
                            'name'        => 'limit',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Maximum number of results to return',
                        ],
                        [
                            'name'        => 'offset',
                            'type'        => 'integer',
                            'required'    => false,
                            'description' => 'Number of results to skip for pagination',
                        ],
                    ],
                    'response_example' => [
                        'results' => [
                            [
                                'id'    => 'uuid-123',
                                'type'  => 'gebruik',
                                '@self' => [
                                    'organisation' => 'org-uuid',
                                    'register'     => 'register-id',
                                    'schema'       => 'schema-id',
                                ],
                            ],
                        ],
                        'total'   => 1,
                        'page'    => 1,
                        'pages'   => 1,
                        'limit'   => 20,
                        'offset'  => 0,
                    ],
                ],
            ],
            'security'    => [
                'afnemer_filtering'          => 'Uses standard RBAC filtering based on organization association',
                'deelnemers_filtering'       => 'Uses RBAC-disabled search to find participation records',
                'self_update_permission'     => 'Only allowed if active organization is the afnemer or aanbieder for the specific gebruik or koppeling',
                'koppelingen_gebruik_access' => 'Extended access: ambtenaar users can see all objects (optionally filtered by organization), organization owners can see usage of their applications/modules',
            ],
            'error_codes' => [
                400 => 'Bad Request - Invalid parameters or missing required fields',
                403 => 'Forbidden - Operation not allowed (e.g., org is not afnemer or aanbieder for @self update or delete)',
                404 => 'Not Found - Gebruik object not found',
                500 => 'Internal Server Error - Server-side error occurred',
            ],
        ];

        return new JSONResponse($documentation, 200);
    }//end getApiDocumentation()

    /**
     * Parse query parameters into options array
     *
     * This method extracts and validates query parameters for filtering,
     * pagination, and other options. Always forces database source for real-time data.
     *
     * @return array Parsed options array with database source
     */
    private function parseQueryOptions(): array
    {
        $options = [];

        // Parse pagination parameters (with and without underscore for compatibility)
        $limit = $this->request->getParam('_limit') ?? $this->request->getParam('limit');
        if ($limit !== null && is_numeric($limit)) {
            $options['_limit'] = (int) $limit;
            $options['limit']  = (int) $limit;
            // Keep both for compatibility
        }

        $offset = $this->request->getParam('_offset') ?? $this->request->getParam('offset');
        if ($offset !== null && is_numeric($offset)) {
            $options['_offset'] = (int) $offset;
            $options['offset']  = (int) $offset;
            // Keep both for compatibility
        }

        $page = $this->request->getParam('_page') ?? $this->request->getParam('page');
        if ($page !== null && is_numeric($page)) {
            $options['_page'] = (int) $page;
        }

        // Parse filter parameters
        $status = $this->request->getParam('status');
        if ($status !== null && !empty(trim($status))) {
            $options['status'] = trim($status);
        }

        $startDate = $this->request->getParam('startDate');
        if ($startDate !== null && !empty(trim($startDate))) {
            $options['startDate'] = trim($startDate);
        }

        $endDate = $this->request->getParam('endDate');
        if ($endDate !== null && !empty(trim($endDate))) {
            $options['endDate'] = trim($endDate);
        }

        // Force database source for all custom endpoints to ensure real-time data
        $options['_source'] = 'database';

        $this->logger->debug(
                'Parsed query options for AangebodenGebruik',
                [
                    'raw_params'     => [
                        'limit'     => $limit,
                        'offset'    => $offset,
                        'status'    => $status,
                        'startDate' => $startDate,
                        'endDate'   => $endDate,
                    ],
                    'parsed_options' => $options,
                ]
                );

        return $options;
    }//end parseQueryOptions()
}//end class
