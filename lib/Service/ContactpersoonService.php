<?php
/**
 * Contactpersoon Service
 *
 * This file contains the service class for handling contact person-specific operations
 * in the SoftwareCatalog application.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Service for handling contact person-specific operations
 *
 * This service provides functionality for contact person processing,
 * user account creation, and group management.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/SoftwareCatalog
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
class ContactpersoonService
{
    /**
     * ContactpersoonService constructor.
     *
     * @param ContactPersonHandler $contactPersonHandler Contact person handler.
     * @param GroupHandler         $groupHandler         Group handler.
     * @param HierarchyHandler     $hierarchyHandler     Hierarchy handler.
     * @param LoggerInterface      $logger               Logger interface.
     * @param ContainerInterface   $container            Container interface.
     * @param IAppManager          $appManager           App manager.
     * @param IAppConfig           $config               Configuration service.
     * @param SettingsService      $settingsService      Settings service.
     */
    public function __construct(
        private readonly ContactPersonHandler $contactPersonHandler,
        private readonly GroupHandler $groupHandler,
        private readonly HierarchyHandler $hierarchyHandler,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IAppConfig $config,
        private readonly SettingsService $settingsService
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
     * @param object $contactpersoonObject The contactpersoon object to process.
     * @param bool   $isUpdate             Whether this is an update operation.
     *
     * @return bool True if processing was successful.
     *
     * @throws \Exception If processing fails.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isUpdate is a simple create-vs-update toggle
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-7
     */
    public function processContactpersoon(object $contactpersoonObject, bool $isUpdate=false): bool
    {
        $startTime = microtime(true);
        $contactId = $contactpersoonObject->getId();

        try {
            $contactData = $contactpersoonObject->getObject();

            // Recursion guard: saveObject triggers ObjectUpdatedEvent which re-enters here.
            if (isset(self::$processingContacts[$contactId]) === true) {
                return true;
            }

            self::$processingContacts[$contactId] = true;

            $emailValue      = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
            $hasEmail        = empty($emailValue) === false;
            $hasOrganisation = empty($contactData['organisation']) === false;
            $this->logger->info(
                'ContactpersoonService: Starting contactpersoon processing',
                [
                    'contactId'       => $contactId,
                    'isUpdate'        => $isUpdate,
                    'hasEmail'        => $hasEmail,
                    'hasOrganisation' => $hasOrganisation,
                ]
            );

            // Check if contactpersoon has required data.
            // Schema uses 'e-mailadres' but some data may use 'email'.
            $email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
            if (empty($email) === true) {
                $this->logger->warning(
                    'ContactpersoonService: Contactpersoon has no email, skipping processing',
                    ['contactId' => $contactId]
                );
                return false;
            }

            // Validate email format before attempting user creation.
            // Imported contacts may have invalid emails that would cause Nextcloud user creation to fail.
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->logger->warning(
                    'ContactpersoonService: Contactpersoon has invalid email, skipping user creation',
                    [
                        'contactId' => $contactId,
                        'email'     => $email,
                    ]
                );
                return false;
            }

            // Use email as username.
            $username = $email;

            // Check if user already exists.
            $userManager = \OC::$server->get('OCP\IUserManager');
            $user        = $userManager->get($username);

            if ($user === null) {
                // Check if organization is active before creating user account.
                $organizationUuid = ($contactData['organisation'] ?? $contactData['organisatie'] ?? '');

                if (empty($organizationUuid) === false) {
                    // Look up organization entity, creating backup if missing.
                    $organisationEntity = null;
                    try {
                        $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
                        $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
                    } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                        // Org entity missing — try backup creation from org object.
                        $this->logger->info(
                            'ContactpersoonService: Organisation entity missing, attempting backup creation',
                            [
                                'contactId'        => $contactId,
                                'organizationUuid' => $organizationUuid,
                            ]
                        );
                        try {
                            $objectService       = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
                            $settingsService     = \OC::$server->get('OCA\SoftwareCatalog\Service\SettingsService');
                            $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
                            $orgObject           = $objectService->find(
                                id: $organizationUuid,
                                register: ($voorzieningenConfig['register'] ?? ''),
                                schema: ($voorzieningenConfig['organisatie_schema'] ?? ''),
                                _rbac: false,
                                _multitenancy: false
                            );
                            if ($orgObject !== null) {
                                $orgData   = $orgObject->getObject();
                                $orgStatus = strtolower(($orgData['status'] ?? ''));
                                if (in_array(needle: $orgStatus, haystack: ['actief', 'active']) === true) {
                                    $syncServiceClass        = 'OCA\SoftwareCatalog\Service\OrganizationSyncService';
                                    $organizationSyncService = \OC::$server->get($syncServiceClass);
                                    $backupStats        = [
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
                                            'contactId'        => $contactId,
                                            'organizationUuid' => $organizationUuid,
                                            'entityCreated'    => $organisationEntity !== null,
                                        ]
                                    );
                                }
                            }//end if
                        } catch (\Exception $backupEx) {
                            $this->logger->error(
                                'ContactpersoonService: Backup entity creation failed',
                                [
                                    'contactId'        => $contactId,
                                    'organizationUuid' => $organizationUuid,
                                    'error'            => $backupEx->getMessage(),
                                ]
                            );
                        }//end try
                    }//end try

                    try {
                        if ($organisationEntity !== null && $organisationEntity->getActive() === true) {
                            // Determine if this is the first contact for the organization.
                            $isFirstContact = $this->contactPersonHandler->isFirstContactForOrganization(
                                contactObject: $contactpersoonObject,
                                objectData: $contactData
                            );

                            // Create user account - organization is active.
                            $this->logger->info(
                                'ContactpersoonService: Creating user account for contactpersoon (org is active)',
                                [
                                    'contactId'        => $contactId,
                                    'username'         => $username,
                                    'organizationUuid' => $organizationUuid,
                                    'isFirstContact'   => $isFirstContact,
                                ]
                            );

                            $success = $this->contactPersonHandler->createUserAccount(
                                contactpersoonObject: $contactpersoonObject,
                                isFirstContact: $isFirstContact
                            );
                            if ($success === false) {
                                throw new \Exception('Failed to create user account');
                            }

                            // Link user to organization entity.
                            $this->contactPersonHandler->addUserToOrganizationEntity(
                                contactpersoonObject: $contactpersoonObject,
                                username: $username,
                                organizationUuidOverride: $organizationUuid
                            );

                            // Update contactpersoon object owner to user UID.
                                                        $this->updateContactpersoonObjectOwner(
                                contactObject: $contactpersoonObject,
                                userUID: $username
                            );

                            $this->logger->info(
                                'ContactpersoonService: Successfully created user account',
                                [
                                    'contactId' => $contactId,
                                    'username'  => $username,
                                ]
                            );
                        }//end if

                        if ($organisationEntity === null || $organisationEntity->getActive() !== true) {
                                $orgActive = false;
                            if ($organisationEntity !== null) {
                            }

                            $this->logger->info(
                                // phpcs:ignore Generic.Files.LineLength.TooLong
                                'ContactpersoonService: Skipping user creation - organization not active or entity not found',
                                [
                                    'contactId'          => $contactId,
                                    'organizationUuid'   => $organizationUuid,
                                    'organizationFound'  => $organisationEntity !== null,
                                    'organizationActive' => $orgActive,
                                ]
                            );
                            return false;
                        }
                    } catch (\Exception $e) {
                        $this->logger->error(
                            'ContactpersoonService: User creation failed',
                            [
                                'contactId'        => $contactId,
                                'organizationUuid' => $organizationUuid,
                                'error'            => $e->getMessage(),
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
                        'username'  => $username,
                    ]
                );
            }//end if

            // Update user groups based on contactpersoon data.
                        $this->updateUserGroups(
                contactpersoonObject: $contactpersoonObject,
                username: $username
            );

            // Ensure organization has at least one beheerder.
                        $this->ensureOrganizationBeheerder(
                contactpersoonObject: $contactpersoonObject,
                username: $username
            );

            // Update the contactpersoon object with username if not set.
            if (empty($contactData['username']) === true) {
                                $this->updateContactpersoonUsername(
                    contactpersoonObject: $contactpersoonObject,
                    username: $username
                );
            }

            $processingTime = round(((microtime(true) - $startTime) * 1000), 2);
            $this->logger->info(
                'ContactpersoonService: Successfully processed contactpersoon',
                [
                    'contactId'      => $contactId,
                    'username'       => $username,
                    'processingTime' => $processingTime.'ms',
                ]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to process contactpersoon object',
                [
                    'exception'      => $e->getMessage(),
                    'file'           => $e->getFile(),
                    'line'           => $e->getLine(),
                    'trace'          => $e->getTraceAsString(),
                    'objectId'       => ($contactpersoonObject->getId() ?? 'unknown'),
                    'processingTime' => round(((microtime(true) - $startTime) * 1000), 2).'ms',
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
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username             The username to update groups for
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-1
     */
    public function updateUserGroups(object $contactpersoonObject, string $username): void
    {
        // Use the new organization type-based logic instead of old role-based logic.
        $userManager = \OC::$server->get('OCP\IUserManager');
        $user        = $userManager->get($username);
        if ($user === null) {
            $this->logger->warning('User not found for group update', ['username' => $username]);
            return;
        }

        $contactData = $contactpersoonObject->getObject();
        $this->contactPersonHandler->updateUserGroupsFromContactData(
            user: $user,
            contactData: $contactData
        );

    }//end updateUserGroups()

    /**
     * Ensures organization has at least one beheerder and manages user hierarchy
     *
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username             The username being processed
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-1
     */
    public function ensureOrganizationBeheerder(object $contactpersoonObject, string $username): void
    {
        $this->hierarchyHandler->ensureOrganizationBeheerder(
            contactgegevensObject: $contactpersoonObject,
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
    public function getUserManager(string $username): ?string
    {
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
    private function normalizeContactDataTypes(array $data): array
    {
        // Fields that should always be strings according to the contactpersoon schema.
        $stringFields = [
            'voornaam',
            'tussenvoegsel',
            'achternaam',
            'functie',
            'telefoonnummer',
            'username',
        ];

        foreach ($stringFields as $field) {
            if (isset($data[$field]) === true && (is_int($data[$field]) === true || is_float($data[$field]) === true)) {
                $data[$field] = (string) $data[$field];
            }
        }

        return $data;

    }//end normalizeContactDataTypes()

    /**
     * Updates contactpersoon object with username.
     *
     * @param object $contactpersoonObject The contactpersoon object.
     * @param string $username             The username to set.
     *
     * @return void
     */
    private function updateContactpersoonUsername(object $contactpersoonObject, string $username): void
    {
        try {
            $contactData = $contactpersoonObject->getObject();
            $contactData['username'] = $username;
            $contactpersoonObject->setObject($contactData);

            // FIX #434: Use MagicMapper directly instead of ObjectService::saveObject().
            // To avoid validation errors on the organisatie field (stored as UUID string but.
            // Schema expects object type) and to avoid triggering ObjectUpdatedEvent cascades.
            // That could interfere with the ongoing org activation process.
            $objectMapper = \OC::$server->get('OCA\OpenRegister\Db\MagicMapper');
            $objectMapper->update($contactpersoonObject);

            $this->logger->info(
                'ContactpersoonService: Updated contactpersoon with username',
                [
                    'contactId' => $contactpersoonObject->getId(),
                    'username'  => $username,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to update contactpersoon username',
                [
                    'contactId' => $contactpersoonObject->getId(),
                    'username'  => $username,
                    'error'     => $e->getMessage(),
                ]
            );
        }//end try

    }//end updateContactpersoonUsername()

    /**
     * Handles contactpersoon updates, particularly role changes
     *
     * @param object      $contactpersoonObject    The updated contactpersoon object
     * @param object|null $oldContactpersoonObject The previous contactpersoon object
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-7
     */
    public function handleContactpersoonUpdate(object $contactpersoonObject, object $oldContactpersoonObject=null): void
    {
        try {
            $contactData = $contactpersoonObject->getObject();
            $contactId   = $contactpersoonObject->getId();

            $this->logger->info(
                'ContactpersoonService: Handling contactpersoon update',
                [
                    'contactId'    => $contactId,
                    'hasOldObject' => $oldContactpersoonObject !== null,
                ]
            );

            // Process the contactpersoon (this will handle user creation/updates).
                        $this->processContactpersoon(
                contactpersoonObject: $contactpersoonObject,
                isUpdate: true
            );

            // If we have old object, check for role changes.
            if ($oldContactpersoonObject !== null) {
                                $this->handleRoleChanges(
                    newContactpersoonObject: $contactpersoonObject,
                    oldContactpersoonObject: $oldContactpersoonObject
                );
            }

            // Sync name/functie fields back to the Nextcloud user when changed.
                        $this->syncNameFieldsToUser(
                contactpersoonObject: $contactpersoonObject,
                oldContactpersoonObject: $oldContactpersoonObject
            );

            $this->logger->info(
                'ContactpersoonService: Successfully handled contactpersoon update',
                ['contactId' => $contactId]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to handle contactpersoon update',
                [
                    'contactId' => $contactpersoonObject->getId(),
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );
        }//end try

    }//end handleContactpersoonUpdate()

    /**
     * Syncs name/functie fields from contactpersoon to the corresponding Nextcloud user.
     *
     * @param object      $contactpersoonObject    The updated contactpersoon object.
     * @param object|null $oldContactpersoonObject The previous contactpersoon object.
     *
     * @return void
     */
    private function syncNameFieldsToUser(object $contactpersoonObject, ?object $oldContactpersoonObject): void
    {
        $newData     = $contactpersoonObject->getObject();
            $oldData = [];
        if ($oldContactpersoonObject !== null) {
        }

        // Check if any name/functie fields have changed.
        $nameFields     = [
            'voornaam',
            'tussenvoegsel',
            'achternaam',
            'functie',
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

        $userManager = \OC::$server->get('OCP\IUserManager');
        $user        = $userManager->get($username);

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
                'username'    => $username,
                'contactId'   => $contactpersoonObject->getId(),
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
     * @param object $newContactpersoonObject The new contactpersoon object
     * @param object $oldContactpersoonObject The old contactpersoon object
     *
     * @return void
     */
    private function handleRoleChanges(object $newContactpersoonObject, object $oldContactpersoonObject): void
    {
        $newData = $newContactpersoonObject->getObject();
        $oldData = $oldContactpersoonObject->getObject();

        $newRoles = ($newData['roles'] ?? []);
        $oldRoles = ($oldData['roles'] ?? []);

        // Check if roles have changed.
        if ($newRoles !== $oldRoles) {
            $username = ($newData['email'] ?? $newData['e-mailadres'] ?? $newData['username'] ?? '');
            if (empty($username) === false) {
                $this->logger->info(
                    'ContactpersoonService: Roles changed, updating user groups',
                    [
                        'contactId' => $newContactpersoonObject->getId(),
                        'username'  => $username,
                        'oldRoles'  => $oldRoles,
                        'newRoles'  => $newRoles,
                    ]
                );

                // Update user groups based on new roles.
                                $this->updateUserGroups(
                    contactpersoonObject: $newContactpersoonObject,
                    username: $username
                );
            }
        }

    }//end handleRoleChanges()

    /**
     * Gets the ObjectService instance
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if ($this->appManager->isEnabledForUser('openregister') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to get ObjectService: '.$e->getMessage());
            return null;
        }

    }//end getObjectService()

    /**
     * Handles contact person deletion
     *
     * @param object $contactObject The contact object being deleted
     *
     * @return void
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-1
     */
    public function handleContactDeletion(object $contactObject): void
    {
        try {
            $contactData = $contactObject->getObject();
            $username    = ($contactData['email'] ?? $contactData['e-mailadres'] ?? $contactData['username'] ?? '');

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
                    'username'  => $username,
                ]
            );

            // Get user manager to disable the user.
            $userManager = \OC::$server->get('OCP\IUserManager');
            $user        = $userManager->get($username);

            if ($user === null) {
                $this->logger->warning(
                    'ContactpersoonService: User not found for deleted contact',
                    [
                        'contactId' => $contactObject->getId(),
                        'username'  => $username,
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
                        'username'  => $username,
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to handle contact deletion',
                [
                    'contactId' => $contactObject->getId(),
                    'error'     => $e->getMessage(),
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
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-2
     */
    public function getContactPersonsForOrganization(string $organizationUuid): array
    {
        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return [];
            }

            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? null);
            $register            = ($voorzieningenConfig['register'] ?? null);

            // Skip if no proper configuration is available.
            if ($contactSchema === null || $register === null) {
                $this->logger->warning(
                'ContactpersoonService: Missing Voorzieningen configuration',
                [
                    'contactSchema' => $contactSchema,
                    'register'      => $register,
                ]
                );
                return [];
            }

            // Build query for searchObjects method.
            $query = [
                '@self'        => [
                    'register' => (int) $register,
                    'schema'   => (int) $contactSchema,
                ],
                'organisation' => $organizationUuid,
            ];

            return $objectService->searchObjects($query);
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to get contact persons for organization',
                [
                    'organizationUuid' => $organizationUuid,
                    'error'            => $e->getMessage(),
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
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-2
     */
    public function getContactPersonsWithUserDetailsForOrganization(string $organizationUuid): array
    {
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
                    'organizationUuid'   => $organizationUuid,
                    'contactPersonCount' => count($contactPersons),
                ]
            );

            // Get user manager to fetch user details.
            $userManager            = \OC::$server->get('OCP\IUserManager');
            $enhancedContactPersons = [];

            // Loop through each contact person and fetch user details.
            foreach ($contactPersons as $contactPerson) {
                try {
                    $contactData = $contactPerson->getObject();
                    $username    = ($contactData['username'] ?? null);

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
                                'uid'         => $user->getUID(),
                                'email'       => $user->getEMailAddress(),
                                'displayName' => $user->getDisplayName(),
                                'enabled'     => $user->isEnabled(),
                                'lastLogin'   => $user->getLastLogin(),
                                'backend'     => $user->getBackendClassName(),
                                'home'        => $user->getHome(),
                                'avatarImage' => $user->getAvatarImage(64)->data(),
                                'quota'       => $user->getQuota(),
                                'freeQuota'   => $user->getFreeQuota(),
                            ];

                            $this->logger->debug(
                                'ContactpersoonService: Fetched user details',
                                [
                                    'contactPersonId' => $contactPerson->getId(),
                                    'username'        => $username,
                                    'userEnabled'     => $user->isEnabled(),
                                ]
                            );
                        }//end if

                        if ($user === null) {
                            $this->logger->warning(
                                'ContactpersoonService: User not found for username',
                                [
                                    'contactPersonId' => $contactPerson->getId(),
                                    'username'        => $username,
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
                            'contactPersonId'  => $contactPerson->getId(),
                            'organizationUuid' => $organizationUuid,
                            'error'            => $e->getMessage(),
                        ]
                    );

                    // Still add the contact person without user details.
                    $enhancedContactPersons[] = $contactPerson;
                }//end try
            }//end foreach

            $this->logger->info(
                'ContactpersoonService: Successfully enhanced contact persons with user details',
                [
                    'organizationUuid'              => $organizationUuid,
                    'totalContactPersons'           => count($enhancedContactPersons),
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
                    'error'            => $e->getMessage(),
                    'trace'            => $e->getTraceAsString(),
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
     * @param array $contactpersoonIds Array of contact person IDs/UUIDs
     *
     * @return array Array of user information keyed by contact person ID
     *
     * @throws \Exception If bulk user info retrieval fails
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-2
     */
    public function getBulkUserInfo(array $contactpersoonIds): array
    {
        try {
            $this->logger->info(
                'ContactpersoonService: Getting bulk user info',
                [
                    'contactpersoonCount' => count($contactpersoonIds),
                ]
            );

            $bulkUserInfo = [];
            $userManager  = \OC::$server->get('OCP\IUserManager');

            // Get contact person register and schema from settings.
            $contactRegister = null;
            $contactSchema   = null;
            try {
                $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
                $contactRegister     = (int) ($voorzieningenConfig['register'] ?? 2);
                $contactSchema       = (int) ($voorzieningenConfig['contactpersoon_schema'] ?? 25);
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Could not get contact person schema config, using defaults',
                    [
                        'error' => $e->getMessage(),
                    ]
                );
                $contactRegister = 2;
                $contactSchema   = 25;
            }

            foreach ($contactpersoonIds as $contactpersoonId) {
                try {
                    // Get contactpersoon from OpenRegister.
                    $objectService = $this->getObjectService();
                    if ($objectService === null) {
                        $this->logger->warning(
                            'ContactpersoonService: ObjectService not available for bulk user info',
                            ['contactpersoonId' => $contactpersoonId]
                        );
                        continue;
                    }

                    // Find the contactpersoon object with register and schema specified.
                    $contactObject = $objectService->findSilent(
                        id: $contactpersoonId,
                        _extend: [],
                        files: false,
                        register: $contactRegister,
                        schema: $contactSchema,
                        _rbac: false,
                        _multitenancy: false
                    );

                    if ($contactObject === null) {
                        $this->logger->warning(
                            'ContactpersoonService: Contactpersoon not found for bulk user info',
                            ['contactpersoonId' => $contactpersoonId]
                        );
                        $bulkUserInfo[$contactpersoonId] = [
                            'hasUser'  => false,
                            'username' => null,
                            'groups'   => [],
                        ];
                        continue;
                    }

                    $contactData = $contactObject->getObject();
                    $username    = $contactData['username'] ?? null;

                    $userInfo = [
                        'hasUser'  => empty($username) === false,
                        'username' => $username,
                        'groups'   => [],
                    ];

                    // If user exists, get their current groups.
                    if (empty($username) === false) {
                        $user = $userManager->get($username);
                        if ($user === null) {
                            $this->logger->warning(
                                'ContactpersoonService: User not found for bulk user info',
                                [
                                    'contactpersoonId' => $contactpersoonId,
                                    'username'         => $username,
                                ]
                            );
                        }

                        if ($user !== null) {
                            $groupManager        = \OC::$server->get('OCP\IGroupManager');
                            $userGroups          = $groupManager->getUserGroups($user);
                            $userInfo['groups']  = array_keys($userGroups);
                            $userInfo['enabled'] = $user->isEnabled();
                            $userInfo['displayName'] = $user->getDisplayName();
                            $userInfo['lastLogin']   = $user->getLastLogin();
                        }
                    }//end if

                    $bulkUserInfo[$contactpersoonId] = $userInfo;
                } catch (\Exception $e) {
                    $this->logger->error(
                        'ContactpersoonService: Failed to get user info for contactpersoon in bulk operation',
                        [
                            'contactpersoonId' => $contactpersoonId,
                            'error'            => $e->getMessage(),
                        ]
                    );

                    // Add error entry for this contactpersoon.
                    $bulkUserInfo[$contactpersoonId] = [
                        'hasUser'  => false,
                        'username' => null,
                        'groups'   => [],
                        'error'    => $e->getMessage(),
                    ];
                }//end try
            }//end foreach

            $this->logger->info(
                'ContactpersoonService: Successfully retrieved bulk user info',
                [
                    'totalContactpersonen' => count($contactpersoonIds),
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
                    'contactpersoonIds' => $contactpersoonIds,
                    'error'             => $e->getMessage(),
                    'trace'             => $e->getTraceAsString(),
                ]
            );
            throw $e;
        }//end try

    }//end getBulkUserInfo()

    /**
     * Updates the contactpersoon object's @self metadata to set owner to the user UID.
     *
     * @param object $contactObject The contactpersoon object to update.
     * @param string $userUID       The user UID to set as owner.
     *
     * @return void
     */
    private function updateContactpersoonObjectOwner(object $contactObject, string $userUID): void
    {
        try {
            $contactId = $contactObject->getUuid();

            // Get configuration for register and schema.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

            if (empty($register) === true || empty($contactSchema) === true) {
                $this->logger->warning(
                    'ContactpersoonService: Cannot update object owner - missing configuration',
                    [
                        'contactId'     => $contactId,
                        'register'      => $register,
                        'contactSchema' => $contactSchema,
                    ]
                );
                return;
            }

            $this->logger->info(
                'ContactpersoonService: Updating contactpersoon object owner',
                [
                    'contactId' => $contactId,
                    'userUID'   => $userUID,
                    'register'  => $register,
                    'schema'    => $contactSchema,
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
            $organizationUuid = ($currentObject['organisation'] ?? $currentObject['organisatie'] ?? '');
            if (empty($organizationUuid) === true) {
                $this->logger->warning(
                    'ContactpersoonService: No organization UUID found for contact person',
                    [
                        'contactId'   => $contactId,
                        'contactData' => $currentObject,
                    ]
                );
            }

            if (empty($organizationUuid) === false) {
                $selfMetadata['organisation'] = $organizationUuid;
                $this->logger->info(
                    'ContactpersoonService: Setting @self.organisation metadata',
                    [
                        'contactId'        => $contactId,
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

            // FIX #434: Use MagicMapper directly instead of ObjectService::saveObject().
            // To avoid validation errors on the organisatie field (stored as UUID string but.
            // Schema expects object type) and to avoid triggering ObjectUpdatedEvent cascades.
            $objectMapper = \OC::$server->get('OCA\OpenRegister\Db\MagicMapper');
            $objectMapper->update($contactObject);

            $this->logger->info(
                'ContactpersoonService: Successfully updated contactpersoon object owner and organisation',
                [
                    'contactId'       => $contactId,
                    'userUID'         => $userUID,
                    'ownerSet'        => $selfMetadata['owner'],
                    'organisationSet' => ($selfMetadata['organisation'] ?? 'not set'),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to update contactpersoon object owner',
                [
                    'contactId' => $contactObject->getUuid(),
                    'userUID'   => $userUID,
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );
        }//end try

    }//end updateContactpersoonObjectOwner()

    /**
     * Enable user account for a contactpersoon.
     *
     * @param string $contactpersoonId The UUID of the contactpersoon.
     *
     * @return void
     *
     * @throws \Exception If enabling fails.
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-2
     */
    public function enableUserForContactpersoon(string $contactpersoonId): void
    {
        try {
            $this->logger->info(
                'ContactpersoonService: Enabling user for contactpersoon',
                ['contactpersoonId' => $contactpersoonId]
            );

            $objectService = $this->getObjectService();
            if ($objectService === null) {
                throw new \Exception('ObjectService not available');
            }

            $contactObject = $objectService->find(
                id: $contactpersoonId,
                register: 'voorzieningen',
                schema: 'contactpersoon',
                _rbac: false,
                _multitenancy: false
            );
            if ($contactObject === null) {
                throw new \Exception('Contactpersoon not found');
            }

            $contactData = $contactObject->getObject();
            $username    = ($contactData['username'] ?? null);

            if (empty($username) === true) {
                throw new \Exception('No username found for contactpersoon');
            }

            $userManager = \OC::$server->get('OCP\IUserManager');
            $user        = $userManager->get($username);

            if ($user === null) {
                throw new \Exception('User not found in Nextcloud');
            }

            // Enable the user.
            $user->setEnabled(true);

            $this->logger->info(
                'ContactpersoonService: User enabled successfully',
                [
                    'contactpersoonId' => $contactpersoonId,
                    'username'         => $username,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to enable user for contactpersoon',
                [
                    'contactpersoonId' => $contactpersoonId,
                    'error'            => $e->getMessage(),
                    'trace'            => $e->getTraceAsString(),
                ]
            );
            throw $e;
        }//end try

    }//end enableUserForContactpersoon()

    /**
     * Disable user account for a contactpersoon.
     *
     * @param string $contactpersoonId The UUID of the contactpersoon.
     *
     * @return void
     *
     * @throws \Exception If disabling fails.
     * @spec openspec/changes/retrofit-2026-05-26-contactpersoon-sync/tasks.md#task-2
     */
    public function disableUserForContactpersoon(string $contactpersoonId): void
    {
        try {
            $this->logger->info(
                'ContactpersoonService: Disabling user for contactpersoon',
                ['contactpersoonId' => $contactpersoonId]
            );

            $objectService = $this->getObjectService();
            if ($objectService === null) {
                throw new \Exception('ObjectService not available');
            }

            $contactObject = $objectService->find(
                id: $contactpersoonId,
                register: 'voorzieningen',
                schema: 'contactpersoon',
                _rbac: false,
                _multitenancy: false
            );
            if ($contactObject === null) {
                throw new \Exception('Contactpersoon not found');
            }

            $contactData = $contactObject->getObject();
            $username    = ($contactData['username'] ?? null);

            if (empty($username) === true) {
                throw new \Exception('No username found for contactpersoon');
            }

            $userManager = \OC::$server->get('OCP\IUserManager');
            $user        = $userManager->get($username);

            if ($user === null) {
                throw new \Exception('User not found in Nextcloud');
            }

            // Disable the user.
            $user->setEnabled(false);

            $this->logger->info(
                'ContactpersoonService: User disabled successfully',
                [
                    'contactpersoonId' => $contactpersoonId,
                    'username'         => $username,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'ContactpersoonService: Failed to disable user for contactpersoon',
                [
                    'contactpersoonId' => $contactpersoonId,
                    'error'            => $e->getMessage(),
                    'trace'            => $e->getTraceAsString(),
                ]
            );
            throw $e;
        }//end try

    }//end disableUserForContactpersoon()
}//end class
