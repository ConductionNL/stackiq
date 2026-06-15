<?php
/**
 * Registration moderation / approval-queue controller.
 *
 * Admin-only endpoints to review the anonymous-registration moderation queue
 * and approve/reject each entry. Approve sets `registratiestatus = active` AND
 * `publicatiedatum = now` (making the entry anonymously visible via the same
 * RBAC gate as open-data publish); reject sets `registratiestatus = rejected`
 * and leaves it unpublished.
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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
 * Admin-gated registration moderation queue.
 */
class ModerationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request    The request.
     * @param ModerationService $moderation The moderation service.
     */
    public function __construct(
        IRequest $request,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the pending anonymous registrations awaiting moderation.
     *
     * @return JSONResponse `{ok, items}` or a 400.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function pending(): JSONResponse
    {
        $result = $this->moderation->listPending();
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end pending()

    /**
     * Approve a pending registration (active + publish).
     *
     * @param string $uuid The registration uuid.
     *
     * @return JSONResponse `{ok, status}` or a 400.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function approve(string $uuid): JSONResponse
    {
        $result = $this->moderation->approve($uuid);
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end approve()

    /**
     * Reject a pending registration.
     *
     * @param string $uuid The registration uuid.
     *
     * @return JSONResponse `{ok, status}` or a 400.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function reject(string $uuid): JSONResponse
    {
        $result = $this->moderation->reject($uuid);
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end reject()
}//end class
