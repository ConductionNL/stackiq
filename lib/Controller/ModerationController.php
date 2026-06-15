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
 * AUTH (ADR-005): every method is `#[NoAdminRequired]` at the routing layer but
 * enforces an explicit admin guard in the body (`IGroupManager::isAdmin`).
 * Without the guard any authenticated user could approve/publish arbitrary
 * pending registrations (privilege escalation / IDOR — OWASP A01:2021), so the
 * guard runs first on every call. The decision targets a uuid; the
 * ModerationService refuses to act on anything not currently `pending` and on
 * peer-sourced (federated) mirrors.
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-gated registration moderation queue.
 */
class ModerationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request      The request.
     * @param IUserSession      $userSession  The user session (auth guard).
     * @param IGroupManager     $groupManager Group membership (admin check).
     * @param ModerationService $moderation   The moderation service.
     * @param LoggerInterface   $logger       Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ModerationService $moderation,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the pending anonymous registrations awaiting moderation.
     *
     * @return JSONResponse `{ok, items}` or a 403.
     *
     * @NoAdminRequired
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    #[NoAdminRequired]
    public function pending(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

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
     * @return JSONResponse `{ok, status}` or a 403/400.
     *
     * @NoAdminRequired
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    #[NoAdminRequired]
    public function approve(string $uuid): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

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
     * @return JSONResponse `{ok, status}` or a 403/400.
     *
     * @NoAdminRequired
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    #[NoAdminRequired]
    public function reject(string $uuid): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $result = $this->moderation->reject($uuid);
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end reject()

    /**
     * Require the caller to be a Nextcloud admin. Returns a JSONResponse to
     * short-circuit on failure, or null when the caller is an admin.
     *
     * @return JSONResponse|null Error response, or null when authorized.
     *
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            $this->logger->warning(
                'ModerationController: moderation refused (not admin)',
                ['uid' => $user->getUID()]
            );
            return new JSONResponse(
                data: ['message' => 'Only administrators can moderate registrations'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()
}//end class
