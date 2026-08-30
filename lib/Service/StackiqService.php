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
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
	 * @param OrganizationHandler $_organizationHandler Organization handler.
	 * @param ContactPersonHandler $_contactPersonHandler Contact person handler.
	 * @param GroupHandler $_groupHandler Group handler.
	 * @param HierarchyHandler $_hierarchyHandler Hierarchy handler.
	 * @param SymfonyEmailService $_emailService Email service.
	 * @param LoggerInterface $_logger Logger interface.
	 * @param ContainerInterface $_container Container interface.
	 * @param IAppManager $_appManager App manager interface.
	 * @param IUserSession $_userSession User session interface.
	 * @param IUserManager $_userManager User manager interface.
	 * @param IGroupManager $_groupManager Group manager interface.
	 */
	public function __construct(
		private readonly OrganizationHandler $_organizationHandler,
		private readonly ContactPersonHandler $_contactPersonHandler,
		private readonly GroupHandler $_groupHandler,
		private readonly HierarchyHandler $_hierarchyHandler,
		private readonly SymfonyEmailService $_emailService,
		private readonly LoggerInterface $_logger,
		private readonly ContainerInterface $_container,
		private readonly IAppManager $_appManager,
		private readonly IUserSession $_userSession,
		private readonly IUserManager $_userManager,
		private readonly IGroupManager $_groupManager,
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
	 * Gets the OrganisationService instance
	 *
	 * @return \OCA\OpenRegister\Service\OrganisationService|null
	 */
	private function getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService {
		if ($this->_appManager->isEnabledForUser(appId: 'openregister') === false) {
			return null;
		}

		try {
			return $this->_container->get('OCA\\OpenRegister\\Service\\OrganisationService');
		} catch (\Exception $e) {
			$this->_logger->error('Failed to get OrganisationService: ' . $e->getMessage());
			return null;
		}
	}//end getOrganisationService()

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
	 * Processes organization without contactpersonen processing
	 *
	 * @param object $organizationObject The organization object to process.
	 *
	 * @return bool True if processing was successful.
	 * @throws \Exception If processing fails.
	 *
	 * @deprecated This method is disabled to prevent organization duplication.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function processOrganization(object $organizationObject): bool {
		// DISABLED: Organization processing is disabled to prevent duplication.
		$this->_logger->info(
			'Organization processing is disabled to prevent duplication',
			[
				'organizationId' => $organizationObject->getId(),
			]
		);

		return false;
		// Disabled: organization processing logic removed.
	}//end processOrganization()

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
	 * Handles new organization creation - syncs with OpenRegister and processes organization
	 *
	 * @param object $organizationObject The new organization object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleNewOrganization(object $organizationObject): void {
		try {
			$this->_logger->info(
				'StackiqService: Handling new organization',
				[
					'objectId' => $organizationObject->getId(),
				]
			);

			// First, sync the organization with OpenRegister.
			$syncResult = $this->syncOrganizationWithOpenRegister(organizationObject: $organizationObject);

			if ($syncResult === true) {
				$this->_logger->info(
					'StackiqService: Successfully synced organization with OpenRegister',
					[
						'objectId' => $organizationObject->getId(),
					]
				);

				// Update organization references on objects to point to the newly created organization entity.
				$this->updateOrganizationReferences(organizationObject: $organizationObject);
			} else {
				$this->_logger->warning(
					'StackiqService: Failed to sync organization with OpenRegister',
					[
						'objectId' => $organizationObject->getId(),
					]
				);
			}

			// Process the organization (existing functionality) - this creates users.
			$this->processOrganization(organizationObject: $organizationObject);

			// Add all admin group users to the organization.
			$objectData = $organizationObject->getObject();
			$organizationUuid = $objectData['id'] ?? $organizationObject->getId();
			$this->addAdminGroupUsersToOrganization(organizationUuid: $organizationUuid);

			// Handle ownership assignment for anonymous user registrations AFTER user creation.
			$this->handleOwnershipAssignment(organizationObject: $organizationObject);

			// Send welcome email for new organization.
			$this->sendOrganizationWelcomeEmail(organizationObject: $organizationObject);

			// If organization is active, send activation email too.
			$objectData = $organizationObject->getObject();
			$assessment = strtolower($objectData['beoordeling'] ?? '');

			if ($assessment === 'actief') {
				try {
					$success = $this->_emailService->sendOrganizationActivationEmail($objectData);
					$this->_logger->info(
						'Organization activation email sent',
						[
							'objectId' => $organizationObject->getId(),
							'success' => $success,
						]
					);
				} catch (\Exception $e) {
					$this->_logger->error(
						'Failed to send organization activation email: ' . $e->getMessage(),
						[
							'objectId' => $organizationObject->getId(),
							'exception' => $e,
						]
					);
				}
			}

			// Process nested contact persons and add their users to the organization entity.
			$contactpersonen = $objectData['contactpersonen'] ?? [];
			if (empty($contactpersonen) === false) {
				$this->_logger->info(
					'StackiqService: Processing nested contact persons',
					[
						'objectId' => $organizationObject->getId(),
						'contactPersonCount' => count($contactpersonen),
					]
				);

				$organizationUuid = $objectData['id'] ?? $organizationObject->getId();
				$objectService = $this->getObjectService();

				if (empty($objectService) === false) {
					$settingsService = $this->_container->get('OCA\Stackiq\Service\SettingsService');
					$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
					$contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;

					if ($contactSchemaId === null) {
						$this->_logger->warning('StackiqService: Missing contactpersoon schema configuration');
						return;
					}

					$organisationMapper = $this->getOrganisationMapper();
					if ($organisationMapper === null) {
						$this->_logger->warning(
							'StackiqService: OpenRegister OrganisationMapper not available, skipping contact person membership'
						);
						return;
					}

					$organisation = $organisationMapper->findByUuid($organizationUuid);

					if (empty($organisation) === false) {
						$currentUsers = $organisation->getUsers() ?? [];
						$addedUsers = [];

						foreach ($contactpersonen as $contactPersonId) {
							try {
								$contactPersonObject = $objectService->find($contactPersonId);
								$contactData = $contactPersonObject->getObject();
								$email = $contactData['email'] ?? null;

								if ($email !== false && in_array($email, $currentUsers) === false) {
									$currentUsers[] = $email;
									$addedUsers[] = $email;

									$this->_logger->info(
										'StackiqService: Added nested contact person user to organization',
										[
											'objectId' => $organizationObject->getId(),
											'contactPersonId' => $contactPersonId,
											'username' => $email,
											'organizationUuid' => $organizationUuid,
										]
									);
								}
							} catch (\Exception $e) {
								$this->_logger->warning(
									'StackiqService: Failed to process nested contact person',
									[
										'objectId' => $organizationObject->getId(),
										'contactPersonId' => $contactPersonId,
										'error' => $e->getMessage(),
									]
								);
							}//end try
						}//end foreach

						if (empty($addedUsers) === false) {
							$organisation->setUsers($currentUsers);
							$organisationMapper->save($organisation);

							$this->_logger->info(
								'StackiqService: Updated org with nested contact person users',
								[
									'objectId' => $organizationObject->getId(),
									'organizationUuid' => $organizationUuid,
									'addedUsers' => $addedUsers,
									'totalUsers' => count($currentUsers),
								]
							);
						}
					}//end if
				}//end if
			}//end if

			// Final synchronization: ensure all contact persons associated with this organization are in the users array.
			// This handles cases where contact persons were created separately and not as nested objects.
			$objectData = $organizationObject->getObject();
			$organizationUuid = $objectData['id'] ?? $organizationObject->getId();
			$this->syncContactPersonUsernamesWithOrganization(organizationUuid: $organizationUuid);

			$this->_logger->info(
				'StackiqService: Completed final contact person synchronization for new organization',
				[
					'objectId' => $organizationObject->getId(),
					'organizationUuid' => $organizationUuid,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to handle new organization: ' . $e->getMessage(),
				[
					'objectId' => $organizationObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end handleNewOrganization()

	/**
	 * Handles organization updates - syncs with OpenRegister and manages user status based on organization status
	 *
	 * @param object $organizationObject The updated organization object
	 * @param object $oldOrganizationObject The previous organization object
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function handleOrganizationUpdate(object $organizationObject, object $oldOrganizationObject): void {
		try {
			$this->_logger->info(
				'StackiqService: Handling organization update',
				[
					'objectId' => $organizationObject->getId(),
				]
			);

			$newData = $organizationObject->getObject();
			$oldData = $oldOrganizationObject->getObject();

			// Check both 'beoordeling' and 'status' fields (different schemas use different field names).
			$newAssessment = strtolower($newData['beoordeling'] ?? $newData['status'] ?? '');
			$oldAssessment = strtolower($oldData['beoordeling'] ?? $oldData['status'] ?? '');

			// Sync the organization with OpenRegister.
			$syncResult = $this->syncOrganizationWithOpenRegister(organizationObject: $organizationObject);

			if ($syncResult === true) {
				$this->_logger->info(
					'StackiqService: Successfully synced organization with OpenRegister',
					[
						'objectId' => $organizationObject->getId(),
					]
				);
			} else {
				$this->_logger->warning(
					'StackiqService: Failed to sync organization with OpenRegister',
					[
						'objectId' => $organizationObject->getId(),
					]
				);
			}

			// Add all admin group users to the organization (ensure they're always included).
			$organizationUuid = $newData['id'] ?? $organizationObject->getId();
			$this->addAdminGroupUsersToOrganization(organizationUuid: $organizationUuid);

			// Check if organization status changed to active.
			if ($newAssessment === 'actief') {
				$becameActive = ($oldAssessment !== 'actief');

				$activeMessage = 'Organization is active';
				if ($becameActive === true) {
					$activeMessage = 'Organization became active';
				}

				$this->_logger->info(
					$activeMessage,
					[
						'organizationId' => $organizationObject->getId(),
						'oldBeoordeling' => $oldAssessment,
						'newBeoordeling' => $newAssessment,
						'becameActive' => $becameActive,
					]
				);

				if ($becameActive === true) {
					$organizationUuid = $newData['id'] ?? $organizationObject->getId();

					$this->_logger->info(
						'StackiqService: Organization became active - creating users from contactpersonen',
						[
							'organizationUuid' => $organizationUuid,
						]
					);

					// Process the organization to create users from contactpersonen.
					// This is crucial when an organization is activated for the first time.
					// and contactpersonen were added before activation.
					$this->processOrganization(organizationObject: $organizationObject);

					// Activate Stackiq-specific users in this organization.
					$this->activateStackiqUsersForOrganization(organizationUuid: $organizationUuid);

					// Send activation email.
					try {
						$success = $this->_emailService->sendOrganizationActivationEmail($newData);
						$this->_logger->info(
							'Organization activation email sent',
							[
								'objectId' => $organizationObject->getId(),
								'success' => $success,
							]
						);
					} catch (\Exception $e) {
						$this->_logger->error(
							'Failed to send organization activation email: ' . $e->getMessage(),
							[
								'objectId' => $organizationObject->getId(),
								'exception' => $e,
							]
						);
					}
				}//end if
			}//end if

			// Check if organization status changed to inactive.
			if ($newAssessment === 'inactief' || $newAssessment === 'deactief') {
				$becameInactive = ($oldAssessment === 'actief');

				$inactiveMessage = 'Organization is inactive';
				if ($becameInactive === true) {
					$inactiveMessage = 'Organization became inactive';
				}

				$this->_logger->info(
					$inactiveMessage,
					[
						'organizationId' => $organizationObject->getId(),
						'oldBeoordeling' => $oldAssessment,
						'newBeoordeling' => $newAssessment,
						'becameInactive' => $becameInactive,
					]
				);

				if ($becameInactive === true) {
					// Deactivate Stackiq-specific users in this organization.
					$organizationUuid = $newData['id'] ?? $organizationObject->getId();
					$this->deactivateStackiqUsersForOrganization(organizationUuid: $organizationUuid);
				}
			}//end if
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to handle organization update: ' . $e->getMessage(),
				[
					'objectId' => $organizationObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end handleOrganizationUpdate()

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
	 * Sends welcome email to organization.
	 *
	 * @param object $organizationObject The organization object.
	 *
	 * @return void
	 *
	 * @deprecated This method is disabled to prevent organization duplication.
	 * @spec       openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function sendOrganizationWelcomeEmail(object $organizationObject): void {
		// DISABLED: Organization handling is disabled to prevent duplication.
		$this->_logger->info(
			'Organization welcome email sending is disabled to prevent duplication',
			[
				'organizationId' => $organizationObject->getId(),
			]
		);

		return;
		// Disabled: organization welcome email logic removed.
	}//end sendOrganizationWelcomeEmail()

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
	 * Syncs organization data with OpenRegister
	 *
	 * @param object $organizationObject The organization object to sync
	 *
	 * @return bool True if sync was successful
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function syncOrganizationWithOpenRegister(object $organizationObject): bool {
		try {
			$this->_logger->info(
				'StackiqService: SYNC_STEP_1 - Starting syncOrganizationWithOpenRegister',
				[
					'objectId' => $organizationObject->getId(),
					'objectClass' => get_class($organizationObject),
				]
			);

			$objectData = $organizationObject->getObject();
			$organizationUuid = $objectData['id'] ?? $organizationObject->getId();

			$this->_logger->info(
				'StackiqService: SYNC_STEP_2 - Extracted organization data',
				[
					'organizationUuid' => $organizationUuid,
					'objectDataKeys' => array_keys($objectData),
					'hasId' => isset($objectData['id']) === true,
				]
			);

			// Get OpenRegister OrganisationService for proper organization entity management.
			$this->_logger->info('StackiqService: SYNC_STEP_3 - Getting OrganisationService');
			$organisationService = $this->getOrganisationService();
			if ($organisationService === null) {
				$this->_logger->error(
					'StackiqService: SYNC_STEP_3 - OpenRegister OrganisationService not available'
				);
				return false;
			}

			$this->_logger->info(
				'StackiqService: SYNC_STEP_3 - OrganisationService retrieved',
				[
					'serviceClass' => get_class($organisationService),
				]
			);

			$this->_logger->info(
				'StackiqService: SYNC_STEP_4 - OpenRegister configuration',
				[
					'organizationUuid' => $organizationUuid,
					'organizationName' => $objectData['name'] ?? 'Unknown',
				]
			);

			// Check if organization already exists in OpenRegister.
			$this->_logger->info('StackiqService: SYNC_STEP_5 - Checking if organization exists in OpenRegister');
			try {
				$this->_logger->info('StackiqService: SYNC_STEP_5A - Getting OrganisationMapper for lookup');
				$organisationMapper = $this->getOrganisationMapper();
				if ($organisationMapper === null) {
					$this->_logger->error(
						'StackiqService: OpenRegister OrganisationMapper not available, cannot sync organization'
					);
					return false;
				}

				$this->_logger->info(
					'StackiqService: SYNC_STEP_5B - Calling findByUuid',
					[
						'uuid' => $organizationUuid,
					]
				);
				$existingOrganisation = $organisationMapper->findByUuid($organizationUuid);

				// Organization exists - update it.
				$this->_logger->info(
					'StackiqService: SYNC_STEP_6 - Organization exists in OpenRegister, updating',
					[
						'organizationId' => $organizationUuid,
						'existingOrganisationClass' => get_class($existingOrganisation),
					]
				);

				// Map status from Stackiq to OpenRegister.
				$this->_logger->info('StackiqService: SYNC_STEP_7 - Mapping organization data');
				$mappedData = $this->mapOrganizationDataForOpenRegister(objectData: $objectData);

				// Update the organization using OrganisationService.
				$updatedOrganisation = $this->updateOrganisationInOpenRegister(
					organisationService: $organisationService,
					existingOrganisation: $existingOrganisation,
					mappedData: $mappedData
				);

				$this->_logger->info(
					'StackiqService: Successfully updated organization in OpenRegister',
					[
						'organizationId' => $organizationUuid,
						'openRegisterId' => $updatedOrganisation->getUuid(),
					]
				);

				return true;
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Organization doesn't exist - create it.
				$this->_logger->info(
					'StackiqService: SYNC_STEP_8 - Organization not found in OpenRegister, creating',
					[
						'organizationId' => $organizationUuid,
						'exception' => $e->getMessage(),
					]
				);

				// Map status from Stackiq to OpenRegister.
				$this->_logger->info('StackiqService: SYNC_STEP_9 - Mapping organization data for creation');
				$mappedData = $this->mapOrganizationDataForOpenRegister(objectData: $objectData);

				// Create the organization using OrganisationService.
				$this->_logger->info('StackiqService: SYNC_STEP_10 - Calling createOrganisationInOpenRegister');
				$createdOrganisation = $this->createOrganisationInOpenRegisterInternal(
					organisationService: $organisationService,
					mappedData: $mappedData,
					organizationUuid: $organizationUuid
				);

				$this->_logger->info(
					'StackiqService: SYNC_STEP_11 - Successfully created organization in OpenRegister',
					[
						'organizationId' => $organizationUuid,
						'openRegisterId' => $createdOrganisation->getUuid(),
						'createdOrganisationClass' => get_class($createdOrganisation),
					]
				);

				return true;
			}//end try
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to sync organization with OpenRegister: ' . $e->getMessage(),
				[
					'objectId' => $organizationObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return false;
		}//end try
	}//end syncOrganizationWithOpenRegister()

	/**
	 * Public wrapper for creating organization in OpenRegister (used by background job)
	 *
	 * @param array $objectData The organization object data
	 *
	 * @return object|null The created organisation entity or null on failure
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function createOrganisationInOpenRegister(array $objectData): ?object {
		try {
			$organizationUuid = $objectData['id'] ?? null;
			if ($organizationUuid === null) {
				$this->_logger->error('StackiqService: No organization UUID provided for creation');
				return null;
			}

			// Map the data.
			$mappedData = [
				'name' => $objectData['name'] ?? 'Unknown',
				'type' => $objectData['type'] ?? '',
				'website' => $objectData['website'] ?? '',
				'active' => $this->mapStatus(status: $objectData['beoordeling'] ?? 'actief'),
				'contactpersonen' => $objectData['contactpersonen'] ?? [],
				'participants' => $objectData['participants'] ?? [],
			];

			// Get organisation service.
			$organisationService = $this->_container->get('OCA\OpenRegister\Service\OrganisationService');

			return $this->createOrganisationInOpenRegisterInternal(
				organisationService: $organisationService,
				mappedData: $mappedData,
				organizationUuid: $organizationUuid
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error in public createOrganisationInOpenRegister',
				[
					'error' => $e->getMessage(),
					'objectData' => $objectData,
				]
			);
			return null;
		}//end try
	}//end createOrganisationInOpenRegister()

	/**
	 * Map status from Software Catalog to OpenRegister format
	 *
	 * @param string $status The status from Software Catalog
	 *
	 * @return bool The mapped active status for OpenRegister
	 */
	private function mapStatus(string $status): bool {
		switch (strtolower($status)) {
			case 'actief':
				return true;
			case 'inactief':
				return false;
			default:
				return true;
				// Default to active.
		}
	}//end mapStatus()

	/**
	 * Creates an organization in OpenRegister using OrganisationService
	 *
	 * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The OpenRegister organisation service
	 * @param array $mappedData The mapped organization data
	 * @param string $organizationUuid The organization UUID to use
	 *
	 * @return \OCA\OpenRegister\Db\Organisation The created organization
	 */
	private function createOrganisationInOpenRegisterInternal(
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		array $mappedData,
		string $organizationUuid,
	): \OCA\OpenRegister\Db\Organisation {
		$this->_logger->info(
			'StackiqService: STEP 1 - Starting createOrganisationInOpenRegister',
			[
				'organizationUuid' => $organizationUuid,
				'name' => $mappedData['name'] ?? 'Unknown',
				'mappedDataKeys' => array_keys($mappedData),
			]
		);

		// Check if we're in an anonymous context (no logged-in user).
		$userSession = $this->_userSession;
		$currentUser = $userSession->getUser();

		$currentUserValue = 'null';
		if ($currentUser !== null) {
			$currentUserValue = $currentUser->getUID();
		}

		$this->_logger->info(
			'StackiqService: STEP 2 - Checking user context',
			[
				// Always true: $userSession is an injected, non-nullable IUserSession.
				'hasUserSession' => true,
				'currentUser' => $currentUserValue,
				'isAnonymous' => $currentUser === null,
			]
		);

		if ($currentUser === null) {
			$this->_logger->info(
				'StackiqService: STEP 3A - Anonymous path: No user, creating org directly via mapper',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Keep the original UUID format - no conversion needed.
			$this->_logger->info(
				'StackiqService: STEP 3B - Using original UUID format for OpenRegister (anonymous)',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Create organization directly via mapper to avoid user context requirements.
			$this->_logger->info('StackiqService: STEP 3C - Getting OrganisationMapper from container');
			$organisationMapper = $this->getOrganisationMapper();
			if ($organisationMapper === null) {
				// This method's return type is non-nullable and its caller already
				// holds an OpenRegister OrganisationService, so "unavailable" here
				// escapes exactly as the raw container exception used to.
				throw new RuntimeException('OpenRegister OrganisationMapper is not available');
			}

			$this->_logger->info(
				'StackiqService: STEP 3D - OrganisationMapper retrieved',
				[
					'mapperClass' => get_class($organisationMapper),
				]
			);

			// Create a new Organisation entity.
			$this->_logger->info('StackiqService: STEP 3E - Creating new Organisation entity');
			$organisation = new \OCA\OpenRegister\Db\Organisation();

			$this->_logger->info(
				'StackiqService: STEP 3F - Setting organisation properties',
				[
					'name' => $mappedData['name'] ?? 'Unknown Organization',
					'description' => $mappedData['website'] ?? '',
					'uuid' => $organizationUuid,
				]
			);

			// Collect all contact person usernames for this organization.
			$contactPersonUsernames = $this->collectContactPersonUsernames(
				organizationUuid: $organizationUuid,
				objectData: $mappedData
			);

			// Start with admin user and add all contact person usernames.
			$allUsernames = array_merge(['admin'], $contactPersonUsernames);
			$allUsernames = array_unique($allUsernames);

			$this->_logger->info(
				'StackiqService: STEP 3F_2 - Collected usernames for organization',
				[
					'organizationUuid' => $organizationUuid,
					'totalUsernames' => count($allUsernames),
					'usernames' => $allUsernames,
				]
			);

			$organisation->setName($mappedData['name'] ?? 'Unknown Organization');
			$organisation->setDescription($mappedData['website'] ?? '');
			// Use website as description.
			$organisation->setUuid($organizationUuid);
			$organisation->setUsers($allUsernames);
			$organisation->setOwner('admin');
			// Set admin as owner for anonymous registrations.
			$organisation->setActive($mappedData['active'] ?? true);
			// Set active status based on organization beoordeling.
			// Debug: Check if UUID was set correctly.
			$this->_logger->info(
				'StackiqService: STEP 3G - Debug - UUID before save',
				[
					'setUuid' => $organizationUuid,
					'getUuid' => $organisation->getUuid(),
					'uuidMatches' => $organisation->getUuid() === $organizationUuid,
					'organisationClass' => get_class($organisation),
				]
			);

			// Save the organization.
			$this->_logger->info('StackiqService: STEP 3H - Calling organisationMapper->save()');
			try {
				$savedOrganisation = $organisationMapper->save($organisation);
				$this->_logger->info(
					'StackiqService: STEP 3I - organisationMapper->save() completed'
				);
			} catch (\Exception $e) {
				$this->_logger->error(
					'StackiqService: STEP 3I - organisationMapper->save() failed',
					[
						'error' => $e->getMessage(),
						'errorClass' => get_class($e),
						'trace' => $e->getTraceAsString(),
					]
				);
				throw $e;
			}

			$this->_logger->info(
				'StackiqService: Successfully created organization in OpenRegister via mapper',
				[
					'organizationUuid' => $organizationUuid,
					'openRegisterId' => $savedOrganisation->getUuid(),
					'savedUuid' => $savedOrganisation->getUuid(),
					'expectedUuid' => $organizationUuid,
				]
			);

			// Verify the UUID was preserved.
			if ($savedOrganisation->getUuid() !== $organizationUuid) {
				$this->_logger->warning(
					'StackiqService: UUID mismatch after saving organization',
					[
						'expectedUuid' => $organizationUuid,
						'actualUuid' => $savedOrganisation->getUuid(),
					]
				);
			}
		}//end if

		$this->_logger->info(
			'StackiqService: STEP 4A - Auth path: User logged in, creating org via mapper',
			[
				'organizationUuid' => $organizationUuid,
				'currentUser' => $currentUser->getUID(),
			]
		);

		// Keep the original UUID format - no conversion needed.
		$this->_logger->info(
			'StackiqService: STEP 4B - Using original UUID format for OpenRegister',
			[
				'organizationUuid' => $organizationUuid,
			]
		);

		// Create organization directly via mapper to avoid service issues.
		$this->_logger->info('StackiqService: STEP 4C - Getting OrganisationMapper from container');
		$organisationMapper = $this->getOrganisationMapper();
		if ($organisationMapper === null) {
			// Non-nullable return type, same reasoning as the anonymous branch above.
			throw new RuntimeException('OpenRegister OrganisationMapper is not available');
		}

		$this->_logger->info(
			'StackiqService: STEP 4D - OrganisationMapper retrieved',
			[
				'mapperClass' => get_class($organisationMapper),
			]
		);

		// Debug: Check UUID before creating.
		// Collect all contact person usernames for this organization.
		$contactPersonUsernames = $this->collectContactPersonUsernames(
			organizationUuid: $organizationUuid,
			objectData: $mappedData
		);

		// Start with current user and add all contact person usernames.
		$allUsernames = array_merge([$currentUser->getUID()], $contactPersonUsernames);
		$allUsernames = array_unique($allUsernames);

		$this->_logger->info(
			'StackiqService: STEP 4E - Debug - UUID before createWithUuid',
			[
				'organizationUuid' => $organizationUuid,
				'uuidLength' => strlen($organizationUuid),
				'uuidIsEmpty' => empty($organizationUuid) === true,
				'name' => $mappedData['name'] ?? 'Unknown Organization',
				'description' => $mappedData['website'] ?? '',
				'owner' => $currentUser->getUID(),
				'users' => $allUsernames,
				'contactPersonUsernames' => $contactPersonUsernames,
			]
		);

		$this->_logger->info('StackiqService: STEP 4F - Calling organisationMapper->createWithUuid()');
		try {
			// Debug: Log the exact parameters being passed.
			$this->_logger->info(
				'StackiqService: STEP 4F_DEBUG - Parameters for createWithUuid',
				[
					'name' => $mappedData['name'] ?? 'Unknown Organization',
					'description' => $mappedData['website'] ?? '',
					'uuid' => $organizationUuid,
					'owner' => $currentUser->getUID(),
					'users' => $allUsernames,
					'isDefault' => false,
					'uuidLength' => strlen($organizationUuid),
					'uuidIsEmpty' => empty($organizationUuid) === true,
				]
			);

			$organisation = $organisationMapper->createWithUuid(
				$mappedData['name'] ?? 'Unknown Organization',
				$mappedData['website'] ?? '',
				// Use website as description.
				$organizationUuid,
				// Pass the original UUID.
				$currentUser->getUID(),
				// Set current user as owner.
				$allUsernames,
				// Add all users including contact persons.
				false
				// Not default.
			);
			$this->_logger->info(
				'StackiqService: STEP 4G - organisationMapper->createWithUuid() completed'
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: STEP 4G - organisationMapper->createWithUuid() failed',
				[
					'error' => $e->getMessage(),
					'errorClass' => get_class($e),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try

		// Note: OpenRegister Organisation entity doesn't have status or type fields.
		// These are managed in the Stackiq object, not in the OpenRegister organisation.
		$this->_logger->info(
			'StackiqService: Successfully created organization in OpenRegister via service',
			[
				'organizationUuid' => $organizationUuid,
				'openRegisterId' => $organisation->getUuid(),
				'savedUuid' => $organisation->getUuid(),
				'expectedUuid' => $organizationUuid,
			]
		);

		return $organisation;
	}//end createOrganisationInOpenRegisterInternal()

	/**
	 * Updates an organization in OpenRegister using OrganisationService
	 *
	 * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The OpenRegister organisation service
	 * @param \OCA\OpenRegister\Db\Organisation $existingOrganisation The existing organization
	 * @param array $mappedData The mapped organization data
	 *
	 * @return \OCA\OpenRegister\Db\Organisation The updated organization
	 */
	private function updateOrganisationInOpenRegister(
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Db\Organisation $existingOrganisation,
		array $mappedData,
	): \OCA\OpenRegister\Db\Organisation {
		$this->_logger->info(
			'StackiqService: Updating organization in OpenRegister',
			[
				'organizationUuid' => $existingOrganisation->getUuid(),
				'name' => $mappedData['name'] ?? 'Unknown',
			]
		);

		// Update organization fields (only those that exist on the Organisation entity).
		if (isset($mappedData['name']) === true) {
			$existingOrganisation->setName($mappedData['name']);
		}

		if (isset($mappedData['description']) === true) {
			$existingOrganisation->setDescription($mappedData['description']);
		}

		// Note: OpenRegister Organisation entity doesn't have status or type fields.
		// These are managed in the Stackiq object, not in the OpenRegister organisation.
		// Save the updated organization.
		$organisationMapper = $this->getOrganisationMapper();
		if ($organisationMapper === null) {
			// Non-nullable return type; the caller already holds an OpenRegister
			// OrganisationService, so this escapes as the container lookup used to.
			throw new RuntimeException('OpenRegister OrganisationMapper is not available');
		}

		$updatedOrganisation = $organisationMapper->save($existingOrganisation);

		$this->_logger->info(
			'StackiqService: Successfully updated organization in OpenRegister',
			[
				'organizationUuid' => $existingOrganisation->getUuid(),
				'openRegisterId' => $updatedOrganisation->getUuid(),
			]
		);

		return $updatedOrganisation;
	}//end updateOrganisationInOpenRegister()

	/**
	 * Collects all contact person usernames associated with an organization
	 *
	 * @param string $organizationUuid The organization UUID
	 * @param array $objectData The organization object data (for nested contact persons)
	 *
	 * @return array Array of usernames
	 */
	private function collectContactPersonUsernames(string $organizationUuid, array $objectData = []): array {
		$usernames = [];

		// Focus on nested contact persons in the organization object data.
		// These are available immediately when the organization is created.
		$nestedContactPersons = $objectData['contactpersonen'] ?? [];
		$this->_logger->info(
			'StackiqService: Processing nested contact persons',
			[
				'organizationUuid' => $organizationUuid,
				'nestedContactPersonCount' => count($nestedContactPersons),
			]
		);

		foreach ($nestedContactPersons as $contactPerson) {
			if (is_array($contactPerson) === true && isset($contactPerson['email']) === true) {
				$usernames[] = $contactPerson['email'];
				$this->_logger->info(
					'StackiqService: Added nested contact person username',
					[
						'username' => $contactPerson['email'],
						'contactPersonData' => $contactPerson,
					]
				);
			}
		}

		// Also try to find existing contact persons by their organisatie field.
		// This is useful for updates or when contact persons were created separately.
		$objectService = $this->getObjectService();
		if (empty($objectService) === false) {
			$settingsService = $this->_container->get('OCA\Stackiq\Service\SettingsService');
			$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
			$contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;

			if ($contactSchemaId === null) {
				$this->_logger->warning(
					'StackiqService: Missing contactpersoon schema config for username extraction'
				);
				return $usernames;
			}

			try {
				// Try multiple approaches to find contact persons.
				$contactPersons = [];

				// Approach 1: Find by organisatie field.
				try {
					$contactPersons = $objectService->findAll(
						[
							'filters' => [
								'register' => $objectData['register'] ?? '6',
								'schema' => $contactSchemaId,
								'organization' => $organizationUuid,
							],
						]
					);
				} catch (\Exception $e) {
					$this->_logger->info(
						'StackiqService: Approach 1 failed, trying approach 2',
						[
							'organizationUuid' => $organizationUuid,
							'error' => $e->getMessage(),
						]
					);
				}

				// Approach 2: If approach 1 fails, try to find all contact persons and filter by organisatie.
				if (empty($contactPersons) === true) {
					try {
						$allContactPersons = $objectService->findAll(
							[
								'filters' => [
									'register' => $objectData['register'] ?? '6',
									'schema' => $contactSchemaId,
								],
							]
						);

						foreach ($allContactPersons as $contactPerson) {
							$contactData = $contactPerson->getObject();
							$contactOrganisation = $contactData['organization'] ?? null;
							if ($contactOrganisation === $organizationUuid) {
								$contactPersons[] = $contactPerson;
							}
						}
					} catch (\Exception $e) {
						$this->_logger->info(
							'StackiqService: Approach 2 also failed',
							[
								'organizationUuid' => $organizationUuid,
								'error' => $e->getMessage(),
							]
						);
					}//end try
				}//end if

				$this->_logger->info(
					'StackiqService: Found existing contact persons for organization',
					[
						'organizationUuid' => $organizationUuid,
						'contactPersonCount' => count($contactPersons),
						'contactPersonIds' => array_map(
							function ($cp) {
								return $cp->getId();
							},
							$contactPersons
						),
					]
				);

				foreach ($contactPersons as $contactPerson) {
					$contactData = $contactPerson->getObject();
					$email = $contactData['email'] ?? null;
					if (empty($email) === false) {
						$usernames[] = $email;
						$this->_logger->info(
							'StackiqService: Added existing contact person username',
							[
								'username' => $email,
								'contactPersonId' => $contactPerson->getId(),
							]
						);
					}
				}
			} catch (\Exception $e) {
				$this->_logger->error(
					'StackiqService: Error collecting existing contact person usernames',
					[
						'organizationUuid' => $organizationUuid,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end if

		// Remove duplicates and return.
		$uniqueUsernames = array_unique($usernames);
		$this->_logger->info(
			'StackiqService: Collected contact person usernames',
			[
				'organizationUuid' => $organizationUuid,
				'totalUsernames' => count($uniqueUsernames),
				'usernames' => $uniqueUsernames,
			]
		);

		return $uniqueUsernames;
	}//end collectContactPersonUsernames()

	/**
	 * Maps organization data from Stackiq format to OpenRegister format
	 *
	 * @param array $objectData The organization data from Stackiq
	 *
	 * @return array The mapped data for OpenRegister
	 */
	private function mapOrganizationDataForOpenRegister(array $objectData): array {
		$mappedData = [
			'name' => $objectData['name'] ?? '',
			'type' => $objectData['type'] ?? '',
			'website' => $objectData['website'] ?? '',
			'active' => false,
			// Default to inactive for new organizations.
			'contactpersonen' => [],
			'participants' => [],
		];

		// Map status from Stackiq to OpenRegister.
		$assessment = strtolower($objectData['beoordeling'] ?? '');
		if ($assessment === 'actief') {
			$mappedData['active'] = true;
		} elseif ($assessment === 'inactief' || $assessment === 'deactief') {
			$mappedData['active'] = false;
		}

		// Map other fields if they exist.
		if (isset($objectData['adres']) === true) {
			$mappedData['adres'] = $objectData['adres'];
		}

		if (isset($objectData['postcode']) === true) {
			$mappedData['postcode'] = $objectData['postcode'];
		}

		if (isset($objectData['plaats']) === true) {
			$mappedData['plaats'] = $objectData['plaats'];
		}

		if (isset($objectData['telefoon']) === true) {
			$mappedData['telefoon'] = $objectData['telefoon'];
		}

		if (isset($objectData['email']) === true) {
			$mappedData['email'] = $objectData['email'];
		}

		return $mappedData;
	}//end mapOrganizationDataForOpenRegister()

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
	 * Activates Stackiq-specific users for an organization
	 * Only affects users from contactpersoon objects, not admin group users
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 */
	private function activateStackiqUsersForOrganization(string $organizationUuid): void {
		try {
			$this->_logger->info(
				'StackiqService: Activating Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Get Stackiq-specific users (from contactpersonen).
			$softwareCatalogUsers = $this->getStackiqUsersForOrganization(organizationUuid: $organizationUuid);

			if (empty($softwareCatalogUsers) === true) {
				$this->_logger->info(
					'StackiqService: No Stackiq users found for organization',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}

			$this->_logger->info(
				'StackiqService: Found Stackiq users to activate',
				[
					'organizationUuid' => $organizationUuid,
					'userCount' => count($softwareCatalogUsers),
					'users' => $softwareCatalogUsers,
				]
			);

			// Get the user manager.
			$userManager = $this->_userManager;
			$activatedUsers = [];
			$failedUsers = [];

			foreach ($softwareCatalogUsers as $username) {
				try {
					$user = $userManager->get($username);
					if ($user !== null && $user->isEnabled() === false) {
						$user->setEnabled(true);
						$activatedUsers[] = $username;
						$this->_logger->debug(
							'StackiqService: Activated Stackiq user',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
							]
						);
					} elseif ($user !== null && $user->isEnabled() === true) {
						$this->_logger->debug(
							'StackiqService: Stackiq user already active',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
							]
						);
					} else {
						$failedUsers[] = $username;
						$this->_logger->warning(
							'StackiqService: Stackiq user not found',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
							]
						);
					}//end if
				} catch (\Exception $e) {
					$failedUsers[] = $username;
					$this->_logger->error(
						'StackiqService: Failed to activate Stackiq user',
						[
							'organizationUuid' => $organizationUuid,
							'username' => $username,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach

			$this->_logger->info(
				'StackiqService: Stackiq user activation complete',
				[
					'organizationUuid' => $organizationUuid,
					'activatedUsers' => $activatedUsers,
					'failedUsers' => $failedUsers,
					'totalProcessed' => count($softwareCatalogUsers),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error activating Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end activateStackiqUsersForOrganization()

	/**
	 * Deactivates Stackiq-specific users for an organization
	 * Only affects users from contactpersoon objects, not admin group users
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 */
	private function deactivateStackiqUsersForOrganization(string $organizationUuid): void {
		try {
			$this->_logger->info(
				'StackiqService: Deactivating Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Get Stackiq-specific users (from contactpersonen).
			$softwareCatalogUsers = $this->getStackiqUsersForOrganization(organizationUuid: $organizationUuid);

			if (empty($softwareCatalogUsers) === true) {
				$this->_logger->info(
					'StackiqService: No Stackiq users found for organization',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}

			$this->_logger->info(
				'StackiqService: Found Stackiq users to deactivate',
				[
					'organizationUuid' => $organizationUuid,
					'userCount' => count($softwareCatalogUsers),
					'users' => $softwareCatalogUsers,
				]
			);

			// Get the user manager.
			$userManager = $this->_userManager;
			$deactivatedUsers = [];
			$failedUsers = [];

			foreach ($softwareCatalogUsers as $username) {
				try {
					$user = $userManager->get($username);
					if ($user !== null && $user->isEnabled() === true) {
						$user->setEnabled(false);
						$deactivatedUsers[] = $username;
						$this->_logger->debug(
							'StackiqService: Deactivated Stackiq user',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
							]
						);
					} elseif ($user !== null && $user->isEnabled() === false) {
						$this->_logger->debug(
							'StackiqService: Stackiq user already inactive',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
							]
						);
					} else {
						$failedUsers[] = $username;
						$this->_logger->warning(
							'StackiqService: Stackiq user not found',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
							]
						);
					}//end if
				} catch (\Exception $e) {
					$failedUsers[] = $username;
					$this->_logger->error(
						'StackiqService: Failed to deactivate Stackiq user',
						[
							'organizationUuid' => $organizationUuid,
							'username' => $username,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach

			$this->_logger->info(
				'StackiqService: Stackiq user deactivation complete',
				[
					'organizationUuid' => $organizationUuid,
					'deactivatedUsers' => $deactivatedUsers,
					'failedUsers' => $failedUsers,
					'totalProcessed' => count($softwareCatalogUsers),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error deactivating Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end deactivateStackiqUsersForOrganization()

	/**
	 * Gets Stackiq-specific users for an organization
	 * These are users from contactpersoon objects, excluding admin group users
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Array of usernames
	 */
	private function getStackiqUsersForOrganization(string $organizationUuid): array {
		try {
			$this->_logger->debug(
				'StackiqService: Getting Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Get the object service to find contactpersonen.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error(
					'StackiqService: ObjectService not available for getting Stackiq users'
				);
				return [];
			}

			// Find all contactpersonen for this organization.
			$contactpersonen = $objectService->findAll(
				[
					'filters' => [
						'register' => 6,
						// Voorzieningen register.
						'schema' => 38,
						// Contactpersoon schema.
					],
				]
			);

			$softwareCatalogUsers = [];
			$adminGroupUsers = $this->getAdminGroupUsernames();

			foreach ($contactpersonen as $contactPersonObject) {
				$contactData = $contactPersonObject->getObject();
				$contactOrganisation = $contactData['organization'] ?? null;

				// Check if this contactpersoon belongs to our organization.
				if ($contactOrganisation === $organizationUuid) {
					// Extract username from contactpersoon object data.
					$contactData = $contactPersonObject->getObject();
					$username = $contactData['username'] ?? null;

					if ($username !== false && in_array($username, $adminGroupUsers) === false) {
						$softwareCatalogUsers[] = $username;
						$this->_logger->debug(
							'StackiqService: Found Stackiq user',
							[
								'organizationUuid' => $organizationUuid,
								'username' => $username,
								'contactpersoonId' => $contactPersonObject->getId(),
							]
						);
					}
				}
			}//end foreach

			$this->_logger->info(
				'StackiqService: Found Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
					'userCount' => count($softwareCatalogUsers),
					'users' => $softwareCatalogUsers,
				]
			);

			return $softwareCatalogUsers;
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error getting Stackiq users for organization',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}//end try
	}//end getStackiqUsersForOrganization()

	/**
	 * Gets all usernames from the admin group
	 *
	 * @return array Array of admin usernames
	 */
	private function getAdminGroupUsernames(): array {
		try {
			$groupManager = $this->_groupManager;
			$adminGroup = $groupManager->get('admin');

			if ($adminGroup === null) {
				$this->_logger->warning('StackiqService: Admin group not found');
				return [];
			}

			$adminUsers = $adminGroup->getUsers();
			$adminUsernames = [];

			foreach ($adminUsers as $adminUser) {
				$adminUsernames[] = $adminUser->getUID();
			}

			$this->_logger->debug(
				'StackiqService: Found admin group users',
				[
					'adminUserCount' => count($adminUsernames),
					'adminUsers' => $adminUsernames,
				]
			);

			return $adminUsernames;
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error getting admin group usernames',
				[
					'error' => $e->getMessage(),
				]
			);
			return [];
		}//end try
	}//end getAdminGroupUsernames()

	/**
	 * Adds all users from the admin group to the organization entity
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 */
	private function addAdminGroupUsersToOrganization(string $organizationUuid): void {
		try {
			$this->_logger->info(
				'StackiqService: Adding admin group users to organization entity',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			// Get the group manager to access admin group users.
			$groupManager = $this->_groupManager;
			$adminGroup = $groupManager->get('admin');

			if ($adminGroup === null) {
				$this->_logger->warning('StackiqService: Admin group not found');
				return;
			}

			$adminUsers = $adminGroup->getUsers();
			$this->_logger->info(
				'StackiqService: Found admin group users',
				[
					'organizationUuid' => $organizationUuid,
					'adminUserCount' => count($adminUsers),
				]
			);

			// Get the organization entity (not object) to update its users list.
			$organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
			if ($organisationMapper === null) {
				$this->_logger->error('StackiqService: OrganisationMapper not available for adding admin users');
				return;
			}

			// Find the organization entity by UUID.
			$this->_logger->info(
				'StackiqService: Searching for organization entity',
				[
					'organizationUuid' => $organizationUuid,
				]
			);

			try {
				$targetOrganisation = $organisationMapper->findByUuid($organizationUuid);

				$this->_logger->info(
					'StackiqService: Found target organization entity',
					[
						'organizationUuid' => $organizationUuid,
						'entityId' => $targetOrganisation->getId(),
					]
				);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				$this->_logger->warning(
					'StackiqService: Organization entity not found for adding admin users',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}

			// Get current users list from the entity.
			$currentUsers = $targetOrganisation->getUsers() ?? [];

			$this->_logger->info(
				'StackiqService: Current organization entity users',
				[
					'organizationUuid' => $organizationUuid,
					'currentUsers' => $currentUsers,
					'currentUserCount' => count($currentUsers),
				]
			);

			// Add admin users to the list.
			$updatedUsers = $currentUsers;
			$addedUsers = [];
			foreach ($adminUsers as $adminUser) {
				$adminUsername = $adminUser->getUID();
				if (in_array($adminUsername, $updatedUsers) === false) {
					$updatedUsers[] = $adminUsername;
					$addedUsers[] = $adminUsername;
					$this->_logger->debug(
						'StackiqService: Added admin user to organization entity',
						[
							'organizationUuid' => $organizationUuid,
							'adminUsername' => $adminUsername,
						]
					);
				}
			}

			$this->_logger->info(
				'StackiqService: Admin users processing complete',
				[
					'organizationUuid' => $organizationUuid,
					'addedUsers' => $addedUsers,
					'totalUsersAfterUpdate' => count($updatedUsers),
				]
			);

			// Update the organization entity with the new users list.
			if (count($updatedUsers) > count($currentUsers)) {
				$this->_logger->info(
					'StackiqService: Updating organization entity with new users',
					[
						'organizationUuid' => $organizationUuid,
						'entityId' => $targetOrganisation->getId(),
						'usersToAdd' => count($updatedUsers) - count($currentUsers),
					]
				);

				// Set the updated users list on the entity.
				$targetOrganisation->setUsers($updatedUsers);

				$this->_logger->info(
					'StackiqService: Saving updated organization entity',
					[
						'organizationUuid' => $organizationUuid,
						'entityId' => $targetOrganisation->getId(),
						'newUserCount' => count($updatedUsers),
					]
				);

				// Save the updated organization entity.
				$savedOrganisation = $organisationMapper->save($targetOrganisation);

				$this->_logger->info(
					'StackiqService: Successfully added admin users to organization entity',
					[
						'organizationUuid' => $organizationUuid,
						'addedUsers' => count($updatedUsers) - count($currentUsers),
						'totalUsers' => count($updatedUsers),
					]
				);
			} else {
				$this->_logger->info(
					'StackiqService: All admin users already in organization entity',
					[
						'organizationUuid' => $organizationUuid,
						'totalUsers' => count($updatedUsers),
					]
				);
			}//end if
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to add admin users to organization entity: ' . $e->getMessage(),
				[
					'organizationUuid' => $organizationUuid,
					'exception' => $e,
				]
			);
		}//end try
	}//end addAdminGroupUsersToOrganization()

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
	 * Handles ownership assignment for anonymous user registrations
	 *
	 * @param object $organizationObject The organization object
	 *
	 * @return void
	 */
	private function handleOwnershipAssignment(object $organizationObject): void {
		try {
			$this->_logger->info(
				'StackiqService: Handling ownership assignment for organization',
				[
					'objectId' => $organizationObject->getId(),
				]
			);

			$objectData = $organizationObject->getObject();
			$organizationUuid = $objectData['id'] ?? $organizationObject->getId();
			$contactpersonen = $objectData['contactpersonen'] ?? [];

			if (empty($contactpersonen) === true) {
				$this->_logger->info(
					'StackiqService: No contact persons found for ownership assignment',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}

			// Get the first contact person as the primary owner.
			$primaryContactUuid = $contactpersonen[0];

			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error(
					'StackiqService: OpenRegister ObjectService not available for ownership assignment'
				);
				return;
			}

			// Get the primary contact person object.
			$settingsService = $this->_container->get(SettingsService::class);
			$registerId = $settingsService->getVoorzieningenRegisterId();
			$contactPersonSchemaId = $settingsService->getSchemaIdForObjectType('contactPerson');
			$organisationSchemaId = $settingsService->getSchemaIdForObjectType('organization');

			if ($registerId === null || $contactPersonSchemaId === null || $organisationSchemaId === false) {
				$this->_logger->error(
					'StackiqService: Register or schema not configured for contactpersoon/organisatie'
				);
				return;
			}

			// Retry mechanism for user creation timing.
			$maxRetries = 3;
			$retryDelay = 1;
			// Seconds.
			for ($retry = 0; $retry < $maxRetries; $retry++) {
				try {
					$primaryContactObject = $objectService->find(
						$primaryContactUuid,
						[],
						false,
						$registerId,
						$contactPersonSchemaId
					);
					$primaryContactData = $primaryContactObject->getObject();
					$primaryUsername = $primaryContactData['username'] ?? '';

					if (empty($primaryUsername) === true) {
						if ($retry < $maxRetries - 1) {
							$this->_logger->info(
								'StackiqService: Primary contact no username, retry in ' . $retryDelay . 's',
								[
									'contactUuid' => $primaryContactUuid,
									'organizationUuid' => $organizationUuid,
									'retry' => $retry + 1,
									'maxRetries' => $maxRetries,
								]
							);
							sleep($retryDelay);
						}

						$this->_logger->warning(
							'StackiqService: Primary contact person still has no username after retries',
							[
								'contactUuid' => $primaryContactUuid,
								'organizationUuid' => $organizationUuid,
							]
						);
						return;
					}//end if

					// Get the organization entity UUID - use the same UUID as the organization object.
					$organisationEntityUuid = $organizationUuid;
					// Organization entity should have same UUID as object.
					// Add users to the organization entity.
					$organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
					try {
						$organisationEntity = $organisationMapper->findByUuid($organisationEntityUuid);

						// Add all contact person users to the organization entity.
						foreach ($contactpersonen as $contactUuid) {
							try {
								$contactObject = $objectService->find(
									$contactUuid,
									[],
									false,
									$registerId,
									$contactPersonSchemaId
								);
								$contactData = $contactObject->getObject();
								$contactUsername = $contactData['username'] ?? '';

								if (empty($contactUsername) === false) {
									$organisationEntity->addUser($contactUsername);
								}
							} catch (\Exception $e) {
								$this->_logger->warning(
									'StackiqService: Failed to add contact person to organization entity',
									[
										'contactUuid' => $contactUuid,
										'error' => $e->getMessage(),
									]
								);
							}//end try
						}//end foreach

						// Save the updated organization entity.
						$organisationMapper->save($organisationEntity);

						$this->_logger->info(
							'StackiqService: Successfully added users to organization entity',
							[
								'organizationUuid' => $organisationEntityUuid,
								'userCount' => count($organisationEntity->getUserIds()),
							]
						);
					} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
						$this->_logger->error(
							'StackiqService: Organization entity not found for adding users',
							[
								'organizationUuid' => $organisationEntityUuid,
							]
						);
					}//end try

					// Update organization object ownership and organization reference.
					$organizationData['owner'] = $primaryUsername;
					$organizationData['organisation'] = $organisationEntityUuid;

					$updatedOrganization = $objectService->saveObject(
						$organizationData,
						[],
						$registerId,
						$organisationSchemaId,
						$organizationUuid
					);

					// Update primary contact person object ownership and organization reference.
					$primaryContactData['owner'] = $primaryUsername;
					$primaryContactData['organization'] = $organisationEntityUuid;

					$updatedPrimaryContact = $objectService->saveObject(
						$primaryContactData,
						[],
						$registerId,
						$contactPersonSchemaId,
						$primaryContactUuid
					);

					// Update other contact persons with organization reference.
					for ($i = 1; $i < count($contactpersonen); $i++) {
						$contactUuid = $contactpersonen[$i];
						try {
							$contactObject = $objectService->find(
								$contactUuid,
								[],
								false,
								$registerId,
								$contactPersonSchemaId
							);
							$contactData = $contactObject->getObject();
							$contactUsername = $contactData['username'] ?? '';

							if (empty($contactUsername) === false) {
								$contactData['owner'] = $contactUsername;
								$contactData['organization'] = $organisationEntityUuid;

								$objectService->saveObject(
									$contactData,
									[],
									$registerId,
									$contactPersonSchemaId,
									$contactUuid
								);
							}
						} catch (\Exception $e) {
							$this->_logger->warning(
								'StackiqService: Failed to update contact person ownership',
								[
									'contactUuid' => $contactUuid,
									'error' => $e->getMessage(),
								]
							);
						}//end try
					}//end for

					$this->_logger->info(
						'StackiqService: Successfully assigned ownership for organization',
						[
							'organizationUuid' => $organizationUuid,
							'primaryOwner' => $primaryUsername,
							'organisationEntityUuid' => $organisationEntityUuid,
							'contactPersonCount' => count($contactpersonen),
							'retries' => $retry,
						]
					);

					return;
					// Success, exit retry loop.
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					if ($retry < $maxRetries - 1) {
						$this->_logger->info(
							'StackiqService: Primary contact not found, retrying in ' . $retryDelay . ' seconds',
							[
								'contactUuid' => $primaryContactUuid,
								'organizationUuid' => $organizationUuid,
								'retry' => $retry + 1,
								'maxRetries' => $maxRetries,
							]
						);
						sleep($retryDelay);
					}

					$this->_logger->error(
						'StackiqService: Primary contact person not found after retries',
						[
							'contactUuid' => $primaryContactUuid,
							'organizationUuid' => $organizationUuid,
						]
					);
					return;
				}//end try
			}//end for
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error handling ownership assignment',
				[
					'objectId' => $organizationObject->getId(),
					'error' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
		}//end try
	}//end handleOwnershipAssignment()

	/**
	 * Synchronizes contact person usernames with the organization entity's users array
	 * This method finds all contact persons associated with a given organization UUID
	 * and ensures their emails are present in the organization entity's users array
	 *
	 * @param string $organizationUuid The UUID of the organization
	 *
	 * @return void
	 * @spec   openspec/specs/softwarecatalogue-orchestration/spec.md
	 */
	public function syncContactPersonUsernamesWithOrganization(string $organizationUuid): void {
		$this->_logger->info(
			'StackiqService: Starting contact person username synchronization',
			[
				'organizationUuid' => $organizationUuid,
			]
		);

		// Get the ObjectService to find contact persons.
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->_logger->error('StackiqService: ObjectService not available for username synchronization');
			return;
		}

		// Get the contact person schema ID from configuration.
		$settingsService = $this->_container->get('OCA\Stackiq\Service\SettingsService');
		$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
		$contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
		$registerId = $voorzieningenConfig['register'] ?? null;

		if ($contactSchemaId === null || $registerId === false) {
			$this->_logger->warning(
				'StackiqService: Missing Voorzieningen configuration for contact person sync',
				[
					'organizationUuid' => $organizationUuid,
					'contactSchemaId' => $contactSchemaId,
					'registerId' => $registerId,
				]
			);
			return;
		}

		try {
			// Find all contact persons that have this organization as their organisatie.
			$contactPersons = $objectService->findAll(
				[
					'filters' => [
						'register' => (string)$registerId,
						'schema' => $contactSchemaId,
						'organization' => $organizationUuid,
					],
				]
			);

			$this->_logger->info(
				'StackiqService: Found contact persons for synchronization',
				[
					'organizationUuid' => $organizationUuid,
					'contactPersonCount' => count($contactPersons),
				]
			);

			// Collect all usernames from contact persons.
			$contactPersonUsernames = [];
			foreach ($contactPersons as $contactPerson) {
				$contactData = $contactPerson->getObject();
				$email = $contactData['email'] ?? null;
				if (empty($email) === false) {
					$contactPersonUsernames[] = $email;
					$this->_logger->info(
						'StackiqService: Found contact person username',
						[
							'username' => $email,
							'contactPersonId' => $contactPerson->getId(),
						]
					);
				}
			}

			// Get the organization entity.
			$organisationMapper = $this->getOrganisationMapper();
			if ($organisationMapper === null) {
				$this->_logger->error(
					'StackiqService: OpenRegister OrganisationMapper not available for synchronization',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}

			$organisation = $organisationMapper->findByUuid($organizationUuid);

			if ($organisation === null) {
				$this->_logger->error(
					'StackiqService: Organization entity not found for synchronization',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}

			// Get current users and add contact person usernames.
			$currentUsers = $organisation->getUsers() ?? [];
			$allUsers = array_merge($currentUsers, $contactPersonUsernames);
			$allUsers = array_unique($allUsers);

			$this->_logger->info(
				'StackiqService: Updating organization entity users',
				[
					'organizationUuid' => $organizationUuid,
					'currentUsers' => $currentUsers,
					'contactPersonUsernames' => $contactPersonUsernames,
					'finalUsers' => $allUsers,
				]
			);

			// Update the organization entity.
			$organisation->setUsers($allUsers);
			$organisationMapper->save($organisation);

			$this->_logger->info(
				'StackiqService: Successfully synchronized contact person usernames',
				[
					'organizationUuid' => $organizationUuid,
					'totalUsers' => count($allUsers),
				]
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Organization entity doesn't exist yet - this can happen due to race conditions.
			// Log and return gracefully, the organization sync will handle this later.
			$this->_logger->warning(
				'StackiqService: Organization entity not found during username sync (race condition)',
				[
					'organizationUuid' => $organizationUuid,
					'message' => 'Expected during anonymous registration - org entity created after contacts',
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Error synchronizing contact person usernames',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end syncContactPersonUsernamesWithOrganization()

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
	 * Updates organization references on objects to point to the newly created organization entity
	 *
	 * @param object $organizationObject The organization object
	 *
	 * @return void
	 */
	private function updateOrganizationReferences(object $organizationObject): void {
		try {
			$this->_logger->info(
				'StackiqService: Updating organization references',
				[
					'objectId' => $organizationObject->getId(),
				]
			);

			$objectData = $organizationObject->getObject();
			$organizationUuid = $objectData['id'] ?? $organizationObject->getId();

			// Get the ObjectService to update objects.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->_logger->error('StackiqService: ObjectService not available for updating references');
				return;
			}

			// Get the organization entity UUID (should be the same as the organization object UUID).
			$organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
			try {
				// Use the original UUID format for OpenRegister lookup.
				$organisationEntity = $organisationMapper->findByUuid($organizationUuid);
				$organisationEntityUuid = $organisationEntity->getUuid();

				$this->_logger->info(
					'StackiqService: Found organization entity for reference update',
					[
						'organizationObjectUuid' => $organizationUuid,
						'organizationEntityUuid' => $organisationEntityUuid,
					]
				);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				$this->_logger->error(
					'StackiqService: Organization entity not found for reference update',
					[
						'organizationUuid' => $organizationUuid,
					]
				);
				return;
			}//end try

			// Update the organization object's @self.organisation field.
			$this->_logger->info(
				'StackiqService: Updating organization object reference',
				[
					'objectId' => $organizationObject->getId(),
					'newOrganisationUuid' => $organisationEntityUuid,
				]
			);

			// Get the current object data and update the organisation field.
			$currentObjectData = $organizationObject->getObject();
			$currentObjectData['@self']['organisation'] = $organisationEntityUuid;

			// Update the organization object using the ObjectService.
			// Don't update version, not a patch, no extend.
			$objectService->saveObject(
				object: $currentObjectData,
				register: $organizationObject->getRegisterId(),
				schema: $organizationObject->getSchemaId(),
				uuid: $organizationObject->getUuid()
			);

			// Update contact person objects' @self.organization field.
			$contactpersonen = $objectData['contactpersonen'] ?? [];
			foreach ($contactpersonen as $contactUuid) {
				$this->_logger->info(
					'StackiqService: Updating contact person object reference',
					[
						'contactUuid' => $contactUuid,
						'newOrganisationUuid' => $organisationEntityUuid,
					]
				);

				// Get the contact person schema ID from configuration.
				$settingsService = $this->_container->get('OCA\Stackiq\Service\SettingsService');
				$voorzieningenConfig = $settingsService->getVoorzieningenConfig();
				$contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;

				if ($contactSchemaId === null) {
					$this->_logger->warning(
						'StackiqService: Missing contactpersoon schema configuration for object update',
						[
							'contactUuid' => $contactUuid,
						]
					);
					continue;
				}

				// Find the contact person object.
				try {
					$regId = $organizationObject->getRegisterId();
					$contactObject = $objectService->find(
						$contactUuid,
						[],
						false,
						$regId,
						$contactSchemaId
					);
					if (empty($contactObject) === false) {
						// Get the current object data and update the organisatie field.
						$contactObjectData = $contactObject->getObject();
						$contactObjectData['@self']['organization'] = $organisationEntityUuid;

						// Update the contact person object using the ObjectService.
						// Don't update version, not a patch, no extend.
						$objectService->saveObject(
							object: $contactObjectData,
							register: $organizationObject->getRegisterId(),
							schema: $contactSchemaId,
							uuid: $contactObject->getUuid()
						);
					}
				} catch (\Exception $e) {
					$this->_logger->error(
						'StackiqService: Failed to update contact person object',
						[
							'contactUuid' => $contactUuid,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach

			$this->_logger->info(
				'StackiqService: Successfully updated organization references',
				[
					'organizationUuid' => $organizationUuid,
					'organizationEntityUuid' => $organisationEntityUuid,
					'contactPersonCount' => count($contactpersonen),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'StackiqService: Failed to update organization references: ' . $e->getMessage(),
				[
					'objectId' => $organizationObject->getId(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end updateOrganizationReferences()

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
