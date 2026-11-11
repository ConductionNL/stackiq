<?php

/**
 * Facet Controller for Stackiq
 *
 * Handles HTTP requests for the GEMMA-dimension facet aggregation endpoint.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\FacetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the GEMMA-dimension facet aggregation endpoint.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */
class FacetController extends Controller {
	/**
	 * Constructor for FacetController.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param FacetService $facetService The facet aggregation service.
	 * @param LoggerInterface $logger The logger service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FacetService $facetService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Get GEMMA-dimension facet counts for a schema.
	 *
	 * API Endpoint: GET /api/facets/{schema}
	 *
	 * Query Parameters:
	 * - search (string): Free-text query narrowing the candidate set.
	 * - referenceComponent[], standard[], applicationService[], domain[] (string[]):
	 *   Currently-selected facet values per dimension.
	 * - organization (string): Optional organisation override.
	 *
	 * Facet aggregation is a read operation available to any authenticated
	 * catalog user — RBAC scoping happens inside FacetService (identical
	 * posture to ViewController), not at this boundary.
	 *
	 * @param string $schema The facet schema (`module` or `dienst`).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with the per-dimension facet buckets.
	 *
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context
	 */
	public function getFacets(string $schema): JSONResponse {
		$filters = $this->parseFilters();

		try {
			return $this->facetsResponse(schema: $schema, filters: $filters);
		} catch (\Exception $e) {
			return $this->failureResponse(schema: $schema, error: $e);
		}
	}//end getFacets()

	/**
	 * The facets for a schema, the selected filters and the search and organisation parameters.
	 *
	 * @param string $schema The requested schema.
	 * @param array $filters The selected filters.
	 *
	 * @return JSONResponse The 200 with the facets.
	 *
	 * @throws \Exception When the aggregation fails.
	 */
	private function facetsResponse(string $schema, array $filters): JSONResponse {
		$result = $this->facetService->getFacets(
			schema: $schema,
			filters: $filters,
			search: $this->stringParam(name: 'search'),
			organization: $this->stringParam(name: 'organization')
		);

		return new JSONResponse($result, 200);
	}//end facetsResponse()

	/**
	 * A request parameter when it is a string, otherwise null.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * The response to a failed aggregation: 400 for an unsupported schema, 503 when
	 * OpenRegister is unavailable, otherwise 500; the last two are logged.
	 *
	 * @param string $schema The requested schema.
	 * @param \Exception $error The failure.
	 *
	 * @return JSONResponse
	 */
	private function failureResponse(string $schema, \Exception $error): JSONResponse {
		if ($error instanceof \InvalidArgumentException) {
			return new JSONResponse(
				[
					'message' => $error->getMessage(),
					'supportedSchemas' => ['module', 'catalogService'],
				],
				400
			);
		}

		if ($error instanceof \RuntimeException) {
			return $this->unavailableResponse(schema: $schema, error: $error);
		}

		return $this->unexpectedFailureResponse(schema: $schema, error: $error);
	}//end failureResponse()

	/**
	 * The logged 503 when OpenRegister's object service is unavailable.
	 *
	 * @param string $schema The requested schema.
	 * @param \RuntimeException $error The failure.
	 *
	 * @return JSONResponse
	 */
	private function unavailableResponse(string $schema, \RuntimeException $error): JSONResponse {
		$this->logger->error(
			message: 'FacetController: ObjectService unavailable',
			context: ['schema' => $schema, 'error' => $error->getMessage()]
		);

		return new JSONResponse(
			['message' => 'Facet aggregation is temporarily unavailable: ' . $error->getMessage()],
			503
		);
	}//end unavailableResponse()

	/**
	 * The logged 500 for any other failure.
	 *
	 * @param string $schema The requested schema.
	 * @param \Exception $error The failure.
	 *
	 * @return JSONResponse
	 */
	private function unexpectedFailureResponse(string $schema, \Exception $error): JSONResponse {
		$this->logger->error(
			message: 'FacetController: failed to compute facets',
			context: [
				'schema' => $schema,
				'error' => $error->getMessage(),
				'trace' => $error->getTraceAsString(),
			]
		);

		return new JSONResponse(
			['message' => 'Internal server error: ' . $error->getMessage()],
			500
		);
	}//end unexpectedFailureResponse()

	/**
	 * Parse the per-dimension facet filter query parameters into
	 * `dimension => string[]`.
	 *
	 * @return array<string,array> Raw filters keyed by dimension.
	 */
	private function parseFilters(): array {
		$dimensions = ['referenceComponent', 'standard', 'applicationService', 'domain'];
		$filters = [];

		foreach ($dimensions as $dimension) {
			$value = $this->request->getParam($dimension);
			if ($value === null) {
				continue;
			}

			$filters[$dimension] = [$value];
			if (is_array($value) === true) {
				$filters[$dimension] = $value;
			}
		}

		return $filters;
	}//end parseFilters()
}//end class
