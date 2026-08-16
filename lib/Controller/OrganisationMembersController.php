<?php

/**
 * Softwarecatalog OrganisationMembersController.
 *
 * Self-service colleague access (VNG Softwarecatalogus #65): lets a
 * `beheerder` of an organisation grant or revoke an EXISTING Nextcloud
 * user's membership of that organisation, without an administrator.
 *
 * This controller deliberately does NOT reimplement membership storage.
 * Every mutation delegates to OpenRegister's already-shipped
 * `OrganisationService::joinOrganisation()` / `leaveOrganisation()`
 * (ADR-011/ADR-022). It exists only because OpenRegister's own
 * `OrganisationController::join()`/`leave()` authorize a caller managing
 * ANOTHER user's membership solely via Nextcloud-admin-or-single-`owner`
 * (`canManageOrganisationMembers()`), which does not match SoftwareCatalog's
 * `beheerder`-role domain model — so this controller adds that
 * domain-specific authorization guard before delegating.
 *
 * AUTH: `#[NoAdminRequired]` route annotation PLUS an explicit, per-object
 * authorization guard in the method body (`authorizeBeheerder()`) — the
 * route annotation alone is NOT treated as sufficient authorization
 * (no-admin-idor gate). The guard is fail-closed: both checks (global
 * `beheerder` NC group membership AND OpenRegister-verified membership of
 * the TARGET organisation, never a client-supplied claim) MUST pass before
 * any membership mutation is attempted.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\OrganisationService;

/**
 * Beheerder-gated grant/revoke of organisation membership for an existing
 * Nextcloud user, delegating the actual mutation to OpenRegister.
 *
 * @spec openspec/specs/multi-org-membership/spec.md
 */
class OrganisationMembersController extends Controller {
	/**
	 * NC group whose members may manage an organisation's own membership,
	 * per organisation, when they also belong to that organisation.
	 * Matches the group `ContactPersonHandler::assignBeheerderRole()`
	 * already assigns.
	 */
	private const BEHEERDER_GROUP = 'maintainer';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session (auth guard).
	 * @param IGroupManager $groupManager Group membership (beheerder guard).
	 * @param IUserManager $userManager User lookup (existing-user-only guard).
	 *                                      `OrganisationService` without a hard compile-time
	 *                                      dependency on another app's class.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
		private readonly OrganisationService $organisationService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Grant an existing Nextcloud user access to an organisation.
	 *
	 * @param string $uuid The organisation UUID.
	 * @param string $userId The existing Nextcloud user id to grant access to.
	 *
	 * @return JSONResponse Success (200), or a 401/403/404/400 error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function grant(string $uuid, string $userId): JSONResponse {
		$guard = $this->authorizeMaintainer(organisationUuid: $uuid);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		if ($this->userManager->get($userId) === null) {
			return new JSONResponse(
				data: ['error' => 'User not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$organisationService = $this->getOrganisationService();
			$organisationService->joinOrganisation(organisationUuid: $uuid, targetUserId: $userId);
		} catch (\Exception $e) {
			$this->logger->error(
				'[OrganisationMembersController] Failed to grant organisation access',
				['organisationUuid' => $uuid, 'userId' => $userId, 'error' => $e->getMessage()]
			);
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: [
				'message' => 'Successfully granted organisation access',
				'userId' => $userId,
			],
			statusCode: Http::STATUS_OK
		);
	}//end grant()

	/**
	 * Revoke an existing member's access to an organisation.
	 *
	 * @param string $uuid The organisation UUID.
	 * @param string $userId The Nextcloud user id to revoke access from.
	 *
	 * @return JSONResponse Success (200), or a 401/403/400 error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revoke(string $uuid, string $userId): JSONResponse {
		$guard = $this->authorizeMaintainer(organisationUuid: $uuid);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		try {
			$organisationService = $this->getOrganisationService();
			$organisationService->leaveOrganisation(organisationUuid: $uuid, targetUserId: $userId);
		} catch (\Exception $e) {
			$this->logger->error(
				'[OrganisationMembersController] Failed to revoke organisation access',
				['organisationUuid' => $uuid, 'userId' => $userId, 'error' => $e->getMessage()]
			);
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: [
				'message' => 'Successfully revoked organisation access',
				'userId' => $userId,
			],
			statusCode: Http::STATUS_OK
		);
	}//end revoke()

	/**
	 * Beheerder-of-this-organisation authorization guard (IDOR guard).
	 *
	 * Fail-closed, two independent server-side checks — never a
	 * client-supplied claim:
	 *  1. The caller is authenticated and belongs to the global `beheerder`
	 *     NC group (cheap check first; rejects most non-beheerders without
	 *     an OpenRegister round trip).
	 *  2. The caller's OWN organisation memberships, resolved from
	 *     OpenRegister's `OrganisationService::getUserOrganisations()`
	 *     (session-scoped — the same source `setActiveOrganisation()`
	 *     trusts), include the TARGET organisation UUID. A `beheerder` of a
	 *     different organisation does NOT pass this check.
	 *
	 * @param string $organisationUuid The organisation the caller is trying to manage.
	 *
	 * @return JSONResponse|null Error response, or null when authorized.
	 *
	 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
	 */
	private function authorizeMaintainer(string $organisationUuid): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isInGroup($user->getUID(), self::BEHEERDER_GROUP) === false) {
			$this->logger->warning(
				'[OrganisationMembersController] Access refused (not a beheerder)',
				['uid' => $user->getUID(), 'organisationUuid' => $organisationUuid]
			);
			return new JSONResponse(
				data: ['error' => 'Only a beheerder of this organisation may grant or revoke access'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		try {
			$organisationService = $this->getOrganisationService();
			$callerOrganisations = $organisationService->getUserOrganisations();
		} catch (\Exception $e) {
			$this->logger->error(
				'[OrganisationMembersController] Failed to resolve caller organisations',
				['uid' => $user->getUID(), 'error' => $e->getMessage()]
			);
			return new JSONResponse(
				data: ['error' => 'Unable to verify organisation membership'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		foreach ($callerOrganisations as $organisation) {
			if ($organisation->getUuid() === $organisationUuid) {
				return null;
			}
		}

		$this->logger->warning(
			'[OrganisationMembersController] Access refused (beheerder of a different organisation)',
			['uid' => $user->getUID(), 'organisationUuid' => $organisationUuid]
		);
		return new JSONResponse(
			data: ['error' => 'Only a beheerder of this organisation may grant or revoke access'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end authorizeBeheerder()

	/**
	 * Resolve OpenRegister's OrganisationService via the DI container.
	 *
	 * String-based lookup (not a compile-time type-hint) matches the
	 * existing pattern in `ContactpersonenController::getMe()` and
	 * `OrganizationHandler` — OpenRegister is a required app dependency but
	 * its classes are not part of this app's own composer autoload map.
	 *
	 * @return \OCA\OpenRegister\Service\OrganisationService The service instance.
	 *
	 * @throws \Throwable When OpenRegister is unavailable.
	 */
	private function getOrganisationService(): \OCA\OpenRegister\Service\OrganisationService {
		return $this->organisationService;
	}//end getOrganisationService()
}//end class
