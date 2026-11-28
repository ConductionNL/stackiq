<?php

/**
 * Landscape comparison controller for Stackiq.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use DomainException;
use Exception;
use InvalidArgumentException;
use OCA\Stackiq\Kernel\Contracts\LandscapeComparisonServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OutOfBoundsException;

/**
 * Serves the comparison of one organisation's landscape today with the plan, or a scenario, on a date.
 *
 * @spec openspec/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
class LandscapeComparisonController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                              $appName       The app name.
	 * @param IRequest                            $request       The request.
	 * @param LandscapeComparisonServiceInterface $comparison    Builds the comparison.
	 * @param OrganisationReportRequest           $reportRequest The requested organisation and whether the user may read it.
	 *
	 * @spec openspec/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LandscapeComparisonServiceInterface $comparison,
		private readonly OrganisationReportRequest $reportRequest,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The comparison for `?organisation=` on `?date=`, or for `?scenario=`; refused before any read when the user may not read the organisation.
	 *
	 * @return JSONResponse The comparison, or an error.
	 *
	 * @spec openspec/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
	 * @spec openspec/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$refusal = $this->reportRequest->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->comparison->compare(
				organisationUuid: $this->reportRequest->organisation(),
				date: (string)$this->request->getParam('date', ''),
				scenarioId: (string)$this->request->getParam('scenario', '')
			));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DomainException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OutOfBoundsException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()
}//end class
