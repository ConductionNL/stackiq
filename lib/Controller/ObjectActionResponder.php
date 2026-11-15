<?php

/**
 * Object action responder for Stackiq controllers
 *
 * Runs an accept/deny style action on one object and turns the service
 * envelope into the HTTP response: 400 for a missing id, 200 on success, 404
 * for a missing object, 403 for an authorisation refusal, 500 otherwise.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use Closure;
use Exception;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Shared request flow of the object action endpoints. The caller checks
 * authentication before handing the action over.
 *
 * @spec openspec/specs/aanbod-listings/spec.md
 */
final class ObjectActionResponder {
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
	 * Run the action and respond; a thrown failure becomes a logged 500.
	 *
	 * @param ObjectAction $action  The action.
	 * @param Closure      $perform Calls the service with the forwarded options and returns its envelope.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/aanbod-listings/spec.md
	 */
	public function respond(ObjectAction $action, Closure $perform): JSONResponse {
		$this->logger->info($action->definition['started'], $this->startedContext(action: $action));

		try {
			return $this->perform(action: $action, perform: $perform);
		} catch (Exception $e) {
			$this->logger->error($action->definition['failed'], $this->failureContext(action: $action, error: $e));
			return new JSONResponse(
				['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()] + $action->definition['emptyResult'],
				500
			);
		}
	}//end respond()

	/**
	 * Validate the id, call the service and map its envelope to a response.
	 *
	 * @param ObjectAction $action  The action.
	 * @param Closure      $perform Calls the service with the forwarded options.
	 *
	 * @return JSONResponse
	 */
	private function perform(ObjectAction $action, Closure $perform): JSONResponse {
		if (empty($action->id) === true) {
			return new JSONResponse(
				['success' => false, 'error' => $action->definition['idLabel'] . ' is required'] + $action->definition['emptyResult'],
				400
			);
		}

		$result     = $perform($this->forwardedOptions(pathParam: $action->definition['idParam']));
		$statusCode = $this->statusFor(action: $action, result: $result);
		$this->logger->info(
			$action->definition['completed'],
			$this->completedContext(action: $action, result: $result) + ['status_code' => $statusCode]
		);

		return new JSONResponse($result, $statusCode);
	}//end perform()

	/**
	 * The request parameters without the path parameter, as options for the service.
	 *
	 * @param string $pathParam The route parameter to leave out.
	 *
	 * @return array
	 */
	private function forwardedOptions(string $pathParam): array {
		$options     = [];
		$requestBody = $this->request->getParams();
		if (empty($requestBody) === false) {
			$options = array_filter(
				$requestBody,
				function ($key) use ($pathParam) {
					// Exclude path parameters.
					return in_array(needle: $key, haystack: [$pathParam]) === false;
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		return $options;
	}//end forwardedOptions()

	/**
	 * The HTTP status for a service envelope.
	 *
	 * @param ObjectAction $action The action.
	 * @param array        $result The service envelope.
	 *
	 * @return int
	 */
	private function statusFor(ObjectAction $action, array $result): int {
		if ($result['success'] === true) {
			return 200;
		}

		if ($result['error'] === $action->definition['notFoundError']) {
			return 404;
		}

		if (strpos(haystack: ($result['error'] ?? ''), needle: 'Operation not allowed') !== false) {
			return 403;
		}

		return 500;
	}//end statusFor()

	/**
	 * The context of the started log line.
	 *
	 * @param ObjectAction $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function startedContext(ObjectAction $action): array {
		return [
			'endpoint' => sprintf($action->definition['endpoint'], $action->id),
			'method' => $action->definition['method'],
			$action->definition['logIdKey'] => $action->id,
		];
	}//end startedContext()

	/**
	 * The context of the completed log line, before its status code.
	 *
	 * @param ObjectAction $action The action.
	 * @param array        $result The service envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function completedContext(ObjectAction $action, array $result): array {
		$context = [
			$action->definition['logIdKey'] => $action->id,
			'success' => $result['success'],
		];
		if ($action->definition['reportsDeleted'] === true) {
			$context['deleted'] = $result['deleted'] ?? false;
		}

		return $context;
	}//end completedContext()

	/**
	 * The context of the failure log line.
	 *
	 * @param ObjectAction $action The action.
	 * @param Exception    $error  The failure.
	 *
	 * @return array<string, mixed>
	 */
	private function failureContext(ObjectAction $action, Exception $error): array {
		return [
			$action->definition['logIdKey'] => $action->id,
			'error' => $error->getMessage(),
			'trace' => $error->getTraceAsString(),
		];
	}//end failureContext()
}//end class
