<?php

/**
 * Contactpersoon Service
 *
 * This file contains the service class for handling contact person-specific operations
 * in the Stackiq application.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling contact person-specific operations
 *
 * This service provides functionality for contact person processing,
 * user account creation, and group management.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class ContactpersoonService {
	/**
	 * ContactpersoonService constructor.
	 *
	 * @param ContactPersonHandler $contactPersonHandler Contact person handler.
	 * @param GroupHandler $groupHandler Group handler.
	 * @param HierarchyHandler $hierarchyHandler Hierarchy handler.
	 * @param LoggerInterface $logger Logger interface.
	 * @param ContainerInterface $container Container interface.
	 * @param IAppManager $appManager App manager.
	 * @param IAppConfig $config Configuration service.
	 * @param SettingsService $settingsService Settings service.
	 */
	public function __construct(
		private readonly ContactPersonHandler $contactPersonHandler,
		private readonly GroupHandler $groupHandler,
		private readonly HierarchyHandler $hierarchyHandler,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $config,
		private readonly SettingsService $settingsService,
	) {

	}//end __construct()

	/**
	 * Tracks contact UUIDs currently being processed to prevent event recursion.
	 *
	 * When saveObject() is called to update the username, it triggers ObjectUpdatedEvent
	 * which re-enters this method — this guard breaks that loop.
	 *
	 * @var array
	 */
	private static array $processingContacts = [];

	/**
	 * Processes a contactpersoon object to create a user account.
	 *
	 * If the contactpersoon object doesn't have a user or the user is missing,
	 * this method will create a user account with appropriate status.
	 *
	 * @param object $contactPersonObject The contactpersoon object to process.
	 * @param bool $isUpdate Whether this is an update operation.
	 *
	 * @return bool True if processing was successful.
	 *
	 * @throws \Exception If processing fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isUpdate is a simple create-vs-update toggle
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function processContactpersoon(object $contactPersonObject, bool $isUpdate = false): bool {
		$startTime = microtime(true);
		$contactId = $contactPersonObject->getId();

		try {
			$contactData = $contactPersonObject->getObject();

			// Recursion guard: saveObject triggers ObjectUpdatedEvent which re-enters here.
			if (isset(self::$processingContacts[$contactId]) === true) {
				return true;
			}

			self::$processingContacts[$contactId] = true;

			$emailValue = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
			$hasEmail = empty($emailValue) === false;
			$hasOrganisation = empty($contactData['organisation']) === false;
			$this->logger->info(
				'ContactpersoonService: Starting contactpersoon processing',
				[
					'contactId' => $contactId,
					'isUpdate' => $isUpdate,
					'hasEmail' => $hasEmail,
					'hasOrganisation' => $hasOrganisation,
				]
			);

			// Check if contactpersoon has required data.
			// Schema uses 'e-mailadres' but some data may use 'email'.
			$email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
			if ($this->isContactPersonEmailUsable(email: $email, contactId: (string)$contactId) === false) {
				return false;
			}

			// Use email as username.
			$username = $email;

			// Check if user already exists.
			$userManager = $this->container->get('OCP\IUserManager');
			$user = $userManager->get($username);

			if ($user === null) {
				// Check if organization is active before creating user account.
				$organizationUuid = ($contactData['organisation'] ?? $contactData['organization'] ?? '');

				if (empty($organizationUuid) === false) {
					// Look up organization entity, creating backup if missing.
					$organisationEntity = null;
					try {
						$organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
						$organisationEntity = $organisationMapper->findByUuid($organizationUuid);
					} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
						// Org entity missing — try backup creation from org object.
						$this->logger->info(
							'ContactpersoonService: Organisation entity missing, attempting backup creation',
							[
								'contactId' => $contactId,
								'organizationUuid' => $organizationUuid,
							]
						);
						try {
							$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
							$settingsService = $this->container->get('OCA\Stackiq\Service\SettingsService');
							$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
							$orgObject = $objectService->find(
								id: $organizationUuid,
								register: ($voorzieningenConfig['register'] ?? ''),
								schema: ($voorzieningenConfig['organisatie_schema'] ?? ''),
								_rbac: false,
								_multitenancy: false
							);
							if ($orgObject !== null) {
								$orgData = $orgObject->getObject();
								$orgStatus = strtolower(($orgData['status'] ?? ''));
								if (in_array(needle: $orgStatus, haystack: ['actief', 'active']) === true) {
									$syncServiceClass = 'OCA\Stackiq\Service\OrganizationSyncService';
									$organizationSyncService = $this->container->get($syncServiceClass);
									$backupStats = [
										'entitiesCreated' => 0,
										'entitiesUpdated' => 0,
									];
									$organisationEntity = $organizationSyncService->ensureOrganisationEntityPublic(
										orgObject: $orgObject,
										stats: $backupStats
									);
									$this->logger->info(
										'ContactpersoonService: Backup entity created',
										[
											'contactId' => $contactId,
											'organizationUuid' => $organizationUuid,
											'entityCreated' => $organisationEntity !== null,
										]
									);
								}
							}//end if
						} catch (\Exception $backupEx) {
							$this->logger->error(
								'ContactpersoonService: Backup entity creation failed',
								[
									'contactId' => $contactId,
									'organizationUuid' => $organizationUuid,
									'error' => $backupEx->getMessage(),
								]
							);
						}//end try
					}//end try

					try {
						if ($organisationEntity !== null && $organisationEntity->getActive() === true) {
							// Determine if this is the first contact for the organization.
							$isFirstContact = $this->contactPersonHandler->isFirstContactForOrganization(
								contactObject: $contactPersonObject,
								objectData: $contactData
							);

							// Create user account - organization is active.
							$this->logger->info(
								'ContactpersoonService: Creating user account for contactpersoon (org is active)',
								[
									'contactId' => $contactId,
									'username' => $username,
									'organizationUuid' => $organizationUuid,
									'isFirstContact' => $isFirstContact,
								]
							);

							$success = $this->contactPersonHandler->createUserAccount(
								contactPersonObject: $contactPersonObject,
								isFirstContact: $isFirstContact
							);
							if ($success === false) {
								throw new \Exception('Failed to create user account');
							}

							// Link user to organization entity.
							$this->contactPersonHandler->addUserToOrganizationEntity(
								contactPersonObject: $contactPersonObject,
								username: $username,
								organizationUuidOverride: $organizationUuid
							);

							// Update contactpersoon object owner to user UID.
							$this->updateContactPersonObjectOwner(
								contactObject: $contactPersonObject,
								userUID: $username
							);

							$this->logger->info(
								'ContactpersoonService: Successfully created user account',
								[
									'contactId' => $contactId,
									'username' => $username,
								]
							);
						}//end if

						if ($organisationEntity === null || $organisationEntity->getActive() !== true) {
							$orgActive = false;
							if ($organisationEntity !== null) {
								$orgActive = $organisationEntity->getActive() === true;
							}

							$this->logger->info(
								// phpcs:ignore Generic.Files.LineLength.TooLong
								'ContactpersoonService: Skipping user creation - organization not active or entity not found',
								[
									'contactId' => $contactId,
									'organizationUuid' => $organizationUuid,
									'organizationFound' => $organisationEntity !== null,
									'organizationActive' => $orgActive,
								]
							);
							return false;
						}
					} catch (\Exception $e) {
						$this->logger->error(
							'ContactpersoonService: User creation failed',
							[
								'contactId' => $contactId,
								'organizationUuid' => $organizationUuid,
								'error' => $e->getMessage(),
							]
						);
						return false;
					}//end try

					$this->logger->warning(
						'ContactpersoonService: Contactpersoon has no organization reference, skipping user creation',
						['contactId' => $contactId]
					);
					return false;
				}//end if

				$this->logger->info(
					'ContactpersoonService: User account already exists',
					[
						'contactId' => $contactId,
						'username' => $username,
					]
				);
			}//end if

			// Update user groups based on contactpersoon data.
			$this->updateUserGroups(
				contactPersonObject: $contactPersonObject,
				username: $username
			);

			// Ensure organization has at least one beheerder.
			$this->ensureOrganizationBeheerder(
				contactPersonObject: $contactPersonObject,
				username: $username
			);

			// Update the contactpersoon object with username if not set.
			if (empty($contactData['username']) === true) {
				$this->updateContactPersonUsername(
					contactPersonObject: $contactPersonObject,
					username: $username
				);
			}

			$processingTime = round(((microtime(true) - $startTime) * 1000), 2);
			$this->logger->info(
				'ContactpersoonService: Successfully processed contactpersoon',
				[
					'contactId' => $contactId,
					'username' => $username,
					'processingTime' => $processingTime . 'ms',
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to process contactpersoon object',
				[
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
					'objectId' => ($contactPersonObject->getId() ?? 'unknown'),
					'processingTime' => round(((microtime(true) - $startTime) * 1000), 2) . 'ms',
				]
			);
			throw $e;
		} finally {
			unset(self::$processingContacts[$contactId]);
		}//end try

	}//end processContactpersoon()

	/**
	 * Updates user groups based on contactpersoon data
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username to update groups for
	 *
	 * @return void
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function updateUserGroups(object $contactPersonObject, string $username): void {
		// Use the new organization type-based logic instead of old role-based logic.
		$userManager = $this->container->get('OCP\IUserManager');
		$user = $userManager->get($username);
		if ($user === null) {
			$this->logger->warning('User not found for group update', ['username' => $username]);
			return;
		}

		$contactData = $contactPersonObject->getObject();
		$this->contactPersonHandler->updateUserGroupsFromContactData(
			user: $user,
			contactData: $contactData
		);

	}//end updateUserGroups()

	/**
	 * Ensures organization has at least one beheerder and manages user hierarchy
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username being processed
	 *
	 * @return void
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function ensureOrganizationBeheerder(object $contactPersonObject, string $username): void {
		$this->hierarchyHandler->ensureOrganizationBeheerder(
			contactgegevensObject: $contactPersonObject,
			username: $username
		);

	}//end ensureOrganizationBeheerder()

	/**
	 * Gets a user's manager
	 *
	 * @param string $username The username
	 *
	 * @return string|null The manager's username or null if not set
	 */
	public function getUserManager(string $username): ?string {
		return $this->contactPersonHandler->getUserManager($username);
	}//end getUserManager()

	/**
	 * Normalize contact data types to match schema expectations.
	 * This ensures numeric strings are properly typed as strings.
	 *
	 * @param array $data The contact data to normalize
	 *
	 * @return array The normalized contact data
	 */
	private function normalizeContactDataTypes(array $data): array {
		// Fields that should always be strings according to the contactpersoon schema.
		$stringFields = [
			'voornaam',
			'tussenvoegsel',
			'achternaam',
			'role',
			'telefoonnummer',
			'username',
		];

		foreach ($stringFields as $field) {
			if (isset($data[$field]) === true && (is_int($data[$field]) === true || is_float($data[$field]) === true)) {
				$data[$field] = (string)$data[$field];
			}
		}

		return $data;
	}//end normalizeContactDataTypes()

	/**
	 * Updates contactpersoon object with username.
	 *
	 * @param object $contactPersonObject The contactpersoon object.
	 * @param string $username The username to set.
	 *
	 * @return void
	 */
	private function updateContactPersonUsername(object $contactPersonObject, string $username): void {
		try {
			$contactData = $contactPersonObject->getObject();
			$contactData['username'] = $username;
			$contactPersonObject->setObject($contactData);

			// FIX #434, through the PUBLISHED contract instead of OpenRegister's Db
			// layer. Both reasons the original gave are flags on saveObject():
			//
			//   _validation: false  the organisatie field holds a UUID string where
			//                       the schema expects an object
			//   silent: true        no ObjectUpdatedEvent, so the cascade cannot
			//                       interfere with an in-flight org activation
			//
			// saveObject() is not a lesser route: OpenRegister's SaveObject calls
			// objectEntityMapper->update(entity:, register:, schema:) itself, which
			// IS the magic-mapper path this used to reach for directly.
			$objectService = $this->getObjectService();
			if ($objectService !== null) {
				$objectService->saveObject(
					object: $contactData,
					register: $contactPersonObject->getRegister(),
					schema: $contactPersonObject->getSchema(),
					uuid: $contactPersonObject->getUuid(),
					silent: true,
					_validation: false
				);
			}

			$this->logger->info(
				'ContactpersoonService: Updated contactpersoon with username',
				[
					'contactId' => $contactPersonObject->getId(),
					'username' => $username,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to update contactpersoon username',
				[
					'contactId' => $contactPersonObject->getId(),
					'username' => $username,
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end updateContactpersoonUsername()

	/**
	 * Handles contactpersoon updates, particularly role changes
	 *
	 * @param object $contactPersonObject The updated contactpersoon object
	 * @param object|null $oldContactPersonObject The previous contactpersoon object
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function handleContactpersoonUpdate(object $contactPersonObject, ?object $oldContactPersonObject = null): void {
		try {
			$contactData = $contactPersonObject->getObject();
			$contactId = $contactPersonObject->getId();

			$this->logger->info(
				'ContactpersoonService: Handling contactpersoon update',
				[
					'contactId' => $contactId,
					'hasOldObject' => $oldContactPersonObject !== null,
				]
			);

			// Process the contactpersoon (this will handle user creation/updates).
			$this->processContactpersoon(
				contactPersonObject: $contactPersonObject,
				isUpdate: true
			);

			// If we have old object, check for role changes.
			if ($oldContactPersonObject !== null) {
				$this->handleRoleChanges(
					newContactPersonObject: $contactPersonObject,
					oldContactPersonObject: $oldContactPersonObject
				);
			}

			// Sync name/functie fields back to the Nextcloud user when changed.
			$this->syncNameFieldsToUser(
				contactPersonObject: $contactPersonObject,
				oldContactPersonObject: $oldContactPersonObject
			);

			$this->logger->info(
				'ContactpersoonService: Successfully handled contactpersoon update',
				['contactId' => $contactId]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to handle contactpersoon update',
				[
					'contactId' => $contactPersonObject->getId(),
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try

	}//end handleContactpersoonUpdate()

	/**
	 * Syncs name/functie fields from contactpersoon to the corresponding Nextcloud user.
	 *
	 * @param object $contactPersonObject The updated contactpersoon object.
	 * @param object|null $oldContactPersonObject The previous contactpersoon object.
	 *
	 * @return void
	 */
	private function syncNameFieldsToUser(object $contactPersonObject, ?object $oldContactPersonObject): void {
		$newData = $contactPersonObject->getObject();
		$oldData = [];
		if ($oldContactPersonObject !== null) {
			$oldData = $oldContactPersonObject->getObject();
		}

		// Check if any name/functie fields have changed.
		$nameFields = [
			'voornaam',
			'tussenvoegsel',
			'achternaam',
			'role',
			'e-mailadres',
		];
		$hasNameChanges = false;

		foreach ($nameFields as $field) {
			if (($newData[$field] ?? '') !== ($oldData[$field] ?? '')) {
				$hasNameChanges = true;
				break;
			}
		}

		if ($hasNameChanges === false) {
			return;
		}

		// Find the corresponding Nextcloud user.
		$username = ($newData['username'] ?? '');
		if (empty($username) === true) {
			return;
		}

		$userManager = $this->container->get('OCP\IUserManager');
		$user = $userManager->get($username);

		if ($user === null) {
			$this->logger->debug(
				'ContactpersoonService: No Nextcloud user found for name sync',
				['username' => $username]
			);
			return;
		}

		$this->logger->info(
			'ContactpersoonService: Syncing contactpersoon name fields to user',
			[
				'username' => $username,
				'contactId' => $contactPersonObject->getId(),
				'changedData' => array_intersect_key(
					$newData,
					array_flip($nameFields)
				),
			]
		);

		$this->contactPersonHandler->storeContactNameFields(
			user: $user,
			contactData: $newData
		);

	}//end syncNameFieldsToUser()

	/**
	 * Handles role changes between old and new contactpersoon objects
	 *
	 * @param object $newContactPersonObject The new contactpersoon object
	 * @param object $oldContactPersonObject The old contactpersoon object
	 *
	 * @return void
	 */
	private function handleRoleChanges(object $newContactPersonObject, object $oldContactPersonObject): void {
		$newData = $newContactPersonObject->getObject();
		$oldData = $oldContactPersonObject->getObject();

		$newRoles = ($newData['roles'] ?? []);
		$oldRoles = ($oldData['roles'] ?? []);

		// Check if roles have changed.
		if ($newRoles !== $oldRoles) {
			$username = ($newData['email'] ?? $newData['e-mailadres'] ?? $newData['username'] ?? '');
			if (empty($username) === false) {
				$this->logger->info(
					'ContactpersoonService: Roles changed, updating user groups',
					[
						'contactId' => $newContactPersonObject->getId(),
						'username' => $username,
						'oldRoles' => $oldRoles,
						'newRoles' => $newRoles,
					]
				);

				// Update user groups based on new roles.
				$this->updateUserGroups(
					contactPersonObject: $newContactPersonObject,
					username: $username
				);
			}
		}

	}//end handleRoleChanges()

	/**
	 * Gets the ObjectService instance
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if ($this->appManager->isEnabledForUser('openregister') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error('ContactpersoonService: Failed to get ObjectService: ' . $e->getMessage());
			return null;
		}

	}//end getObjectService()

	/**
	 * Handles contact person deletion
	 *
	 * @param object $contactObject The contact object being deleted
	 *
	 * @return void
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function handleContactDeletion(object $contactObject): void {
		try {
			$contactData = $contactObject->getObject();
			$username = ($contactData['email'] ?? $contactData['e-mailadres'] ?? $contactData['username'] ?? '');

			if (empty($username) === true) {
				$this->logger->warning(
					'ContactpersoonService: Contact deletion - no username found',
					[
						'contactId' => $contactObject->getId(),
					]
				);
				return;
			}

			$this->logger->info(
				'ContactpersoonService: Handling contact deletion',
				[
					'contactId' => $contactObject->getId(),
					'username' => $username,
				]
			);

			// Get user manager to disable the user.
			$userManager = $this->container->get('OCP\IUserManager');
			$user = $userManager->get($username);

			if ($user === null) {
				$this->logger->warning(
					'ContactpersoonService: User not found for deleted contact',
					[
						'contactId' => $contactObject->getId(),
						'username' => $username,
					]
				);
			}

			if ($user !== null) {
				// Disable the user instead of deleting.
				$user->setEnabled(false);

				$this->logger->info(
					'ContactpersoonService: Disabled user for deleted contact',
					[
						'contactId' => $contactObject->getId(),
						'username' => $username,
					]
				);
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to handle contact deletion',
				[
					'contactId' => $contactObject->getId(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end handleContactDeletion()

	/**
	 * Gets all contact persons for an organization
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Array of contact person objects
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function getContactPersonsForOrganization(string $organizationUuid): array {
		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [];
			}

			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? null);
			$register = ($voorzieningenConfig['register'] ?? null);

			// Skip if no proper configuration is available.
			if ($contactSchema === null || $register === null) {
				$this->logger->warning(
					'ContactpersoonService: Missing Voorzieningen configuration',
					[
						'contactSchema' => $contactSchema,
						'register' => $register,
					]
				);
				return [];
			}

			// Build query for searchObjects method.
			$query = [
				'@self' => [
					'register' => (int)$register,
					'schema' => (int)$contactSchema,
				],
				'organisation' => $organizationUuid,
				'_limit' => 500,
			];

			return $objectService->searchObjects($query);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to get contact persons for organization',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end getContactPersonsForOrganization()

	/**
	 * Gets all contact persons for an organization with user details spliced in
	 *
	 * This method retrieves contact person objects linked to a specific organization
	 * and enhances each contact person with their corresponding user details from Nextcloud.
	 *
	 * @param string $organizationUuid The organization UUID to get contact persons for
	 *
	 * @return array Array of contact person objects with user details spliced in
	 *
	 * @throws \Exception If contact person retrieval fails
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function getContactPersonsWithUserDetailsForOrganization(string $organizationUuid): array {
		try {
			$this->logger->info(
				'ContactpersoonService: Getting contact persons with user details for organization',
				['organizationUuid' => $organizationUuid]
			);

			// Get contact persons for the organization.
			$contactPersons = $this->getContactPersonsForOrganization(organizationUuid: $organizationUuid);

			if (empty($contactPersons) === true) {
				$this->logger->info(
					'ContactpersoonService: No contact persons found for organization',
					['organizationUuid' => $organizationUuid]
				);
				return [];
			}

			$this->logger->info(
				'ContactpersoonService: Found contact persons, fetching user details',
				[
					'organizationUuid' => $organizationUuid,
					'contactPersonCount' => count($contactPersons),
				]
			);

			// Get user manager to fetch user details.
			$userManager = $this->container->get('OCP\IUserManager');
			$enhancedContactPersons = [];

			// Loop through each contact person and fetch user details.
			foreach ($contactPersons as $contactPerson) {
				try {
					$contactData = $contactPerson->getObject();
					$username = ($contactData['username'] ?? null);

					// Initialize user details as null.
					$userDetails = null;

					// If username exists, fetch user details.
					if ($username === null) {
						$this->logger->debug(
							'ContactpersoonService: No username found for contact person',
							[
								'contactPersonId' => $contactPerson->getId(),
							]
						);
					}

					if ($username !== null) {
						$user = $userManager->get($username);
						if ($user !== null) {
							$userDetails = [
								'uid' => $user->getUID(),
								'email' => $user->getEMailAddress(),
								'displayName' => $user->getDisplayName(),
								'enabled' => $user->isEnabled(),
								'lastLogin' => $user->getLastLogin(),
								'backend' => $user->getBackendClassName(),
								'home' => $user->getHome(),
								'avatarImage' => $user->getAvatarImage(64)->data(),
								'quota' => $user->getQuota(),
								'freeQuota' => $user->getFreeQuota(),
							];

							$this->logger->debug(
								'ContactpersoonService: Fetched user details',
								[
									'contactPersonId' => $contactPerson->getId(),
									'username' => $username,
									'userEnabled' => $user->isEnabled(),
								]
							);
						}//end if

						if ($user === null) {
							$this->logger->warning(
								'ContactpersoonService: User not found for username',
								[
									'contactPersonId' => $contactPerson->getId(),
									'username' => $username,
								]
							);
						}
					}//end if

					// Create enhanced contact person object with user details spliced in.
					$enhancedContactData = $contactData;
					$enhancedContactData['userDetails'] = $userDetails;

					// Create a new object with the enhanced data.
					$enhancedContactPerson = clone $contactPerson;
					$enhancedContactPerson->setObject($enhancedContactData);

					$enhancedContactPersons[] = $enhancedContactPerson;
				} catch (\Exception $e) {
					$this->logger->error(
						'ContactpersoonService: Failed to process contact person',
						[
							'contactPersonId' => $contactPerson->getId(),
							'organizationUuid' => $organizationUuid,
							'error' => $e->getMessage(),
						]
					);

					// Still add the contact person without user details.
					$enhancedContactPersons[] = $contactPerson;
				}//end try
			}//end foreach

			$this->logger->info(
				'ContactpersoonService: Successfully enhanced contact persons with user details',
				[
					'organizationUuid' => $organizationUuid,
					'totalContactPersons' => count($enhancedContactPersons),
					'contactPersonsWithUserDetails' => count(
						array_filter(
							$enhancedContactPersons,
							static function ($cp) {
								$data = $cp->getObject();
								return $data['userDetails'] !== null;
							}
						)
					),
				]
			);

			return $enhancedContactPersons;
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to get contact persons with user details for organization',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try

	}//end getContactPersonsWithUserDetailsForOrganization()

	/**
	 * Gets bulk user information for multiple contact persons
	 *
	 * This method retrieves user information for multiple contact persons in a single operation,
	 * which is more efficient than individual calls.
	 *
	 * @param array $contactPersonIds Array of contact person IDs/UUIDs
	 *
	 * @return array Array of user information keyed by contact person ID
	 *
	 * @throws \Exception If bulk user info retrieval fails
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function getBulkUserInfo(array $contactPersonIds): array {
		try {
			$this->logger->info(
				'ContactpersoonService: Getting bulk user info',
				[
					'contactpersoonCount' => count($contactPersonIds),
				]
			);

			$bulkUserInfo = [];
			$userManager = $this->container->get('OCP\IUserManager');

			// Get contact person register and schema from settings.
			$contactRegister = null;
			$contactSchema = null;
			try {
				$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
				$contactRegister = (int)($voorzieningenConfig['register'] ?? 2);
				$contactSchema = (int)($voorzieningenConfig['contactpersoon_schema'] ?? 25);
			} catch (\Exception $e) {
				$this->logger->warning(
					'Could not get contact person schema config, using defaults',
					[
						'error' => $e->getMessage(),
					]
				);
				$contactRegister = 2;
				$contactSchema = 25;
			}

			foreach ($contactPersonIds as $contactPersonId) {
				try {
					// Get contactpersoon from OpenRegister.
					$objectService = $this->getObjectService();
					if ($objectService === null) {
						$this->logger->warning(
							'ContactpersoonService: ObjectService not available for bulk user info',
							['contactpersoonId' => $contactPersonId]
						);
						continue;
					}

					// Find the contactpersoon object with register and schema specified.
					//
					// A MISS RAISES, it does not return null: `findSilent()` is
					// declared `ObjectEntityInterface` (non-nullable) on the
					// published contract and OpenRegister lets the mapper's
					// DoesNotExistException out. The `=== null` test this replaces
					// could therefore never be true — the distinct "not found"
					// entry below was unreachable, and every missing contactpersoon
					// fell through to the generic error arm and came back carrying
					// an `error` key instead.
					try {
						$contactObject = $objectService->findSilent(
							id: $contactPersonId,
							_extend: [],
							files: false,
							register: $contactRegister,
							schema: $contactSchema
						);
					} catch (DoesNotExistException $e) {
						$this->logger->warning(
							'ContactpersoonService: Contactpersoon not found for bulk user info',
							['contactpersoonId' => $contactPersonId]
						);
						$bulkUserInfo[$contactPersonId] = [
							'hasUser' => false,
							'username' => null,
							'groups' => [],
						];
						continue;
					}//end try

					$contactData = $contactObject->getObject();
					$username = $contactData['username'] ?? null;

					$userInfo = [
						'hasUser' => empty($username) === false,
						'username' => $username,
						'groups' => [],
					];

					// If user exists, get their current groups.
					if (empty($username) === false) {
						$user = $userManager->get($username);
						if ($user === null) {
							$this->logger->warning(
								'ContactpersoonService: User not found for bulk user info',
								[
									'contactpersoonId' => $contactPersonId,
									'username' => $username,
								]
							);
						}

						if ($user !== null) {
							$groupManager = $this->container->get('OCP\IGroupManager');
							$userGroups = $groupManager->getUserGroups($user);
							$userInfo['groups'] = array_keys($userGroups);
							$userInfo['enabled'] = $user->isEnabled();
							$userInfo['displayName'] = $user->getDisplayName();
							$userInfo['lastLogin'] = $user->getLastLogin();
						}
					}//end if

					$bulkUserInfo[$contactPersonId] = $userInfo;
				} catch (\Exception $e) {
					$this->logger->error(
						'ContactpersoonService: Failed to get user info for contactpersoon in bulk operation',
						[
							'contactpersoonId' => $contactPersonId,
							'error' => $e->getMessage(),
						]
					);

					// Add error entry for this contactpersoon.
					$bulkUserInfo[$contactPersonId] = [
						'hasUser' => false,
						'username' => null,
						'groups' => [],
						'error' => $e->getMessage(),
					];
				}//end try
			}//end foreach

			$this->logger->info(
				'ContactpersoonService: Successfully retrieved bulk user info',
				[
					'totalContactpersonen' => count($contactPersonIds),
					'successfulRetrievals' => count(
						array_filter(
							$bulkUserInfo,
							function ($info) {
								return isset($info['error']) === false;
							}
						)
					),
				]
			);

			return $bulkUserInfo;
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to get bulk user info',
				[
					'contactpersoonIds' => $contactPersonIds,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try

	}//end getBulkUserInfo()

	/**
	 * Updates the contactpersoon object's @self metadata to set owner to the user UID.
	 *
	 * @param object $contactObject The contactpersoon object to update.
	 * @param string $userUID The user UID to set as owner.
	 *
	 * @return void
	 */
	private function updateContactPersonObjectOwner(object $contactObject, string $userUID): void {
		try {
			$contactId = $contactObject->getUuid();

			// Get configuration for register and schema.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$register = ($voorzieningenConfig['register'] ?? '');
			$contactSchema = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

			if (empty($register) === true || empty($contactSchema) === true) {
				$this->logger->warning(
					'ContactpersoonService: Cannot update object owner - missing configuration',
					[
						'contactId' => $contactId,
						'register' => $register,
						'contactSchema' => $contactSchema,
					]
				);
				return;
			}

			$this->logger->info(
				'ContactpersoonService: Updating contactpersoon object owner',
				[
					'contactId' => $contactId,
					'userUID' => $userUID,
					'register' => $register,
					'schema' => $contactSchema,
				]
			);

			// Get the current object data and normalize types.
			$currentObject = $contactObject->getObject();
			$currentObject = $this->normalizeContactDataTypes(data: $currentObject);

			// Get current @self metadata or create new.
			$selfMetadata = ($currentObject['@self'] ?? []);

			// Update the owner field to the user UID.
			$selfMetadata['owner'] = $userUID;

			// Set the organisation field in @self metadata to the organization UUID.
			// This ensures the contact person is properly linked to their organization.
			$organizationUuid = ($currentObject['organisation'] ?? $currentObject['organization'] ?? '');
			if (empty($organizationUuid) === true) {
				$this->logger->warning(
					'ContactpersoonService: No organization UUID found for contact person',
					[
						'contactId' => $contactId,
						'contactData' => $currentObject,
					]
				);
			}

			if (empty($organizationUuid) === false) {
				$selfMetadata['organisation'] = $organizationUuid;
				$this->logger->info(
					'ContactpersoonService: Setting @self.organisation metadata',
					[
						'contactId' => $contactId,
						'organizationUuid' => $organizationUuid,
					]
				);
			}

			// Update the object with the new @self metadata.
			$currentObject['@self'] = $selfMetadata;
			$contactObject->setObject($currentObject);

			// Also update the entity's owner and organisation fields directly.
			// These system fields control multi-tenancy filtering.
			$contactObject->setOwner($userUID);
			if (empty($organizationUuid) === false) {
				$contactObject->setOrganisation($organizationUuid);
			}

			// FIX #434, through the PUBLISHED contract. Same two flags as the other
			// site (_validation: false, silent: true), plus the two pieces of
			// entity METADATA this call exists to set, which the payload API
			// expresses differently:
			//
			//   organisation  travels in `@self`, which SaveObject reads and applies
			//                 via setOrganisation() — behind an access check, so an
			//                 organisation the caller may not use is refused rather
			//                 than written, which the direct mapper call bypassed
			//   owner         is NOT settable from the payload; SaveObject derives it
			//                 from the acting user, so it is passed as `currentUser`
			$objectService = $this->getObjectService();
			$userManager = $this->container->get('OCP\IUserManager');
			$actingUser = $userManager->get($userUID);
			if ($objectService !== null && $actingUser !== null) {
				$payload = $contactObject->getObject();
				$payload['@self'] = ['organisation' => $organizationUuid];

				$objectService->saveObject(
					object: $payload,
					register: $contactObject->getRegister(),
					schema: $contactObject->getSchema(),
					uuid: $contactObject->getUuid(),
					silent: true,
					_validation: false,
					currentUser: $actingUser
				);
			}

			$this->logger->info(
				'ContactpersoonService: Successfully updated contactpersoon object owner and organisation',
				[
					'contactId' => $contactId,
					'userUID' => $userUID,
					'ownerSet' => $selfMetadata['owner'],
					'organisationSet' => ($selfMetadata['organisation'] ?? 'not set'),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to update contactpersoon object owner',
				[
					'contactId' => $contactObject->getUuid(),
					'userUID' => $userUID,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try

	}//end updateContactpersoonObjectOwner()

	/**
	 * Enable user account for a contactpersoon.
	 *
	 * @param string $contactPersonId The UUID of the contactpersoon.
	 *
	 * @return void
	 *
	 * @throws \Exception If enabling fails.
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function enableUserForContactpersoon(string $contactPersonId): void {
		try {
			$this->logger->info(
				'ContactpersoonService: Enabling user for contactpersoon',
				['contactpersoonId' => $contactPersonId]
			);

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \Exception('ObjectService not available');
			}

			$contactObject = $objectService->find(
				id: $contactPersonId,
				register: 'voorzieningen',
				schema: 'contactPerson',
				_rbac: false,
				_multitenancy: false
			);
			if ($contactObject === null) {
				throw new \Exception('Contactpersoon not found');
			}

			$contactData = $contactObject->getObject();
			$username = ($contactData['username'] ?? null);

			if (empty($username) === true) {
				throw new \Exception('No username found for contactpersoon');
			}

			$userManager = $this->container->get('OCP\IUserManager');
			$user = $userManager->get($username);

			if ($user === null) {
				throw new \Exception('User not found in Nextcloud');
			}

			// Enable the user.
			$user->setEnabled(true);

			$this->logger->info(
				'ContactpersoonService: User enabled successfully',
				[
					'contactpersoonId' => $contactPersonId,
					'username' => $username,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to enable user for contactpersoon',
				[
					'contactpersoonId' => $contactPersonId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try

	}//end enableUserForContactpersoon()

	/**
	 * Disable user account for a contactpersoon.
	 *
	 * @param string $contactPersonId The UUID of the contactpersoon.
	 *
	 * @return void
	 *
	 * @throws \Exception If disabling fails.
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function disableUserForContactpersoon(string $contactPersonId): void {
		try {
			$this->logger->info(
				'ContactpersoonService: Disabling user for contactpersoon',
				['contactpersoonId' => $contactPersonId]
			);

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \Exception('ObjectService not available');
			}

			$contactObject = $objectService->find(
				id: $contactPersonId,
				register: 'voorzieningen',
				schema: 'contactPerson',
				_rbac: false,
				_multitenancy: false
			);
			if ($contactObject === null) {
				throw new \Exception('Contactpersoon not found');
			}

			$contactData = $contactObject->getObject();
			$username = ($contactData['username'] ?? null);

			if (empty($username) === true) {
				throw new \Exception('No username found for contactpersoon');
			}

			$userManager = $this->container->get('OCP\IUserManager');
			$user = $userManager->get($username);

			if ($user === null) {
				throw new \Exception('User not found in Nextcloud');
			}

			// Disable the user.
			$user->setEnabled(false);

			$this->logger->info(
				'ContactpersoonService: User disabled successfully',
				[
					'contactpersoonId' => $contactPersonId,
					'username' => $username,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to disable user for contactpersoon',
				[
					'contactpersoonId' => $contactPersonId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try

	}//end disableUserForContactpersoon()

	/**
	 * Returns true when the contactpersoon email is non-empty AND passes
	 * `filter_var(FILTER_VALIDATE_EMAIL)`. Emits a warning log + returns false
	 * otherwise so the caller can early-return.
	 *
	 * Extracted from {@see processContactpersoon()} as part of task 7.2.
	 *
	 * @param string $email The candidate email address.
	 * @param string $contactId The contact id (for the log context).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-7
	 */
	private function isContactPersonEmailUsable(string $email, string $contactId): bool {
		if (empty($email) === true) {
			$this->logger->warning(
				'ContactpersoonService: Contactpersoon has no email, skipping processing',
				['contactId' => $contactId]
			);
			return false;
		}

		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			$this->logger->warning(
				'ContactpersoonService: Contactpersoon has invalid email, skipping user creation',
				[
					'contactId' => $contactId,
					'email' => $email,
				]
			);
			return false;
		}

		return true;
	}//end isContactpersoonEmailUsable()
}//end class
