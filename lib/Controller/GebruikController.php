<?php

/**
 * View Controller for SoftwareCatalog
 *
 * Handles HTTP requests for view-related operations including querying views
 * with enrichment options for products and usage data.
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/nextcloud/softwarecatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
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
 * Controller for handling gebruik-related API operations.
 *
 * This controller provides REST API endpoints for querying and managing gebruik objects
 * with role-based access for gebruik-beheerder and aanbod-beheerder users.
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/nextcloud/softwarecatalog
 */
class GebruikController extends Controller
{
    /**
     * Constructor for GebruikController.
     *
     * @param string         $appName        The app name
     * @param IRequest       $request        The request object
     * @param IUserSession   $userSession    The user session service
     * @param IGroupManager  $groupManager   The group manager service
     * @param IConfig        $config         The configuration service
     * @param GebruikService $gebruikService The gebruik service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IConfig $config,
        private readonly GebruikService $gebruikService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Fetch gebruiken based on user role.
     *
     * For a gebruik-beheerder, returns all gebruiken.
     * For an aanbod-beheerder, returns gebruiken of applications of the user's organization.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse The JSON response with gebruiken results
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
     */
    public function getGebruiken(): JSONResponse
    {
        $user = $this->userSession->getUser();

        // Return empty results for non-logged-in users to prevent unnecessary errors.
        if ($user === null) {
            return new JSONResponse($this->getEmptyResult());
        }

        $groups     = $this->groupManager->getUserGroups(user: $user);
        $groupNames = array_map(
                function (IGroup $group) {
                    return $group->getGID();
                },
                $groups
                );

        $orgUuid = $this->config->getUserValue(userId: $user->getUID(), appName: 'core', key: 'organisation');

        $isAdmin     = in_array(needle: 'admin', haystack: $groupNames);
        $isBeheerder = in_array(needle: 'gebruik-beheerder', haystack: $groupNames);
        $isAanbod    = in_array(needle: 'aanbod-beheerder', haystack: $groupNames);

        if ($isAdmin !== true && $isBeheerder !== true && $isAanbod !== true) {
            return new JSONResponse($this->getEmptyResult());
        }

        $options = $this->request->getParams();

        if ($isAanbod === true && $isAdmin !== true && $isBeheerder !== true) {
            $appOptions    = ['aanbieder' => $orgUuid];
            $applicatieIds = $this->gebruikService->getApplicationIds(options: $appOptions);

            if ($applicatieIds === []) {
                return new JSONResponse($this->getEmptyResult());
            }

            if (isset($options['module']) === true && in_array($options['module'], $applicatieIds) === false) {
                return new JSONResponse($this->getEmptyResult());
            }

            if (isset($options['module']) === false) {
                $options['module'] = $applicatieIds;
            }
        }

        try {
            return new JSONResponse($this->gebruikService->getGebruiken(options: $options));
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getGebruiken()

    /**
     * Fetch gebruiken for a deelnemer.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
     */
    public function getGebruikenForDeelnemer(): JSONResponse
    {
        $user = $this->userSession->getUser();

        // Return empty results for non-logged-in users to prevent unnecessary errors.
        if ($user === null) {
            return new JSONResponse($this->getEmptyResult());
        }

        $orgUuid = $this->config->getUserValue(userId: $user->getUID(), appName: 'core', key: 'organisation');

        $options = $this->request->getParams();
        $options['deelnemers'] = [$orgUuid];

        try {
            return new JSONResponse($this->gebruikService->getGebruiken(options: $options));
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getGebruikenForDeelnemer()

    /**
     * Returns an empty result set with the standard paginated response structure.
     *
     * @return array The empty result structure.
     */
    private function getEmptyResult(): array
    {
        return [
            'results' => [],
            'total'   => 0,
            'page'    => 1,
            'pages'   => 0,
            'limit'   => 1000,
            'offset'  => 0,
            'facets'  => [],
            '@self'   => [
                'source'    => 'database',
                'query'     => [],
                'rbac'      => false,
                'multi'     => false,
                'published' => false,
                'deleted'   => false,
            ],
        ];
    }//end getEmptyResult()
}//end class
