<?php

/**
 * Stackiq OrganisationMembersController.
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
 * (`canManageOrganisationMembers()`), which does not match Stackiq's
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
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
	 * @param LoggerInterface $logger Logger.
	 * @param OrganisationService $organisationService Resolves and updates organisation membership.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
		private readonly OrganisationService $organisationService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
			return self::userNotFound();
		}

		$failure = $this->joinMembership(uuid: $uuid, userId: $userId);
		if ($failure !== null) {
			return $failure;
		}

		return self::membershipChanged(message: 'Successfully granted organisation access', userId: $userId);
	}//end grant()

	/**
	 * Add the user to the organisation through OpenRegister.
	 *
	 * @param string $uuid   The organisation UUID.
	 * @param string $userId The existing Nextcloud user id to grant access to.
	 *
	 * @return JSONResponse|null The 400 response when OpenRegister refuses, null on success.
	 */
	private function joinMembership(string $uuid, string $userId): ?JSONResponse {
		try {
			$organisationService = $this->getOrganisationService();
			$organisationService->joinOrganisation(organisationUuid: $uuid, targetUserId: $userId);
		} catch (\Exception $e) {
			$this->logger->error(
				'[OrganisationMembersController] Failed to grant organisation access',
				['organisationUuid' => $uuid, 'userId' => $userId, 'error' => $e->getMessage()]
			);
			return self::badRequest(error: $e);
		}

		return null;
	}//end joinMembership()

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

		$failure = $this->leaveMembership(uuid: $uuid, userId: $userId);
		if ($failure !== null) {
			return $failure;
		}

		return self::membershipChanged(message: 'Successfully revoked organisation access', userId: $userId);
	}//end revoke()

	/**
	 * Remove the user from the organisation through OpenRegister.
	 *
	 * @param string $uuid   The organisation UUID.
	 * @param string $userId The Nextcloud user id to revoke access from.
	 *
	 * @return JSONResponse|null The 400 response when OpenRegister refuses, null on success.
	 */
	private function leaveMembership(string $uuid, string $userId): ?JSONResponse {
		try {
			$organisationService = $this->getOrganisationService();
			$organisationService->leaveOrganisation(organisationUuid: $uuid, targetUserId: $userId);
		} catch (\Exception $e) {
			$this->logger->error(
				'[OrganisationMembersController] Failed to revoke organisation access',
				['organisationUuid' => $uuid, 'userId' => $userId, 'error' => $e->getMessage()]
			);
			return self::badRequest(error: $e);
		}

		return null;
	}//end leaveMembership()

	/**
	 * The 404 answered when the user to grant access to does not exist.
	 *
	 * @return JSONResponse
	 */
	private static function userNotFound(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'User not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end userNotFound()

	/**
	 * The 400 answered when OpenRegister refuses a membership change.
	 *
	 * @param \Exception $error What OpenRegister threw.
	 *
	 * @return JSONResponse
	 */
	private static function badRequest(\Exception $error): JSONResponse {
		return new JSONResponse(
			data: ['error' => $error->getMessage()],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end badRequest()

	/**
	 * The 200 answered after a membership change.
	 *
	 * @param string $message What changed.
	 * @param string $userId  The user whose access changed.
	 *
	 * @return JSONResponse
	 */
	private static function membershipChanged(string $message, string $userId): JSONResponse {
		return new JSONResponse(
			data: [
				'message' => $message,
				'userId' => $userId,
			],
			statusCode: Http::STATUS_OK
		);
	}//end membershipChanged()

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
			return $this->refuseMaintainer(reason: 'not a beheerder', context: ['uid' => $user->getUID(), 'organisationUuid' => $organisationUuid]);
		}

		return $this->membershipRefusal(user: $user, organisationUuid: $organisationUuid);
	}//end authorizeMaintainer()

	/**
	 * Refuse a beheerder whose own organisations do not include the target.
	 *
	 * @param IUser  $user             The authenticated beheerder.
	 * @param string $organisationUuid The organisation the caller is trying to manage.
	 *
	 * @return JSONResponse|null Error response, or null when the caller belongs to the organisation.
	 */
	private function membershipRefusal(IUser $user, string $organisationUuid): ?JSONResponse {
		$callerOrganisations = $this->callerOrganisations(user: $user);
		if ($callerOrganisations === null) {
			return self::forbidden(error: 'Unable to verify organisation membership');
		}

		if (self::includesOrganisation(organisations: $callerOrganisations, organisationUuid: $organisationUuid) === true) {
			return null;
		}

		return $this->refuseMaintainer(
			reason: 'beheerder of a different organisation',
			context: ['uid' => $user->getUID(), 'organisationUuid' => $organisationUuid]
		);
	}//end membershipRefusal()

	/**
	 * The caller's own organisation memberships, or null when they cannot be resolved.
	 *
	 * @param IUser $user The authenticated beheerder.
	 *
	 * @return iterable<object>|null The organisations, from OpenRegister's session-scoped lookup.
	 */
	private function callerOrganisations(IUser $user): ?iterable {
		try {
			return $this->getOrganisationService()->getUserOrganisations();
		} catch (\Exception $e) {
			$this->logger->error(
				'[OrganisationMembersController] Failed to resolve caller organisations',
				['uid' => $user->getUID(), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end callerOrganisations()

	/**
	 * Whether a list of organisations includes the one with the given uuid.
	 *
	 * @param iterable<object> $organisations    The caller's organisations.
	 * @param string           $organisationUuid The organisation uuid.
	 *
	 * @return bool True when one of them has exactly that uuid.
	 */
	private static function includesOrganisation(iterable $organisations, string $organisationUuid): bool {
		foreach ($organisations as $organisation) {
			if ($organisation->getUuid() === $organisationUuid) {
				return true;
			}
		}

		return false;
	}//end includesOrganisation()

	/**
	 * Log and refuse a caller who may not manage the organisation's members.
	 *
	 * @param string               $reason  Why access is refused, for the log.
	 * @param array<string, mixed> $context The caller and the organisation, for the log.
	 *
	 * @return JSONResponse The 403 response.
	 */
	private function refuseMaintainer(string $reason, array $context): JSONResponse {
		$this->logger->warning('[OrganisationMembersController] Access refused (' . $reason . ')', $context);
		return self::forbidden(error: 'Only a beheerder of this organisation may grant or revoke access');
	}//end refuseMaintainer()

	/**
	 * A 403 response with an error message.
	 *
	 * @param string $error The message.
	 *
	 * @return JSONResponse
	 */
	private static function forbidden(string $error): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: Http::STATUS_FORBIDDEN);
	}//end forbidden()

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
	 * No `@throws`: the body is a plain property read. If OpenRegister is
	 * unavailable the failure happens in the container while CONSTRUCTING this
	 * controller, not here.
	 */
	private function getOrganisationService(): \OCA\OpenRegister\Service\OrganisationService {
		return $this->organisationService;
	}//end getOrganisationService()
}//end class
