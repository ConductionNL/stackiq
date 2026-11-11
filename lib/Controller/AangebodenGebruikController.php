<?php

/**
 * AangebodenGebruik Controller for Stackiq.
 *
 * Handles HTTP requests for offered usage (aangeboden gebruik) operations including
 * retrieving gebruiks objects where the active organization is involved as afnemer
 * or in deelnemers, and updating the @self property of gebruiks objects.
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

use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling offered usage (aangeboden gebruik) API operations.
 *
 * This controller provides REST API endpoints for managing gebruiks objects where
 * the active organization is involved either as afnemer (consumer) or in deelnemers
 * (participants), and for updating the @self property of gebruiks objects.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/vendor-visibility-rbac/tasks.md#task-1
 */
class AangebodenGebruikController extends Controller {
	/**
	 * The object the set-self and deny actions work on.
	 *
	 * @var array<string, string>
	 */
	private const ACTION_OBJECT = [
		'idParam' => 'gebruikId',
		'idLabel' => 'Gebruik ID',
		'logIdKey' => 'gebruik_id',
		'notFoundError' => 'Gebruik object not found',
	];

	/**
	 * The set-self and deny actions: route, log messages and error body fields.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ACTIONS = [
		'set-self' => [
			'endpoint' => '/api/aangeboden-gebruik/%s/set-self',
			'method' => 'PUT',
			'started' => 'API: Setting gebruik @self property to active org',
			'completed' => 'API: Set gebruik @self property request completed',
			'failed' => 'API: Failed to set gebruik @self property',
			'emptyResult' => ['usage' => null],
			'reportsDeleted' => false,
		],
		'deny' => [
			'endpoint' => '/api/aangeboden-gebruik/%s/deny',
			'method' => 'DELETE',
			'started' => 'API: Deleting gebruik object as afnemer',
			'completed' => 'API: Delete gebruik as afnemer request completed',
			'failed' => 'API: Failed to delete gebruik as afnemer',
			'emptyResult' => ['deleted' => false],
			'reportsDeleted' => true,
		],
	];

	/**
	 * Constructor for AangebodenGebruikController.
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The HTTP request object
	 * @param IUserSession $userSession The user session service for getting the current user
	 * @param AangebodenGebruikService $gebruikSvc The business logic service
	 * @param LoggerInterface $logger The logger service for debugging and error reporting
	 * @param IGroupManager $groupManager The group manager for group membership checks
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AangebodenGebruikService $gebruikSvc,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The listing reads: endpoint as logged and log messages.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const LISTINGS = [
		'afnemer' => [
			'endpoint' => '/api/aangeboden-gebruik/afnemer',
			'rejected' => 'API: Rejecting unauthenticated afnemer gebruiks request',
			'started' => 'API: Getting gebruiks where active org is afnemer',
			'completed' => 'API: Afnemer gebruiks request completed',
			'failed' => 'API: Failed to get afnemer gebruiks',
			'failureLogsEndpoint' => false,
		],
		'koppelingen' => [
			'endpoint' => '/api/koppelingen-gebruik/{uuid}',
			'started' => 'API: Getting koppelingen and gebruiks for specific UUID',
			'completed' => 'API: Koppelingen-gebruik by UUID request completed',
			'failed' => 'API: Failed to get koppelingen-gebruik by UUID',
			'failureLogsEndpoint' => false,
		],
		'ambtenaar' => [
			'endpoint' => '/api/aangeboden-gebruik/ambtenaar',
			'started' => 'API: Getting all gebruiks for ambtenaar (ignoring RBAC/multitenancy)',
			'completed' => 'API: Ambtenaar all gebruiks request completed',
			'failed' => 'API: Failed to get all gebruiks for ambtenaar',
			'failureLogsEndpoint' => true,
		],
		'ambtenaarSingle' => [
			'endpoint' => '/api/aangeboden-gebruik/ambtenaar/{gebruikId}',
			'started' => 'API: Getting single gebruik for ambtenaar (ignoring RBAC/multitenancy)',
			'completed' => 'API: Ambtenaar single gebruik request completed',
			'failed' => 'API: Failed to get single gebruik for ambtenaar',
			'failureLogsEndpoint' => true,
		],
	];

	/**
	 * Get all gebruiks objects where the active organization is the afnemer (consumer).
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
	 * @return JSONResponse JSON response with gebruiks array where org is afnemer
	 *
	 * REQ-004 requires an authenticated caller, and the body enforces it.
	 * The PublicPage annotation told Nextcloud's middleware to admit
	 * anonymous requests anyway, so declared and enforced contract disagreed:
	 * every anonymous call was admitted, routed, and only then rejected by
	 * the controller. @NoAdminRequired is the annotation that matches —
	 * any logged-in user, rejected at the middleware. The in-body guard
	 * stays as deny-before-grant (REQ-001).
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @spec            openspec/specs/aangeboden-gebruik-api/spec.md
	 * @spec            openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-offered-usage-afnemer-endpoint-must-require-authentication-explicitly-not-implicitly-req-004
	 */
	public function getGebruiksWhereAfnemer(): JSONResponse {
		// REQ-004: explicit authentication guard. Do NOT rely on
		// AangebodenGebruikService::getCurrentOrganisation() resolving to
		// null for an anonymous session as the sole safeguard — that is an
		// implicit, unstated invariant of a downstream helper, not an
		// explicit guard at the entry point. Reject before the service is
		// ever invoked (deny-before-grant, REQ-001).
		$listing = $this->objectListing(name: 'afnemer', idContext: []);
		if ($this->userSession->getUser() === null) {
			return $this->listingResponder()->rejectUnauthenticated(listing: $listing);
		}

		// Don't include product filter for "get all" endpoint.
		return $this->listingResponder()->respond(
			listing: $listing,
			fetch: fn (): array => $this->gebruikSvc->getGebruiksWhereAfnemer($this->parseQueryOptions())
		);
	}//end getGebruiksWhereAfnemer()

	/**
	 * Get koppelingen and gebruiks for a specific application/module UUID.
	 *
	 * This endpoint returns both koppelingen and gebruiks related to a specific application/module.
	 * Access rules:
	 * - Users with "ambtenaar" group can see all related objects
	 * - Users whose organization owns the application/module can see all related usage
	 * - Supports filtering by organization UUID via query parameter for ambtenaar users
	 *
	 * @param string $uuid The UUID of the application/module
	 *
	 * @return JSONResponse Koppelingen and gebruiks objects for the specified UUID
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec           openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getKoppelingenGebruikByUuid(string $uuid): JSONResponse {
		return $this->listingResponder()->respond(
			listing: $this->objectListing(name: 'koppelingen', idContext: ['uuid' => $uuid]),
			fetch: fn (): array => $this->fetchKoppelingenGebruik(uuid: $uuid)
		);
	}//end getKoppelingenGebruikByUuid()

	/**
	 * Read the koppelingen and gebruiks of one application/module; the
	 * organisation filter applies only for admin or ambtenaar users.
	 *
	 * @param string $uuid The UUID of the application/module
	 *
	 * @return array The service result.
	 */
	private function fetchKoppelingenGebruik(string $uuid): array {
		// Check if user is in admin or ambtenaar group.
		$isAmbtenaar = $this->isUserInGroup(groupName: 'admin') || $this->isUserInGroup(groupName: 'ambtenaar');

		// Get organization filter if provided (only for ambtenaar users).
		$organisationFilter = $this->request->getParam('organisation');
		$options            = $this->parseQueryOptions();
		if ($isAmbtenaar === true && $organisationFilter !== null) {
			$options['organisation'] = $organisationFilter;
		}

		return $this->gebruikSvc->getKoppelingenGebruikByUuid(
			uuid: $uuid,
			options: $options,
			isAmbtenaar: $isAmbtenaar
		);
	}//end fetchKoppelingenGebruik()

	/**
	 * Get all gebruiks objects (ignoring RBAC and multitenancy) - restricted to ambtenaar group.
	 *
	 * This endpoint returns all gebruiks objects regardless of ownership or organization,
	 * bypassing normal RBAC and multitenancy restrictions. Access is restricted to users
	 * with the "ambtenaar" group.
	 *
	 * @deprecated Use getKoppelingenGebruik() instead
	 *
	 * @return JSONResponse All gebruiks objects in standard searchObjectsPaginated format
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec           openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getAllGebruiksForAmbtenaar(): JSONResponse {
		$listing = $this->objectListing(name: 'ambtenaar', idContext: []);
		return $this->listingResponder()->respond(
			listing: $listing,
			fetch: fn (): array|JSONResponse => $this->fetchAllForAmbtenaar(listing: $listing)
		);
	}//end getAllGebruiksForAmbtenaar()

	/**
	 * Read all gebruiks for an admin or ambtenaar user; anyone else gets the empty listing.
	 *
	 * @param ObjectListing $listing The listing.
	 *
	 * @return array|JSONResponse The service result, or the empty listing response.
	 */
	private function fetchAllForAmbtenaar(ObjectListing $listing): array|JSONResponse {
		if ($this->isAdminOrAmbtenaar() === false) {
			return $this->outsiderListing(listing: $listing);
		}

		// Don't include product filter for "get all" endpoint.
		return $this->gebruikSvc->getAllGebruiksForAmbtenaar($this->parseQueryOptions());
	}//end fetchAllForAmbtenaar()

	/**
	 * Get a single gebruiks object by ID (ignoring RBAC and multitenancy) - restricted to ambtenaar group.
	 *
	 * This endpoint returns a specific gebruiks object by its ID, bypassing normal RBAC
	 * and multitenancy restrictions. Access is restricted to users with the "ambtenaar" group.
	 *
	 * @param string $gebruikId The ID of the gebruik object to retrieve
	 *
	 * @return JSONResponse Single gebruik object in standard searchObjectsPaginated format
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getSingleGebruikForAmbtenaar(string $gebruikId): JSONResponse {
		$listing = $this->objectListing(name: 'ambtenaarSingle', idContext: ['gebruik_id' => $gebruikId]);
		return $this->listingResponder()->respond(
			listing: $listing,
			fetch: fn (): array|JSONResponse => $this->fetchSingleForAmbtenaar(listing: $listing, gebruikId: $gebruikId)
		);
	}//end getSingleGebruikForAmbtenaar()

	/**
	 * Read one gebruik for an admin or ambtenaar user; anyone else gets the empty listing.
	 *
	 * @param ObjectListing $listing   The listing.
	 * @param string        $gebruikId The ID of the gebruik object to retrieve
	 *
	 * @return array|JSONResponse The service result, or the empty listing response.
	 */
	private function fetchSingleForAmbtenaar(ObjectListing $listing, string $gebruikId): array|JSONResponse {
		if ($this->isAdminOrAmbtenaar() === false) {
			return $this->outsiderListing(listing: $listing);
		}

		// Don't include product filter - we use uses parameter instead.
		return $this->gebruikSvc->getSingleGebruikForAmbtenaar(
			suiteId: $gebruikId,
			options: $this->parseQueryOptions()
		);
	}//end fetchSingleForAmbtenaar()

	/**
	 * Whether the current user is in the admin or the ambtenaar group; both
	 * memberships are checked.
	 *
	 * @return bool
	 */
	private function isAdminOrAmbtenaar(): bool {
		$isAdmin     = $this->isUserInGroup(groupName: 'admin');
		$isAmbtenaar = $this->isUserInGroup(groupName: 'ambtenaar');
		return $isAdmin === true || $isAmbtenaar === true;
	}//end isAdminOrAmbtenaar()

	/**
	 * The empty listing (200, not an error) for a user outside the admin and ambtenaar groups.
	 *
	 * @param ObjectListing $listing The listing.
	 *
	 * @return JSONResponse
	 */
	private function outsiderListing(ObjectListing $listing): JSONResponse {
		// Get user ID for logging (may be null if not authenticated).
		$user   = $this->userSession->getUser();
		$userId = 'null';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$this->logger->info(
			'API: Returning empty results - user not in admin or ambtenaar group',
			['endpoint' => $listing->definition['endpoint']] + $listing->idContext + ['user' => $userId]
		);

		return new JSONResponse(array_replace($this->listingResponder()->emptyListing(), ['pages' => 1]), 200);
	}//end outsiderListing()

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
	 * Check if the current user is in a specific group.
	 *
	 * @param string $groupName The name of the group to check
	 *
	 * @return bool True if user is in the group, false otherwise
	 */
	private function isUserInGroup(string $groupName): bool {
		try {
			// Get the current user from the session.
			$user = $this->userSession->getUser();
			if ($user === null) {
				$this->logger->debug(
					'No user in session for group check',
					[
						'group' => $groupName,
					]
				);
				return false;
			}

			return $this->isMemberOf(user: $user, groupName: $groupName);
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
	 * Check if a user is in a group; a group that does not exist has no members.
	 *
	 * @param IUser  $user      The user.
	 * @param string $groupName The name of the group to check
	 *
	 * @return bool True if user is in the group, false otherwise
	 */
	private function isMemberOf(IUser $user, string $groupName): bool {
		$userId = $user->getUID();

		$group = $this->groupManager->get($groupName);
		if ($group === null) {
			$this->logger->warning('Group does not exist', ['group' => $groupName]);
			return false;
		}

		$isInGroup = $group->inGroup($user);

		$this->logger->debug(
			'User group membership check',
			[
				'user' => $userId,
				'group' => $groupName,
				'isMember' => $isInGroup,
			]
		);

		return $isInGroup;
	}//end isMemberOf()

	/**
	 * Get all gebruiks objects where the active organization is in deelnemers (participants).
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
	 * @return JSONResponse JSON response with gebruiks array where org is in deelnemers
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @spec           openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getGebruiksWhereDeelnemers(): JSONResponse {
		$this->logger->info(
			'API: Getting gebruiks where active org is in deelnemers',
			[
				'endpoint' => '/api/aangeboden-gebruik/deelnemers',
				'method' => 'GET',
				'query_params' => $this->request->getParams(),
			]
		);

		try {
			// Parse query parameters for filtering options.
			// Don't include product filter for deelnemers endpoint.
			$options = $this->parseQueryOptions();

			// Get gebruiks from service where org is in deelnemers.
			$result = $this->gebruikSvc->getGebruiksWhereDeelnemers($options);

			// Determine appropriate HTTP status code.
			$statusCode = 500;
			if ($result['success'] === true) {
				$statusCode = 200;
			} elseif (($result['error'] ?? '') === 'Gebruik object not found') {
				$statusCode = 404;
			} elseif (strpos(haystack: ($result['error'] ?? ''), needle: 'Operation not allowed') !== false) {
				$statusCode = 403;
			}

			$this->logger->info(
				'API: Deelnemers gebruiks request completed',
				[
					'success' => $result['success'],
					'gebruiks_count' => $result['count'] ?? 0,
					'organisation' => $result['organisation'] ?? 'unknown',
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
					'success' => false,
					'error' => 'Internal server error: ' . $e->getMessage(),
					'gebruiks' => [],
					'count' => 0,
				],
				500
			);
		}//end try
	}//end getGebruiksWhereDeelnemers()

	/**
	 * Set the @self property of a gebruik or koppeling to the active organization.
	 *
	 * API Endpoint: PUT /api/aangeboden-gebruik/{gebruikId}/set-self
	 *
	 * This endpoint allows setting the @self.organisation property of a specific gebruik
	 * or koppeling object to the active organization, but only if the active organization
	 * is the afnemer (consumer) or aanbieder (provider) for that object.
	 *
	 * @param string $gebruikId The UUID of the gebruik or koppeling object to update
	 *
	 * @return JSONResponse JSON response with success status and updated object
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function setGebruikSelfToActiveOrg(string $gebruikId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->actionResponder()->respond(
			action: $this->objectAction(id: $gebruikId, name: 'set-self'),
			perform: fn (array $options): array => $this->gebruikSvc->setGebruikSelfToActiveOrg(gebruikId: $gebruikId, options: $options)
		);
	}//end setGebruikSelfToActiveOrg()

	/**
	 * Delete (deny) a gebruik or koppeling object as afnemer or aanbieder.
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
	 * @param string $gebruikId The UUID of the gebruik or koppeling object to delete
	 *
	 * @return JSONResponse JSON response with success status and deletion details
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	public function deleteGebruikAsAfnemer(string $gebruikId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->actionResponder()->respond(
			action: $this->objectAction(id: $gebruikId, name: 'deny'),
			perform: fn (array $options): array => $this->gebruikSvc->deleteGebruikAsAfnemer(gebruikId: $gebruikId, options: $options)
		);
	}//end deleteGebruikAsAfnemer()

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
	 * Get API documentation for AangebodenGebruik endpoints.
	 *
	 * API Endpoint: GET /api/aangeboden-gebruik/docs
	 *
	 * @return JSONResponse JSON response with API documentation
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getApiDocumentation(): JSONResponse {
		return new JSONResponse(AangebodenGebruikApiDocumentation::DOCUMENTATION, 200);
	}//end getApiDocumentation()

	/**
	 * Parse query parameters into options array.
	 *
	 * This method extracts and validates query parameters for filtering,
	 * pagination, and other options. Always forces database source for real-time data.
	 *
	 * @return array Parsed options array with database source
	 */
	private function parseQueryOptions(): array {
		$pagination = new ListingPagination(request: $this->request);
		$raw        = $pagination->rawParams();
		$filters    = $this->textFilterParams();
		$options    = $this->applyTextFilters(options: $pagination->options(raw: $raw), filters: $filters);

		// Force database source for all custom endpoints to ensure real-time data.
		$options['_source'] = 'database';

		$this->logger->debug(
			'Parsed query options for AangebodenGebruik',
			[
				'raw_params' => ['limit' => $raw['limit'], 'offset' => $raw['offset']] + $filters,
				'parsed_options' => $options,
			]
		);

		return $options;
	}//end parseQueryOptions()

	/**
	 * The raw status, startDate and endDate filter parameters.
	 *
	 * @return array{status: mixed, startDate: mixed, endDate: mixed}
	 */
	private function textFilterParams(): array {
		return [
			'status' => $this->request->getParam('status'),
			'startDate' => $this->request->getParam('startDate'),
			'endDate' => $this->request->getParam('endDate'),
		];
	}//end textFilterParams()

	/**
	 * Add the filter parameters that pass the filter check, trimmed.
	 *
	 * @param array $options The options so far.
	 * @param array $filters The raw filter parameters.
	 *
	 * @return array The options with the filters added.
	 */
	private function applyTextFilters(array $options, array $filters): array {
		foreach ($filters as $name => $value) {
			if ($value !== null && empty(trim($value) === true) === false) {
				$options[$name] = trim($value);
			}
		}

		return $options;
	}//end applyTextFilters()
}//end class
