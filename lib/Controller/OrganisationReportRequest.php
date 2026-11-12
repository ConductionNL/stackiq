<?php

/**
 * The organisation a report request is for, and whether the caller may read it.
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

use OCA\Stackiq\Service\OrganisationReportAccess;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Gates a per-organisation report on `?organisation=` and the caller's right to read it.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
 */
final class OrganisationReportRequest {
	/**
	 * The requested organisation, read by refusal().
	 *
	 * @var string
	 */
	private string $organisation = '';

	/**
	 * Constructor.
	 *
	 * @param IUserSession             $userSession The user session.
	 * @param IRequest                 $request     The request carrying `?organisation=`.
	 * @param OrganisationReportAccess $access      Who may read which organisation's report.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly OrganisationReportAccess $access,
	) {
	}//end __construct()

	/**
	 * The refusal of the request: 401 without a user, 400 without an organisation,
	 * 403 when the user may not read it; null when the report may be built.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
	 */
	public function refusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->organisation = (string)$this->request->getParam('organisation', '');
		if ($this->organisation === '') {
			return new JSONResponse(['message' => 'organisation is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->access->mayRead(user: $user, organisationUuid: $this->organisation) === false) {
			// Fail closed: denied BEFORE any report query is built.
			return new JSONResponse(['message' => 'Not authorised for this organisation'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()

	/**
	 * The requested organisation; empty until refusal() has run.
	 *
	 * @return string The organisation uuid.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
	 */
	public function organisation(): string {
		return $this->organisation;
	}//end organisation()
}//end class
