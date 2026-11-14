<?php

/**
 * Entry type controller
 *
 * Preview and perform the type change of a catalogue entry. Any signed-in
 * user may ask; EntryTypeService reads the entry with the caller's own rights
 * and refuses the change unless the caller may update the entry and create in
 * the target schema.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Kernel\AppIdentity;
use OCA\Stackiq\Service\EntryType\EntryTypeRefusal;
use OCA\Stackiq\Service\EntryTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The type-change endpoints of a catalogue entry.
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
 */
class EntryTypeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The caller.
	 * @param EntryTypeService $entryTypes The type change.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EntryTypeService $entryTypes,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * What a change to `target` keeps, drops and is blocked by. Writes nothing.
	 *
	 * @param string $uuid The entry.
	 *
	 * @return JSONResponse The preview, or 401/404/422.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
	 */
	#[NoAdminRequired]
	public function preview(string $uuid): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->entryTypes->preview(uuid: $uuid, targetType: (string)$this->request->getParam('target', '')));
		} catch (EntryTypeRefusal $refusal) {
			return self::refused(refusal: $refusal);
		}

	}//end preview()

	/**
	 * Change the entry into `target`; `serviceTypes` is required for a service.
	 *
	 * @param string $uuid The entry.
	 *
	 * @return JSONResponse The outcome, or 401/403/404/409/422.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
	 */
	#[NoAdminRequired]
	public function change(string $uuid): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$serviceTypes = $this->request->getParam('serviceTypes', []);
		if (is_array($serviceTypes) === false) {
			$serviceTypes = [$serviceTypes];
		}

		try {
			return new JSONResponse(
				data: $this->entryTypes->change(
					uuid: $uuid,
					targetType: (string)$this->request->getParam('target', ''),
					serviceTypes: array_values($serviceTypes)
				)
			);
		} catch (EntryTypeRefusal $refusal) {
			return self::refused(refusal: $refusal);
		}

	}//end change()

	/**
	 * The answer to a refused change.
	 *
	 * @param EntryTypeRefusal $refusal The refusal.
	 *
	 * @return JSONResponse
	 */
	private static function refused(EntryTypeRefusal $refusal): JSONResponse {
		$data = ['message' => $refusal->getMessage()];
		if ($refusal->blockers !== []) {
			$data['blockers'] = $refusal->blockers;
		}

		return new JSONResponse(data: $data, statusCode: $refusal->status);

	}//end refused()
}//end class
