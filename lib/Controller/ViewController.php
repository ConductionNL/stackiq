<?php

/**
 * View Controller for SoftwareCatalog
 *
 * Handles HTTP requests for view-related operations including querying views
 * with enrichment options for products and usage data.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/nextcloud/softwarecatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
 */

namespace OCA\SoftwareCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\SoftwareCatalog\Service\ViewService;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling view-related API operations.
 *
 * This controller provides REST API endpoints for querying and managing ArchiMate views
 * with optional enrichment capabilities for products, usage data (gebruik), and related information.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/nextcloud/softwarecatalog
 */
class ViewController extends Controller
{
    /**
     * Constructor for ViewController
     *
     * @param string          $appName     The app name
     * @param IRequest        $request     The request object
     * @param ViewService     $viewService The view service for business logic
     * @param LoggerInterface $logger      The logger service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ViewService $viewService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get all views with optional enrichment
     *
     * API Endpoint: GET /api/views
     *
     * Query Parameters:
     * - include_products (bool): Include product data in view nodes
     * - include_modules (bool): Include module data in view nodes (linked via elementRef)
     * - include_gebruik (bool): Include usage data in view nodes
     * - include_deelnames_gebruik (bool): Include participation usage data in view nodes
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with views array
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
     */
    public function getAllViews(): JSONResponse
    {
        $this->logger->info(
                'API: Getting all views',
                [
                    'endpoint'     => '/api/views',
                    'method'       => 'GET',
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Parse query parameters for enrichment options.
            $options = $this->parseEnrichmentOptions();

            // Get views from service with enrichments.
            $result = $this->viewService->getAllViews($options);

            // Return appropriate HTTP status code.
            $statusCode = 200;
            if ($result['success'] !== true) {
                $statusCode = 500;
            }

            $this->logger->info(
                    'API: All views request completed',
                    [
                        'success'             => $result['success'],
                        'views_count'         => $result['count'] ?? 0,
                        'enrichments_applied' => $result['enrichments_applied'] ?? [],
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get all views',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                        'views'   => [],
                        'count'   => 0,
                    ],
                    500
                    );
        }//end try
    }//end getAllViews()

    /**
     * Get a specific view by ID with optional enrichment
     *
     * API Endpoint: GET /api/views/{viewId}
     *
     * Query Parameters:
     * - include_products (bool): Include product data in view nodes
     * - include_modules (bool): Include module data in view nodes (linked via elementRef)
     * - include_gebruik (bool): Include usage data in view nodes
     * - include_deelnames_gebruik (bool): Include participation usage data in view nodes
     *
     * @param string $viewId The view identifier
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with view object
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
     */
    public function getView(string $viewId): JSONResponse
    {
        $this->logger->info(
                'API: Getting specific view',
                [
                    'endpoint'     => "/api/views/{$viewId}",
                    'method'       => 'GET',
                    'view_id'      => $viewId,
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Validate view ID.
            if (empty($viewId) === true) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'error'   => 'View ID is required',
                            'view'    => null,
                        ],
                        400
                        );
            }

            // Parse query parameters for enrichment options.
            $options = $this->parseEnrichmentOptions();

            // Get view from service with enrichments.
            $result = $this->viewService->getView(
                viewId: $viewId,
                options: $options
            );

            // Return appropriate HTTP status code.
            $statusCode = 500;
            if ($result['success'] === true) {
                $statusCode = 200;
            } else if ($result['view'] === null) {
                $statusCode = 404;
            }

            $this->logger->info(
                    'API: Specific view request completed',
                    [
                        'view_id'             => $viewId,
                        'success'             => $result['success'],
                        'found'               => $result['view'] !== null,
                        'enrichments_applied' => $result['enrichments_applied'] ?? [],
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get specific view',
                    [
                        'view_id' => $viewId,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                        'view'    => null,
                    ],
                    500
                    );
        }//end try
    }//end getView()

    /**
     * Parse enrichment options from query parameters
     *
     * @return array Parsed enrichment options
     */
    private function parseEnrichmentOptions(): array
    {
        $options = [];

        // Parse boolean query parameters.
        $includeProducts = $this->request->getParam('include_products');
        if ($includeProducts !== null) {
            $options['include_products'] = $this->parseBooleanParam(value: $includeProducts);
        }

        $includeModules = $this->request->getParam('include_modules');
        if ($includeModules !== null) {
            $options['include_modules'] = $this->parseBooleanParam(value: $includeModules);
        }

        $includeGebruik = $this->request->getParam('include_gebruik');
        if ($includeGebruik !== null) {
            $options['include_gebruik'] = $this->parseBooleanParam(value: $includeGebruik);
        }

        $inclDeelGebruik = $this->request->getParam('include_deelnames_gebruik');
        if ($inclDeelGebruik !== null) {
            $options['include_deelnames_gebruik'] = $this->parseBooleanParam(value: $inclDeelGebruik);
        }

        $this->logger->debug(
                'Parsed enrichment options',
                [
                    'raw_params'     => [
                        'include_products'          => $includeProducts,
                        'include_modules'           => $includeModules,
                        'include_gebruik'           => $includeGebruik,
                        'include_deelnames_gebruik' => $inclDeelGebruik,
                    ],
                    'parsed_options' => $options,
                ]
                );

        return $options;
    }//end parseEnrichmentOptions()

    /**
     * Parse a boolean parameter from string values.
     *
     * Accepts: true, false, 1, 0, "true", "false", "1", "0", "yes", "no"
     *
     * @param mixed $value The parameter value to parse
     *
     * @return bool The parsed boolean value
     */
    private function parseBooleanParam($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_numeric($value) === true) {
            return (int) $value > 0;
        }

        if (is_string($value) === true) {
            $lowerValue = strtolower(trim($value));
            return in_array($lowerValue, ['true', '1', 'yes', 'on'], true) === true;
        }

        return false;
    }//end parseBooleanParam()

    /**
     * Get API documentation for view endpoints
     *
     * API Endpoint: GET /api/views/docs
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with API documentation
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @spec                                          openspec/changes/retrofit-2026-05-26-dashboard-views-api/tasks.md#task-2
     */
    public function getApiDocumentation(): JSONResponse
    {
        $documentation = [
            'api_version'               => '1.0.0',
            'description'               => 'SoftwareCatalog View API - Query and enrich ArchiMate views',
            'base_url'                  => '/api/views',
            'endpoints'                 => [
                [
                    'method'           => 'GET',
                    'path'             => '/api/views',
                    'description'      => 'Get all views with optional enrichment',
                    'parameters'       => [
                        [
                            'name'        => 'include_products',
                            'type'        => 'boolean',
                            'required'    => false,
                            'description' => 'Include product data in view nodes',
                        ],
                        [
                            'name'        => 'include_gebruik',
                            'type'        => 'boolean',
                            'required'    => false,
                            'description' => 'Include usage data in view nodes',
                        ],
                        [
                            'name'        => 'include_deelnames_gebruik',
                            'type'        => 'boolean',
                            'required'    => false,
                            'description' => 'Include participation usage data in view nodes',
                        ],
                    ],
                    'response_example' => [
                        'success'             => true,
                        'views'               => [
                            [
                                'id'                => 'view-lv01',
                                'name'              => 'LV01 BGT basisregistratie en SVB view',
                                'documentation'     => 'Mocked from GEMMA LV01.',
                                'viewNodes'         => [],
                                'viewRelationships' => [],
                            ],
                        ],
                        'count'               => 1,
                        'enrichments_applied' => [],
                    ],
                ],
                [
                    'method'           => 'GET',
                    'path'             => '/api/views/{viewId}',
                    'description'      => 'Get a specific view by ID with optional enrichment',
                    'parameters'       => [
                        [
                            'name'        => 'viewId',
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'The view identifier (in URL path)',
                        ],
                        [
                            'name'        => 'include_products',
                            'type'        => 'boolean',
                            'required'    => false,
                            'description' => 'Include product data in view nodes',
                        ],
                        [
                            'name'        => 'include_gebruik',
                            'type'        => 'boolean',
                            'required'    => false,
                            'description' => 'Include usage data in view nodes',
                        ],
                        [
                            'name'        => 'include_deelnames_gebruik',
                            'type'        => 'boolean',
                            'required'    => false,
                            'description' => 'Include participation usage data in view nodes',
                        ],
                    ],
                    'response_example' => [
                        'success'             => true,
                        'view'                => [
                            'id'                => 'view-lv01',
                            'name'              => 'LV01 BGT basisregistratie en SVB view',
                            'documentation'     => 'Mocked from GEMMA LV01.',
                            'viewNodes'         => [],
                            'viewRelationships' => [],
                        ],
                        'enrichments_applied' => [],
                    ],
                ],
                [
                    'method'           => 'GET',
                    'path'             => '/api/views/docs',
                    'description'      => 'Get this API documentation',
                    'parameters'       => [],
                    'response_example' => '(this response)',
                ],
            ],
            'enrichment_options'        => [
                [
                    'name'        => 'products',
                    'description' => 'Adds product information to nodes with product associations',
                ],
                [
                    'name'        => 'gebruik',
                    'description' => 'Adds usage/usage statistics to view nodes',
                ],
                [
                    'name'        => 'deelnames_gebruik',
                    'description' => 'Adds participation-based usage data to view nodes created from participation records',
                ],
            ],
            'boolean_parameter_formats' => [
                'accepted_true_values'  => ['true', '1', 'yes', 'on', true, 1],
                'accepted_false_values' => ['false', '0', 'no', 'off', false, 0],
                'examples'              => [
                    '/api/views?include_products=true',
                    '/api/views?include_gebruik=1&include_products=false',
                    '/api/views/view-123?include_deelnames_gebruik=yes',
                ],
            ],
        ];

        return new JSONResponse($documentation, 200);
    }//end getApiDocumentation()
}//end class
