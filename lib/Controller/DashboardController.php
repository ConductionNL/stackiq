<?php
/**
 * Dashboard Controller for SoftwareCatalog.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

namespace OCA\SoftwareCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\ContentSecurityPolicy;

class DashboardController extends Controller
{
    /**
     * Constructor for DashboardController.
     *
     * @param string   $appName The app name
     * @param IRequest $request The request object
     */
    public function __construct($appName, IRequest $request)
    {
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
     */
    public function index(): JSONResponse
    {
        try {
            $results = ['results' => []];
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end index()
}//end class
