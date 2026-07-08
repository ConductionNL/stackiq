<?php
/**
 * Dashboard Controller for SoftwareCatalog.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\Http\ContentSecurityPolicy;

class DashboardController extends Controller
{
    /**
     * Constructor for DashboardController.
     *
     * @param string       $appName     The app name
     * @param IRequest     $request     The request object
     * @param IUserSession $userSession The user session
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Renders the main application page.
     *
     * @param string|null $getParameter Optional query parameter
     *
     * @return TemplateResponse The rendered page template
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/changes/retrofit-2026-05-26-dashboard-views-api/tasks.md#task-1
     */
    public function page(?string $getParameter): TemplateResponse
    {
        try {
            $response = new TemplateResponse(
                $this->appName,
                'index',
                []
            );

            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain('*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            return new TemplateResponse(
                $this->appName,
                'error',
                ['error' => $e->getMessage()],
                '500'
            );
        }
    }//end page()

    /**
     * Returns an empty JSON result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The JSON response with empty results
     * @spec   openspec/changes/retrofit-2026-05-26-dashboard-views-api/tasks.md#task-1
     */
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $results = ['results' => []];
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end index()
}//end class
