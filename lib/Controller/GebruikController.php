<?php

/**
 * View Controller for SoftwareCatalog
 *
 * Handles HTTP requests for view-related operations including querying views
 * with enrichment options for products and usage data.
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   SoftwareCatalog Team
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */

namespace OCA\SoftwareCatalog\Controller;

use Exception;
use OCA\SoftwareCatalog\Service\GebruikService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for handling view-related API operations
 *
 * This controller provides REST API endpoints for querying and managing ArchiMate views
 * with optional enrichment capabilities for products, usage data (gebruik), and related information.
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   SoftwareCatalog Team
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */
class GebruikController extends Controller
{
    /**
     * Constructor for ViewController
     *
     * @param string $appName The app name
     * @param IRequest $request The request object
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IConfig $config,
        private readonly GebruikService $gebruikService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Fetch gebruiken, for a gebruik-beheerder, get all gebruiken, for an aanbod-beheerder, fetch gebruiken of applications of the organization of the user.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function getGebruiken(): JSONResponse
    {
        $user = $this->userSession->getUser();

        $groups = $this->groupManager->getUserGroups(user: $user);
        $groupNames = array_map(function (IGroup $group) {
            return $group->getGID();
        }, $groups);

        $orgUuid = $this->config->getUserValue(userId: $user->getUID(), appName: 'core', key: 'organisation');

        if (in_array(needle: 'admin', haystack: $groupNames) === true || in_array(needle: 'gebruik-beheerder', haystack: $groupNames) === true) {
            $options = $this->request->getParams();
        } else if (in_array(needle: 'aanbod-beheerder', haystack: $groupNames) === true) {
            $options = $this->request->getParams();
            $applicatieOptions['aanbieder'] = $orgUuid;
            $applicatieIds = $this->gebruikService->getApplicationIds(options: $applicatieOptions);

            if ($applicatieIds === []) {
                return new JSONResponse(data: ['error' => 'no access'], statusCode: 403);
            }

            if (isset($options['module']) === true && in_array($options['module'], $applicatieIds) === false) {
                return new JSONResponse(data: ['error' => 'no access'], statusCode: 403);
            } else if (isset($options['module']) === false) {
                $options['module'] = $applicatieIds;
            }

        } else {
            return new JSONResponse(data: ['error' => 'no access'], statusCode: 403);
        }

        try {
            return new JSONResponse($this->gebruikService->getGebruiken(options: $options));
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
        }
    }

    /**
     * Fetch gebruiken for a deelnemer.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function getGebruikenForDeelnemer(): JSONResponse
    {
        $user = $this->userSession->getUser();
        $orgUuid = $this->config->getUserValue(userId: $user->getUID(), appName: 'core', key: 'organisation');

        $options = $this->request->getParams();
        $options['deelnemers'] = [$orgUuid];

        try {
            return new JSONResponse($this->gebruikService->getGebruiken(options: $options));
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
        }
    }



}
