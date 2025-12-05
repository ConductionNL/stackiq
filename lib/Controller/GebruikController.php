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

    public function getGebruiken(): JSONResponse
    {
        $user = $this->userSession->getUser();

        $groups = $this->groupManager->getUserGroups($user);
        $groupNames = array_map(function (IGroup $group) {
            return $group->getGID();
        }, $groups);

        $orgUuid = $this->config->getUserValue(userId: $user->getUID(), appname: 'core', key: 'organisation');

        if(in_array(needle: 'admin', haystack: $groupNames) === true|| in_array(needle: 'gebruik-beheerder', haystack: $groupNames) === true) {
            $options = $this->request->getParams();
        } else if (in_array(needle: 'aanbod-beheerder', haystack: $groupNames) === true) {
            $options = $this->request->getParams();
            $applicatieConfig['aanbieder'] = $orgUuid;
            $applicatieIds = $this->gebruikService->getApplicationIds($applicatieConfig);

            $options['module'] = ['or' => $applicatieIds];
        } else {
            return new JSONResponse(data: ['error' => 'no access'], statusCode: 403);
        }

        try {
            return new JSONResponse($this->gebruikService->getGebruiken($options));
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
        }
    }
}
