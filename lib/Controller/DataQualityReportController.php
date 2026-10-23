<?php

/**
 * Data quality report controller for Stackiq.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use Exception;
use OCA\Stackiq\Service\DataQualityReportService;
use OCA\Stackiq\Service\OrganisationReportAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves one organisation's data quality report.
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */
class DataQualityReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session.
	 * @param DataQualityReportService $reportService Builds the report.
	 * @param OrganisationReportAccess $access Who may read which organisation's report.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly DataQualityReportService $reportService,
		private readonly OrganisationReportAccess $access,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The data quality report of `?organisation=`, refused before any query when the user may not read it.
	 *
	 * @return JSONResponse The report, or an error.
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
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
			return new JSONResponse($this->reportService->build(organisationUuid: $organisation));
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
