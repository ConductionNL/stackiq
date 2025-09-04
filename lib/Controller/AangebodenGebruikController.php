<?php

declare(strict_types=1);

/**
 * AangebodenGebruik Controller for SoftwareCatalog
 * 
 * Handles HTTP requests for offered usage (aangeboden gebruik) operations including
 * retrieving gebruiks objects where the active organization is involved as afnemer
 * or in deelnemers, and updating the @self property of gebruiks objects.
 * 
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\SoftwareCatalog\Service\AangebodenGebruikService;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling offered usage (aangeboden gebruik) API operations
 * 
 * This controller provides REST API endpoints for managing gebruiks objects where
 * the active organization is involved either as afnemer (consumer) or in deelnemers
 * (participants), and for updating the @self property of gebruiks objects.
 * 
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class AangebodenGebruikController extends Controller
{
    /**
     * Constructor for AangebodenGebruikController
     * 
     * @param string $appName The name of the app
     * @param IRequest $request The HTTP request object
     * @param AangebodenGebruikService $aangebodenGebruikService The business logic service
     * @param LoggerInterface $logger The logger service for debugging and error reporting
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AangebodenGebruikService $aangebodenGebruikService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

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
     * 
     * @return JSONResponse JSON response with gebruiks array where org is afnemer
     */
    public function getGebruiksWhereAfnemer(): JSONResponse
    {
        $this->logger->info('API: Getting gebruiks where active org is afnemer', [
            'endpoint' => '/api/aangeboden-gebruik/afnemer',
            'method' => 'GET',
            'query_params' => $this->request->getParams()
        ]);

        try {
            // Parse query parameters for filtering options
            $options = $this->parseQueryOptions();
            
            // Get gebruiks from service where org is afnemer
            $result = $this->aangebodenGebruikService->getGebruiksWhereAfnemer($options);
            
            // Determine appropriate HTTP status code
            $statusCode = $result['success'] ? 200 : 500;
            
            $this->logger->info('API: Afnemer gebruiks request completed', [
                'success' => $result['success'],
                'gebruiks_count' => $result['count'] ?? 0,
                'organisation' => $result['organisation'] ?? 'unknown'
            ]);
            
            return new JSONResponse($result, $statusCode);
            
        } catch (\Exception $e) {
            $this->logger->error('API: Failed to get afnemer gebruiks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage(),
                'gebruiks' => [],
                'count' => 0
            ], 500);
        }
    }

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
     * 
     * @return JSONResponse JSON response with gebruiks array where org is in deelnemers
     */
    public function getGebruiksWhereDeelnemers(): JSONResponse
    {
        $this->logger->info('API: Getting gebruiks where active org is in deelnemers', [
            'endpoint' => '/api/aangeboden-gebruik/deelnemers',
            'method' => 'GET',
            'query_params' => $this->request->getParams()
        ]);

        try {
            // Parse query parameters for filtering options
            $options = $this->parseQueryOptions();
            
            // Get gebruiks from service where org is in deelnemers
            $result = $this->aangebodenGebruikService->getGebruiksWhereDeelnemers($options);
            
            // Determine appropriate HTTP status code
            $statusCode = $result['success'] ? 200 : 500;
            
            $this->logger->info('API: Deelnemers gebruiks request completed', [
                'success' => $result['success'],
                'gebruiks_count' => $result['count'] ?? 0,
                'organisation' => $result['organisation'] ?? 'unknown'
            ]);
            
            return new JSONResponse($result, $statusCode);
            
        } catch (\Exception $e) {
            $this->logger->error('API: Failed to get deelnemers gebruiks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage(),
                'gebruiks' => [],
                'count' => 0
            ], 500);
        }
    }

    /**
     * Set the @self property of a gebruik to the active organization
     * 
     * API Endpoint: PUT /api/aangeboden-gebruik/{gebruikId}/set-self
     * 
     * This endpoint allows setting the @self.organisation property of a specific gebruik
     * object to the active organization, but only if the active organization is the
     * afnemer (consumer) for that gebruik.
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * 
     * @param string $gebruikId The UUID of the gebruik object to update
     * @return JSONResponse JSON response with success status and updated object
     */
    public function setGebruikSelfToActiveOrg(string $gebruikId): JSONResponse
    {
        $this->logger->info('API: Setting gebruik @self property to active org', [
            'endpoint' => "/api/aangeboden-gebruik/{$gebruikId}/set-self",
            'method' => 'PUT',
            'gebruik_id' => $gebruikId
        ]);

        try {
            // Validate input
            if (empty($gebruikId)) {
                return new JSONResponse([
                    'success' => false,
                    'error' => 'Gebruik ID is required',
                    'gebruik' => null
                ], 400);
            }

            // Parse any additional options from request body
            $options = [];
            $requestBody = $this->request->getParams();
            if (!empty($requestBody)) {
                $options = array_filter($requestBody, function($key) {
                    return !in_array($key, ['gebruikId']); // Exclude path parameters
                }, ARRAY_FILTER_USE_KEY);
            }
            
            // Update gebruik @self property via service
            $result = $this->aangebodenGebruikService->setGebruikSelfToActiveOrg($gebruikId, $options);
            
            // Determine appropriate HTTP status code
            $statusCode = $result['success'] ? 200 : ($result['error'] === 'Gebruik object not found' ? 404 : 
                         ($result['error'] === 'Operation not allowed: active organization is not the afnemer' ? 403 : 500));
            
            $this->logger->info('API: Set gebruik @self property request completed', [
                'gebruik_id' => $gebruikId,
                'success' => $result['success'],
                'status_code' => $statusCode
            ]);
            
            return new JSONResponse($result, $statusCode);
            
        } catch (\Exception $e) {
            $this->logger->error('API: Failed to set gebruik @self property', [
                'gebruik_id' => $gebruikId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage(),
                'gebruik' => null
            ], 500);
        }
    }

    /**
     * Get API documentation for AangebodenGebruik endpoints
     * 
     * API Endpoint: GET /api/aangeboden-gebruik/docs
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * 
     * @return JSONResponse JSON response with API documentation
     */
    public function getApiDocumentation(): JSONResponse
    {
        $documentation = [
            'api_version' => '1.0.0',
            'description' => 'SoftwareCatalog AangebodenGebruik API - Manage gebruiks objects where active organization is involved',
            'base_url' => '/api/aangeboden-gebruik',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/api/aangeboden-gebruik/afnemer',
                    'description' => 'Get all gebruiks objects where the active organization is the afnemer (consumer)',
                    'parameters' => [
                        [
                            'name' => 'limit',
                            'type' => 'integer',
                            'required' => false,
                            'description' => 'Maximum number of results to return'
                        ],
                        [
                            'name' => 'offset',
                            'type' => 'integer',
                            'required' => false,
                            'description' => 'Number of results to skip for pagination'
                        ],
                        [
                            'name' => 'status',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by usage status'
                        ],
                        [
                            'name' => 'product',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by product ID'
                        ],
                        [
                            'name' => 'startDate',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by start date (ISO 8601 format)'
                        ],
                        [
                            'name' => 'endDate',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by end date (ISO 8601 format)'
                        ]
                    ],
                    'response_example' => [
                        'success' => true,
                        'gebruiks' => [
                            [
                                'id' => 'usage-uuid-123',
                                'afnemer' => 'org-uuid',
                                'product' => 'product-uuid',
                                'status' => 'actief',
                                '_filter_type' => 'afnemer',
                                '_schema_id' => 'schema-id'
                            ]
                        ],
                        'count' => 1,
                        'filter_type' => 'afnemer',
                        'organisation' => 'org-uuid'
                    ]
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/aangeboden-gebruik/deelnemers',
                    'description' => 'Get all gebruiks objects where the active organization is in deelnemers (participants)',
                    'parameters' => [
                        [
                            'name' => 'limit',
                            'type' => 'integer',
                            'required' => false,
                            'description' => 'Maximum number of results to return'
                        ],
                        [
                            'name' => 'offset',
                            'type' => 'integer',
                            'required' => false,
                            'description' => 'Number of results to skip for pagination'
                        ],
                        [
                            'name' => 'status',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by usage status'
                        ],
                        [
                            'name' => 'product',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by product ID'
                        ],
                        [
                            'name' => 'startDate',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by start date (ISO 8601 format)'
                        ],
                        [
                            'name' => 'endDate',
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Filter by end date (ISO 8601 format)'
                        ]
                    ],
                    'response_example' => [
                        'success' => true,
                        'gebruiks' => [
                            [
                                'id' => 'usage-uuid-456',
                                'afnemer' => 'other-org-uuid',
                                'deelnemers' => ['org-uuid', 'another-org-uuid'],
                                'product' => 'product-uuid',
                                'status' => 'actief',
                                '_filter_type' => 'deelnemers',
                                '_schema_id' => 'schema-id'
                            ]
                        ],
                        'count' => 1,
                        'filter_type' => 'deelnemers',
                        'organisation' => 'org-uuid'
                    ]
                ],
                [
                    'method' => 'PUT',
                    'path' => '/api/aangeboden-gebruik/{gebruikId}/set-self',
                    'description' => 'Set the @self property of a gebruik to the active organization (only allowed if active org is afnemer)',
                    'parameters' => [
                        [
                            'name' => 'gebruikId',
                            'type' => 'string',
                            'required' => true,
                            'description' => 'The UUID of the gebruik object to update (in URL path)'
                        ]
                    ],
                    'response_example' => [
                        'success' => true,
                        'message' => 'Gebruik @self property updated successfully',
                        'gebruik' => [
                            'id' => 'usage-uuid-123',
                            'afnemer' => 'org-uuid',
                            '@self' => [
                                'organisation' => 'org-uuid',
                                'register' => 'register-id',
                                'schema' => 'schema-id'
                            ]
                        ],
                        'updated_fields' => ['@self.organisation']
                    ]
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/aangeboden-gebruik/docs',
                    'description' => 'Get this API documentation',
                    'parameters' => [],
                    'response_example' => '(this response)'
                ]
            ],
            'security' => [
                'afnemer_filtering' => 'Uses standard RBAC filtering based on organization association',
                'deelnemers_filtering' => 'Uses RBAC-disabled search to find participation records',
                'self_update_permission' => 'Only allowed if active organization is the afnemer for the specific gebruik'
            ],
            'error_codes' => [
                400 => 'Bad Request - Invalid parameters or missing required fields',
                403 => 'Forbidden - Operation not allowed (e.g., org is not afnemer for @self update)',
                404 => 'Not Found - Gebruik object not found',
                500 => 'Internal Server Error - Server-side error occurred'
            ]
        ];

        return new JSONResponse($documentation, 200);
    }

    /**
     * Parse query parameters into options array
     * 
     * This method extracts and validates query parameters for filtering,
     * pagination, and other options.
     * 
     * @return array Parsed options array
     */
    private function parseQueryOptions(): array
    {
        $options = [];
        
        // Parse pagination parameters
        $limit = $this->request->getParam('limit');
        if ($limit !== null && is_numeric($limit)) {
            $options['limit'] = (int)$limit;
        }
        
        $offset = $this->request->getParam('offset');
        if ($offset !== null && is_numeric($offset)) {
            $options['offset'] = (int)$offset;
        }
        
        // Parse filter parameters
        $status = $this->request->getParam('status');
        if ($status !== null && !empty(trim($status))) {
            $options['status'] = trim($status);
        }
        
        $product = $this->request->getParam('product');
        if ($product !== null && !empty(trim($product))) {
            $options['product'] = trim($product);
        }
        
        $startDate = $this->request->getParam('startDate');
        if ($startDate !== null && !empty(trim($startDate))) {
            $options['startDate'] = trim($startDate);
        }
        
        $endDate = $this->request->getParam('endDate');
        if ($endDate !== null && !empty(trim($endDate))) {
            $options['endDate'] = trim($endDate);
        }
        
        $this->logger->debug('Parsed query options for AangebodenGebruik', [
            'raw_params' => [
                'limit' => $limit,
                'offset' => $offset,
                'status' => $status,
                'product' => $product,
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'parsed_options' => $options
        ]);
        
        return $options;
    }
}
