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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
	 * @param DataQualityReportService $reportService Builds the report.
	 * @param OrganisationReportRequest $reportRequest The requested organisation and whether the user may read it.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DataQualityReportService $reportService,
		private readonly OrganisationReportRequest $reportRequest,
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
		$refusal = $this->reportRequest->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->reportService->build(organisationUuid: $this->reportRequest->organisation()));
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
