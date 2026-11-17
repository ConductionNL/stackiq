<?php

/**
 * View Document Controller for Stackiq
 *
 * Asks filinq for a Word document or a PowerPoint slide of an architecture
 * view (architecture-views-to-office-documents). Kept apart from the view
 * listing and drawing routes of ViewController.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use InvalidArgumentException;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCA\Stackiq\Service\ViewDocumentContent;
use OCA\Stackiq\Service\ViewDocumentGateway;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The view document route, POST /api/views/{viewId}/document.
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
class ViewDocumentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName         The app name.
	 * @param IRequest             $request         The request object.
	 * @param ViewServiceInterface $viewService     Reads the stored view with OpenRegister's RBAC on.
	 * @param IUserSession         $userSession     The user session service.
	 * @param ViewDocumentGateway  $documents       Asks filinq for a document of a view; it logs a refusal.
	 * @param ViewDocumentContent  $documentContent Builds the content of that document.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ViewServiceInterface $viewService,
		private readonly IUserSession $userSession,
		private readonly ViewDocumentGateway $documents,
		private readonly ViewDocumentContent $documentContent,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Ask filinq for a Word document or a PowerPoint slide of the view.
	 *
	 * API Endpoint: POST /api/views/{viewId}/document, body `{format: "docx"|"pptx"}`
	 *
	 * The availability of filinq's document contract is checked before the
	 * view is read, so a request that cannot be carried out writes nothing.
	 * The view is read with OpenRegister's RBAC on, like the image route.
	 *
	 * @param string $viewId The view identifier.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse 201 with the Files path, file id and name; 400 for another format, 401 without a user,
	 *                      404 when the view is not there for the caller, 502 when filinq refuses, 503 without filinq's contract.
	 *
	 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
	 */
	#[NoAdminRequired]
	public function createViewDocument(string $viewId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$format = (string)$this->request->getParam('format', '');
		if (in_array($format, ViewDocumentGateway::FORMATS, true) === false) {
			return new JSONResponse(['success' => false, 'error' => 'Choose the format docx or pptx.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->documents->isAvailable() === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Word and PowerPoint need the document app filinq.'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$view = $this->viewService->findStoredView(viewId: $viewId);
		if ($view === null) {
			return new JSONResponse(['success' => false, 'error' => 'View not found'], Http::STATUS_NOT_FOUND);
		}

		$content = $this->documentContent->contentFor(view: $view, viewId: $viewId);
		try {
			$document = $this->documents->requestDocument(format: $format, content: $content, userId: $user->getUID());
		} catch (InvalidArgumentException | RuntimeException) {
			return new JSONResponse(['success' => false, 'error' => 'filinq could not make the document.'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['success' => true] + $document, Http::STATUS_CREATED);
	}//end createViewDocument()
}//end class
