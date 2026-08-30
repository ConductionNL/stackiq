<?php

/**
 * Stackiq MergeController.
 *
 * Admin-triggered organisation-merge endpoints (VNG Softwarecatalogus #141):
 * dry-run preview and execute for folding a source organisation into a
 * target organisation.
 *
 * AUTH (ADR-005): `#[NoAdminRequired]` route annotation PLUS an explicit
 * admin-group authorization guard in the method body
 * (`IGroupManager::isAdmin()`) — the route annotation alone is NOT treated
 * as sufficient authorization (no-admin-idor gate). Without the body guard
 * any authenticated non-admin could trigger or observe a merge on
 * organisations they don't manage (IDOR / OWASP A01:2021).
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-only organisation-merge dry-run/execute endpoints.
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */
class MergeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session (auth guard).
	 * @param IGroupManager $groupManager Group membership (admin-only guard).
	 * @param MergeOrganisatieService $mergeService The merge service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly MergeOrganisatieService $mergeService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Preview a merge: per-relation-type counts, no writes.
	 *
	 * @param string $uuid The source organisation uuid.
	 * @param string $targetUuid The target organisation uuid.
	 *
	 * @return JSONResponse `{sourceUuid, targetUuid, counts, blockers}` or a 401/403.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	#[NoAdminRequired]
	public function dryRun(string $uuid, string $targetUuid): JSONResponse {
		$guard = $this->authorizeAdmin();
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$result = $this->mergeService->dryRun(sourceUuid: $uuid, targetUuid: $targetUuid);

		return new JSONResponse(data: $result);
	}//end dryRun()

	/**
	 * Execute a merge: re-point every relation type, migrate group
	 * membership, tombstone the source. Admin-only, idempotent.
	 *
	 * @param string $uuid The source organisation uuid.
	 * @param string $targetUuid The target organisation uuid.
	 *
	 * @return JSONResponse `{operationId, sourceUuid, targetUuid, status, counts}` or a 401/403/409.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	#[NoAdminRequired]
	public function execute(string $uuid, string $targetUuid): JSONResponse {
		$guard = $this->authorizeAdmin();
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$actorUid = $this->userSession->getUser()?->getUID();
		$result = $this->mergeService->execute(sourceUuid: $uuid, targetUuid: $targetUuid, actorUid: $actorUid);

		if ($result['ok'] === false) {
			return new JSONResponse(
				data: [
					'message' => 'Merge request rejected.',
					'blockers' => $result['blockers'],
				],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(data: $result);
	}//end execute()

	/**
	 * Admin-only authorization guard (IDOR guard). Returns a JSONResponse to
	 * short-circuit on failure, or null when the caller may merge.
	 *
	 * @return JSONResponse|null Error response, or null when authorized.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard
	 */
	private function authorizeAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			$this->logger->warning('MergeController: merge refused (not admin)', ['uid' => $user->getUID()]);
			return new JSONResponse(
				data: ['message' => 'Only administrators may merge organisations'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end authorizeAdmin()
}//end class
