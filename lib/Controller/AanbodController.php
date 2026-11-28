<?php

/**
 * Aanbod Controller for Stackiq.
 *
 * Handles HTTP requests for aanbod (offers) operations including retrieving
 * aanbod objects (gebruik, dienst, module, koppeling) and accepting or denying them.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Kernel\Contracts\AanbodServiceInterface;
use OCA\Stackiq\Kernel\Contracts\PublishedAanbodInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling aanbod (offers) API operations.
 *
 * This controller provides REST API endpoints for managing aanbod objects where
 * the active organization is involved either as afnemer (consumer) or aanbieder
 * (provider), and for accepting or denying these offers.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */
class AanbodController extends Controller {
	/**
	 * The object the accept and deny actions work on.
	 *
	 * @var array<string, string>
	 */
	private const ACTION_OBJECT = [
		'idParam' => 'uuid',
		'idLabel' => 'Aanbod UUID',
		'logIdKey' => 'aanbod_id',
		'notFoundError' => 'Aanbod object not found',
	];

	/**
	 * The accept and deny actions: route, log messages and error body fields.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ACTIONS = [
		'accept' => [
			'endpoint' => '/api/aanbod/%s/accept',
			'method' => 'PUT',
			'started' => 'API: Accepting aanbod object',
			'completed' => 'API: Accept aanbod request completed',
			'failed' => 'API: Failed to accept aanbod object',
			'emptyResult' => ['aanbod' => null],
			'reportsDeleted' => false,
		],
		'deny' => [
			'endpoint' => '/api/aanbod/%s/deny',
			'method' => 'DELETE',
			'started' => 'API: Denying aanbod object',
			'completed' => 'API: Deny aanbod request completed',
			'failed' => 'API: Failed to deny aanbod object',
			'emptyResult' => ['deleted' => false],
			'reportsDeleted' => true,
		],
	];

	/**
	 * Constructor for AanbodController.
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The HTTP request object
	 * @param IUserSession $userSession The user session service for getting the current user
	 * @param AanbodServiceInterface $aanbodService The business logic service
	 * @param LoggerInterface $logger The logger service for debugging and error reporting
	 * @param PublishedAanbodInterface|null $publishedAanbod The published entries an anonymous caller reads; without it an anonymous caller is rejected
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AanbodServiceInterface $aanbodService,
		private readonly LoggerInterface $logger,
		private readonly ?PublishedAanbodInterface $publishedAanbod = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The aanbod listing: endpoint and log messages.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const LISTINGS = [
		'aanbod' => [
			'endpoint' => '/api/aanbod',
			'rejected' => 'API: Rejecting unauthenticated aanbod request',
			'started' => 'API: Getting aanbod objects',
			'completed' => 'API: Aanbod request completed',
			'failed' => 'API: Failed to get aanbod objects',
			'failureLogsEndpoint' => false,
		],
	];

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
	 * REQ-009 (decision 392): the controller decides explicitly what an
	 * anonymous caller gets, before AanbodService::getAanbod() runs. An
	 * anonymous caller reads the published modules, diensten and koppelingen
	 * in the open-data projection (PublishedAanbod), with no organisation
	 * resolved; everything else needs login. @PublicPage admits the anonymous
	 * request so this branch can answer it, rate-limited like the other
	 * anonymous open-data reads.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/aanbod-listings/spec.md
	 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getAanbod(): JSONResponse {
		// REQ-009: the anonymous branch is decided here, before
		// AanbodService::getAanbod() is ever invoked. Do NOT rely on its
		// getCurrentOrganisation() resolving to null for an anonymous session
		// (deny-before-grant, REQ-001): an anonymous caller reads only the
		// published entries, and is rejected when that reader is missing.
		$listing = $this->objectListing(name: 'aanbod', idContext: []);
		$user    = $this->userSession->getUser();
		if ($user === null) {
			if ($this->publishedAanbod === null) {
				return $this->listingResponder()->rejectUnauthenticated(listing: $listing);
			}

			return $this->listingResponder()->respond(
				listing: $listing,
				fetch: fn (): array => $this->publishedAanbod->page(options: $this->parseQueryOptions())
			);
		}

		return $this->listingResponder()->respond(
			listing: $listing,
			fetch: fn (): array => $this->aanbodService->getAanbod($this->parseQueryOptions())
		);
	}//end getAanbod()

	/**
	 * The responder that runs this controller's listing reads.
	 *
	 * @return ListingResponder
	 */
	private function listingResponder(): ListingResponder {
		return new ListingResponder(logger: $this->logger, request: $this->request);
	}//end listingResponder()

	/**
	 * Build a listing from the listing table.
	 *
	 * @param string $name      The listing name in the listing table.
	 * @param array  $idContext Identifying fields of the log lines.
	 *
	 * @return ObjectListing
	 */
	private function objectListing(string $name, array $idContext): ObjectListing {
		return new ObjectListing(idContext: $idContext, definition: self::LISTINGS[$name]);
	}//end objectListing()

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
	public function acceptAanbod(string $uuid): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->actionResponder()->respond(
			action: $this->objectAction(id: $uuid, name: 'accept'),
			perform: fn (array $options): array => $this->aanbodService->acceptAanbod(aanbodId: $uuid, options: $options)
		);
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
	public function denyAanbod(string $uuid): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->actionResponder()->respond(
			action: $this->objectAction(id: $uuid, name: 'deny'),
			perform: fn (array $options): array => $this->aanbodService->denyAanbod(aanbodId: $uuid, options: $options)
		);
	}//end denyAanbod()

	/**
	 * The responder that runs this controller's object actions.
	 *
	 * @return ObjectActionResponder
	 */
	private function actionResponder(): ObjectActionResponder {
		return new ObjectActionResponder(logger: $this->logger, request: $this->request);
	}//end actionResponder()

	/**
	 * Build an object action from the action table.
	 *
	 * @param string $id   The object id from the route.
	 * @param string $name The action name in the action table.
	 *
	 * @return ObjectAction
	 */
	private function objectAction(string $id, string $name): ObjectAction {
		return new ObjectAction(id: $id, definition: self::ACTIONS[$name] + self::ACTION_OBJECT);
	}//end objectAction()

	/**
	 * Parse query parameters into options array.
	 *
	 * @return array Parsed options array
	 *
	 * @spec openspec/specs/aanbod-listings/spec.md
	 */
	private function parseQueryOptions(): array {
		$pagination = new ListingPagination(request: $this->request);
		$raw        = $pagination->rawParams();
		$options    = $pagination->options(raw: $raw);

		// Force database source for real-time data.
		$options['_source'] = 'database';

		$this->logger->debug(
			'Parsed query options for Aanbod',
			[
				'raw_params' => $raw,
				'parsed_options' => $options,
			]
		);

		return $options;
	}//end parseQueryOptions()
}//end class
