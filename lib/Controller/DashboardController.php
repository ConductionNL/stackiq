<?php

/**
 * Dashboard Controller for Stackiq.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\SyncAccessPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class DashboardController extends Controller {
	/**
	 * Constructor for DashboardController.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param IUserSession $userSession The user session
	 * @param IInitialState|null $initialState Hands the shell its permissions; null provides none.
	 * @param IGroupManager|null $groupManager Tells a Nextcloud admin apart.
	 * @param SyncAccessPolicy|null $syncAccess Who may see the organisation sync.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ?IInitialState $initialState = null,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?SyncAccessPolicy $syncAccess = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Renders the main application page.
	 *
	 * @param string|null $getParameter Optional query parameter
	 *
	 * @return TemplateResponse The rendered page template
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @spec                                          openspec/specs/dashboard-views-api/spec.md
	 */
	public function page(?string $getParameter): TemplateResponse {
		try {
			return $this->indexPage();
		} catch (\Exception $e) {
			return new TemplateResponse(
				$this->appName,
				'error',
				['error' => $e->getMessage()],
				TemplateResponse::RENDER_AS_ERROR,
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end page()

	/**
	 * The app's index template with a policy that lets it connect anywhere.
	 *
	 * @return TemplateResponse
	 */
	private function indexPage(): TemplateResponse {
		$this->initialState?->provideInitialState('permissions', $this->permissions());

		$response = new TemplateResponse(
			$this->appName,
			'index',
			[]
		);

		$csp = new ContentSecurityPolicy();
		$csp->addAllowedConnectDomain('*');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end indexPage()

	/**
	 * The permissions the shell's menu checks entries against: `user` for
	 * every signed-in user, `admin` for a Nextcloud admin and `sync.view` for
	 * whom the sync policy allows. Empty without a user.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
	 */
	private function permissions(): array {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return [];
		}

		$permissions = ['user'];
		if ($this->groupManager?->isAdmin($uid) === true) {
			$permissions[] = 'admin';
		}

		if ($this->syncAccess?->mayViewSync(uid: $uid) === true) {
			$permissions[] = 'sync.view';
		}

		return $permissions;
	}//end permissions()

	/**
	 * Returns an empty JSON result.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The JSON response with empty results
	 * @spec   openspec/specs/dashboard-views-api/spec.md
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$results = ['results' => []];
			return new JSONResponse($results);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end index()
}//end class
