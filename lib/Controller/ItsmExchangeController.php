<?php

/**
 * Service desk exchange endpoints: status, set-up and the file import.
 *
 * AUTH (ADR-005): set-up and import are `#[AuthorizedAdminSetting(StackiqAdmin::class)]`,
 * so only a Nextcloud admin or a delegated stackiq admin reaches them. The
 * status is `#[NoAdminRequired]` because the CMDB page shows every signed-in
 * user whether the exchange runs; it returns no flow ids, no source and no
 * organisation, and takes no object id.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\ItsmExchangeService;
use OCA\Stackiq\Service\ItsmFileImportService;
use OCA\Stackiq\Settings\StackiqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin set-up and import, and a public-to-users status, for the service desk exchange.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
class ItsmExchangeController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request      The request.
	 * @param ItsmExchangeService   $exchange     Sets up the flows.
	 * @param ItsmFileImportService $fileImport   Imports a spreadsheet.
	 * @param IUserSession          $userSession  The signed-in user, who the imports run as.
	 */
	public function __construct(
		IRequest $request,
		private readonly ItsmExchangeService $exchange,
		private readonly ItsmFileImportService $fileImport,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the exchange runs, and with which desk.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The summary.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		$status = $this->exchange->status();

		return new JSONResponse(
			[
				'available' => $status['available'],
				'enabled'   => $status['enabled'],
				'desk'      => $status['desk'],
				'setUpAt'   => $status['setUpAt'],
				'desks'     => $status['desks'],
			]
		);
	}//end status()

	/**
	 * The full set-up, for the admin section.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Stackiq\Settings\StackiqAdmin)
	 *
	 * @return JSONResponse The set-up.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	#[AuthorizedAdminSetting(settings: StackiqAdmin::class)]
	public function config(): JSONResponse {
		return new JSONResponse($this->exchange->status());
	}//end config()

	/**
	 * Set up, or set up again, the exchange.
	 *
	 * @param string $desk         The desk key.
	 * @param string $organisation The organisation uuid.
	 * @param string $templateId   The desk's asset template for new records (TOPdesk), or empty.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Stackiq\Settings\StackiqAdmin)
	 *
	 * @return JSONResponse The outcome; 422 when nothing was created.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
	 */
	#[AuthorizedAdminSetting(settings: StackiqAdmin::class)]
	public function setUp(string $desk = '', string $organisation = '', string $templateId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['created' => false, 'message' => 'Sign in first.'], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->exchange->setUp(desk: $desk, organisation: $organisation, runAs: $user->getUID(), templateId: trim($templateId));
		if ($result['created'] !== true) {
			return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result);
	}//end setUp()

	/**
	 * Import a CSV or XLSX file.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Stackiq\Settings\StackiqAdmin)
	 *
	 * @return JSONResponse The outcome; 422 when nothing started.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	#[AuthorizedAdminSetting(settings: StackiqAdmin::class)]
	public function import(): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		if (is_array($file) === false || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || is_string($file['tmp_name'] ?? null) === false) {
			return new JSONResponse(['started' => false, 'message' => 'Choose a .csv or .xlsx file to import.'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->fileImport->import(path: $file['tmp_name'], name: (string) ($file['name'] ?? ''));
		if ($result['started'] !== true) {
			return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result);
	}//end import()
}//end class
