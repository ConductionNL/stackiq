<?php

/**
 * Reference component coverage controller for Stackiq.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use Exception;
use OCA\Stackiq\Service\ReferenceComponentCoverageService;
use OCA\Stackiq\Service\OrganisationReportAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves one organisation's reference component coverage, as JSON or CSV.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
 */
class ReferenceComponentCoverageController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session.
	 * @param ReferenceComponentCoverageService $reportService Builds the report.
	 * @param OrganisationReportAccess $access Who may read which organisation's report.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ReferenceComponentCoverageService $reportService,
		private readonly OrganisationReportAccess $access,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The coverage of `?organisation=` (`format=csv` for CSV), refused before any query when the user may not read it.
	 *
	 * @return JSONResponse|DataDownloadResponse The coverage, or an error.
	 *
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse|DataDownloadResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = (string)$this->request->getParam('organisation', '');
		if ($organisation === '') {
			return new JSONResponse(['message' => 'organisation is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->access->mayRead(user: $user, organisationUuid: $organisation) === false) {
			return new JSONResponse(['message' => 'Not authorised for this organisation'], Http::STATUS_FORBIDDEN);
		}

		try {
			if ((string)$this->request->getParam('format', 'json') === 'csv') {
				$csv = $this->reportService->buildCsv(organisationUuid: $organisation);
				return new DataDownloadResponse($csv, 'reference-component-coverage-' . $organisation . '.csv', 'text/csv');
			}

			return new JSONResponse($this->reportService->build(organisationUuid: $organisation));
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
