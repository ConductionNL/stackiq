<?php
/**
 * Federation admin controller.
 *
 * Admin-only endpoints for the federation settings UI: read the federation
 * status (availability, directory, per-peer failure/stale state), add or remove
 * a federation peer (subject to the SSRF allowlist), and trigger a manual pull
 * of all configured peers. All write/read paths delegate to FederationService;
 * no bespoke federation logic lives here.
 *
 * AUTH (ADR-005): every method is `#[AuthorizedAdminSetting(SoftwareCatalogAdmin::class)]`
 * — Nextcloud's admin-settings middleware rejects any non-admin caller before
 * the controller body runs (matching the moderation controller's posture and the
 * service's admin-only intent), so an authenticated non-admin can never reach
 * the peer-mutation or manual-pull paths (no privilege escalation / IDOR —
 * OWASP A01:2021).
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\Federation\FederationService;
use OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-gated federation settings + manual-pull surface.
 */
class FederationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request    The request.
     * @param FederationService $federation The federation service.
     */
    public function __construct(
        IRequest $request,
        private readonly FederationService $federation,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Federation status: availability, directory URL, and per-peer state.
     *
     * @return JSONResponse `{available, enabled, directoryUrl, peers, staleAfter, message}`.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function status(): JSONResponse
    {
        return new JSONResponse(data: $this->federation->getStatus());
    }//end status()

    /**
     * Add a federation peer (subject to the SSRF allowlist).
     *
     * @param string $peerUrl The peer base URL.
     *
     * @return JSONResponse `{ok, reason}` or a 400 when the peer is rejected.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function addPeer(string $peerUrl=''): JSONResponse
    {
        $result = $this->federation->addPeer($peerUrl);
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end addPeer()

    /**
     * Remove a federation peer and clear its failure streak.
     *
     * @param string $peerUrl The peer base URL.
     *
     * @return JSONResponse `{ok, reason}` or a 400 when the peer is unknown.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function removePeer(string $peerUrl=''): JSONResponse
    {
        $result = $this->federation->removePeer($peerUrl);
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end removePeer()

    /**
     * Trigger a manual pull of all configured peers.
     *
     * @return JSONResponse `{ok, reason, peers}` or a 400 when federation is off/unavailable.
     *
     * @AuthorizedAdminSetting(settings=OCA\SoftwareCatalog\Settings\SoftwareCatalogAdmin)
     * @spec                                                                               openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
     */
    #[AuthorizedAdminSetting(settings: SoftwareCatalogAdmin::class)]
    public function pull(): JSONResponse
    {
        $result = $this->federation->pullAllPeers();
        if ($result['ok'] === false) {
            return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $result);
    }//end pull()
}//end class
