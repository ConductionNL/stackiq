<?php

/**
 * View Controller for Stackiq
 *
 * Handles HTTP requests for view-related operations including querying views
 * with enrichment options for products and usage data.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\ViewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling view-related API operations.
 *
 * This controller provides REST API endpoints for querying and managing ArchiMate views
 * with optional enrichment capabilities for products, usage data (gebruik), and related information.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */
class ViewController extends Controller {
	/**
	 * The boolean enrichment query parameters, in the order they are read.
	 *
	 * @var array<int, string>
	 */
	private const ENRICHMENT_PARAMS = ['include_products', 'include_modules', 'include_gebruik', 'include_deelnames_gebruik'];

	/**
	 * Constructor for ViewController
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param ViewService $viewService The view service for business logic
	 * @param LoggerInterface $logger The logger service
	 * @param IUserSession $userSession The user session service
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ViewService $viewService,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
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
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getAllViews(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->logListRequest();

		try {
			return $this->listResponse();
		} catch (\Exception $e) {
			return $this->listFailure(exception: $e);
		}
	}//end getAllViews()

	/**
	 * Log a listing request.
	 *
	 * @return void
	 */
	private function logListRequest(): void {
		$this->logger->info(
			'API: Getting all views',
			[
				'endpoint' => '/api/views',
				'method' => 'GET',
				'query_params' => $this->request->getParams(),
			]
		);
	}//end logListRequest()

	/**
	 * The views with the requested enrichments, logged.
	 *
	 * @return JSONResponse 200 with the views, or 500 for a failed envelope.
	 *
	 * @throws \Exception When the service fails.
	 */
	private function listResponse(): JSONResponse {
		// Parse query parameters for enrichment options.
		$options = $this->parseEnrichmentOptions();

		// Get views from service with enrichments.
		$result = $this->viewService->getAllViews($options);

		$statusCode = $this->determineListStatusCode(result: $result);

		$this->logger->info(
			'API: All views request completed',
			[
				'success' => $result['success'],
				'views_count' => $result['count'] ?? 0,
				'enrichments_applied' => $result['enrichments_applied'] ?? [],
			]
		);

		return new JSONResponse($result, $statusCode);
	}//end listResponse()

	/**
	 * The logged 500 of a listing that threw.
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function listFailure(\Exception $exception): JSONResponse {
		$this->logger->error(
			'API: Failed to get all views',
			[
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);

		return new JSONResponse(
			$this->buildListErrorPayload(exception: $exception),
			500
		);
	}//end listFailure()

	/**
	 * Pick the HTTP status for a list-of-views service result.
	 *
	 * Extracted from `getAllViews()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.6.
	 *
	 * @param array $result The service result envelope.
	 *
	 * @return int 200 on success, 500 otherwise.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-6
	 */
	private function determineListStatusCode(array $result): int {
		if ($result['success'] === true) {
			return 200;
		}

		return 500;
	}//end determineListStatusCode()

	/**
	 * Build the standard list-error JSON payload.
	 *
	 * Extracted from `getAllViews()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.6.
	 *
	 * @param \Throwable $exception The caught throwable.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-6
	 */
	private function buildListErrorPayload(\Throwable $exception): array {
		return [
			'success' => false,
			'error' => 'Internal server error: ' . $exception->getMessage(),
			'views' => [],
			'count' => 0,
		];

	}//end buildListErrorPayload()

	/**
	 * Pick the HTTP status for a single-view service result.
	 *
	 * Extracted from `getView()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.6.
	 *
	 * @param array $result The service result envelope — expects 'success'
	 *                      bool and 'view' (null on not-found).
	 *
	 * @return int 200 on success, 404 when the view is missing, 500 otherwise.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-6
	 */
	private function determineViewStatusCode(array $result): int {
		if ($result['success'] === true) {
			return 200;
		}

		if ($result['view'] === null) {
			return 404;
		}

		return 500;
	}//end determineViewStatusCode()

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
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getView(string $viewId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->logViewRequest(viewId: $viewId);

		try {
			return $this->viewResponse(viewId: $viewId);
		} catch (\Exception $e) {
			return $this->viewFailure(viewId: $viewId, exception: $e);
		}
	}//end getView()

	/**
	 * One view with the requested enrichments, logged; 400 without a view id.
	 *
	 * @param string $viewId The view ID.
	 *
	 * @return JSONResponse 200 with the view, 404 when it is missing, 500 for a failed envelope, 400 without an id.
	 *
	 * @throws \Exception When the service fails.
	 */
	private function viewResponse(string $viewId): JSONResponse {
		// Validate view ID.
		if (empty($viewId) === true) {
			return $this->missingViewIdResponse();
		}

		// Parse query parameters for enrichment options.
		$options = $this->parseEnrichmentOptions();

		// Get view from service with enrichments.
		$result = $this->viewService->getView(
			viewId: $viewId,
			options: $options
		);

		$this->logViewCompleted(viewId: $viewId, result: $result);

		return new JSONResponse($result, $this->determineViewStatusCode(result: $result));
	}//end viewResponse()

	/**
	 * The 400 for a view request without a view id.
	 *
	 * @return JSONResponse
	 */
	private function missingViewIdResponse(): JSONResponse {
		return new JSONResponse(
			[
				'success' => false,
				'error' => 'View ID is required',
				'view' => null,
			],
			400
		);
	}//end missingViewIdResponse()

	/**
	 * Log a view request.
	 *
	 * @param string $viewId The view ID.
	 *
	 * @return void
	 */
	private function logViewRequest(string $viewId): void {
		$this->logger->info(
			'API: Getting specific view',
			[
				'endpoint' => "/api/views/{$viewId}",
				'method' => 'GET',
				'view_id' => $viewId,
				'query_params' => $this->request->getParams(),
			]
		);
	}//end logViewRequest()

	/**
	 * Log a completed view request.
	 *
	 * @param string $viewId The view ID.
	 * @param array $result The service envelope.
	 *
	 * @return void
	 */
	private function logViewCompleted(string $viewId, array $result): void {
		$this->logger->info(
			'API: Specific view request completed',
			[
				'view_id' => $viewId,
				'success' => $result['success'],
				'found' => $result['view'] !== null,
				'enrichments_applied' => $result['enrichments_applied'] ?? [],
			]
		);
	}//end logViewCompleted()

	/**
	 * The logged 500 of a view request that threw.
	 *
	 * @param string $viewId The view ID.
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function viewFailure(string $viewId, \Exception $exception): JSONResponse {
		$this->logger->error(
			'API: Failed to get specific view',
			[
				'view_id' => $viewId,
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);

		return new JSONResponse($this->buildViewErrorPayload(exception: $exception), 500);
	}//end viewFailure()

	/**
	 * The body of a view request that threw.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return array<string, mixed>
	 */
	private function buildViewErrorPayload(\Throwable $exception): array {
		return [
			'success' => false,
			'error' => 'Internal server error: ' . $exception->getMessage(),
			'view' => null,
		];
	}//end buildViewErrorPayload()

	/**
	 * Parse enrichment options from query parameters
	 *
	 * @return array Parsed enrichment options
	 */
	private function parseEnrichmentOptions(): array {
		$options = [];
		$raw = [];

		// Parse boolean query parameters.
		foreach (self::ENRICHMENT_PARAMS as $param) {
			$raw[$param] = $this->request->getParam($param);
			if ($raw[$param] !== null) {
				$options[$param] = $this->parseBooleanParam(value: $raw[$param]);
			}
		}

		$this->logEnrichmentOptions(raw: $raw, options: $options);

		return $options;
	}//end parseEnrichmentOptions()

	/**
	 * Log the enrichment parameters as sent and as parsed.
	 *
	 * @param array<string, mixed> $raw The parameters as sent.
	 * @param array<string, bool> $options The parsed options.
	 *
	 * @return void
	 */
	private function logEnrichmentOptions(array $raw, array $options): void {
		$this->logger->debug(
			'Parsed enrichment options',
			[
				'raw_params' => $raw,
				'parsed_options' => $options,
			]
		);
	}//end logEnrichmentOptions()

	/**
	 * Parse a boolean parameter from string values.
	 *
	 * Accepts: true, false, 1, 0, "true", "false", "1", "0", "yes", "no"
	 *
	 * @param mixed $value The parameter value to parse
	 *
	 * @return bool The parsed boolean value
	 */
	private function parseBooleanParam($value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_numeric($value) === true) {
			return (int)$value > 0;
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
	 * @spec openspec/specs/dashboard-views-api/spec.md
	 */
	public function getApiDocumentation(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(ViewApiDocumentation::DOCUMENTATION, 200);
	}//end getApiDocumentation()
}//end class
