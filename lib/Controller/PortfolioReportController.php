<?php

/**
 * Portfolio Rationalization Report Controller for Stackiq.
 *
 * Serves the per-organisation portfolio rationalization report (TIME
 * quadrant counts, EOL exposure, cloud-transition share, annualised cost)
 * as JSON, plus a CSV export variant of the same bounded, organisation-
 * scoped row set.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use Exception;
use OCA\Stackiq\Service\PortfolioReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the portfolio rationalization report endpoint.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */
class PortfolioReportController extends Controller {
	/**
	 * Constructor for PortfolioReportController.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession The user session service.
	 * @param IGroupManager $groupManager The group manager service.
	 * @param IConfig $config The configuration service.
	 * @param PortfolioReportService $reportService The report aggregation service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
		private readonly PortfolioReportService $reportService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Serve the portfolio rationalization report for an organisation, as
	 * JSON (default) or CSV (`?format=csv`).
	 *
	 * Deny-before-query (REQ-001/REQ-005 of `vendor-visibility-rbac`,
	 * applied to this endpoint per `portfolio-rationalization-time`
	 * REQ "Report and CSV export are scoped..."): the caller's
	 * organisation-access is resolved and checked BEFORE
	 * `PortfolioReportService::buildReport()`/`buildCsv()` ever issues an
	 * OpenRegister query for the requested organisation.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse|DataDownloadResponse
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report
	 */
	public function index() {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = (string)$this->request->getParam('organisation', '');
		if ($organisation === '') {
			return new JSONResponse(['message' => 'organisation is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isAuthorisedForOrganisation(user: $user, organisationUuid: $organisation) === false) {
			// Fail closed: denied BEFORE any report query is built.
			return new JSONResponse(['message' => 'Not authorised for this organisation'], Http::STATUS_FORBIDDEN);
		}

		$format = (string)$this->request->getParam('format', 'json');

		try {
			if ($format === 'csv') {
				$csv = $this->reportService->buildCsv(organisationUuid: $organisation);
				return new DataDownloadResponse($csv, 'portfolio-report-' . $organisation . '.csv', 'text/csv');
			}

			return new JSONResponse($this->reportService->buildReport(organisationUuid: $organisation));
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Whether the caller is authorised to see `$organisationUuid`'s
	 * portfolio report.
	 *
	 * Reuses the same role/organisation resolution mechanism as
	 * `GebruikController::resolveUserRoles()` / `applyAanbodScopeToOptions()`
	 * (per design.md: this change plugs into the current
	 * tenant/organisation-scoping mechanism rather than inventing a new
	 * matrix). `admin`/`ambtenaar` may request any organisation's report
	 * (existing unrestricted-read bypass); every other authenticated user
	 * may request only their own active organisation's report — a report
	 * is a synthesis of another organisation's gebruik/contract data, which
	 * `vendor-visibility-rbac` REQ-002/REQ-003 do not grant beyond the
	 * caller's own organisation or offered-products relationship.
	 *
	 * @param \OCP\IUser $user The authenticated caller.
	 * @param string $organisationUuid The requested organisation uuid.
	 *
	 * @return bool True when the caller may see this organisation's report.
	 *
	 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-beheerder-reads-of-gebruik-objects-must-be-scoped-to-the-caller-s-own-organisation-req-003
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
	 */
	private function isAuthorisedForOrganisation(\OCP\IUser $user, string $organisationUuid): bool {
		$groups = $this->groupManager->getUserGroups(user: $user);
		$groupNames = array_map(
			static function (IGroup $group) {
				return $group->getGID();
			},
			$groups
		);

		$isAdmin = in_array('admin', $groupNames, true);
		$isAmbtenaar = in_array('ambtenaar', $groupNames, true);
		if ($isAdmin === true || $isAmbtenaar === true) {
			return true;
		}

		$orgUuid = (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

		if ($orgUuid === '') {
			return false;
		}

		return $orgUuid === $organisationUuid;
	}//end isAuthorisedForOrganisation()
}//end class
