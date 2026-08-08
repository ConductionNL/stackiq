<?php

/**
 * Aanbod Controller for SoftwareCatalog.
 *
 * Handles HTTP requests for aanbod (offers) operations including retrieving
 * aanbod objects (gebruik, dienst, module, koppeling) and accepting or denying them.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\SoftwareCatalog\Service\AanbodService;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling aanbod (offers) API operations.
 *
 * This controller provides REST API endpoints for managing aanbod objects where
 * the active organization is involved either as afnemer (consumer) or aanbieder
 * (provider), and for accepting or denying these offers.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */
class AanbodController extends Controller
{
    /**
     * Constructor for AanbodController.
     *
     * @param string          $appName       The name of the app
     * @param IRequest        $request       The HTTP request object
     * @param IUserSession    $userSession   The user session service for getting the current user
     * @param AanbodService   $aanbodService The business logic service
     * @param LoggerInterface $logger        The logger service for debugging and error reporting
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly AanbodService $aanbodService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get all aanbod objects (modules, diensten, koppelingen, gebruiks).
     *
     * API Endpoint: GET /api/aanbod
     *
     * Returns modules, diensten, and koppelingen where the current organisation
     * is in the aanbieder property, or gebruiks where the current organisation
     * is in the afnemer property. Excludes objects where @self.organisation
     * equals the current organisation.
     *
     * Query Parameters:
     * - limit (int): Maximum number of results to return
     * - offset (int): Number of results to skip for pagination
     * - page (int): Page number for pagination
     *
     * @return JSONResponse JSON response with aanbod objects array
     *
     * REQ-009 requires an authenticated caller, and the body enforces it.
     * The PublicPage annotation told Nextcloud's middleware to admit
     * anonymous requests anyway, so declared and enforced contract disagreed:
     * every anonymous call was admitted, routed, and only then rejected by
     * the controller. @NoAdminRequired is the annotation that matches —
     * any logged-in user, rejected at the middleware. The in-body guard
     * stays as deny-before-grant (REQ-001).
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @spec openspec/specs/aanbod-listings/spec.md
     * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-must-require-authentication-explicitly-not-implicitly-req-009
     */
    public function getAanbod(): JSONResponse
    {
        // REQ-009: explicit authentication guard, mirroring
        // AangebodenGebruikController::getGebruiksWhereAfnemer() (REQ-004). Do
        // NOT rely on AanbodService::getAanbod()'s internal
        // getCurrentOrganisation() resolving to null for an anonymous session
        // as the sole safeguard — reject before the service is ever invoked
        // (deny-before-grant, REQ-001).
        if ($this->userSession->getUser() === null) {
            $this->logger->info(
                    'API: Rejecting unauthenticated aanbod request',
                    [
                        'endpoint' => '/api/aanbod',
                        'method'   => 'GET',
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
                        'message' => 'Not authenticated',
                    ],
                    Http::STATUS_UNAUTHORIZED
                    );
        }//end if

        $this->logger->info(
                'API: Getting aanbod objects',
                [
                    'endpoint'     => '/api/aanbod',
                    'method'       => 'GET',
                    'query_params' => $this->request->getParams(),
                ]
                );

        try {
            // Parse query parameters for filtering options.
            $options = $this->parseQueryOptions();

            // Get aanbod objects from service.
            $result = $this->aanbodService->getAanbod($options);

            // Determine HTTP status code based on whether there's an error.
            $statusCode = 200;
            if (isset($result['error']) === true) {
                $statusCode = 500;
            }

            $this->logger->info(
                    'API: Aanbod request completed',
                    [
                        'total'         => $result['total'] ?? 0,
                        'results_count' => count($result['results'] ?? []),
                        'has_error'     => isset($result['error']) === true,
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to get aanbod objects',
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
    }//end getAanbod()

    /**
     * Accept an aanbod object (set @self.organisation to current organisation).
     *
     * API Endpoint: PUT /api/aanbod/{uuid}/accept
     *
     * Sets the '@self.organisation' property of an aanbod object to the active
     * organization. This operation is only allowed if the active organization
     * is the afnemer (for gebruiks) or aanbieder (for modules, diensten, koppelingen).
     *
     * @param string $uuid The UUID of the aanbod object to accept
     *
     * @return JSONResponse JSON response with success status and updated object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/aanbod-listings/spec.md
     */
    public function acceptAanbod(string $uuid): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->logger->info(
                'API: Accepting aanbod object',
                [
                    'endpoint'  => "/api/aanbod/{$uuid}/accept",
                    'method'    => 'PUT',
                    'aanbod_id' => $uuid,
                ]
                );

        try {
            // Validate input.
            if (empty($uuid) === true) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'error'   => 'Aanbod UUID is required',
                            'aanbod'  => null,
                        ],
                        400
                        );
            }

            // Parse any additional options from request body.
            $options     = [];
            $requestBody = $this->request->getParams();
            if (empty($requestBody) === false) {
                $options = array_filter(
                        $requestBody,
                        function ($key) {
                            // Exclude path parameters.
                            return in_array(needle: $key, haystack: ['uuid']) === false;
                        },
                        ARRAY_FILTER_USE_KEY
                        );
            }

            // Accept aanbod object via service.
            $result = $this->aanbodService->acceptAanbod(aanbodId: $uuid, options: $options);

            // Determine appropriate HTTP status code.
            $statusCode = 500;
            if ($result['success'] === true) {
                $statusCode = 200;
            } else if ($result['error'] === 'Aanbod object not found') {
                $statusCode = 404;
            } else if (strpos(haystack: ($result['error'] ?? ''), needle: 'Operation not allowed') !== false) {
                $statusCode = 403;
            }

            $this->logger->info(
                    'API: Accept aanbod request completed',
                    [
                        'aanbod_id'   => $uuid,
                        'success'     => $result['success'],
                        'status_code' => $statusCode,
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to accept aanbod object',
                    [
                        'aanbod_id' => $uuid,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => 'Internal server error: '.$e->getMessage(),
                        'aanbod'  => null,
                    ],
                    500
                    );
        }//end try
    }//end acceptAanbod()

    /**
     * Deny an aanbod object (delete it).
     *
     * API Endpoint: DELETE /api/aanbod/{uuid}/deny
     *
     * Deletes an aanbod object. This operation is only allowed if the active
     * organization is the afnemer (for gebruiks) or aanbieder (for modules,
     * diensten, koppelingen).
     *
     * @param string $uuid The UUID of the aanbod object to deny
     *
     * @return JSONResponse JSON response with success status and deletion details
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/aanbod-listings/spec.md
     */
    public function denyAanbod(string $uuid): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->logger->info(
                'API: Denying aanbod object',
                [
                    'endpoint'  => "/api/aanbod/{$uuid}/deny",
                    'method'    => 'DELETE',
                    'aanbod_id' => $uuid,
                ]
                );

        try {
            // Validate input.
            if (empty($uuid) === true) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'error'   => 'Aanbod UUID is required',
                            'deleted' => false,
                        ],
                        400
                        );
            }

            // Parse any additional options from request body.
            $options     = [];
            $requestBody = $this->request->getParams();
            if (empty($requestBody) === false) {
                $options = array_filter(
                        $requestBody,
                        function ($key) {
                            // Exclude path parameters.
                            return in_array(needle: $key, haystack: ['uuid']) === false;
                        },
                        ARRAY_FILTER_USE_KEY
                        );
            }

            // Deny aanbod object via service.
            $result = $this->aanbodService->denyAanbod(aanbodId: $uuid, options: $options);

            // Determine appropriate HTTP status code.
            $statusCode = 500;
            if ($result['success'] === true) {
                $statusCode = 200;
            } else if ($result['error'] === 'Aanbod object not found') {
                $statusCode = 404;
            } else if (strpos(haystack: ($result['error'] ?? ''), needle: 'Operation not allowed') !== false) {
                $statusCode = 403;
            }

            $this->logger->info(
                    'API: Deny aanbod request completed',
                    [
                        'aanbod_id'   => $uuid,
                        'success'     => $result['success'],
                        'deleted'     => $result['deleted'] ?? false,
                        'status_code' => $statusCode,
                    ]
                    );

            return new JSONResponse($result, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error(
                    'API: Failed to deny aanbod object',
                    [
                        'aanbod_id' => $uuid,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
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
    }//end denyAanbod()

    /**
     * Parse query parameters into options array.
     *
     * @return array Parsed options array
     *
     * @spec openspec/specs/aanbod-listings/spec.md
     */
    private function parseQueryOptions(): array
    {
        $options = [];

        // Parse pagination parameters.
        $limit = $this->request->getParam('_limit') ?? $this->request->getParam('limit');
        if ($limit !== null && is_numeric($limit) === true) {
            $options['_limit'] = (int) $limit;
            // Keep both for compatibility.
            $options['limit'] = (int) $limit;
        }

        $offset = $this->request->getParam('_offset') ?? $this->request->getParam('offset');
        if ($offset !== null && is_numeric($offset) === true) {
            $options['_offset'] = (int) $offset;
            // Keep both for compatibility.
            $options['offset'] = (int) $offset;
        }

        $page = $this->request->getParam('_page') ?? $this->request->getParam('page');
        if ($page !== null && is_numeric($page) === true) {
            $options['_page'] = (int) $page;
        }

        // Force database source for real-time data.
        $options['_source'] = 'database';

        $this->logger->debug(
                'Parsed query options for Aanbod',
                [
                    'raw_params'     => [
                        'limit'  => $limit,
                        'offset' => $offset,
                        'page'   => $page,
                    ],
                    'parsed_options' => $options,
                ]
                );

        return $options;
    }//end parseQueryOptions()
}//end class
