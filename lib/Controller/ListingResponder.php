<?php

/**
 * Listing responder for Stackiq controllers
 *
 * Runs a listing read and turns the service result into the HTTP response:
 * 200, or 500 when the service reports or throws an error, with the empty
 * paginated body on failure.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use Closure;
use Exception;
use OCA\Stackiq\Service\EmptyListing;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Shared request flow of the listing endpoints. Callers check authentication
 * and group membership themselves.
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
final class ListingResponder {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger  The logger.
	 * @param IRequest        $request The current request.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The empty paginated listing body.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function emptyListing(): array {
		return (new EmptyListing())->page();
	}//end emptyListing()

	/**
	 * Log and refuse an anonymous caller with 401.
	 *
	 * @param ObjectListing $listing The listing.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function rejectUnauthenticated(ObjectListing $listing): JSONResponse {
		$this->logger->info(
			$listing->definition['rejected'],
			[
				'endpoint' => $listing->definition['endpoint'],
				'method' => 'GET',
			]
		);

		return new JSONResponse($this->emptyListing() + ['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}//end rejectUnauthenticated()

	/**
	 * Run the read and respond; a fetch may answer with its own response.
	 *
	 * @param ObjectListing $listing The listing.
	 * @param Closure       $fetch   Returns the service result, or a response that ends the request.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function respond(ObjectListing $listing, Closure $fetch): JSONResponse {
		$this->logger->info($listing->definition['started'], $this->startedContext(listing: $listing));

		try {
			$result = $fetch();
			if ($result instanceof JSONResponse) {
				return $result;
			}

			return $this->resultResponse(listing: $listing, result: $result);
		} catch (Exception $e) {
			$this->logger->error($listing->definition['failed'], $this->failureContext(listing: $listing, error: $e));
			return new JSONResponse($this->emptyListing() + ['error' => 'Internal server error: ' . $e->getMessage()], 500);
		}
	}//end respond()

	/**
	 * Log the completed read and answer 200, or 500 when the result reports an error.
	 *
	 * @param ObjectListing $listing The listing.
	 * @param array         $result  The service result.
	 *
	 * @return JSONResponse
	 */
	private function resultResponse(ObjectListing $listing, array $result): JSONResponse {
		$statusCode = 200;
		if (isset($result['error']) === true) {
			$statusCode = 500;
		}

		$this->logger->info(
			$listing->definition['completed'],
			$listing->idContext + [
				'total' => $result['total'] ?? 0,
				'results_count' => count($result['results'] ?? []),
				'has_error' => isset($result['error']) === true,
			]
		);

		return new JSONResponse($result, $statusCode);
	}//end resultResponse()

	/**
	 * The context of the started log line.
	 *
	 * @param ObjectListing $listing The listing.
	 *
	 * @return array<string, mixed>
	 */
	private function startedContext(ObjectListing $listing): array {
		return [
			'endpoint' => $listing->definition['endpoint'],
			'method' => 'GET',
		] + $listing->idContext + ['query_params' => $this->request->getParams()];
	}//end startedContext()

	/**
	 * The context of the failure log line: the stack trace, or the endpoint.
	 *
	 * @param ObjectListing $listing The listing.
	 * @param Exception     $error   The failure.
	 *
	 * @return array<string, mixed>
	 */
	private function failureContext(ObjectListing $listing, Exception $error): array {
		$context = $listing->idContext + ['error' => $error->getMessage()];
		if ($listing->definition['failureLogsEndpoint'] === true) {
			return $context + ['endpoint' => $listing->definition['endpoint']];
		}

		return $context + ['trace' => $error->getTraceAsString()];
	}//end failureContext()
}//end class
