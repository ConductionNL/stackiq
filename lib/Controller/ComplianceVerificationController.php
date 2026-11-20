<?php

/**
 * The Mark as verified action on a compliance claim.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Service\ComplianceVerificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * POST /api/compliancy/{id}/verify. Authenticated only; who may verify is
 * decided by ComplianceVerification before any write.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
 */
class ComplianceVerificationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request      The request.
	 * @param ComplianceVerificationService $verification The verify action.
	 * @param IUserSession                  $userSession  The user session.
	 * @param IGroupManager                 $groupManager Group membership.
	 * @param IConfig                       $config       Per-user organisation lookup.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ComplianceVerificationService $verification,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Mark a claim verified as the signed-in reviewer.
	 *
	 * @param string $id The compliancy uuid.
	 *
	 * @return JSONResponse The verification stamp, or {message} with 401, 403, 404, 409, 422, 500 or 503.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#requirement-a-claim-reads-verified-only-after-a-reviewer-outside-the-supplier-checks-it-req-clr-022
	 */
	#[NoAdminRequired]
	public function verify(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$groups = array_map(static fn (IGroup $group): string => $group->getGID(), $this->groupManager->getUserGroups(user: $user));
		$result = $this->verification->verify(
			id: $id,
			reviewer: $user->getDisplayName(),
			groups: array_values($groups),
			organisation: (string)$this->config->getUserValue(userId: $user->getUID(), appName: 'core', key: 'organisation'),
			now: gmdate('Y-m-d\TH:i:s\Z')
		);

		return new JSONResponse(data: $result['body'], statusCode: $result['status']);
	}//end verify()
}//end class
