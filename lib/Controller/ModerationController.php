<?php

/**
 * Registration / review moderation / approval-queue controller.
 *
 * Admin-only endpoints to review the moderation queue and approve/reject each
 * entry, for either moderated type selected by the `type` query parameter
 * (`organisatie`, the default, or `beoordeeling` — see `ModerationService`).
 * For `organisatie`, approve sets `registratiestatus = active` AND
 * `publicatiedatum = now` (making the entry anonymously visible via the same
 * RBAC gate as open-data publish); reject sets `registratiestatus = rejected`
 * and leaves it unpublished. For `beoordeeling`, approve/reject set `status`
 * to `approved`/`rejected` — the schema's own `status`-conditioned public
 * RBAC rule does the visibility job `publicatiedatum` does for organisatie.
 *
 * AUTH (ADR-005): every method is `#[AuthorizedAdminSetting(SoftwareCatalogAdmin::class)]`
 * — Nextcloud's admin-settings middleware rejects any non-admin caller before
 * the controller body runs, so an authenticated non-admin can never reach the
 * approve/publish path (no privilege escalation / IDOR — OWASP A01:2021). The
 * decision targets a uuid; the ModerationService refuses to act on anything not
 * currently `pending` and on peer-sourced (federated) mirrors.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\ModerationService;
use OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-gated registration / review moderation queue.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ModerationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ModerationService $moderation The moderation service.
	 */
	public function __construct(
		IRequest $request,
		private readonly ModerationService $moderation,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the pending entries (of `type`) awaiting moderation.
	 *
	 * @param string $type The moderated object type ('organisatie', default, or 'beoordeeling').
	 *
	 * @return JSONResponse `{ok, items}` or a 400.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
	 * @spec                                                                               openspec/specs/open-data-publishing/spec.md
	 * @spec                                                                               openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
	 */
	#[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
	public function pending(string $type = ModerationService::MODERATED_TYPE): JSONResponse {
		$result = $this->moderation->listPending(type: $type);
		if ($result['ok'] === false) {
			return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end pending()

	/**
	 * Approve a pending entry (of `type`).
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type ('organisatie', default, or 'beoordeeling').
	 *
	 * @return JSONResponse `{ok, status}` or a 400.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
	 * @spec                                                                               openspec/specs/open-data-publishing/spec.md
	 * @spec                                                                               openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	#[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
	public function approve(string $uuid, string $type = ModerationService::MODERATED_TYPE): JSONResponse {
		$result = $this->moderation->approve($uuid, type: $type);
		if ($result['ok'] === false) {
			return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end approve()

	/**
	 * Reject a pending entry (of `type`).
	 *
	 * @param string $uuid The entry uuid.
	 * @param string $type The moderated object type ('organisatie', default, or 'beoordeeling').
	 *
	 * @return JSONResponse `{ok, status}` or a 400.
	 *
	 * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
	 * @spec                                                                               openspec/specs/open-data-publishing/spec.md
	 * @spec                                                                               openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public
	 */
	#[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
	public function reject(string $uuid, string $type = ModerationService::MODERATED_TYPE): JSONResponse {
		$result = $this->moderation->reject($uuid, type: $type);
		if ($result['ok'] === false) {
			return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end reject()
}//end class
