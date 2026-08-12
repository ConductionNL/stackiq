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
 * @spec openspec/specs/method-decomposition/spec.md
 */

namespace OCA\SoftwareCatalog\Controller;

use Exception;
use OCA\SoftwareCatalog\Service\GebruikService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/nextcloud/softwarecatalog
 *
 * @spec openspec/changes/vendor-visibility-rbac/tasks.md#task-2
 */
class GebruikController extends Controller {
	/**
	 * Constructor for GebruikController.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param IUserSession $userSession The user session service
	 * @param IGroupManager $groupManager The group manager service
	 * @param IConfig $config The configuration service
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
	 * Fetch gebruiken based on user role, per the vendor-visibility-rbac
	 * visibility matrix (see `applyAanbodScopeToOptions()`).
	 *
	 * For `admin`/`ambtenaar`, returns all gebruiken (unrestricted bypass).
	 * For a `gebruik-beheerder`, returns only gebruiken where the caller's
	 * own organisation is the afnemer — NOT every organisation's gebruiken;
	 * that was a cross-organisation leak closed by this capability
	 * (discovery.md finding 2).
	 * For an `aanbod-beheerder`, returns gebruiken of applications of the
	 * user's organization.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return JSONResponse The JSON response with gebruiken results
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 * @spec openspec/specs/open-data-publishing/spec.md
	 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-every-rbac-bypassing-gebruik-koppeling-contract-read-must-evaluate-its-deny-check-before-issuing-the-bypass-query-req-001
	 */
	public function getGebruiken(): JSONResponse {
		// Open-data posture (open-data-publishing): gebruik is inherently
		// organisation-scoped, so an anonymous caller receives the documented
		// empty-result envelope — never RBAC-scoped internal data.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse($this->getEmptyResult());
		}

		$roles = $this->resolveUserRoles(user: $user);
		if ($roles['hasAccess'] === false) {
			return new JSONResponse($this->getEmptyResult());
		}

		$options = $this->request->getParams();

		$scoped = $this->applyAanbodScopeToOptions(roles: $roles, options: $options);
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
	 * @return array{isAdmin:bool,isBeheerder:bool,isAanbod:bool,isAmbtenaar:bool,hasAccess:bool,orgUuid:string}
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
	 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-beheerder-reads-of-gebruik-objects-must-be-scoped-to-the-caller-s-own-organisation-req-003
	 */
	private function resolveUserRoles(\OCP\IUser $user): array {
		$groups = $this->groupManager->getUserGroups(user: $user);
		$groupNames = array_map(
			function (IGroup $group) {
				return $group->getGID();
			},
			$groups
		);

		$orgUuid = (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

		$isAdmin = in_array(needle: 'admin', haystack: $groupNames);
		$isBeheerder = in_array(needle: 'gebruik-beheerder', haystack: $groupNames);
		$isAanbod = in_array(needle: 'aanbod-beheerder', haystack: $groupNames);
		// `ambtenaar` is the same orthogonal "sees everything" bypass group
		// used elsewhere in this codebase (AangebodenGebruikController::
		// isUserInGroup('ambtenaar')). It was missing from this resolver —
		// without it an ambtenaar who is not ALSO admin/gebruik-beheerder/
		// aanbod-beheerder failed hasAccess entirely (REQ-003 regression
		// scenario: "ambtenaar retains the existing unrestricted read").
		$isAmbtenaar = in_array(needle: 'ambtenaar', haystack: $groupNames);

		return [
			'isAdmin' => $isAdmin,
			'isBeheerder' => $isBeheerder,
			'isAanbod' => $isAanbod,
			'isAmbtenaar' => $isAmbtenaar,
			'hasAccess' => (
				$isAdmin === true
				|| $isBeheerder === true
				|| $isAanbod === true
				|| $isAmbtenaar === true
			),
			'orgUuid' => $orgUuid,
		];

	}//end resolveUserRoles()

	/**
	 * Apply organisation-scoping to query options, per the vendor-visibility-
	 * rbac visibility matrix.
	 *
	 * Deny-before-grant ordering (REQ-001): every branch below resolves the
	 * caller's role + relationship and either returns unchanged options
	 * (full read, admin/ambtenaar only), a narrowed options array (scoped
	 * read), or null ("render the empty result") — BEFORE
	 * `GebruikService::getGebruiken()` ever issues its `_rbac:false` /
	 * `_multitenancy:false` bypass query. No branch here can fall through to
	 * an unscoped query for a non-admin, non-ambtenaar caller.
	 *
	 * - `admin` / `ambtenaar`: unrestricted read (existing bypass, unchanged).
	 * - `aanbod-beheerder` (vendor, REQ-002): scoped to the organisation's own
	 *   offered applications (`module` IN the vendor's own applicatie ids) —
	 *   existing, unchanged behaviour, now regression-tested.
	 * - `gebruik-beheerder` (municipality/samenwerking, REQ-003): scoped to
	 *   the organisation's own `afnemer` relationship. Closes
	 *   `discovery.md` finding 2 — this branch did not exist before this
	 *   change, so every `gebruik-beheerder` fell through to the
	 *   `return $options` no-op below and received every organisation's
	 *   gebruik data.
	 *
	 * Extracted from `getGebruiken()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.3; extended by
	 * `vendor-visibility-rbac`.
	 *
	 * @param array{isAdmin:bool,isBeheerder:bool,isAanbod:bool,isAmbtenaar?:bool,orgUuid:string} $roles Role flags.
	 * @param array<string,mixed> $options Current request params.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-3
	 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-aanbod-beheerder-vendor-reads-of-gebruik-koppeling-objects-must-be-scoped-to-the-vendor-s-own-offered-products-req-002
	 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-beheerder-reads-of-gebruik-objects-must-be-scoped-to-the-caller-s-own-organisation-req-003
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 12 (threshold 10). This method IS
	 * the RBAC decision table: one explicit branch per caller role (admin/ambtenaar bypass,
	 * aanbod-beheerder vendor scope, gebruik-beheerder organisation scope) plus the empty-scope
	 * guards that make each branch fail closed. Splitting the table across helpers would move the
	 * branches out of sight of the reader auditing that no role falls through to an unscoped
	 * query — which is exactly the bug (`discovery.md` finding 2) this method was written to fix.
	 */
	private function applyAanbodScopeToOptions(array $roles, array $options): ?array {
		$isAmbtenaar = $roles['isAmbtenaar'] ?? false;

		// Admin / ambtenaar: unrestricted read (unchanged, existing bypass).
		if ($roles['isAdmin'] === true || $isAmbtenaar === true) {
			return $options;
		}

		// Aanbod-beheerder (vendor, REQ-002): scope to the vendor's own
		// offered applications. Unchanged from pre-existing behaviour.
		if ($roles['isAanbod'] === true && $roles['isBeheerder'] !== true) {
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
		}

		// Gebruik-beheerder (municipality/samenwerking, REQ-003): scope to
		// the caller's own organisation's applicatielandschap via the
		// `afnemer` relationship — the same field that defines "this
		// gebruik record is used by my organisation" throughout the rest of
		// this codebase (AangebodenGebruikService::getGebruiksWhereAfnemer).
		// Deny (return null) rather than silently widening if the caller
		// already asked for a different organisation's afnemer filter.
		if ($roles['isBeheerder'] === true) {
			if (isset($options['afnemer']) === true && $options['afnemer'] !== $roles['orgUuid']) {
				return null;
			}

			$options['afnemer'] = $roles['orgUuid'];

			return $options;
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
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getGebruikenForDeelnemer(): JSONResponse {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$orgUuid = $this->config->getUserValue(userId: $user->getUID(), appName: 'core', key: 'organisation');

		// Fail CLOSED on a missing organisation, as the app's canonical
		// deelnemers-scoped read already does in
		// AangebodenGebruikService::getGebruiksWhereDeelnemers(). getUserValue()
		// returns '' rather than null when the user-value was never set, so
		// without this the scope below degrades to `deelnemers: ['']` — and the
		// query runs with `_rbac: false, _multitenancy: false`, so an empty
		// predicate that OpenRegister chooses to ignore would return every
		// organisation's gebruik data rather than none.
		if ($orgUuid === '') {
			return new JSONResponse(
				['message' => 'No organisation is set for this account'],
				Http::STATUS_FORBIDDEN
			);
		}

		// The scope is forced AFTER getParams(), so a caller cannot supply or
		// override `deelnemers` and read another organisation's usage.
		//
		// ⚠️ OPEN, and deliberately not "fixed" by guessing: this passes an
		// ARRAY where the canonical sibling passes a SCALAR, and whether
		// OpenRegister honours array-containment matching on a `related-object`
		// array property is unverified. If it silently ignores the array form
		// this scope is vacuous. It could not be settled here — the instance
		// available has zero gebruik rows, so a live A/B would have returned
		// empty under both forms and proved nothing. Filed rather than changed:
		// swapping the filter form on a working endpoint could equally break
		// it, and an unmeasured change to a scope is not a fix.
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
	private function getEmptyResult(): array {
		return [
			'results' => [],
			'total' => 0,
			'page' => 1,
			'pages' => 0,
			'limit' => 1000,
			'offset' => 0,
			'facets' => [],
			'@self' => [
				'source' => 'database',
				'query' => [],
				'rbac' => false,
				'multi' => false,
				'published' => false,
				'deleted' => false,
			],
		];
	}//end getEmptyResult()
}//end class
