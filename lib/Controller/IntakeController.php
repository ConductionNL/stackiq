<?php

/**
 * Anonymous registration intake controller.
 *
 * The public, write-only entry point for anonymous self-service organisation
 * registrations. It accepts a single POST, hands the payload to IntakeService
 * (which validates anti-spam, strips privileged keys, and stores it as
 * `registratiestatus = pending` WITHOUT a `publicatiedatum`), and returns only
 * a queued acknowledgement — never the stored object.
 *
 * AUTH (ADR-005): `#[PublicPage]` because the submitter is unauthenticated, but
 * it is write-only to the moderation queue and exposes no read surface, so
 * there is no IDOR exposure — the pending record is invisible to anonymous
 * readers (no publicatiedatum) and only an admin can approve it. `#[AnonRateLimit]`
 * throttles the anonymous path (anti-spam); `#[NoCSRFRequired]` because an
 * anonymous client has no CSRF token.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\IntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public, write-only anonymous registration intake.
 */
class IntakeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IntakeService $intake The intake service.
	 */
	public function __construct(
		IRequest $request,
		private readonly IntakeService $intake,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Submit an anonymous organisation registration into the moderation queue.
	 *
	 * @param array<string,mixed> $organisation The registration payload.
	 *
	 * @return JSONResponse `{ok, uuid, status}` (202 Accepted) or a 400/429.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 * @spec           openspec/specs/open-data-publishing/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 3600)]
	public function submit(array $organisation = []): JSONResponse {
		$result = $this->intake->submit($organisation);
		if ($result['ok'] === false) {
			return new JSONResponse(
				data: ['message' => $result['reason']],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		// Acknowledge only — never echo the stored object back to the anonymous caller.
		return new JSONResponse(
			data: [
				'ok' => true,
				'uuid' => $result['uuid'],
				'status' => $result['status'],
				'message' => 'Registration received and queued for moderation',
			],
			statusCode: Http::STATUS_ACCEPTED
		);
	}//end submit()
}//end class
