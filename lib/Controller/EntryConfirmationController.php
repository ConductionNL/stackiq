<?php

/**
 * The Confirm this entry is current action of the application and usage pages.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Kernel\Contracts\EntryConfirmationServiceInterface;
use OCA\Stackiq\Service\EntryConfirmationRefused;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Confirms an application or a usage is current.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
 */
class EntryConfirmationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                            $appName      The app name.
	 * @param IRequest                          $request      The request.
	 * @param EntryConfirmationServiceInterface $confirmation Sets lastConfirmedAt.
	 * @param IUserSession                      $userSession  The current session.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EntryConfirmationServiceInterface $confirmation,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Confirm the entry is current.
	 *
	 * @param string $kind module or usage.
	 * @param string $id   The entry's id.
	 *
	 * @return JSONResponse `{lastConfirmedAt}`, or the refusal: 401 without a session, 403 without the update right,
	 *                      404 for an entry the user cannot read.
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
	 */
	#[NoAdminRequired]
	public function confirm(string $kind, string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->confirmation->confirm(kind: $kind, id: $id));
		} catch (EntryConfirmationRefused $refusal) {
			$data = ['error' => $refusal->reason];
			if ($refusal->getMessage() !== '') {
				$data['message'] = $refusal->getMessage();
			}

			return new JSONResponse($data, $refusal->status);
		}
	}//end confirm()
}//end class
