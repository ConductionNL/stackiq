<?php

/**
 * Stackiq ClaimedUsageController
 *
 * The usages other organisations claim of the active organisation's
 * products, and the supplier's answer to each.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\ClaimedUsageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/aanbod/claimed, PUT /api/aanbod/claimed/{uuid}/accept and /decline.
 *
 * Any signed-in user may call them; the service answers only for the active
 * organisation, and refuses an answer from anyone but the usage's provider.
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
class ClaimedUsageController extends Controller {

	/**
	 * HTTP status per service status.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		'ok'              => Http::STATUS_OK,
		'invalid'         => Http::STATUS_BAD_REQUEST,
		'no_organisation' => Http::STATUS_FORBIDDEN,
		'forbidden'       => Http::STATUS_FORBIDDEN,
		'not_found'       => Http::STATUS_NOT_FOUND,
		'unavailable'     => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param ClaimedUsageService $claims      The claimed usages.
	 * @param IUserSession        $userSession The session.
	 */
	public function __construct(
		IRequest $request,
		private readonly ClaimedUsageService $claims,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The unanswered usages claimed of the active organisation's products.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$listed = $this->claims->claimed();
		return new JSONResponse($listed, $this->httpStatus(status: $listed['status']));
	}//end index()

	/**
	 * Accept a usage claimed of the active organisation's product.
	 *
	 * @param string $uuid The usage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	#[NoAdminRequired]
	public function accept(string $uuid): JSONResponse {
		return $this->answer(uuid: $uuid, answer: 'accepted');
	}//end accept()

	/**
	 * Decline a usage claimed of the active organisation's product; the usage stays.
	 *
	 * @param string $uuid The usage.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	#[NoAdminRequired]
	public function decline(string $uuid): JSONResponse {
		return $this->answer(uuid: $uuid, answer: 'declined');
	}//end decline()

	/**
	 * Record an answer and map the outcome to a response.
	 *
	 * @param string $uuid   The usage.
	 * @param string $answer The answer.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $uuid, string $answer): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->claims->answer(uuid: $uuid, answer: $answer);
		return new JSONResponse($result, $this->httpStatus(status: $result['status']));
	}//end answer()

	/**
	 * The HTTP status for a service status.
	 *
	 * @param string $status The service status.
	 *
	 * @return int
	 */
	private function httpStatus(string $status): int {
		return (self::STATUS[$status] ?? Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end httpStatus()
}//end class
