<?php

/**
 * View Controller for SoftwareCatalog
 *
 * Handles HTTP requests for view-related operations including querying views
 * with enrichment options for products and usage data.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/nextcloud/softwarecatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
 */

namespace OCA\SoftwareCatalog\Controller;

use Exception;
use OCA\SoftwareCatalog\Service\GebruikService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
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
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/nextcloud/softwarecatalog
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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse The JSON response with gebruiken results
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-10
     * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
     */
    public function getGebruiken(): JSONResponse
    {
        // Open-data posture (open-data-publishing): gebruik is inherently
        // organisation-scoped, so an anonymous caller receives the documented
        // empty-result envelope — never RBAC-scoped internal data.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse($this->getEmptyResult());
        }

        $roles = $this->resolveUserRoles($user);
        if ($roles['hasAccess'] === false) {
            return new JSONResponse($this->getEmptyResult());
        }

        $options = $this->request->getParams();

        $scoped = $this->applyAanbodScopeToOptions($roles, $options);
        if ($scoped === null) {
            return new JSONResponse($this->getEmptyResult());
        }

        try {
            return new JSONResponse($this->gebruikService->getGebruiken(options: $scoped));
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getGebruiken()

    /**
     * Resolve the calling user's role flags + organisation UUID.
     *
     * Extracted from `getGebruiken()` per
     * `openspec/changes/method-decomposition/tasks.md` task 9.3 — collapses
     * the group-membership lookup + role flag computation into one helper.
     *
     * @param \OCP\IUser $user The authenticated user.
     *
     * @return array{isAdmin:bool,isBeheerder:bool,isAanbod:bool,hasAccess:bool,orgUuid:string}
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
     */
    private function resolveUserRoles(\OCP\IUser $user): array
    {
        $groups     = $this->groupManager->getUserGroups(user: $user);
        $groupNames = array_map(
            function (IGroup $group) {
                return $group->getGID();
            },
            $groups
        );

        $orgUuid = (string) $this->config->getUserValue(
            userId: $user->getUID(),
            appName: 'core',
            key: 'organisation'
        );

        $isAdmin     = in_array(needle: 'admin', haystack: $groupNames);
        $isBeheerder = in_array(needle: 'gebruik-beheerder', haystack: $groupNames);
        $isAanbod    = in_array(needle: 'aanbod-beheerder', haystack: $groupNames);

        return [
            'isAdmin'     => $isAdmin,
            'isBeheerder' => $isBeheerder,
            'isAanbod'    => $isAanbod,
            'hasAccess'   => ($isAdmin === true || $isBeheerder === true || $isAanbod === true),
            'orgUuid'     => $orgUuid,
        ];

    }//end resolveUserRoles()

    /**
     * Apply aanbod-beheerder scoping to query options.
     *
     * For an aanbod-beheerder that is neither admin nor gebruik-beheerder,
     * restrict the visible gebruiken to the organisation's applicaties.
     * Returns the (possibly augmented) options array, or null when the user
     * is asking for a module they cannot see — the caller treats null as
     * "render empty result".
     *
     * Extracted from `getGebruiken()` per
     * `openspec/changes/method-decomposition/tasks.md` task 9.3.
     *
     * @param array{isAdmin:bool,isBeheerder:bool,isAanbod:bool,orgUuid:string} $roles   Role flags.
     * @param array<string,mixed>                                                $options Current request params.
     *
     * @return array<string,mixed>|null
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
     */
    private function applyAanbodScopeToOptions(array $roles, array $options): ?array
    {
        if ($roles['isAanbod'] !== true || $roles['isAdmin'] === true || $roles['isBeheerder'] === true) {
            return $options;
        }

        $applicatieIds = $this->gebruikService->getApplicationIds(
            options: ['aanbieder' => $roles['orgUuid']]
        );

        if ($applicatieIds === []) {
            return null;
        }

        if (isset($options['module']) === true && in_array($options['module'], $applicatieIds) === false) {
            return null;
        }

        if (isset($options['module']) === false) {
            $options['module'] = $applicatieIds;
        }

        return $options;

    }//end applyAanbodScopeToOptions()

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

        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
