<?php

/**
 * Software Catalogue Service
 *
 * Service for handling software catalog specific operations including
 * user management, contact processing, and object lifecycle management.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling software catalog operations.
 *
 * Provides functionality for user management, contact processing,
 * email notifications, and object lifecycle management.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
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
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 * @SuppressWarnings(PHPMD.CountInLoopExpression)
 */
class StackiqService {

	/**
	 * The name of the app
	 *
	 * @var string
	 */
	private string $appName;

	/**
	 * StackiqService constructor
	 *
	 * @param ContactPersonHandler $_contactPersonHandler Contact person handler.
	 * @param GroupHandler $_groupHandler Group handler.
	 * @param HierarchyHandler $_hierarchyHandler Hierarchy handler.
	 * @param LoggerInterface $_logger Logger interface.
	 * @param ContainerInterface $_container Container interface.
	 * @param IAppManager $_appManager App manager interface.
	 */
	public function __construct(
		private readonly ContactPersonHandler $_contactPersonHandler,
		private readonly GroupHandler $_groupHandler,
		private readonly HierarchyHandler $_hierarchyHandler,
		private readonly LoggerInterface $_logger,
		private readonly ContainerInterface $_container,
		private readonly IAppManager $_appManager,
	) {
		$this->appName = 'stackiq';
	}//end __construct()

	/**
	 * Gets the ObjectService instance
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if ($this->_appManager->isEnabledForUser(appId: 'openregister') === false) {
			return null;
		}

		try {
			return $this->_container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Exception $e) {
			$this->_logger->error('Failed to get ObjectService: ' . $e->getMessage());
			return null;
		}
	}//end getObjectService()

	/**
	 * Gets the OrganisationMapper instance
	 *
	 * OpenRegister is an optional capability for this service (ADR-083 rule 1),
	 * so the mapper is reached the same way the two services above are: the app
	 * is asked whether OpenRegister is available, and a failed resolution
	 * degrades to null with a logged error rather than escaping as a raw
	 * container exception. Callers must treat null as "OpenRegister is not
	 * available" and take their own not-available branch.
	 *
	 * @return \OCA\OpenRegister\Db\OrganisationMapper|null
	 */
	private function getOrganisationMapper(): ?\OCA\OpenRegister\Db\OrganisationMapper {
		if ($this->_appManager->isEnabledForUser(appId: 'openregister') === false) {
			return null;
		}

		try {
			return $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
		} catch (\Exception $e) {
			$this->_logger->error('Failed to get OrganisationMapper: ' . $e->getMessage());
			return null;
		}
	}//end getOrganisationMapper()

	/**
	 * Processes a contactpersoon object to create an inactive user
	 *
	 * If the contactpersoon object doesn't have a user or the user is missing,
	 * this method will create an inactive user account.
	 *
	 * @param object $contactPersonObject The contactpersoon object to process
	 * @param bool $isUpdate Whether this is an update operation (defaults to false)
	 *
	 * @return bool True if processing was successful
	 * @throws \Exception If processing fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isUpdate is a simple create-vs-update toggle
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function processContactpersoon(object $contactPersonObject, bool $isUpdate = false): bool {
		$startTime = microtime(true);

		try {
			$objectId = $contactPersonObject->getId();
			$objectData = $contactPersonObject->getObject();

			$this->_logger->info(
				'StackiqService: Starting contactpersoon processing',
				[
					'objectId' => $objectId,
					'objectData' => $objectData,
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);

			// Delegate to contact person handler.
			$this->_logger->debug(
				'StackiqService: Delegating to ContactPersonHandler for contactpersoon processing',
				[
					'objectId' => $objectId,
				]
			);

			$result = $this->_contactPersonHandler->processContactpersoon($contactPersonObject, $isUpdate);

			$this->_logger->info(
				'StackiqService: ContactPersonHandler processing completed',
				[
					'objectId' => $objectId,
					'result' => $result,
					'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);

			if ($result === true) {
				// Get the username from the processed object.
				$updatedObjectData = $contactPersonObject->getObject();
				$username = $updatedObjectData['username'] ?? '';

				$this->_logger->info(
					'StackiqService: Username extracted from processed object',
					[
						'objectId' => $objectId,
						'username' => $username,
						'hasUsername' => empty($username) === false,
					]
				);

				if (empty($username) === false) {
					// NOTE: Group assignment is already handled by ContactPersonHandler.assignUserGroups().
					// during user creation, so we don't need to call GroupHandler.updateUserGroups() here.
					// as it would overwrite the correct group assignments.
					// Ensure organization has beheerder and set up manager relationships.
					$this->_logger->debug(
						'StackiqService: Ensuring organization beheerder',
						[
							'objectId' => $objectId,
							'username' => $username,
						]
					);

					$this->_hierarchyHandler->ensureOrganizationBeheerder($contactPersonObject, $username);

					// Set user to inactive initially.
					$this->_logger->debug(
						'StackiqService: Setting user to inactive',
						[
							'objectId' => $objectId,
							'username' => $username,
						]
					);

					$this->_contactPersonHandler->setUserInactive($username);

					$this->_logger->info(
						'StackiqService: User setup completed',
						[
							'objectId' => $objectId,
							'username' => $username,
							'totalProcessingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
						]
					);

					// Add the newly created user to the organization entity.
					$organization = $objectData['organization'] ?? null;
					if (empty($organization) === false) {
						$this->_logger->info(
							'StackiqService: Adding user to organization entity',
							[
								'objectId' => $objectId,
								'username' => $username,
								'organization' => $organization,
							]
						);

						try {
							$organisationMapper = $this->getOrganisationMapper();
							if ($organisationMapper === null) {
								$this->_logger->warning(
									'StackiqService: OpenRegister OrganisationMapper not available, skipping organization membership',
									[
										'objectId' => $objectId,
										'username' => $username,
										'organization' => $organization,
									]
								);
								// Nothing follows this block but `return $result;`, so this is the
								// same exit the method would take after skipping the membership work.
								return $result;
							}

							$organisation = $organisationMapper->findByUuid($organization);

							if (empty($organisation) === false) {
								$currentUsers = $organisation->getUsers() ?? [];
								if (in_array($username, $currentUsers) === false) {
									$currentUsers[] = $username;
									$organisation->setUsers($currentUsers);
									$organisationMapper->save($organisation);

									$this->_logger->info(
										'StackiqService: Successfully added user to organization entity',
										[
											'objectId' => $objectId,
											'username' => $username,
											'organization' => $organization,
											'totalUsers' => count($currentUsers),
										]
									);
								} else {
									$this->_logger->info(
										'StackiqService: User already in organization entity',
										[
											'objectId' => $objectId,
											'username' => $username,
											'organization' => $organization,
										]
									);
								}//end if
							} else {
								$this->_logger->warning(
									'StackiqService: Organization entity not found',
									[
										'objectId' => $objectId,
										'username' => $username,
										'organization' => $organization,
									]
								);
							}//end if
						} catch (\Exception $e) {
							$this->_logger->error(
								'StackiqService: Failed to add user to organization entity',
								[
									'objectId' => $objectId,
									'username' => $username,
									'organization' => $organization,
									'error' => $e->getMessage(),
								]
							);
						}//end try
					} else {
						$this->_logger->warning(
							'StackiqService: No organisation reference found for contact person',
							[
								'objectId' => $objectId,
								'username' => $username,
							]
						);
					}//end if
				} else {
					$this->_logger->warning(
						'StackiqService: No username generated for contactpersoon',
						[
							'objectId' => $objectId,
							'objectData' => $updatedObjectData,
						]
					);
				}//end if
			} else {
				$this->_logger->warning(
					'StackiqService: ContactPersonHandler returned false',
					[
						'objectId' => $objectId,
						'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
					]
				);
			}//end if

			return $result;
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to process contactpersoon object: ' . $e->getMessage(),
				[
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
					'objectId' => $contactPersonObject->getId() ?? 'unknown',
					'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			throw $e;
		}//end try
	}//end processContactpersoon()

	/**
	 * Processes a contactpersoon object to ensure it has a username
	 *
	 * If the contactpersoon object doesn't have a username or it's empty,
	 * this method will create a user account and set the username property.
	 *
	 * @param object $contactPersonObject The contactpersoon object to process
	 *
	 * @return bool True if processing was successful
	 * @throws \Exception If processing fails
	 */

	/**
	 * Updates user groups based on contactpersoon data
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username to update groups for
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function updateUserGroups(object $contactPersonObject, string $username): void {
		// Use the new organization type-based logic instead of old role-based logic.
		$user = $this->_container->get(\OCP\IUserManager::class)->get($username);
		if (empty($user) === false) {
			$contactData = $contactPersonObject->getObject();
			$this->_contactPersonHandler->updateUserGroupsFromContactData($user, $contactData);
		} else {
			$this->_logger->warning('User not found for group update', ['username' => $username]);
		}
	}//end updateUserGroups()

	/**
	 * Ensures organization has at least one beheerder and manages user hierarchy
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username being processed
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function ensureOrganizationBeheerder(object $contactPersonObject, string $username): void {
		// Delegate to hierarchy handler.
		$this->_hierarchyHandler->ensureOrganizationBeheerder($contactPersonObject, $username);
	}//end ensureOrganizationBeheerder()

	/**
	 * Gets a user's manager
	 *
	 * @param string $username The username
	 *
	 * @return string|null The manager's username or null if not set
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function getUserManager(string $username): ?string {
		// Delegate to contact person handler.
		return $this->_contactPersonHandler->getUserManager($username);
	}//end getUserManager()

	/**
	 * Activates contactpersonen users for an organization.
	 *
	 * @param string $organizationId The organization ID.
	 *
	 * @return void
	 *
	 * @deprecated This method is disabled to prevent organization duplication.
	 */
	private function activateContactpersonenForOrganization(string $organizationId): void {
		// DISABLED: Organization handling is disabled to prevent duplication.
		$this->_logger->info(
			'Organization contactpersonen activation is disabled to prevent duplication',
			[
				'organizationId' => $organizationId,
			]
		);

		return;
		// Disabled: organization contactpersonen activation logic removed.
	}//end activateContactpersonenForOrganization()

	/**
	 * Handles new contact creation
	 *
	 * @param object $contactObject The contact object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleNewContact(object $contactObject): void {
		// Delegate to contact person handler.
		$this->_contactPersonHandler->handleNewContact($contactObject);
	}//end handleNewContact()

	/**
	 * Handles new gebruiker creation
	 *
	 * @param object $userObject The gebruiker object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleNewGebruiker(object $userObject): void {
		// Implementation for handling new gebruiker.
		$this->_logger->info(
			'Handling new gebruiker',
			[
				'objectId' => $userObject->getId(),
			]
		);
	}//end handleNewGebruiker()

	/*
	 * NO sendGebruikerWelcomeEmail() HERE.
	 *
	 * Its whole body was a `logger->info('Sending gebruiker welcome email')`
	 * — it never sent anything — and no caller reached it:
	 * `StackiqEventListener` does not invoke it on the
	 * gebruiker-created path. Wiring a method that sends no mail would have
	 * bought nothing; implementing one is a feature, not dead-code removal.
	 */

	/**
	 * Handles contact update
	 *
	 * @param object $contactObject The contact object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleContactUpdate(object $contactObject): void {
		// Delegate to contact person handler.
		$this->_contactPersonHandler->handleContactUpdate($contactObject);
	}//end handleContactUpdate()

	/**
	 * Handles gebruiker update
	 *
	 * @param object $userObject The new gebruiker object
	 * @param object $oldUserObject The old gebruiker object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleGebruikerUpdate(object $userObject, object $oldUserObject): void {
		// Implementation for handling gebruiker updates.
		$this->_logger->info(
			'Handling gebruiker update',
			[
				'objectId' => $userObject->getId(),
			]
		);
	}//end handleGebruikerUpdate()

	/**
	 * Handles contact deletion
	 *
	 * @param object $contactObject The contact object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleContactDeletion(object $contactObject): void {
		// Delegate to contact person handler.
		$this->_contactPersonHandler->handleContactDeletion($contactObject);
	}//end handleContactDeletion()

	/**
	 * Blocks user for gebruiker
	 *
	 * @param object $userObject The gebruiker object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function blockUserForGebruiker(object $userObject): void {
		// Implementation for blocking user.
		$this->_logger->info(
			'Blocking user for gebruiker',
			[
				'objectId' => $userObject->getId(),
			]
		);
	}//end blockUserForGebruiker()

	/**
	 * Temporarily blocks user for gebruiker
	 *
	 * @param object $userObject The gebruiker object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function temporarilyBlockUserForGebruiker(object $userObject): void {
		// Implementation for temporarily blocking user.
		$this->_logger->info(
			'Temporarily blocking user for gebruiker',
			[
				'objectId' => $userObject->getId(),
			]
		);
	}//end temporarilyBlockUserForGebruiker()

	/**
	 * Restores user access for gebruiker
	 *
	 * @param object $userObject The gebruiker object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function restoreUserAccessForGebruiker(object $userObject): void {
		// Implementation for restoring user access.
		$this->_logger->info(
			'Restoring user access for gebruiker',
			[
				'objectId' => $userObject->getId(),
			]
		);
	}//end restoreUserAccessForGebruiker()

	/*
	 * NO REVERT-SYNC METHODS HERE.
	 *
	 * `syncUserWithRevertedContact()` and `updateUserFromRevertedGebruiker()`
	 * were the same shape as `sendGebruikerWelcomeEmail()` above: a single
	 * `logger->info()` and nothing else, with no caller —
	 * `StackiqEventListener` handles `ObjectRevertedEvent` without
	 * touching this service. They named a capability (reconcile the Nextcloud
	 * user after an object revert) that has never been implemented; a log line
	 * is not that capability, and wiring one in would have made the gap
	 * invisible instead of removing it.
	 */

	/**
	 * Gets the list of generic user groups
	 *
	 * @return array Array of generic user groups
	 */
	public function getGenericUserGroups(): array {
		return $this->_groupHandler->getGenericUserGroups();
	}//end getGenericUserGroups()

	/**
	 * Sets the list of generic user groups
	 *
	 * @param array $groups Array of generic user groups
	 *
	 * @return void
	 */
	public function setGenericUserGroups(array $groups): void {
		$this->_groupHandler->setGenericUserGroups($groups);
	}//end setGenericUserGroups()

	/**
	 * Ensures all generic user groups exist
	 *
	 * @return array Array of created/existing groups
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function ensureGenericUserGroupsExist(): array {
		return $this->_groupHandler->ensureGenericUserGroupsExist();
	}//end ensureGenericUserGroupsExist()

	/**
	 * Gets organizational hierarchy information for a user
	 *
	 * @param string $username The username to get hierarchy for
	 *
	 * @return array Array containing hierarchy information
	 */
	public function getUserHierarchy(string $username): array {
		return $this->_hierarchyHandler->getUserHierarchy($username);
	}//end getUserHierarchy()

	/**
	 * Gets complete organizational structure
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Array containing organizational structure
	 */
	public function getOrganizationStructure(string $organizationUuid): array {
		return $this->_hierarchyHandler->getOrganizationStructure($organizationUuid);
	}//end getOrganizationStructure()

	/**
	 * Handles contactpersoon updates, particularly role changes
	 *
	 * @param object $contactPersonObject The updated contactpersoon object
	 * @param object $oldContactPersonObject The previous contactpersoon object (optional)
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleContactpersoonUpdate(object $contactPersonObject, ?object $oldContactPersonObject = null): void {
		$startTime = microtime(true);

		try {
			$objectId = $contactPersonObject->getId();
			$this->_logger->info(
				'StackiqService: Starting contactpersoon update handling',
				[
					'objectId' => $objectId,
					'hasOldObject' => $oldContactPersonObject !== null,
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);

			// Get current and old data for comparison.
			$newData = $contactPersonObject->getObject();
			$oldData = [];
			if ($oldContactPersonObject !== null) {
				$oldData = $oldContactPersonObject->getObject();
			}

			$newRoles = $newData['roles'] ?? [];
			$oldRoles = $oldData['roles'] ?? [];

			$this->_logger->debug(
				'StackiqService: Comparing roles for contactpersoon update',
				[
					'objectId' => $objectId,
					'newRoles' => $newRoles,
					'oldRoles' => $oldRoles,
					'newRolesType' => gettype($newRoles),
					'oldRolesType' => gettype($oldRoles),
				]
			);

			// Ensure both are arrays.
			if (is_array($newRoles) === false) {
				$newRoles = [$newRoles];
				$this->_logger->debug(
					'StackiqService: Converted newRoles to array',
					[
						'objectId' => $objectId,
						'newRoles' => $newRoles,
					]
				);
			}

			if (is_array($oldRoles) === false) {
				$oldRoles = [$oldRoles];
				$this->_logger->debug(
					'StackiqService: Converted oldRoles to array',
					[
						'objectId' => $objectId,
						'oldRoles' => $oldRoles,
					]
				);
			}

			// For updates, we need to handle differently based on whether roles changed.
			if ($newRoles !== $oldRoles) {
				// Roles changed - use role-based group assignment instead of generic group assignment.
				$this->_logger->info(
					'StackiqService: Roles changed for contactpersoon, using role-based group assignment',
					[
						'contactpersoonId' => $objectId,
						'oldRoles' => $oldRoles,
						'newRoles' => $newRoles,
						'addedRoles' => array_diff($newRoles, $oldRoles),
						'removedRoles' => array_diff($oldRoles, $newRoles),
					]
				);

				// Ensure user exists (but don't assign generic groups).
				$username = $newData['username'] ?? '';
				if (empty($username) === true) {
					// Generate username and create user if needed.
					$result = $this->_contactPersonHandler->processContactpersoon($contactPersonObject, true);
					if ($result === true) {
						$updatedData = $contactPersonObject->getObject();
						$username = $updatedData['username'] ?? '';
					}
				}

				if (empty($username) === false) {
					$user = $this->_container->get(\OCP\IUserManager::class)->get($username);
					if (empty($user) === false) {
						// Use new organization type-based logic instead of old role-based logic.
						$contactData = $contactPersonObject->getObject();
						$this->_contactPersonHandler->updateUserGroupsFromContactData($user, $contactData);

						$this->_logger->info(
							'StackiqService: Organization type-based group updates completed',
							[
								'username' => $username,
								'objectId' => $objectId,
								'newRoles' => $newRoles,
							]
						);
					} else {
						$this->_logger->warning(
							'StackiqService: User not found for role-based group updates',
							[
								'username' => $username,
								'objectId' => $objectId,
							]
						);
					}//end if
				} else {
					$this->_logger->warning(
						'StackiqService: No username available for role-based group updates',
						[
							'objectId' => $objectId,
							'newData' => $newData,
						]
					);
				}//end if
			} else {
				// No role changes - use standard processing (assigns generic groups).
				$this->_logger->debug(
					'StackiqService: No role changes, using standard contactpersoon processing',
					[
						'objectId' => $objectId,
						'roles' => $newRoles,
					]
				);

				$result = $this->processContactpersoon(contactPersonObject: $contactPersonObject, isUpdate: true);

				$this->_logger->info(
					'StackiqService: Standard contactpersoon processing completed',
					[
						'objectId' => $objectId,
						'result' => $result,
						'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
					]
				);
			}//end if

			$this->_logger->info(
				'StackiqService: Contactpersoon update handling completed',
				[
					'objectId' => $objectId,
					'totalProcessingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to handle contactpersoon update: ' . $e->getMessage(),
				[
					'objectId' => $contactPersonObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
					'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
		}//end try
	}//end handleContactpersoonUpdate()

	/**
	 * Handles organization deletion - deactivates all users in the organization
	 *
	 * @param object $organizationObject The organization object being deleted
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleOrganizationDeletion(object $organizationObject): void {
		try {
			$this->_logger->info(
				'StackiqService: Handling organization deletion',
				[
					'objectId' => $organizationObject->getId(),
				]
			);

			$objectData = $organizationObject->getObject();
			$organizationUuid = $objectData['id'] ?? $organizationObject->getId();

			// Deactivate all users in this organization.
			$this->deactivateUsersForOrganization(organizationUuid: $organizationUuid);

			$this->_logger->info(
				'StackiqService: Successfully handled organization deletion',
				[
					'organizationId' => $organizationUuid,
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to handle organization deletion: ' . $e->getMessage(),
				[
					'objectId' => $organizationObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end handleOrganizationDeletion()

	/**
	 * Activates all users in an organization when the organization becomes active
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 */
	private function activateUsersForOrganization(string $organizationUuid): void {
		try {
			$this->_logger->info(
				'StackiqService: Activating users for organization',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error('StackiqService: OpenRegister ObjectService not available');
				return;
			}

			// Get all contactpersonen for this organization.
			$ctx = $this->resolveVoorzieningenContext(schemaSlug: 'contactPerson', logContext: 'contactpersonen');
			if ($ctx === null) {
				return;
			}

			$registerId = $ctx['registerId'];
			$contactPersonSchemaId = $ctx['schemaId'];

			// The findAll() signature is ($config, bool $_rbac, bool $_multitenancy).
			// Register and schema belong inside the config's filters, as the
			// sibling call above does -- passed positionally they landed on the
			// two booleans, so this ran unscoped across every register with
			// $_rbac set to a register id.
			$contactpersonen = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $contactPersonSchemaId,
						'organisation' => $organizationUuid,
					],
				]
			);

			$userManager = $this->_container->get(\OCP\IUserManager::class);
			$activatedCount = 0;

			foreach ($contactpersonen as $contactPerson) {
				$contactData = $contactPerson->getObject();
				$username = $contactData['username'] ?? '';

				if (empty($username) === false) {
					$user = $userManager->get($username);
					if ($user !== null && $user->isEnabled() === false) {
						$user->setEnabled(true);
						$activatedCount++;

						$this->_logger->info(
							'StackiqService: Activated user for organization',
							[
								'username' => $username,
								'organizationUuid' => $organizationUuid,
							]
						);
					}
				}
			}

			$this->_logger->info(
				'StackiqService: Completed user activation for organization',
				[
					'organizationUuid' => $organizationUuid,
					'totalContactpersonen' => count($contactpersonen),
					'activatedUsers' => $activatedCount,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to activate users for organization: ' . $e->getMessage(),
				[
					'organizationUuid' => $organizationUuid,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end activateUsersForOrganization()

	/**
	 * Deactivates all users in an organization when the organization becomes inactive
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 */
	private function deactivateUsersForOrganization(string $organizationUuid): void {
		try {
			$this->_logger->info(
				'StackiqService: Deactivating users for organization',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error('StackiqService: OpenRegister ObjectService not available');
				return;
			}

			// Get all contactpersonen for this organization.
			$ctx = $this->resolveVoorzieningenContext(schemaSlug: 'contactPerson', logContext: 'contactpersonen');
			if ($ctx === null) {
				return;
			}

			$registerId = $ctx['registerId'];
			$contactPersonSchemaId = $ctx['schemaId'];

			// The findAll() signature is ($config, bool $_rbac, bool $_multitenancy).
			// Register and schema belong inside the config's filters, as the
			// sibling call above does -- passed positionally they landed on the
			// two booleans, so this ran unscoped across every register with
			// $_rbac set to a register id.
			$contactpersonen = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $contactPersonSchemaId,
						'organisation' => $organizationUuid,
					],
				]
			);

			$userManager = $this->_container->get(\OCP\IUserManager::class);
			$deactivatedCount = 0;

			foreach ($contactpersonen as $contactPerson) {
				$contactData = $contactPerson->getObject();
				$username = $contactData['username'] ?? '';

				if (empty($username) === false) {
					$user = $userManager->get($username);
					if ($user !== null && $user->isEnabled() === true) {
						$user->setEnabled(false);
						$deactivatedCount++;

						$this->_logger->info(
							'StackiqService: Deactivated user for organization',
							[
								'username' => $username,
								'organizationUuid' => $organizationUuid,
							]
						);
					}
				}
			}

			$this->_logger->info(
				'StackiqService: Completed user deactivation for organization',
				[
					'organizationUuid' => $organizationUuid,
					'totalContactpersonen' => count($contactpersonen),
					'deactivatedUsers' => $deactivatedCount,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to deactivate users for organization: ' . $e->getMessage(),
				[
					'organizationUuid' => $organizationUuid,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end deactivateUsersForOrganization()

	/**
	 * Checks if a contactpersoon username is in the organization's users list
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 *
	 * @return bool True if the user should be added to the organization
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
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
			$ctx = $this->resolveVoorzieningenContext(schemaSlug: 'organization', logContext: 'organization');
			if ($ctx === null) {
				return false;
			}

			$registerId = $ctx['registerId'];
			$organisationSchemaId = $ctx['schemaId'];

			try {
				$organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisationSchemaId);
				$organizationData = $organizationObject->getObject();

				// Check if the username is already in the organization's users.
				$organizationUsers = $organizationData['users'] ?? [];

				if (is_array($organizationUsers) === true && in_array($username, $organizationUsers) === false) {
					$this->_logger->info(
						'StackiqService: Contactpersoon should be added to organization',
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
					'StackiqService: Organization not found for contactpersoon',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return false;
			}//end try
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to check contactpersoon addition to org: ' . $e->getMessage(),
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
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function addContactpersoonToOrganization(object $contactPersonObject): bool {
		try {
			$objectData = $contactPersonObject->getObject();
			$username = $objectData['username'] ?? '';
			$organizationUuid = $objectData['organisation'] ?? '';

			if (empty($username) === true || empty($organizationUuid) === true) {
				$this->_logger->warning(
					'StackiqService: Cannot add contactpersoon to org - missing username or org',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return false;
			}

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error('StackiqService: OpenRegister ObjectService not available');
				return false;
			}

			// Get the organization object.
			$ctx = $this->resolveVoorzieningenContext(schemaSlug: 'organization', logContext: 'organization');
			if ($ctx === null) {
				return false;
			}

			$registerId = $ctx['registerId'];
			$organisationSchemaId = $ctx['schemaId'];

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
						'StackiqService: Successfully added contactpersoon to organization',
						[
							'username' => $username,
							'organizationUuid' => $organizationUuid,
							'updatedUsers' => $organizationUsers,
						]
					);
				}//end if

				$this->_logger->debug(
					'StackiqService: Contactpersoon already in organization',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return true;
				// Already there, consider it successful.
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				$this->_logger->error(
					'StackiqService: Organization not found for contactpersoon',
					[
						'username' => $username,
						'organizationUuid' => $organizationUuid,
					]
				);
				return false;
			}//end try
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to add contact person to organization: ' . $e->getMessage(),
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
	 * Ensures a contact person's username is in their organization's users array.
	 * NOTE: Dead method — retained only as implementation reference until the sync
	 * pipeline invocation point is wired; not called from any live code path.
	 *
	 * @param object $contactPersonObject The contact person object
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
	 *
	 * @return void
	 */
	private function ensureContactPersonInOrganization(object $contactPersonObject): void {
		$contactData = $contactPersonObject->getObject();
		$email = $contactData['email'] ?? null;
		$organization = $contactData['organization'] ?? null;

		if ($email === null || $organization === false) {
			$this->_logger->info(
				'StackiqService: Contact person missing email or organisation',
				[
					'contactPersonId' => $contactPersonObject->getId(),
					'hasEmail' => empty($email) === false,
					'hasOrganisatie' => empty($organization) === false,
				]
			);
			return;
		}

		// Skip if the contact person is owned by the default organization.
		$owner = $contactPersonObject->getOwner();
		if ($owner === 'system') {
			$this->_logger->info(
				'StackiqService: Skipping contact person owned by system',
				[
					'contactPersonId' => $contactPersonObject->getId(),
					'username' => $email,
				]
			);
			return;
		}

		$this->_logger->info(
			'StackiqService: Ensuring contact person in organization',
			[
				'contactPersonId' => $contactPersonObject->getId(),
				'username' => $email,
				'organization' => $organization,
			]
		);

		try {
			// Get the organization entity.
			$organisationMapper = $this->getOrganisationMapper();
			if ($organisationMapper === null) {
				$this->_logger->error(
					'StackiqService: OpenRegister OrganisationMapper not available for contact person',
					[
						'contactPersonId' => $contactPersonObject->getId(),
						'organization' => $organization,
					]
				);
				return;
			}

			$organisation = $organisationMapper->findByUuid($organization);

			if ($organisation === null) {
				$this->_logger->error(
					'StackiqService: Organization entity not found for contact person',
					[
						'contactPersonId' => $contactPersonObject->getId(),
						'organization' => $organization,
					]
				);
				return;
			}

			// Check if the username is already in the organization's users array.
			$currentUsers = $organisation->getUsers() ?? [];
			if (in_array($email, $currentUsers) === true) {
				$this->_logger->info(
					'StackiqService: Contact person already in organization',
					[
						'contactPersonId' => $contactPersonObject->getId(),
						'username' => $email,
						'organization' => $organization,
					]
				);
				return;
			}

			// Add the username to the organization's users array.
			$currentUsers[] = $email;
			$organisation->setUsers($currentUsers);
			$organisationMapper->save($organisation);

			$this->_logger->info(
				'StackiqService: Successfully added contact person to organization',
				[
					'contactPersonId' => $contactPersonObject->getId(),
					'username' => $email,
					'organization' => $organization,
					'totalUsers' => count($currentUsers),
				]
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Organization entity doesn't exist yet - this can happen due to race conditions.
			// Log and return gracefully, the organization sync will handle this later.
			$this->_logger->warning(
				'StackiqService: Org entity not found (race condition), handled by org sync',
				[
					'contactPersonId' => $contactPersonObject->getId(),
					'username' => $email,
					'organization' => $organization,
					'message' => 'Expected during anonymous registration - org entity created after contacts',
				]
			);
			return;
		}//end try
	}//end ensureContactPersonInOrganization()

	/**
	 * Resolves the voorzieningen register id + a schema id for a given
	 * object-type slug.
	 *
	 * Extracted from the repeated four-line "fetch SettingsService +
	 * getVoorzieningenRegisterId + getSchemaIdForObjectType + null/false
	 * guard" block that appeared in `activateUsersForOrganization()`,
	 * `deactivateUsersForOrganization()`,
	 * `shouldAddContactpersoonToOrganization()`,
	 * `addContactpersoonToOrganization()`, and
	 * `updateOrganizationReferences()`. Centralises the "missing config"
	 * log line so the per-method bodies no longer carry that boilerplate.
	 *
	 * W31 method-decomposition 2.6 — companion helper for the
	 * Stackiq subservice wiring.
	 *
	 * @param string $schemaSlug Object-type slug as understood by
	 *                           `SettingsService::getSchemaIdForObjectType()`
	 *                           (e.g. `contactpersoon`, `organisatie`).
	 * @param string $logContext Short human label for the missing-config
	 *                           log line (e.g. `contactpersonen`).
	 *
	 * @return array{registerId:int, schemaId:int}|null Null when either
	 *                                                  the voorzieningen register or the schema
	 *                                                  is unconfigured; the matched (registerId,
	 *                                                  schemaId) pair otherwise.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-2-6
	 */
	private function resolveVoorzieningenContext(string $schemaSlug, string $logContext): ?array {
		$settingsService = $this->_container->get(SettingsService::class);
		$registerId = $settingsService->getVoorzieningenRegisterId();
		$schemaId = $settingsService->getSchemaIdForObjectType($schemaSlug);

		if ($registerId === null || $schemaId === null || $schemaId === false) {
			$this->_logger->error(
				'StackiqService: Register or schema not configured for ' . $logContext
			);
			return null;
		}

		return [
			'registerId' => $registerId,
			'schemaId' => $schemaId,
		];

	}//end resolveVoorzieningenContext()
}//end class
