<?php

/**
 * Contactpersonen Controller for SoftwareCatalog.
 *
 * Handles HTTP requests for managing contactpersonen and their user accounts,
 * including converting contactpersonen to users, managing passwords and groups.
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing contactpersonen and their user accounts.
 *
 * This controller handles operations related to contactpersonen including:
 * - Converting contactpersonen to users
 * - Managing user passwords
 * - Managing user group memberships
 *
 * @category  Controller
 * @package   OCA\SoftwareCatalog\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-5
 */
class ContactpersonenController extends Controller {

	/**
	 * Settings service for configuration access.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settingsService;

	/**
	 * Contact person handler for user operations.
	 *
	 * @var ContactPersonHandler
	 */
	private ContactPersonHandler $contactPersonHandler;

	/**
	 * User manager for user operations.
	 *
	 * @var IUserManager
	 */
	private IUserManager $userManager;

	/**
	 * Group manager for group operations.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * Secure random generator for passwords.
	 *
	 * @var ISecureRandom
	 */
	private ISecureRandom $secureRandom;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * User session for getting current user.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Container for dependency injection.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Contactpersoon service for business logic.
	 *
	 * @var ContactpersoonService
	 */
	private ContactpersoonService $contactSvc;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param SettingsService $settingsService Settings service
	 * @param ContactPersonHandler $contactPersonHandler Contact person handler
	 * @param ContactpersoonService $contactSvc Contactpersoon service
	 * @param IUserManager $userManager User manager
	 * @param IGroupManager $groupManager Group manager
	 * @param IUserSession $userSession User session
	 * @param ContainerInterface $container Container for DI
	 * @param ISecureRandom $secureRandom Secure random generator
	 * @param LoggerInterface $logger Logger instance
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		SettingsService $settingsService,
		ContactPersonHandler $contactPersonHandler,
		ContactpersoonService $contactSvc,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IUserSession $userSession,
		ContainerInterface $container,
		ISecureRandom $secureRandom,
		LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->settingsService = $settingsService;
		$this->contactPersonHandler = $contactPersonHandler;
		$this->contactSvc = $contactSvc;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->container = $container;
		$this->secureRandom = $secureRandom;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Get contactpersonen for an organisation with user status.
	 *
	 * Authorization (GH#459): the response carries Nextcloud account data —
	 * username, full group membership and enabled/disabled state — for every
	 * contact of the requested organisation. That is the same payload
	 * {@see getUserInfo()} and {@see getBulkUserInfo()} refuse to non-admins,
	 * so the same bar applies here: an instance admin may read any
	 * organisation, anybody else may read only their OWN organisation, and a
	 * caller whose organisation cannot be resolved is refused. This mirrors
	 * {@see verifyCrossTenantScope()}, which already fails closed for writes.
	 *
	 * @param string $organisationId The organisation ID.
	 *
	 * @return JSONResponse List of contactpersonen with user information.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/contactpersonen-api/spec.md
	 */
	public function getContactpersonen(string $organisationId): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->checkOrganisationReadPermission(
			currentUser: $currentUser,
			organisationId: $organisationId
		);
		if ($authError !== null) {
			return $authError;
		}

		try {
			// Get object service.
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			// Search for contactpersonen belonging to this organisation.
			// Use a more generic search that doesn't require specific register/schema.
			$searchParams = [
				'organisation' => $organisationId,
				'_limit' => 100,
				'_schema' => 'contactpersoon',
				// Let ObjectService resolve the schema.
			];

			$contactpersonen = $objectService->searchObjectsPaginated($searchParams);

			// Enhance with user information.
			//
			// GH#459 second finding: the filter above is a bare top-level
			// `organisation` key. Whether OpenRegister treats that as an
			// object-property filter, as `@self` metadata, or ignores it is
			// not visible from here — and an ignored filter returns an
			// UNSCOPED result set that looks exactly like a scoped one. The
			// organisation of every returned record is therefore re-checked
			// here, so a filter that fails to scope cannot leak.
			$enhancedContacts = [];
			foreach ($contactpersonen['results'] as $contactPerson) {
				$contactData = $contactPerson->getObject();

				$contactOrg = $this->normaliseOrganisationRef(value: ($contactData['organisation'] ?? null));
				if ($contactOrg === null) {
					$contactOrg = $this->normaliseOrganisationRef(value: ($contactData['organisatie'] ?? null));
				}

				// A record with no resolvable organisation is NOT served: an
				// unattributed contact must not be presented as a member of
				// the organisation the caller asked for.
				if ($contactOrg === null || $contactOrg !== trim($organisationId)) {
					continue;
				}

				// The buildUserInfoData() shape is what the admin-gated
				// getUserInfo()/getBulkUserInfo() already return: it reports
				// only the three software-catalog group memberships instead of
				// every GID the account holds, which is all this surface needs.
				$enhancedContacts[] = [
					'id' => $contactPerson->getId(),
					'uuid' => $contactPerson->getUuid(),
					'data' => $contactData,
					'user' => $this->buildUserInfoData(contactData: $contactData),
				];
			}//end foreach

			return new JSONResponse(
				[
					'success' => true,
					'contactpersonen' => $enhancedContacts,
					'total' => count($enhancedContacts),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get contactpersonen: ' . $e->getMessage(),
				[
					'organisationId' => $organisationId,
					'exception' => $e,
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to retrieve contactpersonen: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getContactpersonen()

	/**
	 * Check whether the caller may read the contact persons of an organisation.
	 *
	 * The contact-person read-outs carry Nextcloud account data (username,
	 * group membership, enabled state). Instance admins may read any
	 * organisation; everybody else may read only the organisation their own
	 * contactpersoon record belongs to. A caller whose organisation cannot be
	 * resolved is refused — the same fail-closed posture
	 * {@see verifyCrossTenantScope()} already applies to writes (GH#459).
	 *
	 * @param \OCP\IUser $currentUser The currently authenticated caller.
	 * @param string $organisationId The organisation the caller asked for.
	 *
	 * @return JSONResponse|null Forbidden response when refused, null when permitted.
	 *
	 * @spec openspec/specs/contactpersonen-api/spec.md
	 */
	private function checkOrganisationReadPermission(\OCP\IUser $currentUser, string $organisationId): ?JSONResponse {
		if ($this->groupManager->isAdmin($currentUser->getUID()) === true) {
			return null;
		}

		if (trim($organisationId) === '') {
			return new JSONResponse(
				['success' => false, 'message' => 'Forbidden: an organisation is required'],
				Http::STATUS_FORBIDDEN
			);
		}

		$callerOrgUuid = null;
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$callerOrgUuid = $this->resolveContactOrganisation(objectService: $objectService, username: $currentUser->getUID());
		} catch (\Exception $e) {
			$this->logger->warning(
				'ContactpersonenController: could not resolve the caller organisation, denying contact read',
				['callerUid' => $currentUser->getUID(), 'organisationId' => $organisationId, 'error' => $e->getMessage()]
			);

			return new JSONResponse(
				['success' => false, 'message' => 'Forbidden: organisation scope could not be verified'],
				Http::STATUS_FORBIDDEN
			);
		}//end try

		if ($callerOrgUuid === null || $callerOrgUuid !== trim($organisationId)) {
			$this->logger->warning(
				'ContactpersonenController: cross-organisation contact read denied',
				['callerUid' => $currentUser->getUID(), 'callerOrg' => $callerOrgUuid, 'requestedOrg' => $organisationId]
			);

			return new JSONResponse(
				['success' => false, 'message' => 'Forbidden: you may only read contact persons of your own organisation'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end checkOrganisationReadPermission()

	/**
	 * Normalise an organisation reference to a plain identifier string.
	 *
	 * Accepts a UUID string, or a nested related-object array carrying
	 * `uuid`, `id`, or an `@self` envelope with either of those.
	 *
	 * @param mixed $value The raw stored value.
	 *
	 * @return string|null The identifier, or null when there is none.
	 *
	 * @spec openspec/specs/contactpersonen-api/spec.md
	 */
	private function normaliseOrganisationRef(mixed $value): ?string {
		if (is_string($value) === true) {
			$trimmed = trim($value);
			if ($trimmed === '') {
				return null;
			}

			return $trimmed;
		}

		if (is_array($value) === false) {
			return null;
		}

		foreach (['uuid', 'id', '@self'] as $key) {
			if (array_key_exists($key, $value) === false) {
				continue;
			}

			$nested = $this->normaliseOrganisationRef(value: $value[$key]);
			if ($nested !== null) {
				return $nested;
			}
		}

		return null;
	}//end normaliseOrganisationRef()

	/**
	 * Convert a contactpersoon to a user account.
	 *
	 * @param string $contactPersonId The contactpersoon ID.
	 *
	 * @return JSONResponse Result of user creation.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/contactpersonen-api/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 187 lines (threshold 100). Most of the body
	 * is the multi-line, named-argument `JSONResponse` error payloads this codebase's phpcs rules
	 * mandate — one argument per line — for each distinct failure mode of the convert flow. The
	 * method is still longer than it should be and SHOULD be decomposed further (task 5 of
	 * `method-decomposition` already carved out the permission check); that is a deliberate
	 * follow-up rather than something to smuggle into a quality-gate-only change.
	 */
	public function convertToUser(string $contactPersonId): JSONResponse {
		$authError = $this->validateConvertToUserPermission();
		if ($authError !== null) {
			return $authError;
		}

		try {
			// Get object service.
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			// Find the contactpersoon object — bind to current tenant.
			$contactPersonObject = $objectService->find(
				id: $contactPersonId,
				register: 'voorzieningen',
				schema: 'contactpersoon',
				_rbac: true,
				_multitenancy: true
			);

			if ($contactPersonObject === null) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Contactpersoon not found',
					],
					404
				);
			}

			// Get register and schema from the found object.
			$registerId = $contactPersonObject->getRegister();
			$schemaId = $contactPersonObject->getSchema();

			$this->logger->info(
				'ContactpersonenController: Found contactpersoon object',
				[
					'contactpersoonId' => $contactPersonId,
					'registerId' => $registerId,
					'schemaId' => $schemaId,
				]
			);

			$contactData = $contactPersonObject->getObject();

			// Check if user already exists.
			if (empty($contactData['username']) === false) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Contactpersoon already has a user account',
					],
					400
				);
			}

			// Validate email address before attempting user creation.
			$email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';
			$emailError = $this->contactPersonHandler->validateEmailForUsername($email);
			if ($emailError !== null) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => $emailError,
					],
					400
				);
			}

			// Create user account using ContactPersonHandler.
			$user = $this->contactPersonHandler->createUserAccount($contactPersonObject);

			if ($user === null) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Failed to create user account',
					],
					500
				);
			}

			// Ensure groups are assigned based on organization type.
			// This is a safety check in case the createUserAccount didn't assign groups properly.
			$contactData = $contactPersonObject->getObject();
			$organizationId = $contactData['organisatie'] ?? $contactData['organisation'] ?? '';

			if (empty($organizationId) === false) {
				$this->logger->info(
					'ContactpersonenController: Ensuring groups are assigned based on organization type',
					[
						'contactpersoonId' => $contactPersonId,
						'username' => $user->getUID(),
						'organizationId' => $organizationId,
					]
				);

				// Call the ContactPersonHandler to update groups based on contact data.
				$this->contactPersonHandler->updateUserGroupsFromContactData(
					user: $user,
					contactData: $contactData
				);
			}

			// Link user to organization entity.
			$this->contactPersonHandler->addUserToOrganizationEntity(
				contactPersonObject: $contactPersonObject,
				username: $user->getUID(),
				organizationUuidOverride: $organizationId
			);

			// Update the contactpersoon object with the username.
			$contactData['username'] = $user->getUID();

			$contactData = $this->normaliseContactDataForPersist(contactData: $contactData);

			$contactPersonObject->setObject($contactData);

			// Debug logging to understand data types before save.
			$lastNameValue = $contactData['achternaam'] ?? 'not set';
			$lastNameType = 'not set';
			if (isset($contactData['achternaam']) === true) {
				$lastNameType = gettype($contactData['achternaam']);
			}

			$this->logger->info(
				'ContactpersonenController: About to save contactpersoon object',
				[
					'contactpersoonId' => $contactPersonId,
					'achternaamValue' => $lastNameValue,
					'achternaamType' => $lastNameType,
					'registerId' => $registerId,
					'schemaId' => $schemaId,
				]
			);

			// Save using MagicMapper directly to bypass schema validation.
			// This avoids "Unresolved reference" errors when schema references can't be resolved.
			$objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
			$objectMapper->update($contactPersonObject);

			$this->logger->info(
				'ContactpersonenController: Updated contactpersoon with username',
				[
					'contactpersoonId' => $contactPersonId,
					'username' => $user->getUID(),
				]
			);

			$userGroupNames = $this->projectCatalogGroupsForUser(user: $user);

			// Add groups to the contactpersoon data for frontend.
			$updatedContactData = $contactPersonObject->getObject();
			$updatedContactData['groups'] = $userGroupNames;

			// Return the updated contactpersoon object with groups.
			return new JSONResponse(
				[
					'success' => true,
					'message' => 'User account created successfully',
					'username' => $user->getUID(),
					'contactpersoon' => array_merge(
						$contactPersonObject->jsonSerialize(),
						[
							'groups' => $userGroupNames,
						]
					),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to convert contactpersoon to user: ' . $e->getMessage(),
				[
					'contactpersoonId' => $contactPersonId,
					'exception' => $e,
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to create user account: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end convertToUser()

	/**
	 * Authorises the convertToUser endpoint.
	 *
	 * Returns null when the current user is allowed to create user accounts, or
	 * a 401/403 JSONResponse otherwise. Extracted from {@see convertToUser()} as
	 * part of task 5.1.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function validateConvertToUserPermission(): ?JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $currentUser->getUID();
		$isAdmin = $this->groupManager->isAdmin($uid);
		$isOrgAdmin = $this->groupManager->isInGroup($uid, 'gebruik-beheerder')
			|| $this->groupManager->isInGroup($uid, 'aanbod-beheerder');

		if ($isAdmin === false && $isOrgAdmin === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end validateConvertToUserPermission()

	/**
	 * Normalises the contactpersoon payload for the MagicMapper persist call.
	 *
	 * Coerces the string-typed fields (`voornaam`, `achternaam`, …) to strings
	 * — guards against legacy rows where these fields were stored as `null`,
	 * `int`, or `bool` — and nulls out the `organisatie` / `organisation` keys
	 * when they hold a UUID string (the relationship is maintained via the
	 * organisation entity's users array). Extracted from {@see convertToUser()}
	 * as part of task 5.2.
	 *
	 * @param array<string, mixed> $contactData The raw contactpersoon payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function normaliseContactDataForPersist(array $contactData): array {
		$stringFields = [
			'voornaam',
			'tussenvoegsel',
			'achternaam',
			'role',
			'telefoonnummer',
			'email',
			'e-mailadres',
		];
		foreach ($stringFields as $field) {
			if (isset($contactData[$field]) === true && is_string($contactData[$field]) === false) {
				$contactData[$field] = (string)$contactData[$field];
			}
		}

		if (isset($contactData['organisatie']) === true && is_string($contactData['organisatie']) === true) {
			$this->logger->info(
				'ContactpersonenController: Converting organisatie string to null for validation',
				[
					'originalValue' => $contactData['organisatie'],
				]
			);
			$contactData['organisatie'] = null;
		}

		if (isset($contactData['organisation']) === true && is_string($contactData['organisation']) === true) {
			$contactData['organisation'] = null;
		}

		return $contactData;
	}//end normaliseContactDataForPersist()

	/**
	 * Projects the catalog-relevant groups (`gebruik-beheerder` /
	 * `aanbod-beheerder` / `gebruik-raadpleger`) for the supplied user.
	 *
	 * Extracted from {@see convertToUser()} as part of task 5.3 so the response
	 * shaper no longer iterates the user's group list inline.
	 *
	 * @param \OCP\IUser $user The newly-created user.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function projectCatalogGroupsForUser(\OCP\IUser $user): array {
		$catalogGroups = ['gebruik-beheerder', 'aanbod-beheerder', 'gebruik-raadpleger'];
		$projected = [];

		foreach ($this->groupManager->getUserGroups($user) as $group) {
			$groupId = $group->getGID();
			if (in_array(needle: $groupId, haystack: $catalogGroups, strict: true) === true) {
				$projected[] = $groupId;
			}
		}

		return $projected;
	}//end projectCatalogGroupsForUser()

	/**
	 * Change user password.
	 *
	 * Admins may change any user's password. Regular users may only change their
	 * own password, and must supply the current password for confirmation.
	 *
	 * @param string $username The username.
	 * @param string $newPassword The new password.
	 * @param string $currentPassword The current password (required for self-service resets).
	 *
	 * @return JSONResponse Result of password change.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/contactpersonen-api/spec.md
	 */
	public function changePassword(string $username, string $newPassword, string $currentPassword = ''): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$permissionError = $this->validatePasswordChangePermission(
			currentUser: $currentUser,
			username: $username,
			currentPassword: $currentPassword
		);
		if ($permissionError !== null) {
			return $permissionError;
		}

		return $this->performPasswordChange(username: $username, newPassword: $newPassword);
	}//end changePassword()

	/**
	 * Validate permission to change the target user's password.
	 *
	 * @param \OCP\IUser $currentUser The currently authenticated user.
	 * @param string $username The target username.
	 * @param string $currentPassword The current password supplied (for self-service).
	 *
	 * @return JSONResponse|null Error response if not permitted, null if allowed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function validatePasswordChangePermission(
		\OCP\IUser $currentUser,
		string $username,
		string $currentPassword,
	): ?JSONResponse {
		$isAdmin = $this->groupManager->isAdmin($currentUser->getUID());
		$isSelfReset = $currentUser->getUID() === $username;

		if ($isAdmin === false && $isSelfReset === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		return $this->validateCurrentPasswordIfRequired(
			isAdmin: $isAdmin,
			isSelfReset: $isSelfReset,
			username: $username,
			currentPassword: $currentPassword
		);

	}//end validatePasswordChangePermission()

	/**
	 * Validate the current password when a non-admin performs a self-service reset.
	 *
	 * @param bool $isAdmin Whether the current user is an administrator.
	 * @param bool $isSelfReset Whether the target is the current user themselves.
	 * @param string $username The target username.
	 * @param string $currentPassword The current password supplied by the user.
	 *
	 * @return JSONResponse|null Error response if validation fails, null if passed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function validateCurrentPasswordIfRequired(
		bool $isAdmin,
		bool $isSelfReset,
		string $username,
		string $currentPassword,
	): ?JSONResponse {
		if ($isSelfReset === false || $isAdmin === true) {
			return null;
		}

		if (empty($currentPassword) === true) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Current password is required for self-service password reset',
				],
				400
			);
		}

		$authUser = $this->userManager->checkPassword($username, $currentPassword);
		if ($authUser === false) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Current password is incorrect',
				],
				403
			);
		}

		return null;
	}//end validateCurrentPasswordIfRequired()

	/**
	 * Perform the actual password change after permission validation.
	 *
	 * @param string $username The target username.
	 * @param string $newPassword The new password to set.
	 *
	 * @return JSONResponse Success or error response.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function performPasswordChange(string $username, string $newPassword): JSONResponse {
		try {
			$user = $this->userManager->get($username);

			if ($user === null) {
				return new JSONResponse(['success' => false, 'message' => 'User not found'], 404);
			}

			if (strlen($newPassword) < 10) {
				return new JSONResponse(
					['success' => false, 'message' => 'Password must be at least 10 characters long'],
					400
				);
			}

			$result = $user->setPassword($newPassword);

			if ($result === false) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Password was rejected: may be too common or violate the policy. Please choose another.',
					],
					400
				);
			}

			$this->logger->info('Password changed for user', ['username' => $username]);

			return new JSONResponse(['success' => true, 'message' => 'Password changed successfully']);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to change password: ' . $e->getMessage(),
				['username' => $username, 'exception' => $e]
			);

			return new JSONResponse(
				['success' => false, 'message' => 'Failed to change password: ' . $e->getMessage()],
				500
			);
		}//end try

	}//end performPasswordChange()

	/**
	 * Update user groups.
	 *
	 * @param string $username The username.
	 * @param array $groups Array of group names to assign.
	 *
	 * @return JSONResponse Result of group update.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/contactpersonen-api/spec.md
	 */
	public function updateUserGroups(string $username, array $groups = []): JSONResponse {
		$currentUser = $this->userSession->getUser();

		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->checkGroupUpdatePermission(currentUser: $currentUser, username: $username);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$user = $this->userManager->get($username);
			if ($user === null) {
				return new JSONResponse(['success' => false, 'message' => 'User not found'], 404);
			}

			$this->syncUserCatalogGroups(user: $user, username: $username, requestedGroups: $groups);

			return new JSONResponse(
				['success' => true, 'message' => 'User groups updated successfully', 'groups' => $this->resolveCatalogGroupNames(user: $user)]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update user groups: ' . $e->getMessage(),
				['username' => $username, 'groups' => $groups, 'exception' => $e]
			);
			return new JSONResponse(
				['success' => false, 'message' => 'Failed to update user groups: ' . $e->getMessage()],
				500
			);
		}//end try
	}//end updateUserGroups()

	/**
	 * Check whether the current user may update group assignments for the given username.
	 *
	 * Returns a Forbidden/Unauthorized response when the check fails, or null when allowed.
	 *
	 * @param \OCP\IUser $currentUser The currently authenticated caller.
	 * @param string $username The target username.
	 *
	 * @return JSONResponse|null Error response, or null when permitted.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function checkGroupUpdatePermission(\OCP\IUser $currentUser, string $username): ?JSONResponse {
		$isAdmin = $this->groupManager->isAdmin($currentUser->getUID());
		$isOrgAdmin = $this->groupManager->isInGroup($currentUser->getUID(), 'gebruik-beheerder')
			|| $this->groupManager->isInGroup($currentUser->getUID(), 'aanbod-beheerder');

		if ($isAdmin === false && $isOrgAdmin === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		if ($isAdmin === false && $isOrgAdmin === true) {
			return $this->verifyCrossTenantScope(currentUser: $currentUser, username: $username);
		}

		return null;
	}//end checkGroupUpdatePermission()

	/**
	 * Verify that an org-admin may modify groups for the target username.
	 *
	 * @param \OCP\IUser $currentUser The currently authenticated user.
	 * @param string $username The target username.
	 *
	 * @return JSONResponse|null Forbidden response when tenants differ, null when allowed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function verifyCrossTenantScope(\OCP\IUser $currentUser, string $username): ?JSONResponse {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$targetOrgUuid = $this->resolveContactOrganisation(objectService: $objectService, username: $username);
			$callerOrgUuid = $this->resolveContactOrganisation(objectService: $objectService, username: $currentUser->getUID());

			if ($targetOrgUuid !== null && $callerOrgUuid !== null && $targetOrgUuid !== $callerOrgUuid) {
				$this->logger->warning(
					'ContactpersonenController: Cross-tenant group update denied',
					['callerUid' => $currentUser->getUID(), 'callerOrg' => $callerOrgUuid, 'targetOrg' => $targetOrgUuid]
				);
				return new JSONResponse(
					['success' => false, 'message' => 'Forbidden: target user belongs to a different organisation'],
					Http::STATUS_FORBIDDEN
				);
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'ContactpersonenController: Could not verify cross-tenant scope, denying update',
				['callerUid' => $currentUser->getUID(), 'target' => $username, 'error' => $e->getMessage()]
			);
			return new JSONResponse(
				['success' => false, 'message' => 'Forbidden: organisation scope could not be verified'],
				Http::STATUS_FORBIDDEN
			);
		}//end try

		return null;
	}//end verifyCrossTenantScope()

	/**
	 * Resolve the organisation UUID for a user's contactpersoon.
	 *
	 * The stored value is normalised: `organisatie` is declared as a related
	 * object in lib/Settings/softwarecatalogus_register.json, so it may arrive
	 * as a bare UUID string or as a nested envelope. Returning the raw value
	 * made a nested reference compare unequal to a plain UUID, which both this
	 * method's callers treat as "different tenant" (GH#459).
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $username The username to look up.
	 *
	 * @return string|null The organisation UUID or null when not found.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function resolveContactOrganisation(object $objectService, string $username): ?string {
		$results = $objectService->searchObjectsPaginated(
			['username' => $username, '_limit' => 1, '_schema' => 'contactpersoon']
		);

		if (empty($results['results']) === true) {
			return null;
		}

		$data = $results['results'][0]->getObject();

		$ref = $this->normaliseOrganisationRef(value: ($data['organisation'] ?? null));
		if ($ref !== null) {
			return $ref;
		}

		return $this->normaliseOrganisationRef(value: ($data['organisatie'] ?? null));
	}//end resolveContactOrganisation()

	/**
	 * Sync a user's software-catalog group memberships to the requested list.
	 *
	 * @param \OCP\IUser $user The Nextcloud user object.
	 * @param string $username The username (for logging).
	 * @param string[] $requestedGroups The desired software-catalog group IDs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function syncUserCatalogGroups(\OCP\IUser $user, string $username, array $requestedGroups): void {
		$allowedGroups = ['gebruik-beheerder', 'aanbod-beheerder', 'gebruik-raadpleger'];
		$validGroups = array_intersect($requestedGroups, $allowedGroups);
		$currentGroups = $this->groupManager->getUserGroups($user);
		$curCatalogNames = [];

		foreach ($currentGroups as $group) {
			if (in_array(needle: $group->getGID(), haystack: $allowedGroups) === true) {
				$curCatalogNames[] = $group->getGID();
			}
		}

		foreach (array_diff($curCatalogNames, $validGroups) as $groupName) {
			$group = $this->groupManager->get($groupName);
			if ($group !== null && $group->inGroup($user) === true) {
				$group->removeUser($user);
				$this->logger->info('Removed user from group', ['username' => $username, 'group' => $groupName]);
			}
		}

		foreach (array_diff($validGroups, $curCatalogNames) as $groupName) {
			$group = $this->groupManager->get($groupName);
			if ($group === null) {
				$this->logger->warning('Group does not exist, skipping', ['username' => $username, 'group' => $groupName]);
				continue;
			}

			if ($group->inGroup($user) === false) {
				$group->addUser($user);
				$this->logger->info('Added user to group', ['username' => $username, 'group' => $groupName]);
			}
		}

	}//end syncUserCatalogGroups()

	/**
	 * Return the IDs of the user's current software-catalog group memberships.
	 *
	 * @param \OCP\IUser $user The Nextcloud user object.
	 *
	 * @return string[] The group IDs.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function resolveCatalogGroupNames(\OCP\IUser $user): array {
		$updatedGroups = $this->groupManager->getUserGroups($user);
		return array_map(static fn ($group) => $group->getGID(), $updatedGroups);
	}//end resolveCatalogGroupNames()

	/**
	 * Get contact persons for an organization with user details.
	 *
	 * Returns all contact persons linked to a specific organization,
	 * with their corresponding Nextcloud user details spliced in.
	 *
	 * Same authorization bar as {@see getContactpersonen()} — this is the
	 * sibling route that returns the same account data for a caller-chosen
	 * organisation (GH#459).
	 *
	 * @param string $organizationUuid The organization UUID.
	 *
	 * @return JSONResponse JSON response containing contact persons with user details.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/contactpersonen-api/spec.md
	 */
	public function getContactPersonsWithUserDetailsForOrganization(string $organizationUuid): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->checkOrganisationReadPermission(
			currentUser: $currentUser,
			organisationId: $organizationUuid
		);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$this->logger->info(
				'ContactpersonenController: Getting contact persons with user details for organization',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Validate organization UUID.
			if (empty($organizationUuid) === true) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Organization UUID is required',
					],
					400
				);
			}

			// Get contact persons with user details using the service.
			$contactPersons = $this->contactSvc->getContactPersonsWithUserDetailsForOrganization(
				$organizationUuid
			);

			// Convert objects to arrays for JSON response.
			$contactPersonsData = [];
			foreach ($contactPersons as $contactPerson) {
				$contactPersonsData[] = [
					'id' => $contactPerson->getId(),
					'uuid' => $contactPerson->getUuid(),
					'object' => $contactPerson->getObject(),
					'register' => $contactPerson->getRegister(),
					'schema' => $contactPerson->getSchema(),
					'created' => $contactPerson->getCreated(),
					'modified' => $contactPerson->getModified(),
				];
			}

			$this->logger->info(
				'ContactpersonenController: Successfully retrieved contact persons with user details',
				[
					'organizationUuid' => $organizationUuid,
					'contactPersonCount' => count($contactPersonsData),
				]
			);

			return new JSONResponse(
				[
					'success' => true,
					'data' => $contactPersonsData,
					'count' => count($contactPersonsData),
					'organizationUuid' => $organizationUuid,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersonenController: Failed to get contact persons with user details for organization',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get contact persons with user details: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getContactPersonsWithUserDetailsForOrganization()

	/**
	 * Get user information and available groups for a specific contactpersoon.
	 *
	 * Returns user information including current groups and available groups
	 * for a specific contactpersoon identified by UUID.
	 *
	 * @param string $contactPersonId The contactpersoon UUID.
	 *
	 * @return JSONResponse JSON response containing user info and available groups.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/contactpersonen-api/spec.md
	 */
	public function getUserInfo(string $contactPersonId): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = $this->groupManager->isAdmin($currentUser->getUID());
		$isOrgAdmin = $this->groupManager->isInGroup($currentUser->getUID(), 'gebruik-beheerder')
			|| $this->groupManager->isInGroup($currentUser->getUID(), 'aanbod-beheerder');

		$canViewUserInfo = ($isAdmin || $isOrgAdmin);
		if ($canViewUserInfo === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$contactObject = $objectService->find(
				id: $contactPersonId,
				register: 'voorzieningen',
				schema: 'contactpersoon'
			);

			if ($contactObject === null) {
				return new JSONResponse(['success' => false, 'message' => 'Contactpersoon not found'], 404);
			}

			$userInfo = $this->buildUserInfoData(contactData: $contactObject->getObject());

			return new JSONResponse(
				['success' => true, 'userInfo' => $userInfo, 'availableGroups' => $this->resolveExistingCatalogGroups()]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersonenController: Failed to get user info',
				['contactpersoonId' => $contactPersonId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['success' => false, 'message' => 'Failed to get user info: ' . $e->getMessage()], 500);
		}//end try
	}//end getUserInfo()

	/**
	 * Build user info array from a contactpersoon data record.
	 *
	 * @param array<string,mixed> $contactData The contactpersoon object data.
	 *
	 * @return array<string,mixed> User info with hasUser, username, groups, disabled keys.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function buildUserInfoData(array $contactData): array {
		$username = $contactData['username'] ?? null;
		$userInfo = [
			'hasUser' => empty($username) === false,
			'username' => $username,
			'groups' => [],
			'disabled' => false,
		];

		if (empty($username) === false) {
			$user = $this->userManager->get($username);
			if ($user !== null) {
				$catalogGroups = ['gebruik-beheerder', 'aanbod-beheerder', 'gebruik-raadpleger'];
				$allGroups = $this->resolveCatalogGroupNames(user: $user);
				$userInfo['groups'] = array_values(array_filter($allGroups, static fn ($groupName) => in_array($groupName, $catalogGroups, true)));
				$userInfo['disabled'] = ($user->isEnabled() === false);
			}
		}

		return $userInfo;
	}//end buildUserInfoData()

	/**
	 * Return the set of software-catalog groups that actually exist in Nextcloud.
	 *
	 * @return array<int,array<string,string>> List of group descriptor arrays with id/name/description.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function resolveExistingCatalogGroups(): array {
		$candidates = [
			['id' => 'gebruik-beheerder', 'name' => 'Gebruik Beheerder', 'description' => 'Manages software usage and procurement'],
			['id' => 'aanbod-beheerder', 'name' => 'Aanbod Beheerder', 'description' => 'Manages software offerings and catalog content'],
			['id' => 'gebruik-raadpleger', 'name' => 'Gebruik Raadpleger', 'description' => 'Views software usage and procurement data'],
		];

		return array_values(
			array_filter($candidates, fn ($grp) => $this->groupManager->get($grp['id']) !== null)
		);

	}//end resolveExistingCatalogGroups()

	/**
	 * Get available software catalog groups.
	 *
	 * @return JSONResponse List of available groups.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/contactpersonen-api/spec.md
	 */
	public function getAvailableGroups(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$availableGroups = [
				[
					'id' => 'gebruik-beheerder',
					'name' => 'Gebruik Beheerder',
					'description' => 'Manages software usage and procurement',
				],
				[
					'id' => 'aanbod-beheerder',
					'name' => 'Aanbod Beheerder',
					'description' => 'Manages software offerings and catalog content',
				],
				[
					'id' => 'gebruik-raadpleger',
					'name' => 'Gebruik Raadpleger',
					'description' => 'Views software usage and procurement data',
				],
			];

			// Check which groups actually exist.
			$existingGroups = [];
			foreach ($availableGroups as $groupInfo) {
				$group = $this->groupManager->get($groupInfo['id']);
				if ($group !== null) {
					$existingGroups[] = $groupInfo;
				}
			}

			return new JSONResponse(
				[
					'success' => true,
					'groups' => $existingGroups,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get available groups: ' . $e->getMessage(),
				[
					'exception' => $e,
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to retrieve available groups: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getAvailableGroups()

	/**
	 * Disable a user account.
	 *
	 * Requires admin or organisation-admin (gebruik-beheerder / aanbod-beheerder) role.
	 *
	 * @param string $contactPersonId The contactpersoon ID.
	 *
	 * @return JSONResponse Result of the disable operation.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/contactpersonen-api/spec.md
	 */
	public function disableUser(string $contactPersonId): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = $this->groupManager->isAdmin($currentUser->getUID());
		$isOrgAdmin = $this->groupManager->isInGroup($currentUser->getUID(), 'gebruik-beheerder')
			|| $this->groupManager->isInGroup($currentUser->getUID(), 'aanbod-beheerder');
		if ($isAdmin === false && $isOrgAdmin === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			// Delegate to service.
			$this->contactSvc->disableUserForContactpersoon($contactPersonId);

			$this->logger->info(
				'User account disabled',
				[
					'contactpersoonId' => $contactPersonId,
					'disabled_by' => $this->userSession->getUser()->getUID(),
				]
			);
			return new JSONResponse(
				[
					'success' => true,
					'message' => 'User account disabled successfully',
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to disable user account',
				[
					'contactpersoonId' => $contactPersonId,
					'error' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to disable user account: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end disableUser()

	/**
	 * Enable a user account.
	 *
	 * Requires admin or organisation-admin (gebruik-beheerder / aanbod-beheerder) role.
	 *
	 * @param string $contactPersonId The contactpersoon ID.
	 *
	 * @return JSONResponse Result of the enable operation.
	 *
	 * @NoCSRFRequired
	 * @spec           openspec/specs/contactpersonen-api/spec.md
	 */
	public function enableUser(string $contactPersonId): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = $this->groupManager->isAdmin($currentUser->getUID());
		$isOrgAdmin = $this->groupManager->isInGroup($currentUser->getUID(), 'gebruik-beheerder')
			|| $this->groupManager->isInGroup($currentUser->getUID(), 'aanbod-beheerder');
		if ($isAdmin === false && $isOrgAdmin === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			// Delegate to service.
			$this->contactSvc->enableUserForContactpersoon($contactPersonId);

			$this->logger->info(
				'User account enabled',
				[
					'contactpersoonId' => $contactPersonId,
					'enabled_by' => $this->userSession->getUser()->getUID(),
				]
			);
			return new JSONResponse(
				[
					'success' => true,
					'message' => 'User account enabled successfully',
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to enable user account',
				[
					'contactpersoonId' => $contactPersonId,
					'error' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to enable user account: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end enableUser()

	/**
	 * Get user info for multiple contactpersonen in one request.
	 *
	 * @return JSONResponse Bulk user info keyed by contactpersoon ID.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/specs/contactpersonen-api/spec.md
	 */
	public function getBulkUserInfo(): JSONResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = $this->groupManager->isAdmin($currentUser->getUID());
		$isOrgAdmin = $this->groupManager->isInGroup($currentUser->getUID(), 'gebruik-beheerder')
			|| $this->groupManager->isInGroup($currentUser->getUID(), 'aanbod-beheerder');

		$canViewBulkInfo = ($isAdmin || $isOrgAdmin);
		if ($canViewBulkInfo === false) {
			return new JSONResponse(['message' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			$input = json_decode(file_get_contents('php://input'), true);
			$contactPersonIds = $input['contactpersoonIds'] ?? [];

			$this->logger->info(
				'Controller: getBulkUserInfo called',
				[
					'input' => $input,
					'contactpersoonIds' => $contactPersonIds,
				]
			);

			if (empty($contactPersonIds) === true || is_array($contactPersonIds) === false) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'No contactpersoon IDs provided',
					],
					400
				);
			}

			if (count($contactPersonIds) > 100) {
				return new JSONResponse(
					[
						'success' => false,
						'message' => 'Too many contactpersoon IDs: maximum 100 allowed per request',
					],
					400
				);
			}

			// Delegate to service.
			$bulkUserInfo = $this->contactSvc->getBulkUserInfo($contactPersonIds);

			return new JSONResponse(
				[
					'success' => true,
					'userInfo' => $bulkUserInfo,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Controller: Failed to get bulk user info',
				[
					'error' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get bulk user info: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getBulkUserInfo()

	/**
	 * Get current user profile information.
	 *
	 * Returns the current logged-in user's profile including:
	 * - email, firstName, middleName, lastName, functie
	 * - organisations.active (the currently active organisation)
	 * - organisations.all (all organisations the user belongs to)
	 *
	 * @return JSONResponse The user profile data.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @spec                                          openspec/specs/contactpersonen-api/spec.md
	 */
	public function getMe(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Get current user from session.
			$user = $this->userSession->getUser();

			$userId = $user->getUID();
			$userEmail = $user->getEMailAddress() ?? $userId;

			$this->logger->info(
				'ContactpersonenController: Getting /me data for user',
				[
					'userId' => $userId,
					'userEmail' => $userEmail,
				]
			);

			// Initialize response with user data from Nextcloud.
			$response = $this->buildEmptyMeResponse(userEmail: $userEmail);
			$response['isBeheerder'] = $this->groupManager->isInGroup($userId, 'maintainer');

			// Try to get contactpersoon data for additional profile info.
			$this->enrichMeWithContactPersonData(
				response: $response,
				userId: $userId,
				userEmail: $userEmail
			);

			// Get organisation data from OpenRegister.
			try {
				$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');

				// Get active organisation.
				$activeOrg = $organisationService->getActiveOrganisation();
				if ($activeOrg !== null) {
					$response['organisations']['active'] = [
						'uuid' => $activeOrg->getUuid(),
						'name' => $activeOrg->getName(),
						'id' => (string)$activeOrg->getId(),
						'slug' => $activeOrg->getSlug() ?? $this->createSlug(name: $activeOrg->getName()),
					];
				}

				// Get all user organisations.
				$userOrgs = $organisationService->getUserOrganisations();
				foreach ($userOrgs as $org) {
					$response['organisations']['all'][] = [
						'uuid' => $org->getUuid(),
						'name' => $org->getName(),
						'id' => (string)$org->getId(),
						'slug' => $org->getSlug() ?? $this->createSlug(name: $org->getName()),
					];
				}
			} catch (\Exception $e) {
				$this->logger->warning(
					'ContactpersonenController: Could not get organisation data',
					[
						'userId' => $userId,
						'error' => $e->getMessage(),
					]
				);
			}//end try

			return new JSONResponse($response);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersonenController: Failed to get /me data',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Failed to get user profile: ' . $e->getMessage(),
				],
				500
			);
		}//end try
	}//end getMe()

	/**
	 * Create a URL-friendly slug from a name.
	 *
	 * @param string $name The name to convert.
	 *
	 * @return string The slug.
	 */
	private function createSlug(string $name): string {
		// Convert to lowercase.
		$slug = strtolower($name);
		// Replace spaces and special chars with hyphens.
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
		// Remove leading/trailing hyphens.
		$slug = trim($slug, '-');
		return $slug;
	}//end createSlug()

	/**
	 * Builds the empty /me response skeleton with the supplied email defaulted
	 * onto the `email` key.
	 *
	 * Extracted from {@see getMe()} as part of task 5.X so the per-section
	 * enrichments operate on a shared shape.
	 *
	 * @param string $userEmail The Nextcloud user's email address.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function buildEmptyMeResponse(string $userEmail): array {
		return [
			'email' => $userEmail,
			'firstName' => '',
			'middleName' => '',
			'lastName' => '',
			'role' => '',
			'organisations' => [
				'active' => null,
				'all' => [],
			],
			// Global `beheerder` NC group membership — a client-side hint
			// only (per-organisation authorization is re-verified
			// server-side on every grant/revoke by
			// OrganisationMembersController; this flag merely lets the
			// frontend decide whether to render the "manage members"
			// affordance at all).
			// The authorization contract this flag hints at is specified in
			// openspec/specs/multi-org-membership/spec.md, requirement
			// "granting or revoking organisation access must be restricted to
			// a beheerder of that organisation" (req-004).
			'isBeheerder' => false,
		];
	}//end buildEmptyMeResponse()

	/**
	 * Enriches the /me response with the contactpersoon profile fields
	 * (voornaam, tussenvoegsel, achternaam, functie) when a contactpersoon
	 * exists for the supplied Nextcloud user.
	 *
	 * Mutates the supplied response array in place. Silently logs (debug) any
	 * lookup failure — missing contact data is non-fatal. Extracted from
	 * {@see getMe()} as part of task 5.X.
	 *
	 * @param array<string, mixed> $response The /me response, modified in place.
	 * @param string $userId The Nextcloud user id.
	 * @param string $userEmail The user's email (fallback).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-5
	 */
	private function enrichMeWithContactPersonData(
		array &$response,
		string $userId,
		string $userEmail,
	): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$searchParams = [
				'username' => $userId,
				'_limit' => 1,
				'_schema' => 'contactpersoon',
			];

			$contactpersonen = $objectService->searchObjectsPaginated($searchParams);

			if (empty($contactpersonen['results']) === false) {
				$contactPerson = $contactpersonen['results'][0];
				$contactData = $contactPerson->getObject();

				$response['firstName'] = $contactData['voornaam'] ?? $contactData['firstName'] ?? '';
				$response['middleName'] = $contactData['tussenvoegsel'] ?? $contactData['middleName'] ?? '';
				$response['lastName'] = $contactData['achternaam'] ?? $contactData['lastName'] ?? '';
				$response['role'] = $contactData['role'] ?? '';

				if (empty($response['email']) === true) {
					$response['email'] = $contactData['e-mailadres'] ?? $contactData['email'] ?? $userEmail;
				}
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				'ContactpersonenController: Could not find contactpersoon for user',
				[
					'userId' => $userId,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end enrichMeWithContactpersoonData()
}//end class
