<?php
/**
 * SoftwareCatalog Event Listener
 *
 * This file contains the listener class for handling events from OpenRegister
 * specific to the SoftwareCatalog application.
 *
 * @category  EventListener
 * @package   OCA\SoftwareCatalog\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/OpenConnector
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\GebruikSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use Psr\Log\LoggerInterface;

/**
 * Event listener for handling software catalog specific events.
 *
 * This listener handles organization, contact, and user (gebruiker) related events
 * in the software catalog, including user management, email notifications, and
 * user blocking/unblocking functionality.
 *
 * @category EventListener
 * @package  OCA\SoftwareCatalog\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/OpenConnector
 * @todo     This listener should be moved to the software catalog app.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SoftwareCatalogEventListener implements IEventListener
{
    /**
     * Constructor for SoftwareCatalogEventListener
     */
    public function __construct()
    {
        // Empty constructor - we'll get services from the server container.
    }//end __construct()

    /**
     * Handles events related to software catalog objects
     *
     * DISABLED: All processing is now handled by cron-based OrganizationSyncService
     * to avoid race conditions and ensure consistent processing.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function handle(Event $event): void
    {
        try {
            $logger          = \OC::$server->get(LoggerInterface::class);
            $contactSvc      = \OC::$server->get(ContactpersoonService::class);
            $settingsService = \OC::$server->get(SettingsService::class);

            $logger->info(
                    'SoftwareCatalog: Processing event',
                    [
                        'eventType' => get_class($event),
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                    );

            if ($event instanceof ObjectCreatedEvent) {
                $this->handleObjectCreated(
                    event: $event,
                    contactpersoonService: $contactSvc,
                    settingsService: $settingsService,
                    logger: $logger
                );
            } else if ($event instanceof ObjectUpdatedEvent) {
                $this->handleObjectUpdated(
                    event: $event,
                    contactpersoonService: $contactSvc,
                    settingsService: $settingsService,
                    logger: $logger
                );
            } else if ($event instanceof ObjectDeletedEvent) {
                $this->handleObjectDeleted(
                    event: $event,
                    contactpersoonService: $contactSvc,
                    settingsService: $settingsService,
                    logger: $logger
                );
            } else if ($event instanceof ObjectLockedEvent
                || $event instanceof ObjectUnlockedEvent
                || $event instanceof ObjectRevertedEvent
            ) {
                $logger->debug(
                        'SoftwareCatalog: Ignoring object lifecycle event',
                        [
                            'eventType' => get_class($event),
                        ]
                        );
            }//end if
        } catch (\Exception $e) {
            try {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->error(
                        'SoftwareCatalog: Error in event handler',
                        [
                            'eventType' => get_class($event),
                            'exception' => $e->getMessage(),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
                            'trace'     => $e->getTraceAsString(),
                        ]
                        );
            } catch (\Exception $logException) {
                // Silently fail if logging fails - better than breaking the event system.
            }
        }//end try
    }//end handle()

    /**
     * Handles object creation events
     *
     * @param ObjectCreatedEvent    $event           The creation event
     * @param ContactpersoonService $contactSvc      The contact person service
     * @param SettingsService       $settingsService The settings service
     * @param LoggerInterface       $logger          The logger instance
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function handleObjectCreated(
        ObjectCreatedEvent $event,
        ContactpersoonService $contactSvc,
        SettingsService $settingsService,
        LoggerInterface $logger
    ): void {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectCreatedEvent received with null object');
            return;
        }

        $objectSchemaId   = $object->getSchema();
        $objectId         = $object->getUuid();
        $objectRegisterId = $object->getRegister();

        // Convert schema ID to integer for consistent comparison.
        $objectSchemaIdInt = (int) $objectSchemaId;

        $logger->info(
            'SoftwareCatalog: Processing object creation',
            [
                'objectId'    => $objectId,
                'schemaId'    => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'registerId'  => $objectRegisterId,
                'objectData'  => json_encode($object->getObject()),
            ]
        );

        // Get configuration for different object types.
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'organisatie');
        $contactSchemaId     = $settingsService->getSchemaIdForObjectType(objectType: 'contactpersoon');
        $contactInfoSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactgegevens');
        $gebruikSchemaId     = $settingsService->getSchemaIdForObjectType(objectType: 'gebruik');

        $logger->debug(
            'SoftwareCatalog: Configuration lookup results',
            [
                'organisatieSchemaId'     => $organisatieSchemaId,
                'contactpersoonSchemaId'  => $contactSchemaId,
                'contactgegevensSchemaId' => $contactInfoSchemaId,
                'gebruikSchemaId'         => $gebruikSchemaId,
                'objectSchemaId'          => $objectSchemaIdInt,
            ]
        );

        // Check if this is an organization object.
        if ($organisatieSchemaId !== null && $objectSchemaIdInt === (int) $organisatieSchemaId) {
            $objectData = $object->getObject();
            $status     = strtolower($objectData['status'] ?? '');

            // Only process active organizations.
            if (in_array(needle: $status, haystack: ['actief', 'active']) !== true) {
                $logger->debug(
                        'SoftwareCatalog: Skipping non-active organization creation',
                        [
                            'objectId' => $objectId,
                            'status'   => $status,
                        ]
                        );
                return;
            }

            $logger->info(
                    'SoftwareCatalog: Processing active organization creation',
                    [
                        'objectId' => $objectId,
                        'status'   => $status,
                    ]
                    );

            try {
                // Process organization with OrganizationSyncService.
                $orgSyncService = \OC::$server->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
                $result         = $orgSyncService->processSpecificOrganization($object);

                $logger->info(
                        'SoftwareCatalog: Successfully processed organization creation',
                        [
                            'objectId'      => $objectId,
                            'processResult' => $result,
                        ]
                        );
            } catch (\Exception $e) {
                $logger->error(
                        'SoftwareCatalog: Failed to process organization creation',
                        [
                            'objectId'  => $objectId,
                            'exception' => $e->getMessage(),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
                        ]
                        );
            }//end try

            return;
        }//end if

        // Check if this is a contactpersoon object.
        if ($contactSchemaId !== null && $objectSchemaIdInt === (int) $contactSchemaId) {
            $logger->info('SoftwareCatalog: Processing contactpersoon creation', ['objectId' => $objectId]);
            $contactSvc->processContactpersoon($object);
            return;
        }

        // Check if this is a contactgegevens object (deprecated - use contactpersoon instead).
        if ($contactInfoSchemaId !== null && $objectSchemaIdInt === (int) $contactInfoSchemaId) {
            $logger->info('SoftwareCatalog: Processing contactgegevens creation (deprecated)', ['objectId' => $objectId]);
            // Contactgegevens is deprecated, use contactpersoon instead.
            return;
        }

        // Check if this is a gebruik object.
        if ($gebruikSchemaId !== null && $objectSchemaIdInt === (int) $gebruikSchemaId) {
            $logger->info('SoftwareCatalog: Processing gebruik creation', ['objectId' => $objectId]);

            try {
                // Process gebruik object with GebruikSyncService.
                $gebruikSyncService = \OC::$server->get(GebruikSyncService::class);
                $result = $gebruikSyncService->processSpecificGebruik($object);

                $logger->info(
                        'SoftwareCatalog: Successfully processed gebruik creation',
                        [
                            'objectId'      => $objectId,
                            'processResult' => $result,
                        ]
                        );
            } catch (\Exception $e) {
                $logger->error(
                        'SoftwareCatalog: Failed to process gebruik creation',
                        [
                            'objectId'  => $objectId,
                            'exception' => $e->getMessage(),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
                        ]
                        );
            }//end try

            return;
        }//end if

        // Log unhandled object types.
        $logger->debug(
            'SoftwareCatalog: Object creation not handled - not a supported object type',
            [
                'objectId'         => $objectId,
                'schemaId'         => $objectSchemaIdInt,
                'registerId'       => $objectRegisterId,
                'supportedSchemas' => [
                    'organisatie'     => $organisatieSchemaId,
                    'contactpersoon'  => $contactSchemaId,
                    'contactgegevens' => $contactInfoSchemaId,
                    'gebruik'         => $gebruikSchemaId,
                ],
            ]
        );
    }//end handleObjectCreated()

    /**
     * Handles object update events
     *
     * @param ObjectUpdatedEvent    $event           The update event
     * @param ContactpersoonService $contactSvc      The contact person service
     * @param SettingsService       $settingsService The settings service
     * @param LoggerInterface       $logger          The logger instance
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    private function handleObjectUpdated(
        ObjectUpdatedEvent $event,
        ContactpersoonService $contactSvc,
        SettingsService $settingsService,
        LoggerInterface $logger
    ): void {
        $object    = $event->getNewObject();
        $oldObject = $event->getOldObject();

        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectUpdatedEvent received with null object');
            return;
        }

        $objectSchemaId   = $object->getSchema();
        $objectId         = $object->getUuid();
        $objectRegisterId = $object->getRegister();

        // Convert schema ID to integer for consistent comparison.
        $objectSchemaIdInt = (int) $objectSchemaId;

        $logger->info(
            'SoftwareCatalog: Processing object update',
            [
                'objectId'     => $objectId,
                'schemaId'     => $objectSchemaId,
                'schemaIdInt'  => $objectSchemaIdInt,
                'registerId'   => $objectRegisterId,
                'hasOldObject' => $oldObject !== null,
            ]
        );

        // Check if this is an organization update.
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'organisatie');
        $orgSchemaIdInt      = (int) $organisatieSchemaId;

        $logger->debug(
                'Got organisation schema ID',
                [
                    'app'                    => 'softwarecatalog',
                    'organisatieSchemaId'    => $organisatieSchemaId,
                    'organisatieSchemaIdInt' => $orgSchemaIdInt,
                ]
                );

        $logger->debug(
                'Organization schema check',
                [
                    'app'                    => 'softwarecatalog',
                    'objectSchemaId'         => $objectSchemaId,
                    'objectSchemaIdInt'      => $objectSchemaIdInt,
                    'organisatieSchemaId'    => $organisatieSchemaId,
                    'organisatieSchemaIdInt' => $orgSchemaIdInt,
                    'matches'                => ($objectSchemaIdInt === $orgSchemaIdInt),
                ]
                );

        if ($organisatieSchemaId !== null && $objectSchemaIdInt === $orgSchemaIdInt) {
            $objectData = $object->getObject();
            $status     = strtolower($objectData['status'] ?? '');

            if ($oldObject !== null) {
                $oldStatus = strtolower($oldObject->getObject()['status'] ?? '');
            } else {
                $oldStatus = '';
            }

            $logger->debug(
                    'Organization status check',
                    [
                        'app'           => 'softwarecatalog',
                        'objectId'      => $objectId,
                        'status'        => $status,
                        'oldStatus'     => $oldStatus,
                        'statusChanged' => ($status !== $oldStatus),
                        'isActief'      => in_array(needle: $status, haystack: ['actief', 'active']),
                        'willProcess'   => (in_array(needle: $status, haystack: ['actief', 'active']) === true
                            && $status !== $oldStatus),
                    ]
                    );

            // Only process active organizations.
            if (in_array(needle: $status, haystack: ['actief', 'active']) === true && $status !== $oldStatus) {
                $logger->info(
                        'SoftwareCatalog: Processing active organization update',
                        [
                            'objectId' => $objectId,
                            'status'   => $status,
                            'schemaId' => $objectSchemaId,
                        ]
                        );

                try {
                    // Refetch organization WITH contactpersonen expanded to get full contact data.
                    $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
                    $register            = $voorzieningenConfig['register'] ?? '';
                    $organizationSchema  = $voorzieningenConfig['organisatie_schema'] ?? '';

                    $objectService   = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
                    $orgWithContacts = $objectService->find(
                        id: $objectId,
                        register: $register,
                        schema: $organizationSchema,
                        // This expands contactpersonen with full data!
                        _extend: ['contactpersonen'],
                        _rbac: false,
                        _multitenancy: false
                    );

                    $logger->info(
                            'SoftwareCatalog: Refetched organization with contactpersonen',
                            [
                                'objectId'             => $objectId,
                                'contactpersonenCount' => count(
                                    $orgWithContacts->getObject()['contactpersonen'] ?? []
                                ),
                            ]
                            );

                    // Process organization with OrganizationSyncService.
                    $orgSyncService = \OC::$server->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
                    $result         = $orgSyncService->processSpecificOrganization($orgWithContacts);

                    $logger->info(
                            'SoftwareCatalog: Successfully processed organization update',
                            [
                                'objectId'      => $objectId,
                                'processResult' => $result,
                            ]
                            );
                } catch (\Exception $e) {
                    $logger->error(
                            'SoftwareCatalog: Failed to process organization update',
                            [
                                'objectId'  => $objectId,
                                'exception' => $e->getMessage(),
                                'file'      => $e->getFile(),
                                'line'      => $e->getLine(),
                            ]
                            );
                }//end try
            } else {
                $logger->debug(
                        'SoftwareCatalog: Skipping non-active organization update',
                        [
                            'objectId' => $objectId,
                            'status'   => $status,
                            'schemaId' => $objectSchemaId,
                        ]
                        );
            }//end if

            return;
        }//end if

        // Handle contactpersoon updates.
        $contactSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactpersoon');
        $cntSchemaIdInt  = (int) $contactSchemaId;

        if ($contactSchemaId !== null && $objectSchemaIdInt === $cntSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactpersoon schema - processing update',
                [
                    'objectId'           => $objectId,
                    'schemaId'           => $objectSchemaId,
                    'configuredSchemaId' => $contactSchemaId,
                ]
            );

            try {
                $contactSvc->handleContactpersoonUpdate(
                    contactpersoonObject: $object,
                    oldContactpersoonObject: $oldObject
                );

                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon update',
                    [
                        'objectId'  => $objectId,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon update',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                );
            }//end try

            return;
        }//end if

        // Handle contactgegevens updates (backward compatibility).
        $contactInfoSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactgegevens');
        $infoSchemaIdInt     = (int) $contactInfoSchemaId;

        if ($contactInfoSchemaId !== null && $objectSchemaIdInt === $infoSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactgegevens schema - processing update (backward compatibility)',
                [
                    'objectId'           => $objectId,
                    'schemaId'           => $objectSchemaId,
                    'configuredSchemaId' => $contactInfoSchemaId,
                ]
            );

            try {
                // Handle contactgegevens as contactpersoon (backward compatibility).
                $contactSvc->handleContactpersoonUpdate(
                    contactpersoonObject: $object,
                    oldContactpersoonObject: $oldObject
                );

                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens update (as contactpersoon)',
                    [
                        'objectId'  => $objectId,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens update',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                );
            }//end try

            return;
        }//end if

        // Handle gebruik updates.
        $gebruikSchemaId    = $settingsService->getSchemaIdForObjectType(objectType: 'gebruik');
        $gebruikSchemaIdInt = (int) $gebruikSchemaId;

        if ($gebruikSchemaId !== null && $objectSchemaIdInt === $gebruikSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched gebruik schema - processing update',
                [
                    'objectId'           => $objectId,
                    'schemaId'           => $objectSchemaId,
                    'configuredSchemaId' => $gebruikSchemaId,
                ]
            );

            try {
                // Process gebruik object with GebruikSyncService.
                $gebruikSyncService = \OC::$server->get(GebruikSyncService::class);
                $result = $gebruikSyncService->processSpecificGebruik($object);

                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruik update',
                    [
                        'objectId'      => $objectId,
                        'processResult' => $result,
                        'timestamp'     => date('Y-m-d H:i:s'),
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process gebruik update',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                );
            }//end try

            return;
        }//end if

        // Log if we don't handle this schema type.
        $logger->debug(
            'SoftwareCatalog: Object update not handled - focusing only on organisatie, contactpersonen, and gebruik',
            [
                'objectId'       => $objectId,
                'schemaId'       => $objectSchemaId,
                'schemaIdInt'    => $objectSchemaIdInt,
                'schemaIdType'   => gettype($objectSchemaId),
                'registerId'     => $objectRegisterId,
                'handledSchemas' => [
                    'organisatie'     => $organisatieSchemaId,
                    'contactpersoon'  => $contactSchemaId,
                    'contactgegevens' => $contactInfoSchemaId,
                    'gebruik'         => $gebruikSchemaId,
                ],
            ]
        );
    }//end handleObjectUpdated()

    /**
     * Handles object deletion events
     *
     * @param ObjectDeletedEvent    $event           The deletion event
     * @param ContactpersoonService $contactSvc      The contact person service
     * @param SettingsService       $settingsService The settings service
     * @param LoggerInterface       $logger          The logger instance
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function handleObjectDeleted(
        ObjectDeletedEvent $event,
        ContactpersoonService $contactSvc,
        SettingsService $settingsService,
        LoggerInterface $logger
    ): void {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectDeletedEvent received with null object');
            return;
        }

        $objectSchemaId   = $object->getSchema();
        $objectId         = $object->getUuid();
        $objectRegisterId = $object->getRegister();

        $logger->info(
            'SoftwareCatalog: Processing object deletion',
            [
                'objectId'   => $objectId,
                'schemaId'   => $objectSchemaId,
                'registerId' => $objectRegisterId,
                'objectData' => $object->getObject(),
            ]
        );

        // Check if this is an organization deletion.
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'organisatie');
        $orgSchemaIdInt      = (int) $organisatieSchemaId;
        $objectSchemaIdInt   = (int) $objectSchemaId;

        if ($organisatieSchemaId !== null && $objectSchemaIdInt === $orgSchemaIdInt) {
            $logger->info('SoftwareCatalog: Processing organization deletion', ['objectId' => $objectId]);

            try {
                // For deletions, we may need to handle cleanup regardless of status.
                // The OrganizationSyncService can determine what cleanup is needed.
                $orgSyncService = \OC::$server->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');

                // Note: processSpecificOrganization may handle cleanup for deleted organizations.
                // The service can check if the organization exists and handle accordingly.
                $result = $orgSyncService->processSpecificOrganization($object);

                $logger->info(
                        'SoftwareCatalog: Successfully processed organization deletion',
                        [
                            'objectId'      => $objectId,
                            'processResult' => $result,
                        ]
                        );
            } catch (\Exception $e) {
                $logger->error(
                        'SoftwareCatalog: Failed to process organization deletion',
                        [
                            'objectId'  => $objectId,
                            'exception' => $e->getMessage(),
                            'file'      => $e->getFile(),
                            'line'      => $e->getLine(),
                        ]
                        );
            }//end try

            return;
        }//end if

        // Handle contactpersoon deletion.
        $contactSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactpersoon');
        $cntSchemaIdInt  = (int) $contactSchemaId;

        if ($contactSchemaId !== null && $objectSchemaIdInt === $cntSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactpersoon schema - processing deletion',
                [
                    'objectId'           => $objectId,
                    'schemaId'           => $objectSchemaId,
                    'configuredSchemaId' => $contactSchemaId,
                ]
            );

            try {
                $contactSvc->handleContactDeletion($object);

                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon deletion',
                    [
                        'objectId'  => $objectId,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon deletion',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                );
            }//end try

            return;
        }//end if

        // Handle contactgegevens deletion (backward compatibility).
        $contactInfoSchemaId = $settingsService->getSchemaIdForObjectType(objectType: 'contactgegevens');
        $infoSchemaIdInt     = (int) $contactInfoSchemaId;

        if ($contactInfoSchemaId !== null && $objectSchemaIdInt === $infoSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactgegevens schema - processing deletion (backward compatibility)',
                [
                    'objectId'           => $objectId,
                    'schemaId'           => $objectSchemaId,
                    'configuredSchemaId' => $contactInfoSchemaId,
                ]
            );

            try {
                $contactSvc->handleContactDeletion($object);

                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens deletion',
                    [
                        'objectId'  => $objectId,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens deletion',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                );
            }//end try

            return;
        }//end if

        // Handle gebruik deletion.
        $gebruikSchemaId    = $settingsService->getSchemaIdForObjectType(objectType: 'gebruik');
        $gebruikSchemaIdInt = (int) $gebruikSchemaId;

        if ($gebruikSchemaId !== null && $objectSchemaIdInt === $gebruikSchemaIdInt) {
            $objectData = $object->getObject();

            $logger->info(
                'SoftwareCatalog: Matched gebruik schema - processing deletion',
                [
                    'objectId'           => $objectId,
                    'schemaId'           => $objectSchemaId,
                    'configuredSchemaId' => $gebruikSchemaId,
                    'afnemer'            => $objectData['afnemer']['naam'] ?? 'Unknown',
                    'product'            => $objectData['product']['naam'] ?? 'Unknown',
                ]
            );

            // For deletions, we mainly log the event since the object is being removed.
            // No specific cleanup needed for gebruik objects currently.
            $logger->info(
                'SoftwareCatalog: Gebruik object deleted - no specific cleanup required',
                [
                    'objectId'  => $objectId,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]
            );
            return;
        }//end if

        // Log if we don't handle this schema type.
        $logger->debug(
            'SoftwareCatalog: Object deletion not handled - focusing only on organisatie, contactpersonen, and gebruik',
            [
                'objectId'       => $objectId,
                'schemaId'       => $objectSchemaId,
                'registerId'     => $objectRegisterId,
                'handledSchemas' => [
                    'organisatie'     => $organisatieSchemaId,
                    'contactpersoon'  => $contactSchemaId,
                    'contactgegevens' => $contactInfoSchemaId,
                    'gebruik'         => $gebruikSchemaId,
                ],
            ]
        );
    }//end handleObjectDeleted()
}//end class
