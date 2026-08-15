<?php

/**
 * Contact Person Handler for Software Catalog
 *
 * This handler manages contact person-specific operations including user creation,
 * contact processing, and organizational hierarchy management.
 *
 * @category  Handler
 * @package   OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\SoftwareCatalogue;

use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for contact person-related operations.
 *
 * @category  Handler
 * @package   OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class ContactPersonHandler {
	/**
	 * ContactPersonHandler constructor
	 *
	 * @param IUserManager $_userManager User manager interface.
	 * @param ISecureRandom $_secureRandom Secure random generator.
	 * @param IGroupManager $_groupManager Group manager interface.
	 * @param IAppConfig $_config Config interface.
	 * @param ContainerInterface $_container Container interface.
	 * @param IAppManager $_appManager App manager interface.
	 * @param LoggerInterface $_logger Logger interface.
	 * @param SymfonyEmailService $_emailService Email service.
	 * @param IConfig $config Config interface.
	 */
	public function __construct(
		private readonly IUserManager $_userManager,
		private readonly ISecureRandom $_secureRandom,
		private readonly IGroupManager $_groupManager,
		private readonly IAppConfig $_config,
		private readonly ContainerInterface $_container,
		private readonly IAppManager $_appManager,
		private readonly LoggerInterface $_logger,
		private readonly SymfonyEmailService $_emailService,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Gets the OpenRegister ObjectService if available
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null ObjectService instance or null
	 * @throws \RuntimeException If service is not available
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array('openregister', $this->_appManager->getInstalledApps()) === true) {
			return $this->_container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new \RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Generates a username from contact data with fallback strategies
	 *
	 * @param array $contactData The contact data array
	 *
	 * @return string Generated username
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function generateUsernameFromContactData(array $contactData): string {

		$voornaam = $contactData['voornaam'] ?? '';
		$tussenvoegsel = $contactData['tussenvoegsel'] ?? '';
		$lastName = $contactData['achternaam'] ?? '';
		$email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';

		// Strategy 1: full email address (PRIORITY).
		// Sanitize the email first to strip subaddressing (+tag) and invalid chars.
		if (empty($email) === false && strpos($email, '@') !== false) {
			$username = $this->sanitizeEmailForUsername(email: $email);
			if ($this->isValidUsername(username: $username) === true) {
				return $username;
			}
		}

		// Strategies 2 and 3: name-based candidates.
		[$cleanVoornaam, $cleanLastName] = $this->cleanNameParts(voornaam: $voornaam, lastName: $lastName);
		if ($cleanVoornaam !== '' && $cleanLastName !== '') {
			// Strategy 2: firstname.lastname.
			$candidate = $cleanVoornaam . '.' . $cleanLastName;
			if ($this->isValidUsername(username: $candidate) === true) {
				return $candidate;
			}

			// Strategy 3: firstnamelastname.
			$candidate = $cleanVoornaam . $cleanLastName;
			if ($this->isValidUsername(username: $candidate) === true) {
				return $candidate;
			}
		}

		// Strategy 4: timestamp fallback.
		$username = 'user' . time();
		if ($this->isValidUsername(username: $username) === true) {
			return $username;
		}

		// If all strategies fail, log error and return empty string.
		$this->_logger->error('All username generation strategies failed', ['contactData' => $contactData]);
		return '';
	}//end generateUsernameFromContactData()

	/**
	 * Validates if a username meets Nextcloud requirements.
	 *
	 * @param string $username The username to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	private function isValidUsername(string $username): bool {
		if (empty($username) === true) {
			return false;
		}

		// Basic validation rules (adjust based on your Nextcloud configuration).
		if (strlen($username) < 3 || strlen($username) > 64) {
			return false;
		}

		// Must start with alphanumeric.
		if (preg_match('/^[a-z0-9]/', $username) === 0) {
			return false;
		}

		// Only allow alphanumeric, dots, underscores, dashes, and @ (matching Nextcloud's allowed chars).
		if (preg_match('/^[a-z0-9._@\-]+$/', $username) === 0) {
			return false;
		}

		return true;
	}//end isValidUsername()

	/**
	 * Sanitizes an email address for use as a Nextcloud username.
	 *
	 * Strips subaddressing (the +tag part) from the local part of the email,
	 * since Nextcloud does not allow + in usernames. For example,
	 * "user+tag@example.com" becomes "user@example.com".
	 *
	 * @param string $email The email address to sanitize.
	 *
	 * @return string The sanitized email suitable for use as a username.
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function sanitizeEmailForUsername(string $email): string {
		$lowered = strtolower(trim($email));

		// Strip subaddressing (+tag) from the local part of the email.
		if (strpos($lowered, '+') !== false && strpos($lowered, '@') !== false) {
			$parts = explode(separator: '@', string: $lowered, limit: 2);
			$localPart = preg_replace(pattern: '/\+.*$/', replacement: '', subject: $parts[0]);
			$lowered = $localPart . '@' . $parts[1];
		}

		// Remove any remaining characters not allowed in Nextcloud usernames.
		$lowered = preg_replace(pattern: '/[^a-z0-9._@\-]/', replacement: '', subject: $lowered);

		return $lowered;
	}//end sanitizeEmailForUsername()

	/**
	 * Validates an email address for use as a Nextcloud username.
	 * Returns null if valid, or an error message string if invalid.
	 *
	 * If the email contains subaddressing (a +tag), it is stripped before
	 * validation since Nextcloud does not support + in usernames. The
	 * sanitized form will be used as the actual username.
	 *
	 * @param string $email The email address to validate.
	 *
	 * @return string|null Null if valid, error message if invalid.
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function validateEmailForUsername(string $email): ?string {
		if (empty($email) === true) {
			return 'No email address found on the contact person.';
		}

		if (strpos(haystack: $email, needle: '@') === false) {
			return "The email address \"{$email}\" is not a valid email address (missing @).";
		}

		// Sanitize the email by stripping subaddressing and removing invalid chars.
		$sanitized = $this->sanitizeEmailForUsername(email: $email);

		if (strlen($sanitized) < 3) {
			return "The email address \"{$email}\" is too short to be used as a username (minimum 3 characters).";
		}

		if (strlen($sanitized) > 64) {
			return "The email address \"{$email}\" is too long to be used as a username (maximum 64 characters).";
		}

		// Validate the sanitized result has no remaining invalid characters.
		$invalidChars = preg_replace(pattern: '/[a-z0-9._@\-]/', replacement: '', subject: $sanitized);
		if (empty($invalidChars) === false) {
			$uniqueChars = implode(
				separator: ' ',
				array: array_unique(str_split($invalidChars)),
			);
			return "Invalid username characters: {$uniqueChars}.";
		}

		return null;
	}//end validateEmailForUsername()

	/**
	 * Ensures username is unique by adding counter if needed.
	 *
	 * @param string $username The username to check.
	 *
	 * @return string The unique username.
	 */
	private function ensureUniqueUsername(string $username): string {
		$originalUsername = $username;
		$counter = 1;

		while ($this->_userManager->userExists($username) === true) {
			$username = $originalUsername . $counter;
			$counter++;

			// Safety check to prevent infinite loop.
			if ($counter > 9999) {
				$username = $originalUsername . uniqid();
				break;
			}
		}

		return $username;
	}//end ensureUniqueUsername()

	/**
	 * Creates a user account for a contact person.
	 *
	 * @param object $contactPersonObject The contact person object.
	 * @param bool $isFirstContact Whether this is the first contact.
	 *
	 * @return \OCP\IUser|null The created user or null if failed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isFirstContact is a simple role-assignment toggle
	 * @spec                                        openspec/specs/sc-handlers/spec.md
	 */
	public function createUserAccount(object $contactPersonObject, bool $isFirstContact = false): ?\OCP\IUser {
		$startTime = microtime(true);

		try {
			/* @var array<string, mixed> $objectData */
			$objectData = $contactPersonObject->getObject();
			$contactId = $contactPersonObject->getId();
			$email = $objectData['email'] ?? $objectData['e-mailadres'] ?? '';
			$organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';

			$this->_logger->debug(
				'User account creation started',
				[
					'app' => 'softwarecatalog',
					'contactId' => $contactId,
					'email' => $email,
					'organizationUuid' => $organizationUuid,
					'isFirstContact' => $isFirstContact,
				]
			);

			if (empty($email) === true) {
				$this->_logger->error(
					'❌ USER CREATION FAILED - NO EMAIL',
					[
						'app' => 'softwarecatalog',
						'contactpersoonId' => $contactId,
					]
				);
				return null;
			}

			// Generate username first to check both email and username existence.
			$username = $objectData['username'] ?? '';
			if (empty($username) === true) {
				$this->_logger->info(
					'[USER] Step 1: Generating username',
					[
						'contactId' => $contactId,
						'email' => $email,
					]
				);
				$username = $this->generateUsernameFromContactData(contactData: $objectData);
				$this->_logger->critical(
					'📝 USERNAME GENERATED',
					[
						'app' => 'softwarecatalog',
						'contactId' => $contactId,
						'generatedUsername' => $username,
						'email' => $email,
					]
				);
			}

			// Check if user already exists by email.
			$this->_logger->info(
				'[USER] Step 2: Checking existing user by email',
				[
					'email' => $email,
				]
			);
			if ($this->_userManager->userExists($email) === true) {
				$this->_logger->critical(
					'♻️ USER EXISTS BY EMAIL',
					[
						'app' => 'softwarecatalog',
						'email' => $email,
						'contactpersoonId' => $contactId,
					]
				);

				$existingUser = $this->_userManager->get($email);
				if (empty($existingUser) === false) {
					// Store organization UUID for existing user.
					if (empty($organizationUuid) === false) {
						$this->storeUserOrganizationUuid(user: $existingUser, organizationUuid: $organizationUuid);
					}

					// Store contact name fields for existing user (update if contact data changed).
					$this->storeContactNameFields(user: $existingUser, contactData: $objectData);

					// Update groups for existing user.
					$this->assignUserGroups(user: $existingUser, objectData: $objectData, isFirstContact: $isFirstContact);

					$this->_logger->critical(
						'✅ EXISTING USER UPDATED',
						[
							'app' => 'softwarecatalog',
							'username' => $existingUser->getUID(),
							'email' => $email,
							'organizationUuid' => $organizationUuid,
						]
					);

					return $existingUser;
				}//end if
			}//end if

			// Check if user already exists by username.
			$this->_logger->info(
				'[USER] Step 3: Checking existing user by username',
				[
					'username' => $username,
				]
			);
			$existingUserByUsername = $this->_userManager->get($username);
			if (empty($existingUserByUsername) === false) {
				$this->_logger->critical(
					'♻️ USER EXISTS BY USERNAME',
					[
						'app' => 'softwarecatalog',
						'username' => $username,
						'contactpersoonId' => $contactId,
					]
				);

				// Store organization UUID for existing user.
				if (empty($organizationUuid) === false) {
					$this->storeUserOrganizationUuid(user: $existingUserByUsername, organizationUuid: $organizationUuid);
				}

				// Store contact name fields for existing user (update if contact data changed).
				$this->storeContactNameFields(user: $existingUserByUsername, contactData: $objectData);

				// Update groups for existing user.
				$this->assignUserGroups(
					user: $existingUserByUsername,
					objectData: $objectData,
					isFirstContact: $isFirstContact
				);

				$this->_logger->critical(
					'✅ EXISTING USER UPDATED BY USERNAME',
					[
						'app' => 'softwarecatalog',
						'username' => $username,
						'email' => $existingUserByUsername->getEMailAddress(),
						'organizationUuid' => $organizationUuid,
					]
				);

				return $existingUserByUsername;
			}//end if

			// Create new user account.
			$this->_logger->critical(
				'🚀 CREATING NEW USER ACCOUNT',
				[
					'app' => 'softwarecatalog',
					'username' => $username,
					'email' => $email,
					'contactId' => $contactId,
				]
			);

			// Build a password that satisfies NC default policy (≥10 chars, upper+lower+digit+special).
			$randomPw = $this->_secureRandom->generate(length: 4, characters: ISecureRandom::CHAR_UPPER)
				. $this->_secureRandom->generate(length: 4, characters: ISecureRandom::CHAR_LOWER)
				. $this->_secureRandom->generate(length: 2, characters: ISecureRandom::CHAR_DIGITS)
				. $this->_secureRandom->generate(length: 2, characters: '!@#$%^&*()-_=+[]');
			$user = $this->_userManager->createUser(uid: $username, password: $randomPw);

			if (empty($user) === false) {
				$this->_logger->critical(
					'🎊 NEW USER ACCOUNT CREATED',
					[
						'app' => 'softwarecatalog',
						'username' => $username,
						'email' => $email,
						'contactId' => $contactId,
						'userId' => $user->getUID(),
					]
				);

				// Note: filesystem pre-warming via exec() has been removed.
				// The exec() spawned a raw PHP process that used \OC::$server (fatal on NC 34)
				// and created a fork-bomb risk when user creation is triggered from an
				// unauthenticated path. NC performs setupFS/copySkeleton automatically on
				// first login without any pre-warming.
				// Set user details.
				$this->_logger->info(
					'[USER] Step 4: Setting user details',
					[
						'username' => $username,
					]
				);
				$user->setEMailAddress($email);
				$displayName = $this->getDisplayNameFromContactData(contactData: $objectData);
				$user->setDisplayName($displayName);

				// Store contact name fields in Nextcloud user config for /me endpoint.
				$this->storeContactNameFields(user: $user, contactData: $objectData);

				$this->_logger->critical(
					'📋 USER DETAILS SET',
					[
						'app' => 'softwarecatalog',
						'username' => $username,
						'email' => $email,
						'displayName' => $displayName,
						'firstName' => $objectData['voornaam'] ?? '',
						'middleName' => $objectData['tussenvoegsel'] ?? '',
						'lastName' => $objectData['achternaam'] ?? '',
						'role' => $objectData['role'] ?? '',
					]
				);

				// Store organization UUID in user config for OpenConnector access.
				if (empty($organizationUuid) === false) {
					$this->_logger->info(
						'[USER] Step 5: Storing organization UUID',
						[
							'username' => $username,
							'organizationUuid' => $organizationUuid,
						]
					);
					$this->storeUserOrganizationUuid(user: $user, organizationUuid: $organizationUuid);
				}

				// Set user groups based on roles and organization.
				$this->_logger->info(
					'[USER] Step 6: Assigning user groups',
					[
						'username' => $username,
						'isFirstContact' => $isFirstContact,
					]
				);
				$assignedRole = $this->assignUserGroups(
					user: $user,
					objectData: $objectData,
					isFirstContact: $isFirstContact
				);

				// Read the existing roles BEFORE writing username: assigning a key
				// narrows the inferred array shape, after which phpstan reports
				// every other offset as non-existent.
				$currentRoles = $objectData['roles'] ?? [];

				// Update the contact person with username and auto-assigned role.
				$objectData['username'] = $username;
				if (empty($assignedRole) === false
					&& (empty($currentRoles) === true || is_array($currentRoles) === false)
				) {
					$objectData['roles'] = [$assignedRole];
					$this->_logger->info(
						'Auto-populated rollen field on contactpersoon',
						[
							'username' => $username,
							'assignedRole' => $assignedRole,
						]
					);
				}

				$contactPersonObject->setObject($objectData);

				// Send user creation email.
				$this->_logger->info(
					'[USER] Step 7: Sending user creation email',
					[
						'username' => $username,
						'email' => $email,
					]
				);
				$this->sendUserCreationEmail(user: $user, objectData: $objectData);

				$creationTime = round(microtime(true) - $startTime, 3);
				$this->_logger->critical(
					'🎉 USER ACCOUNT CREATION COMPLETED',
					[
						'app' => 'softwarecatalog',
						'contactpersoonId' => $contactId,
						'username' => $username,
						'email' => $email,
						'displayName' => $displayName,
						'organizationUuid' => $organizationUuid,
						'creationTime' => $creationTime . 's',
					]
				);

				return $user;
			}//end if

			$this->_logger->error(
				'❌ USER CREATION RETURNED NULL',
				[
					'app' => 'softwarecatalog',
					'username' => $username,
					'email' => $email,
					'contactpersoonId' => $contactId,
					'note' => 'No exception thrown but createUser returned null',
				]
			);

			return null;
		} catch (\Exception $e) {
			$this->_logger->error(
				'💥 USER CREATION EXCEPTION',
				[
					'app' => 'softwarecatalog',
					'contactpersoonId' => $contactPersonObject->getId(),
					'email' => $objectData['email'] ?? $objectData['e-mailadres'] ?? 'unknown',
					'username' => $username ?? 'unknown',
					'exception' => $e->getMessage(),
					'exception_class' => get_class($e),
					'exception_code' => $e->getCode(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return null;
		}//end try
	}//end createUserAccount()

	/**
	 * Assigns user groups based on organization type and roles
	 *
	 * @param \OCP\IUser $user The user to assign groups to
	 * @param array $objectData The contact person data
	 * @param bool $isFirstContact Whether this is the first contact for the organization
	 *
	 * @return void
	 */

	/**
	 * Assign user to appropriate groups based on their role and organization.
	 *
	 * Users are NOT added to generic groups or organization-specific groups.
	 * Users are tied to organization entities in OpenRegister instead.
	 *
	 * @param \OCP\IUser $user The user to assign groups to.
	 * @param array $objectData The contactpersoon object data.
	 * @param bool $isFirstContact Whether this is the first contact of the organization.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isFirstContact is a simple role-assignment toggle
	 */
	private function assignUserGroups(\OCP\IUser $user, array $objectData, bool $isFirstContact = false): string {
		$assignedRole = '';

		try {
			$roles = $objectData['roles'] ?? [];
			$organizationId = $objectData['organisation'] ?? $objectData['organization'] ?? '';

			// Ensure roles is an array.
			if (is_array($roles) === false) {
				$roles = [$roles];
			}

			// Get the settings service to access group configurations.
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');

			// Add user to organization admin groups if this is the first contact.
			if ($isFirstContact === true) {
				$organizationAdminGroups = $settingsService->getOrganizationAdminGroups();
				foreach ($organizationAdminGroups as $groupName) {
					$this->addUserToGroupWithCheck(user: $user, groupName: $groupName, type: 'organization-admin');
				}

				$this->_logger->info(
					'Assigned organization admin groups to first contact',
					[
						'username' => $user->getUID(),
						'organizationId' => $organizationId,
						'adminGroups' => $organizationAdminGroups,
					]
				);
			}

			// Assign role based on organization type.
			if (empty($organizationId) === false) {
				$organizationType = $this->getOrganizationType(organizationId: (string)$organizationId);
				$roleGroup = $this->getRoleGroupByOrganizationType(organizationType: $organizationType);

				if (empty($roleGroup) === true) {
					$this->_logger->warning(
						'No role mapping found for organization type',
						[
							'username' => $user->getUID(),
							'organizationId' => $organizationId,
							'organizationType' => $organizationType,
						]
					);
				}

				if (empty($roleGroup) === false) {
					$this->addUserToGroupWithCheck(user: $user, groupName: $roleGroup, type: 'organization-type-role');

					// Map the lowercase group name to the title-case enum value for the rollen field.
					$assignedRole = $this->mapGroupNameToRolesEnum(groupName: $roleGroup);

					$this->_logger->info(
						'Assigned role based on organization type',
						[
							'username' => $user->getUID(),
							'organizationId' => $organizationId,
							'organizationType' => $organizationType,
							'assignedRole' => $roleGroup,
							'rollenEnumValue' => $assignedRole,
						]
					);
				}//end if
			}//end if

			// Users are now tied to organisation entities in OpenRegister.
			// No need to add to organization-specific groups.
			$organizationAdminGroupsValue = [];
			if ($isFirstContact === true) {
				$organizationAdminGroupsValue = ['gebruik-beheerder'];
			}

			$this->_logger->info(
				'Successfully assigned user groups based on organization type',
				[
					'username' => $user->getUID(),
					'isFirstContact' => $isFirstContact,
					'organizationAdminGroups' => $organizationAdminGroupsValue,
					'organizationId' => $organizationId,
					'roleGroup' => $roleGroup ?? 'none',
					'organizationType' => $organizationType ?? 'unknown',
					'assignedRollenEnum' => $assignedRole,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to assign user groups: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'exception' => $e,
				]
			);
		}//end try

		return $assignedRole;
	}//end assignUserGroups()

	/**
	 * Maps a lowercase group name to the title-case rollen enum value
	 *
	 * The contactpersoon schema uses title-case enum values (e.g., "Gebruik-beheerder")
	 * while Nextcloud groups use lowercase (e.g., "gebruik-beheerder").
	 *
	 * @param string $groupName The lowercase group name
	 *
	 * @return string The title-case enum value for the rollen field
	 */
	private function mapGroupNameToRolesEnum(string $groupName): string {
		$mapping = [
			'aanbod-beheerder' => 'Aanbod-beheerder',
			'gebruik-beheerder' => 'Gebruik-beheerder',
			'gebruik-raadpleger' => 'Gebruik-raadpleger',
			'functioneel-beheerder' => 'Functioneel-beheerder',
			'organisatie-beheerder' => 'Organisatie-beheerder',
		];

		return $mapping[strtolower(trim($groupName))] ?? '';
	}//end mapGroupNameToRollenEnum()

	/**
	 * Gets the mapping of allowed roles to group names
	 *
	 * @return array Array mapping role names to group names
	 */
	private function getAllowedRoleGroups(): array {
		return [
			'Aanbod-beheerder' => 'Aanbod-beheerder',
			'Gebruik-beheerder' => 'Gebruik-beheerder',
			'Gebruik-raadpleger' => 'Gebruik-raadpleger',
			'Functioneel-beheerder' => 'Functioneel-beheerder',
			'VNG-raadpleger' => 'VNG-raadpleger',
			'Organisatie-beheerder' => 'Organisatie-beheerder',
			'Ambtenaar' => 'Ambtenaar',
		];
	}//end getAllowedRoleGroups()

	/**
	 * Adds a user to a group, creating the group if it doesn't exist
	 *
	 * @param \OCP\IUser $user The user to add
	 * @param string $groupName The group name
	 * @param string $type The type of group assignment (for logging)
	 *
	 * @return void
	 */
	private function addUserToGroup(\OCP\IUser $user, string $groupName, string $type): void {
		try {
			$group = $this->_groupManager->get($groupName);
			if ($group === null) {
				$group = $this->_groupManager->createGroup($groupName);
				if (empty($group) === false) {
					$this->_logger->info(
						'Created group for user assignment',
						['groupName' => $groupName, 'type' => $type]
					);
				}
			}

			if ($group !== false && $group->inGroup($user) === false) {
				$group->addUser($user);
				$this->_logger->info(
					'Added user to group',
					[
						'username' => $user->getUID(),
						'groupName' => $groupName,
						'type' => $type,
					]
				);
			}
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to add user to group: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'groupName' => $groupName,
					'type' => $type,
					'exception' => $e,
				]
			);
		}//end try
	}//end addUserToGroup()

	/**
	 * Adds a user to a group only if the group exists, does not create new groups
	 *
	 * @param \OCP\IUser $user The user to add to the group
	 * @param string $groupName The name of the group
	 * @param string $type The type of group assignment for logging
	 *
	 * @return void
	 */
	private function addUserToGroupWithCheck(\OCP\IUser $user, string $groupName, string $type): void {
		try {
			$group = $this->_groupManager->get($groupName);

			if ($group === null) {
				$this->_logger->warning(
					'Group does not exist, skipping user assignment',
					[
						'username' => $user->getUID(),
						'groupName' => $groupName,
						'type' => $type,
					]
				);
				return;
			}

			if ($group->inGroup($user) === true) {
				$this->_logger->debug(
					'User already in group',
					[
						'username' => $user->getUID(),
						'groupName' => $groupName,
						'type' => $type,
					]
				);
				return;
			}

			$group->addUser($user);
			$this->_logger->info(
				'Added user to existing group',
				[
					'username' => $user->getUID(),
					'groupName' => $groupName,
					'type' => $type,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to add user to group with check: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'groupName' => $groupName,
					'type' => $type,
					'exception' => $e,
				]
			);
		}//end try
	}//end addUserToGroupWithCheck()

	/**
	 * Updates user groups when contact person data changes
	 * Note: Role assignment is now based on organization type, not individual roles
	 *
	 * @param \OCP\IUser $user The user to update
	 * @param array $contactData The updated contact person data
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function updateUserGroupsFromContactData(\OCP\IUser $user, array $contactData): void {
		try {
			$organizationId = $contactData['organisation'] ?? $contactData['organization'] ?? '';

			if (empty($organizationId) === true) {
				$this->_logger->warning(
					'No organization ID found for user group update',
					['username' => $user->getUID()]
				);
				return;
			}

			// Get organization type and determine role group.
			$organizationType = $this->getOrganizationType(organizationId: (string)$organizationId);
			$newRoleGroup = $this->getRoleGroupByOrganizationType(organizationType: $organizationType);

			if (empty($newRoleGroup) === true) {
				$this->_logger->warning(
					'No role group mapping found for organization type during update',
					[
						'username' => $user->getUID(),
						'organizationId' => $organizationId,
						'organizationType' => $organizationType,
					]
				);
				return;
			}

			// Remove user from old organization type role groups.
			$allPossibleRoleGroups = ['gebruik-beheerder', 'aanbod-beheerder'];
			foreach ($allPossibleRoleGroups as $roleGroup) {
				if ($roleGroup !== $newRoleGroup) {
					$group = $this->_groupManager->get($roleGroup);
					if ($group !== false && $group->inGroup($user) === true) {
						$group->removeUser($user);
						$this->_logger->info(
							'Removed user from old organization type role group',
							[
								'username' => $user->getUID(),
								'groupName' => $roleGroup,
								'reason' => 'organization type changed',
							]
						);
					}
				}
			}

			// Add user to new role group if it exists.
			$this->addUserToGroupWithCheck(user: $user, groupName: $newRoleGroup, type: 'organization-type-role-update');

			$this->_logger->info(
				'Updated user groups based on organization type',
				[
					'username' => $user->getUID(),
					'organizationId' => $organizationId,
					'organizationType' => $organizationType,
					'assignedRole' => $newRoleGroup,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to update user groups from contact data: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'exception' => $e,
				]
			);
		}//end try
	}//end updateUserGroupsFromContactData()

	/**
	 * Legacy method for backward compatibility - now redirects to organization type-based logic
	 *
	 * @param \OCP\IUser $user The user to update
	 * @param array $newRoles The new roles (ignored - kept for compatibility)
	 * @param array $oldRoles The old roles (ignored - kept for compatibility)
	 *
	 * @return void
	 * @deprecated Use updateUserGroupsFromContactData instead
	 * @spec       openspec/specs/sc-handlers/spec.md
	 */
	public function updateUserGroupsFromRoles(\OCP\IUser $user, array $newRoles, array $oldRoles = []): void {
		$this->_logger->info(
			'updateUserGroupsFromRoles is deprecated - role assignment now based on organization type',
			[
				'username' => $user->getUID(),
				'newRoles' => $newRoles,
				'oldRoles' => $oldRoles,
			]
		);

		// For backward compatibility, try to find the user's contact data and update based on organization type.
		try {
			$contactObject = $this->findContactPersonByUsername(username: $user->getUID());
			if (empty($contactObject) === true) {
				$this->_logger->warning(
					'Could not find contact person data for user - cannot update groups',
					['username' => $user->getUID()]
				);
			}

			if (empty($contactObject) === false) {
				$contactData = $contactObject->getObject();
				$this->updateUserGroupsFromContactData(user: $user, contactData: $contactData);
			}
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to update user groups via legacy method: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'exception' => $e,
				]
			);
		}//end try
	}//end updateUserGroupsFromRoles()

	/**
	 * Finds contactpersoon object by username
	 *
	 * @param string $username The username to search for
	 *
	 * @return object|null The contactpersoon object or null if not found
	 */
	private function findContactPersonByUsername(string $username): ?object {
		try {
			$objectService = $this->getObjectService();
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');

			// Get configuration values.
			$registerId = $settingsService->getVoorzieningenRegisterId();
			$contactPersonSchemaId = $settingsService->getSchemaIdForObjectType('contactPerson');

			if ($registerId === null || $contactPersonSchemaId === false) {
				throw new \Exception('Register or schema ID not configured for contactpersoon');
			}

			// Search for contactpersoon with the given username.
			$searchFilters = [
				'username' => $username,
			];

			$results = $objectService->findAll($searchFilters, $registerId, $contactPersonSchemaId);

			if (empty($results) === false) {
				return $results[0];
				// Return the first match.
			}

			return null;
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to find contactpersoon by username: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
			return null;
		}//end try
	}//end findContactpersoonByUsername()

	/**
	 * Gets the organization group for a given organization ID
	 *
	 * @param string $organizationId The organization ID
	 *
	 * @return \OCP\IGroup|null The organization group or null if not found
	 */
	private function getOrganizationGroup(string $organizationId): ?\OCP\IGroup {
		try {
			// Get the organization object to find its group.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return null;
			}

			// Get register and schema IDs dynamically from configuration.
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
			$registerId = $settingsService->getVoorzieningenRegisterId();
			$organisationSchemaId = $settingsService->getSchemaIdForObjectType('organization');

			if ($registerId === null || $organisationSchemaId === false) {
				$this->_logger->warning('Register or schema ID not configured for organisatie');
				return null;
			}

			// Use find() method with proper register/schema context.
			$organizationObject = $objectService->find($organizationId, [], false, $registerId, $organisationSchemaId);

			if (empty($organizationObject) === false) {
				$organizationData = $organizationObject->getObject();
				$groupId = $organizationData['group'] ?? '';

				if (empty($groupId) === false) {
					$group = $this->_groupManager->get($groupId);
					return $group;
				}
			}

			return null;
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to get organization group: ' . $e->getMessage(),
				[
					'organizationId' => $organizationId,
					'exception' => $e,
				]
			);
			return null;
		}//end try
	}//end getOrganizationGroup()

	/**
	 * Determines if this contact object is the first contact for the organization
	 *
	 * @param object $contactObject The contact object being processed (contactpersoon)
	 * @param array $objectData The contact data
	 *
	 * @return bool True if this is the first contact for the organization
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function isFirstContactForOrganization(object $contactObject, array $objectData): bool {
		// Simplified approach: Default to true so the first contact always gets admin rights.
		// A more sophisticated check can be implemented later if needed to track.
		// whether other contacts already exist for this organization.
		$this->_logger->info(
			'isFirstContactForOrganization: Defaulting to true (simplified)',
			[
				'app' => 'softwarecatalog',
				'contactId' => $contactObject->getId(),
				'contactUuid' => $contactObject->getUuid(),
			]
		);

		return true;
	}//end isFirstContactForOrganization()

	/**
	 * Stores organization UUID in user config for OpenConnector access
	 *
	 * This method stores the organization UUID in the user's 'core' namespace
	 * configuration, making it accessible to other apps like OpenConnector.
	 * It also sets the user's active organisation in OpenRegister so they're
	 * automatically logged into the correct organisation.
	 *
	 * @param IUser $user The user object
	 * @param string|int $organizationUuid The organization UUID (can be string or int)
	 *
	 * @return void
	 */
	private function storeUserOrganizationUuid(IUser $user, string|int $organizationUuid): void {
		try {
			if (empty($organizationUuid) === false) {
				// Convert to string to ensure consistent storage.
				$organizationUuidStr = (string)$organizationUuid;

				// Store in core config for OpenConnector access.
				$this->config->setUserValue(
					$user->getUID(),
					'core',
					'organisation',
					$organizationUuidStr
				);

				// Also set as active organisation in OpenRegister.
				try {
					$this->config->setUserValue(
						$user->getUID(),
						'openregister',
						'active_organisation',
						$organizationUuidStr
					);

					$this->_logger->info(
						'Stored organization UUID in user config and set as active organisation',
						[
							'username' => $user->getUID(),
							'organizationUuid' => $organizationUuidStr,
							'organizationUuid_type' => gettype($organizationUuid),
						]
					);
				} catch (\Exception $e) {
					$this->_logger->warning(
						'Failed to set active organisation in OpenRegister config, but core config was successful',
						[
							'username' => $user->getUID(),
							'organizationUuid' => $organizationUuidStr,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end if
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to store organization UUID in user config: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'organizationUuid' => $organizationUuid,
					'organizationUuid_type' => gettype($organizationUuid),
					'exception' => $e,
				]
			);
		}//end try
	}//end storeUserOrganizationUuid()

	/**
	 * Gets a display name from contact data
	 *
	 * @param array $contactData The contact data
	 *
	 * @return string The display name
	 */
	private function getDisplayNameFromContactData(array $contactData): string {
		$parts = array_filter(
			[
				$contactData['voornaam'] ?? '',
				$contactData['tussenvoegsel'] ?? '',
				$contactData['achternaam'] ?? '',
			]
		);

		$fullName = implode(' ', $parts);
		if (empty($fullName) === false) {
			return $fullName;
		}

		return ($contactData['email'] ?? $contactData['e-mailadres'] ?? 'Unknown User');
	}//end getDisplayNameFromContactData()

	/**
	 * Stores contact person name fields in Nextcloud user config
	 *
	 * This method stores the contact person's name fields (firstName, lastName, middleName)
	 * and functie (role) in the Nextcloud user configuration so they can be retrieved
	 * by the /me endpoint in OpenRegister's UserService.
	 *
	 * @param \OCP\IUser $user The user to store fields for
	 * @param array $contactData The contact data containing name fields
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function storeContactNameFields(\OCP\IUser $user, array $contactData): void {
		try {
			$userId = $user->getUID();

			// Store name fields in Nextcloud user config (core app).
			// These are read by OpenRegister UserService::getCustomNameFields().
			$firstName = $contactData['voornaam'] ?? '';
			$middleName = $contactData['tussenvoegsel'] ?? '';
			$lastName = $contactData['achternaam'] ?? '';
			$role = $contactData['role'] ?? '';

			if (empty($firstName) === false) {
				$this->config->setUserValue($userId, 'core', 'firstName', $firstName);
			}

			if (empty($lastName) === false) {
				$this->config->setUserValue($userId, 'core', 'lastName', $lastName);
			}

			if (empty($middleName) === false) {
				$this->config->setUserValue($userId, 'core', 'middleName', $middleName);
			}

			// Store functie in AccountManager as 'role' property.
			// This is read by OpenRegister UserService via AccountManager.
			if (empty($role) === false) {
				try {
					$accountManager = $this->_container->get('OCP\Accounts\IAccountManager');
					$account = $accountManager->getAccount($user);

					// Try to set the role property.
					$roleProperty = $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_ROLE);
					if ($roleProperty === null) {
						// Property doesn't exist, create it.
						$account->setProperty(
							\OCP\Accounts\IAccountManager::PROPERTY_ROLE,
							$role,
							\OCP\Accounts\IAccountManager::SCOPE_LOCAL,
							\OCP\Accounts\IAccountManager::NOT_VERIFIED
						);
						$accountManager->updateAccount($account);
					}

					if ($roleProperty !== null) {
						$roleProperty->setValue($role);
						$accountManager->updateAccount($account);
					}
				} catch (\Exception $e) {
					// Fallback: store functie in user config if AccountManager fails.
					$this->config->setUserValue($userId, 'core', 'role', $role);
					$this->_logger->warning(
						'Failed to store functie in AccountManager, stored in user config',
						[
							'userId' => $userId,
							'role' => $role,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end if

			// Sync e-mailadres to user's email property.
			$email = $contactData['e-mailadres'] ?? $contactData['email'] ?? '';
			if (empty($email) === false && $email !== $user->getEMailAddress()) {
				$user->setEMailAddress($email);
				$this->_logger->info(
					'Updated user email from contactpersoon',
					[
						'userId' => $userId,
						'email' => $email,
					]
				);
			}

			$this->_logger->info(
				'Stored contact name fields in user config',
				[
					'userId' => $userId,
					'firstName' => $firstName,
					'middleName' => $middleName,
					'lastName' => $lastName,
					'role' => $role,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to store contact name fields',
				[
					'userId' => $user->getUID(),
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end storeContactNameFields()

	/**
	 * Handles new contact creation
	 *
	 * @param object $contactObject The contact object
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function handleNewContact(object $contactObject): void {
		try {
			$this->_logger->info(
				'Handling new contact',
				[
					'objectId' => $contactObject->getId(),
				]
			);

			// Process the contact to ensure proper user structure.
			$this->processContactpersoon(contactPersonObject: $contactObject);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to handle new contact: ' . $e->getMessage(),
				[
					'objectId' => $contactObject->getId(),
					'exception' => $e,
				]
			);
		}
	}//end handleNewContact()

	/**
	 * Handles contact update
	 *
	 * @param object $contactObject The contact object
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function handleContactUpdate(object $contactObject): void {
		try {
			$this->_logger->info(
				'Handling contact update',
				[
					'objectId' => $contactObject->getId(),
				]
			);

			// Process the updated contact.
			$this->processContactpersoon(contactPersonObject: $contactObject);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to handle contact update: ' . $e->getMessage(),
				[
					'objectId' => $contactObject->getId(),
					'exception' => $e,
				]
			);
		}
	}//end handleContactUpdate()

	/**
	 * Handles contact deletion
	 *
	 * @param object $contactObject The contact object
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function handleContactDeletion(object $contactObject): void {
		try {
			$this->_logger->info(
				'Handling contact deletion',
				[
					'objectId' => $contactObject->getId(),
				]
			);

			// Get the contact data before deletion.
			$objectData = $contactObject->getObject();
			$username = $objectData['username'] ?? '';

			if (empty($username) === false) {
				$user = $this->_userManager->get($username);
				if (empty($user) === false) {
					// Option 1: Delete the user account.
					// $user->delete();.
					// Option 2: Just disable the user.
					$user->setEnabled(false);

					$this->_logger->info(
						'User account disabled due to contact deletion',
						[
							'username' => $username,
							'contactId' => $contactObject->getId(),
						]
					);

					// Send account suspension notification email.
					$this->sendAccountSuspensionEmail(user: $user);
				}
			}//end if
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to handle contact deletion: ' . $e->getMessage(),
				[
					'objectId' => $contactObject->getId(),
					'exception' => $e,
				]
			);
		}//end try
	}//end handleContactDeletion()

	/**
	 * Assigns beheerder role to a user
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function assignBeheerderRole(object $contactPersonObject, string $username, string $organizationUuid): void {
		try {
			$objectData = $contactPersonObject->getObject();
			$currentRoles = $objectData['roles'] ?? [];

			if (is_array($currentRoles) === false) {
				$currentRoles = [];
			}

			// Add beheerder role if not already present.
			if (in_array('maintainer', array_map('strtolower', $currentRoles)) === false) {
				$currentRoles[] = 'maintainer';

				// Update the contactpersoon object (but don't save to prevent event loops).
				$objectData['roles'] = $currentRoles;
				$contactPersonObject->setObject($objectData);

				// Note: NOT saving the object here to prevent infinite event loops.
				// The original API call/operation will handle persistence.
				$this->_logger->info(
					'Beheerder role added to contactpersoon object, but not saved to prevent event loops',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
						'updatedRoles' => $currentRoles,
						'objectId' => $contactPersonObject->getId(),
					]
				);

				// Add user to beheerder group.
				$maintainerGroup = $this->_groupManager->get('maintainer');
				if ($maintainerGroup === null) {
					$maintainerGroup = $this->_groupManager->createGroup('maintainer');
				}

				if (empty($maintainerGroup) === false) {
					$user = $this->_userManager->get($username);
					if ($user !== false && $maintainerGroup->inGroup($user) === false) {
						$maintainerGroup->addUser($user);
					}
				}

				$this->_logger->info(
					'Assigned beheerder role to first user in organization',
					[
						'username' => $username,
						'organization' => $organizationUuid,
						'newRoles' => $currentRoles,
					]
				);
			}//end if
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to assign beheerder role: ' . $e->getMessage(),
				[
					'username' => $username,
					'organization' => $organizationUuid,
					'exception' => $e,
				]
			);
		}//end try
	}//end assignBeheerderRole()

	/**
	 * Sets a user's manager in Nextcloud
	 *
	 * @param string $username The username
	 * @param string $managerUsername The manager's username
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function setUserManager(string $username, string $managerUsername): void {
		try {
			$user = $this->_userManager->get($username);
			$manager = $this->_userManager->get($managerUsername);

			if ($user === null || $manager === false) {
				$this->_logger->warning(
					'Cannot set manager - user or manager not found',
					[
						'username' => $username,
						'manager' => $managerUsername,
						'userExists' => $user !== null,
						'managerExists' => $manager !== null,
					]
				);
				return;
			}

			// In Nextcloud, we can set this as a user preference or custom attribute.
			// Since there's no built-in manager field, we'll use preferences.
			$this->config->setUserValue(
				$username,
				'softwarecatalog',
				'manager',
				$managerUsername
			);

			$this->_logger->info(
				'Set user manager',
				[
					'username' => $username,
					'manager' => $managerUsername,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to set user manager: ' . $e->getMessage(),
				[
					'username' => $username,
					'manager' => $managerUsername,
					'exception' => $e,
				]
			);
		}//end try
	}//end setUserManager()

	/**
	 * Gets a user's manager
	 *
	 * @param string $username The username
	 *
	 * @return string|null The manager's username or null if not set
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function getUserManager(string $username): ?string {
		try {
			$manager = $this->config->getUserValue(
				$username,
				'softwarecatalog',
				'manager',
				''
			);

			if (empty($manager) === false) {
				return $manager;
			}

			return null;
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to get user manager: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
			return null;
		}//end try
	}//end getUserManager()

	/**
	 * Gets the organization type for a given organization ID
	 *
	 * @param string $organizationId The organization ID
	 *
	 * @return string The organization type or empty string if not found
	 */
	private function getOrganizationType(string $organizationId): string {
		try {
			// Get the organization object to find its type.
			$objectService = $this->getObjectService();

			$this->_logger->info(
				'Getting organization type',
				[
					'organizationId' => $organizationId,
				]
			);

			// Get voorzieningen config for register and schema.
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
			$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
			$register = $voorzieningenConfig['register'] ?? '';
			$organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';

			// Find by UUID - use find() with register and schema.
			$organizationObject = $objectService->find(
				id: $organizationId,
				register: $register,
				schema: $organizationSchema,
				_rbac: false,
				_multitenancy: false
			);

			if (empty($organizationObject) === false) {
				$organizationData = $organizationObject->getObject();
				$organizationType = $organizationData['type'] ?? '';

				$this->_logger->info(
					'Found organization type',
					[
						'organizationId' => $organizationId,
						'type' => $organizationType,
						'normalizedType' => strtolower($organizationType),
					]
				);

				return $organizationType;
				// Don't convert to lowercase here, let getRoleGroupByOrganizationType handle it.
			}

			$this->_logger->warning(
				'Organization not found',
				[
					'organizationId' => $organizationId,
				]
			);
			return '';
		} catch (\Exception $e) {
			// "Object not found" is expected for NC org UUIDs that don't have a matching.
			// register organisatie object (e.g. orgs created outside the sync process).
			// Log at warning level — the caller handles '' gracefully.
			$this->_logger->warning(
				'Could not determine organization type (org may not be synced yet): ' . $e->getMessage(),
				[
					'organizationId' => $organizationId,
				]
			);
			return '';
		}//end try
	}//end getOrganizationType()

	/**
	 * Maps organization type to role group based on business rules
	 *
	 * @param string $organizationType The organization type (case-insensitive)
	 *
	 * @return string The role group name or empty string if no mapping exists
	 */
	private function getRoleGroupByOrganizationType(string $organizationType): string {
		// Normalize the organization type to lowercase for comparison.
		$normalizedType = strtolower(trim($organizationType));

		// Define the mapping based on requirements:.
		// "Municipality" -> "gebruik-beheerder".
		// "Supplier" -> "aanbod-beheerder".
		// "Collaboration" -> "gebruik-beheerder".
		// "Community" -> "aanbod-beheerder".
		$typeToRoleMapping = [
			'gemeente' => 'gebruik-beheerder',
			'leverancier' => 'aanbod-beheerder',
			'samenwerking' => 'gebruik-beheerder',
			'community' => 'aanbod-beheerder',
		];

		return $typeToRoleMapping[$normalizedType] ?? '';
	}//end getRoleGroupByOrganizationType()

	/**
	 * Sends user creation email
	 *
	 * @param \OCP\IUser $user The created user
	 * @param array $objectData The contact person data
	 *
	 * @return void
	 */
	private function sendUserCreationEmail(\OCP\IUser $user, array $objectData): void {

		try {
			$this->_logger->info(
				'Sending user creation email',
				[
					'username' => $user->getUID(),
					'email' => $user->getEMailAddress(),
				]
			);

			// Prepare user data for email.
			$userData = [
				'username' => $user->getUID(),
				'email' => $user->getEMailAddress(),
				'displayName' => $user->getDisplayName(),
				'voornaam' => $objectData['voornaam'] ?? '',
				'achternaam' => $objectData['achternaam'] ?? '',
				'roles' => $objectData['roles'] ?? [],
			];

			// Get organization data if available.
			$organizationData = [];
			$organizationId = $objectData['organisation'] ?? $objectData['organization'] ?? '';
			if (empty($organizationId) === false) {
				try {
					$objectService = $this->getObjectService();
					// Get register and schema IDs dynamically from configuration.
					$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
					$registerId = $settingsService->getVoorzieningenRegisterId();
					$organisationSchemaId = $settingsService->getSchemaIdForObjectType('organization');

					if ($registerId === null || $organisationSchemaId === false) {
						$this->_logger->warning('Register or schema ID not configured for organisatie');
						return;
					}

					$organizationObject = $objectService->find(
						$organizationId,
						[],
						false,
						$registerId,
						$organisationSchemaId
					);
					if (empty($organizationObject) === false) {
						$organizationData = $organizationObject->getObject();
						$this->_logger->info(
							'Retrieved organization data for email',
							[
								'organizationId' => $organizationId,
								'organizationUuid' => $organizationData['id'] ?? 'NOT_SET',
								'organizationName' => $organizationData['name'] ?? 'NOT_SET',
							]
						);
					}
				} catch (\Exception $e) {
					$this->_logger->warning(
						'Failed to get organization data for email: ' . $e->getMessage(),
						[
							'organizationId' => $organizationId,
						]
					);
				}//end try
			}//end if

			// Send user creation email.
			$success = $this->_emailService->sendUserCreationEmail($userData, $organizationData);

			if ($success === true) {
				$this->_logger->info(
					'User creation email sent successfully',
					[
						'username' => $user->getUID(),
						'email' => $user->getEMailAddress(),
					]
				);
			}

			if ($success !== true) {
				$this->_logger->warning(
					'Failed to send user creation email',
					[
						'username' => $user->getUID(),
						'email' => $user->getEMailAddress(),
					]
				);
			}
		} catch (\Exception $e) {
			$this->_logger->error(
				'Exception sending user creation email: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'email' => $user->getEMailAddress(),
					'exception' => $e,
				]
			);
		}//end try
	}//end sendUserCreationEmail()

	/**
	 * Processes a contactpersoon object to create an inactive user
	 *
	 * If the contactpersoon object doesn't have a username or user,
	 * this method will create an inactive user account and set the username property.
	 *
	 * @param object $contactPersonObject The contactpersoon object to process
	 * @param bool $isUpdate Whether this is an update operation (defaults to false)
	 *
	 * @return bool True if processing was successful
	 * @throws \Exception If processing fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isUpdate is a simple create-vs-update toggle
	 * @spec                                        openspec/specs/sc-handlers/spec.md
	 */
	public function processContactpersoon(object $contactPersonObject, bool $isUpdate = false): bool {
		try {
			$this->_logger->info(
				'Processing contactpersoon object',
				[
					'objectId' => $contactPersonObject->getId(),
					'isUpdate' => $isUpdate,
				]
			);

			// Get object data.
			$objectData = $contactPersonObject->getObject();

			// Check if username exists and is filled.
			$username = $objectData['username'] ?? '';

			if (empty($username) === true) {
				$this->_logger->info('Username not found or empty, creating inactive user account');

				// Generate username from name fields.
				$username = $this->generateUsernameFromContactData(contactData: $objectData);

				// For updates, try to find existing user first to avoid expensive isFirstContactForOrganization check.
				if ($isUpdate === true) {
					$existingUser = $this->_userManager->get($username);

					if (empty($existingUser) === false) {
						$this->_logger->info(
							'Found existing user during update, skipping expensive first contact check',
							[
								'username' => $username,
								'objectId' => $contactPersonObject->getId(),
							]
						);

						// Update the contactpersoon object with the username (but don't save to prevent event loops).
						$objectData['username'] = $username;
						$contactPersonObject->setObject($objectData);

						$this->_logger->info(
							'Username added to contactpersoon during update, not saved to prevent event loops',
							[
								'username' => $username,
								'objectId' => $contactPersonObject->getId(),
							]
						);

						// Ensure contactpersoon is added to organization.
						$this->ensureContactpersoonInOrganization(contactPersonObject: $contactPersonObject);

						return true;
					}//end if
				}//end if

				// Determine if this is the first contact for the organization (expensive operation).
				$isFirstContact = $this->isFirstContactForOrganization(
					contactObject: $contactPersonObject,
					objectData: $objectData
				);

				// Create the user account.
				$user = $this->createUserAccount(
					contactPersonObject: $contactPersonObject,
					isFirstContact: $isFirstContact
				);

				if ($user === null) {
					throw new \Exception('Failed to create user account');
				}

				// Set user to inactive initially.
				$this->setUserInactive(username: $user->getUID());

				// Update the contactpersoon object with the username (but don't save to prevent event loops).
				$objectData['username'] = $username;
				$contactPersonObject->setObject($objectData);

				// Note: NOT saving the object here to prevent infinite event loops.
				// The original API call/operation will handle persistence.
				$this->_logger->info(
					'Username added to contactpersoon object, but not saved to prevent event loops',
					[
						'username' => $username,
						'objectId' => $contactPersonObject->getId(),
					]
				);

				// Ensure contactpersoon is added to organization.
				$this->ensureContactpersoonInOrganization(contactPersonObject: $contactPersonObject);

				// Also add user to organization entity (OpenRegister entity, not object).
				$organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
				$this->addUserToOrganizationEntity(
					contactPersonObject: $contactPersonObject,
					username: $username,
					organizationUuidOverride: $organizationUuid
				);

				$this->_logger->info(
					'Successfully created inactive user and updated contactpersoon',
					[
						'username' => $username,
						'objectId' => $contactPersonObject->getId(),
					]
				);

				return true;
			}//end if

			$this->_logger->info(
				'Username already exists, contactpersoon processed',
				[
					'username' => $username,
					'objectId' => $contactPersonObject->getId(),
				]
			);

			// Ensure contactpersoon is added to organization (even for existing users).
			$this->ensureContactpersoonInOrganization(contactPersonObject: $contactPersonObject);

			return true;
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to process contactpersoon object: ' . $e->getMessage(),
				[
					'exception' => $e,
					'objectId' => $contactPersonObject->getId() ?? 'unknown',
				]
			);
			throw $e;
		}//end try
	}//end processContactpersoon()

	/**
	 * Sets a user account to inactive
	 *
	 * @param string $username The username to set as inactive
	 *
	 * @return bool True if successful
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function setUserInactive(string $username): bool {
		try {
			$user = $this->_userManager->get($username);

			if (empty($user) === true) {
				$this->_logger->warning(
					'User not found when trying to set inactive',
					[
						'username' => $username,
					]
				);

				return false;
			}

			$user->setEnabled(false);

			$this->_logger->info(
				'Set user account to inactive',
				[
					'username' => $username,
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to set user inactive: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);

			return false;
		}//end try
	}//end setUserInactive()

	/**
	 * Sets a user account to active
	 *
	 * @param string $username The username to set as active
	 *
	 * @return bool True if successful
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function setUserActive(string $username): bool {
		try {
			$user = $this->_userManager->get($username);

			if (empty($user) === true) {
				$this->_logger->warning(
					'User not found when trying to set active',
					[
						'username' => $username,
					]
				);

				return false;
			}

			$user->setEnabled(true);

			$this->_logger->info(
				'Set user account to active',
				[
					'username' => $username,
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to set user active: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);

			return false;
		}//end try
	}//end setUserActive()

	/**
	 * Handles contactpersoon updates, particularly role changes
	 *
	 * @param object $contactPersonObject The updated contactpersoon object
	 * @param object $oldContactPersonObject The previous contactpersoon object
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function handleContactpersoonUpdate(object $contactPersonObject, object $oldContactPersonObject): void {
		try {
			$this->_logger->info(
				'Handling contactpersoon update',
				[
					'objectId' => $contactPersonObject->getId(),
				]
			);

			// Process the updated contactpersoon.
			$this->processContactpersoon(contactPersonObject: $contactPersonObject);

			// Check for role changes and update groups accordingly.
			$newData = $contactPersonObject->getObject();
			$oldData = $oldContactPersonObject->getObject();

			$newRoles = $newData['roles'] ?? [];
			$oldRoles = $oldData['roles'] ?? [];

			// Ensure both are arrays.
			if (is_array($newRoles) === false) {
				$newRoles = [$newRoles];
			}

			if (is_array($oldRoles) === false) {
				$oldRoles = [$oldRoles];
			}

			// Check if roles or organization have changed (organization type determines role assignment).
			$oldOrganization = $oldData['organisation'] ?? $oldData['organization'] ?? '';
			$newOrganization = $newData['organisation'] ?? $newData['organization'] ?? '';

			if ($newRoles !== $oldRoles || $oldOrganization !== $newOrganization) {
				$username = $newData['username'] ?? '';
				if (empty($username) === false) {
					$user = $this->_userManager->get($username);
					if (empty($user) === false) {
						$this->_logger->info(
							'Contact person data changed, updating user groups based on organization type',
							[
								'contactpersoonId' => $contactPersonObject->getId(),
								'username' => $username,
								'oldRoles' => $oldRoles,
								'newRoles' => $newRoles,
								'oldOrganization' => $oldOrganization,
								'newOrganization' => $newOrganization,
							]
						);

						// Update user groups based on organization type (roles are now ignored).
						$this->updateUserGroupsFromContactData(user: $user, contactData: $newData);
					}
				}
			}//end if
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to handle contactpersoon update: ' . $e->getMessage(),
				[
					'objectId' => $contactPersonObject->getId(),
					'exception' => $e,
				]
			);
		}//end try
	}//end handleContactpersoonUpdate()

	/**
	 * Sends account suspension notification email
	 *
	 * @param \OCP\IUser $user The suspended user
	 *
	 * @return void
	 */
	private function sendAccountSuspensionEmail(\OCP\IUser $user): void {
		try {
			$this->_logger->info(
				'Sending account suspension email',
				[
					'username' => $user->getUID(),
					'email' => $user->getEMailAddress(),
				]
			);

			// For now, we'll use a simple log message as the PhpEmailService.
			// doesn't have a specific suspension email method yet.
			// This can be extended later if needed.
			$this->_logger->info(
				'Account suspension email would be sent here',
				[
					'username' => $user->getUID(),
					'email' => $user->getEMailAddress(),
					'displayName' => $user->getDisplayName(),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Exception sending account suspension email: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'email' => $user->getEMailAddress(),
					'exception' => $e,
				]
			);
		}//end try
	}//end sendAccountSuspensionEmail()

	/**
	 * Checks if a contactpersoon username is in the organization's users list
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 *
	 * @return bool True if the user should be added to the organization
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function shouldAddContactpersoonToOrganization(object $contactPersonObject): bool {
		try {
			$objectData = $contactPersonObject->getObject();
			$username = $objectData['username'] ?? '';
			$organizationUuid = $objectData['organisation'] ?? '';

			if (empty($username) === true || empty($organizationUuid) === true) {
				return false;
			}

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return false;
			}

			// Get the organization object.
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
			$registerId = $settingsService->getVoorzieningenRegisterId();
			$organisationSchemaId = $settingsService->getSchemaIdForObjectType('organization');

			if ($registerId === null || $organisationSchemaId === false) {
				return false;
			}

			try {
				$organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisationSchemaId);
				$organizationData = $organizationObject->getObject();

				// Check if the username is already in the organization's users.
				$organizationUsers = $organizationData['users'] ?? [];

				if (is_array($organizationUsers) === true && in_array($username, $organizationUsers) === false) {
					$this->_logger->info(
						'ContactPersonHandler: Contactpersoon should be added to organization',
						[
							'username' => $username,
							'organizationUuid' => $organizationUuid,
							'currentUsers' => $organizationUsers,
						]
					);
					return true;
				}

				return false;
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Organization doesn't exist, so we can't add the user.
				$this->_logger->warning(
					'ContactPersonHandler: Organization not found for contactpersoon',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return false;
			}//end try
		} catch (\Exception $e) {
			$this->_logger->error(
				'ContactPersonHandler: Failed to check if contactpersoon should be added to organization: ' . $e->getMessage(),
				[
					'objectId' => $contactPersonObject->getId(),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end shouldAddContactpersoonToOrganization()

	/**
	 * Adds a contactpersoon username to the organization's users list
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 *
	 * @return bool True if the user was successfully added
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function addContactpersoonToOrganization(object $contactPersonObject): bool {
		try {
			$objectData = $contactPersonObject->getObject();
			$username = $objectData['username'] ?? '';
			$organizationUuid = $objectData['organisation'] ?? '';

			if (empty($username) === true || empty($organizationUuid) === true) {
				$this->_logger->warning(
					'ContactPersonHandler: Cannot add contactpersoon to organization - missing username or organization',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return false;
			}

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error('ContactPersonHandler: OpenRegister ObjectService not available');
				return false;
			}

			// Get the organization object.
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
			$registerId = $settingsService->getVoorzieningenRegisterId();
			$organisationSchemaId = $settingsService->getSchemaIdForObjectType('organization');

			if ($registerId === null || $organisationSchemaId === false) {
				$this->_logger->error('ContactPersonHandler: Register or schema not configured for organisatie');
				return false;
			}

			try {
				$organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisationSchemaId);
				$organizationData = $organizationObject->getObject();

				// Add the username to the organization's users list.
				$organizationUsers = $organizationData['users'] ?? [];
				if (is_array($organizationUsers) === false) {
					$organizationUsers = [];
				}

				if (in_array($username, $organizationUsers) === false) {
					$organizationUsers[] = $username;
					$organizationData['users'] = $organizationUsers;

					// Update the organization object.
					$updatedOrganization = $objectService->saveObject(
						$organizationData,
						[],
						$registerId,
						$organisationSchemaId,
						$organizationUuid
					);

					$this->_logger->info(
						'ContactPersonHandler: Successfully added contactpersoon to organization',
						[
							'username' => $username,
							'organizationUuid' => $organizationUuid,
							'updatedUsers' => $organizationUsers,
						]
					);

					return true;
				}//end if

				$this->_logger->debug(
					'ContactPersonHandler: Contactpersoon already in organization',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				// Already there, consider it successful.
				return true;
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				$this->_logger->error(
					'ContactPersonHandler: Organization not found for contactpersoon',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return false;
			}//end try
		} catch (\Exception $e) {
			$this->_logger->error(
				'ContactPersonHandler: Failed to add contact person to organization: ' . $e->getMessage(),
				[
					'objectId' => $contactPersonObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return false;
		}//end try
	}//end addContactpersoonToOrganization()

	/**
	 * Ensures contactpersoon is added to organization after user creation/update
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function ensureContactpersoonInOrganization(object $contactPersonObject): void {
		try {
			$this->_logger->info(
				'ContactPersonHandler: Ensuring contactpersoon is in organization',
				[
					'objectId' => $contactPersonObject->getId(),
				]
			);

			// Check if user should be added to organization.
			if ($this->shouldAddContactpersoonToOrganization(contactPersonObject: $contactPersonObject) === false) {
				$this->_logger->debug(
					'ContactPersonHandler: Contactpersoon already in organization or no action needed',
					[
						'objectId' => $contactPersonObject->getId(),
					]
				);
				return;
			}

			// Add user to organization.
			$result = $this->addContactpersoonToOrganization(contactPersonObject: $contactPersonObject);

			if ($result === true) {
				$this->_logger->info(
					'ContactPersonHandler: Successfully ensured contactpersoon in organization',
					[
						'objectId' => $contactPersonObject->getId(),
					]
				);
				return;
			}

			$this->_logger->warning(
				'ContactPersonHandler: Failed to add contact person to organization',
				[
					'objectId' => $contactPersonObject->getId(),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'ContactPersonHandler: Failed to ensure contactpersoon in organization: ' . $e->getMessage(),
				[
					'objectId' => $contactPersonObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end ensureContactpersoonInOrganization()

	/**
	 * Adds a user to the organization entity (OpenRegister entity, not object)
	 *
	 * This method ensures that an organization entity exists in OpenRegister's database
	 * before adding the user to it. If the entity doesn't exist, it will be created
	 * from the organization object data.
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username to add
	 * @param string|null $organizationUuidOverride Optional organization UUID to use instead of extracting from object
	 *                                              (useful when organisatie field was removed from object data)
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function addUserToOrganizationEntity(
		object $contactPersonObject,
		string $username,
		?string $organizationUuidOverride = null,
	): void {
		try {
			$objectData = $contactPersonObject->getObject();
			// Use override if provided (useful when organisatie field was removed from object).
			$organizationUuid = $organizationUuidOverride ?? $objectData['organisation'] ?? $objectData['organization'] ?? '';

			if (empty($organizationUuid) === true) {
				$this->_logger->warning(
					'ContactPersonHandler: No organization reference found for contact person',
					[
						'objectId' => $contactPersonObject->getId(),
						'username' => $username,
					]
				);
				return;
			}

			$this->_logger->info(
				'ContactPersonHandler: Adding user to organization entity',
				[
					'objectId' => $contactPersonObject->getId(),
					'username' => $username,
					'organizationUuid' => $organizationUuid,
				]
			);

			try {
				$organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');

				// Try to find the organisation entity.
				try {
					$organisation = $organisationMapper->findByUuid($organizationUuid);

					$this->_logger->info(
						'ContactPersonHandler: Found existing organization entity',
						[
							'organizationUuid' => $organizationUuid,
							'organizationName' => $organisation->getName(),
						]
					);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					// Organization entity doesn't exist, create it from the object data.
					$this->_logger->info(
						'ContactPersonHandler: Organization entity not found, creating it',
						[
							'organizationUuid' => $organizationUuid,
						]
					);

					$organisation = $this->ensureOrganizationEntity(organizationUuid: $organizationUuid);

					if ($organisation === null) {
						$this->_logger->error(
							'ContactPersonHandler: Failed to create organization entity',
							[
								'organizationUuid' => $organizationUuid,
							]
						);
						return;
					}
				}//end try

				// Add user to the organisation entity.
				$currentUsers = $organisation->getUsers() ?? [];
				if (in_array($username, $currentUsers) === true) {
					$this->_logger->info(
						'ContactPersonHandler: User already in organization entity',
						[
							'objectId' => $contactPersonObject->getId(),
							'username' => $username,
							'organizationUuid' => $organizationUuid,
						]
					);
					return;
				}

				$currentUsers[] = $username;
				$organisation->setUsers($currentUsers);
				$organisationMapper->update($organisation);

				$this->_logger->info(
					'ContactPersonHandler: Successfully added user to organization entity',
					[
						'objectId' => $contactPersonObject->getId(),
						'username' => $username,
						'organizationUuid' => $organizationUuid,
						'totalUsers' => count($currentUsers),
					]
				);
			} catch (\Exception $e) {
				$this->_logger->error(
					'ContactPersonHandler: Failed to add user to organization entity',
					[
						'objectId' => $contactPersonObject->getId(),
						'username' => $username,
						'organizationUuid' => $organizationUuid,
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]
				);
			}//end try
		} catch (\Exception $e) {
			$this->_logger->error(
				'ContactPersonHandler: Exception in addUserToOrganizationEntity',
				[
					'objectId' => $contactPersonObject->getId(),
					'username' => $username,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end addUserToOrganizationEntity()

	/**
	 * Ensures an organization entity exists in OpenRegister
	 *
	 * If the organization entity doesn't exist, this method creates it from
	 * the organization object data in the voorzieningen register.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return \OCA\OpenRegister\Db\Organisation|null The organization entity or null on failure
	 */
	private function ensureOrganizationEntity(string $organizationUuid): ?\OCA\OpenRegister\Db\Organisation {
		try {
			// First check if an entity with this UUID already exists (defensive double-check).
			$organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
			try {
				$existing = $organisationMapper->findByUuid($organizationUuid);
				$this->_logger->info(
					'ContactPersonHandler: Organization entity already exists (found by UUID)',
					[
						'organizationUuid' => $organizationUuid,
						'organizationName' => $existing->getName(),
					]
				);
				return $existing;
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Expected — continue to create.
			}

			// Get the organization object from OpenRegister.
			$objectService = $this->getObjectService();

			// Get voorzieningen config for register and schema.
			$settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
			$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
			$register = $voorzieningenConfig['register'] ?? '';
			$organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';

			// Find the organization object by UUID - use find() with register and schema.
			$organizationObject = $objectService->find(
				id: $organizationUuid,
				register: $register,
				schema: $organizationSchema,
				_rbac: false,
				_multitenancy: false
			);

			if ($organizationObject === null) {
				$this->_logger->error(
					'ContactPersonHandler: Organization object not found in OpenRegister',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return null;
			}

			$organizationData = $organizationObject->getObject();

			// Get organization name and description.
			// phpcs:ignore Generic.Files.LineLength.TooLong
			$organizationName = $organizationData['name'] ?? 'Unknown Organization';
			$description = $organizationData['description'] ?? $organizationData['longDescription'] ?? null;
			$organizationDescription = $description ?? $organizationData['description'] ?? '';

			// Check if an entity with the same slug already exists (prevents unique constraint violation).
			try {
				$slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($organizationName)));
				$slug = trim($slug, '-');
				$existingBySlug = $organisationMapper->findBySlug($slug);
				$this->_logger->info(
					'ContactPersonHandler: Organization entity already exists (found by slug)',
					[
						'organizationUuid' => $organizationUuid,
						'slug' => $slug,
						'existingUuid' => $existingBySlug->getUuid(),
						'existingName' => $existingBySlug->getName(),
					]
				);
				return $existingBySlug;
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// No existing entity by slug — proceed with creation.
			}

			$this->_logger->info(
				'ContactPersonHandler: Creating organization entity from object data',
				[
					'organizationUuid' => $organizationUuid,
					'organizationName' => $organizationName,
				]
			);

			// Create the organization entity using OrganisationService.
			$organisationService = $this->_container->get('OCA\\OpenRegister\\Service\\OrganisationService');

			// Create organisation with specific UUID, without adding current user (as we're in admin context).
			$organisation = $organisationService->createOrganisation(
				name: $organizationName,
				description: $organizationDescription,
				addCurrentUser: false,
				// Don't add current user (admin) to this organisation.
				uuid: $organizationUuid
			);

			$this->_logger->info(
				'ContactPersonHandler: Successfully created organization entity',
				[
					'organizationUuid' => $organizationUuid,
					'organizationName' => $organizationName,
					'organizationId' => $organisation->getId(),
				]
			);

			return $organisation;
		} catch (\Exception $e) {
			$this->_logger->error(
				'ContactPersonHandler: Failed to ensure organization entity',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return null;
		}//end try
	}//end ensureOrganizationEntity()

	/**
	 * Strips spaces and non-alphanumeric characters from voornaam/achternaam
	 * and lowercases the result so they can be safely concatenated into a
	 * Nextcloud-compatible username candidate.
	 *
	 * Extracted from {@see generateUsernameFromContactData()} as part of task
	 * 7.6 so the two name-based candidate strategies share the cleaning step.
	 *
	 * @param string $voornaam The raw first name.
	 * @param string $lastName The raw last name.
	 *
	 * @return array{0: string, 1: string} The cleaned pair (empty strings when
	 *                                     no usable characters remain).
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	private function cleanNameParts(string $voornaam, string $lastName): array {
		if ($voornaam === '' || $lastName === '') {
			return ['', ''];
		}

		$cleanVoornaam = (string)preg_replace('/[^a-z0-9]/', '', strtolower($voornaam));
		$cleanLastName = (string)preg_replace('/[^a-z0-9]/', '', strtolower($lastName));

		return [$cleanVoornaam, $cleanLastName];
	}//end cleanNameParts()
}//end class
