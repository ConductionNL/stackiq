<?php

/**
 * View Controller for Stackiq
 *
 * Handles HTTP requests for view-related operations including querying views
 * with enrichment options for products and usage data.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

namespace OCA\Stackiq\Controller;

use Exception;
use OCA\Stackiq\Service\GebruikService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
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
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getGebruiken(): JSONResponse {
		$scoped = $this->scopedGebruikOptions();
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
	 * The request options scoped to what the calling user may read, or null
	 * when the caller gets the empty result (anonymous, no role, or a scope
	 * that resolves to nothing).
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/vendor-visibility-rbac/spec.md#requirement-every-rbac-bypassing-gebruik-koppeling-contract-read-must-evaluate-its-deny-check-before-issuing-the-bypass-query-req-001
	 */
	private function scopedGebruikOptions(): ?array {
		// Open-data posture (open-data-publishing): gebruik is inherently
		// organisation-scoped, so an anonymous caller receives the documented
		// empty-result envelope — never RBAC-scoped internal data.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$roles = $this->resolveUserRoles(user: $user);
		if ($roles['hasAccess'] === false) {
			return null;
		}

		$options = $this->request->getParams();

		return $this->applyAanbodScopeToOptions(roles: $roles, options: $options);
	}//end scopedGebruikOptions()

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
		$flags   = $this->roleFlags(groupNames: $this->groupNamesOf(user: $user));
		$orgUuid = $this->organisationOf(user: $user);

		return $flags + [
			'hasAccess' => self::hasAnyRole(flags: $flags),
			'orgUuid' => $orgUuid,
		];

	}//end resolveUserRoles()

	/**
	 * The ids of the groups the user is a member of.
	 *
	 * @param \OCP\IUser $user The authenticated user.
	 *
	 * @return array<int,string>
	 */
	private function groupNamesOf(\OCP\IUser $user): array {
		$groups = $this->groupManager->getUserGroups(user: $user);
		return array_map(
			function (IGroup $group) {
				return $group->getGID();
			},
			$groups
		);

	}//end groupNamesOf()

	/**
	 * The organisation UUID stored on the user's account ('' when unset).
	 *
	 * @param \OCP\IUser $user The authenticated user.
	 *
	 * @return string
	 */
	private function organisationOf(\OCP\IUser $user): string {
		return (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

	}//end organisationOf()

	/**
	 * The role flags the group ids grant.
	 *
	 * @param array<int,string> $groupNames The user's group ids.
	 *
	 * @return array{isAdmin:bool,isBeheerder:bool,isAanbod:bool,isAmbtenaar:bool}
	 */
	private function roleFlags(array $groupNames): array {
		$isAdmin = in_array(needle: 'admin', haystack: $groupNames);
		$isMaintainer = in_array(needle: 'gebruik-beheerder', haystack: $groupNames);
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
			'isBeheerder' => $isMaintainer,
			'isAanbod' => $isAanbod,
			'isAmbtenaar' => $isAmbtenaar,
		];

	}//end roleFlags()

	/**
	 * Whether any of the role flags grants access to gebruik reads.
	 *
	 * @param array{isAdmin:bool,isBeheerder:bool,isAanbod:bool,isAmbtenaar:bool} $flags The role flags.
	 *
	 * @return bool
	 */
	private static function hasAnyRole(array $flags): bool {
		return $flags['isAdmin'] === true
			|| $flags['isBeheerder'] === true
			|| $flags['isAanbod'] === true
			|| $flags['isAmbtenaar'] === true;

	}//end hasAnyRole()

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
	 * The decision table stays in this method: one explicit branch per caller
	 * role, so a reader can check that no role falls through to an unscoped
	 * query (`discovery.md` finding 2). The empty-scope guards that make each
	 * scoped branch fail closed live in that branch's own helper.
	 */
	private function applyAanbodScopeToOptions(array $roles, array $options): ?array {
		// Admin / ambtenaar: unrestricted read (unchanged, existing bypass).
		if (self::isUnrestrictedReader(roles: $roles) === true) {
			return $options;
		}

		// Aanbod-beheerder (vendor, REQ-002): scope to the vendor's own
		// offered applications. Unchanged from pre-existing behaviour.
		if (self::isVendorOnly(roles: $roles) === true) {
			return $this->scopeToVendorApplications(roles: $roles, options: $options);
		}

		// Gebruik-beheerder (municipality/samenwerking, REQ-003): scope to
		// the caller's own organisation's applicatielandschap.
		if ($roles['isBeheerder'] === true) {
			return self::scopeToOwnOrganisation(roles: $roles, options: $options);
		}

		return $options;
	}//end applyAanbodScopeToOptions()

	/**
	 * Whether the caller may read gebruik data without any scope.
	 *
	 * @param array<string,mixed> $roles Role flags.
	 *
	 * @return bool True for admins and ambtenaren.
	 */
	private static function isUnrestrictedReader(array $roles): bool {
		return $roles['isAdmin'] === true || ($roles['isAmbtenaar'] ?? false) === true;
	}//end isUnrestrictedReader()

	/**
	 * Whether the caller is a vendor (aanbod) without the gebruik-beheerder role.
	 *
	 * @param array<string,mixed> $roles Role flags.
	 *
	 * @return bool True for a pure aanbod-beheerder.
	 */
	private static function isVendorOnly(array $roles): bool {
		return $roles['isAanbod'] === true && $roles['isBeheerder'] !== true;
	}//end isVendorOnly()

	/**
	 * Whether the request asks for a module the vendor does not offer.
	 *
	 * @param array<string,mixed>    $options       Current request params.
	 * @param array<int, string|int> $applicatieIds The vendor's applicatie ids.
	 *
	 * @return bool True when a module filter is set and not one of the vendor's.
	 */
	private static function asksForeignModule(array $options, array $applicatieIds): bool {
		return isset($options['module']) === true && in_array($options['module'], $applicatieIds) === false;
	}//end asksForeignModule()

	/**
	 * Scope a vendor's read to the applicaties the vendor itself offers.
	 *
	 * Refuses (null) when the vendor offers nothing, or when the request
	 * already asks for a module the vendor does not offer.
	 *
	 * @param array<string,mixed> $roles   Role flags.
	 * @param array<string,mixed> $options Current request params.
	 *
	 * @return array<string,mixed>|null The scoped options, or null to refuse.
	 */
	private function scopeToVendorApplications(array $roles, array $options): ?array {
		$applicatieIds = $this->gebruikService->getApplicationIds(
			options: ['provider' => $roles['orgUuid']]
		);

		if ($applicatieIds === []) {
			return null;
		}

		if (self::asksForeignModule(options: $options, applicatieIds: $applicatieIds) === true) {
			return null;
		}

		if (isset($options['module']) === false) {
			$options['module'] = $applicatieIds;
		}

		return $options;
	}//end scopeToVendorApplications()

	/**
	 * Scope a gebruik-beheerder's read to their own organisation as afnemer.
	 *
	 * Uses the `afnemer` relationship, the same field that defines "this
	 * gebruik record is used by my organisation" throughout the rest of this
	 * codebase (AangebodenGebruikService::getGebruiksWhereAfnemer). Refuses
	 * (null) rather than silently widening when the request already asks for
	 * a different organisation's afnemer filter.
	 *
	 * @param array<string,mixed> $roles   Role flags.
	 * @param array<string,mixed> $options Current request params.
	 *
	 * @return array<string,mixed>|null The scoped options, or null to refuse.
	 */
	private static function scopeToOwnOrganisation(array $roles, array $options): ?array {
		if (isset($options['consumer']) === true && $options['consumer'] !== $roles['orgUuid']) {
			return null;
		}

		$options['consumer'] = $roles['orgUuid'];

		return $options;
	}//end scopeToOwnOrganisation()

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

		return $this->participantGebruiken(orgUuid: $orgUuid);
	}//end getGebruikenForDeelnemer()

	/**
	 * Read the gebruiken the caller's organisation participates in.
	 *
	 * @param string $orgUuid The caller's organisation UUID (never empty).
	 *
	 * @return JSONResponse
	 */
	private function participantGebruiken(string $orgUuid): JSONResponse {
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
		$options['participants'] = [$orgUuid];

		try {
			return new JSONResponse($this->gebruikService->getGebruiken(options: $options));
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], statusCode: 500);
		}
	}//end participantGebruiken()

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
			'@self' => $this->emptyResultSelf(),
		];
	}//end getEmptyResult()

	/**
	 * The @self block of the empty result set.
	 *
	 * @return array
	 */
	private function emptyResultSelf(): array {
		return [
			'source' => 'database',
			'query' => [],
			'rbac' => false,
			'multi' => false,
			'published' => false,
			'deleted' => false,
		];
	}//end emptyResultSelf()
}//end class
