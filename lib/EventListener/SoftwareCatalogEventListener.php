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
 * @version   1.0.0
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
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/OpenConnector
 * @todo     This listener should be moved to the software catalog app
 */
class SoftwareCatalogEventListener implements IEventListener
{
    /**
     * Constructor for SoftwareCatalogEventListener
     */
    public function __construct() {
        // Empty constructor - we'll get services from the server container
    }

    /**
     * Handles events related to software catalog objects
     * 
     * DISABLED: All processing is now handled by cron-based OrganizationSyncService
     * to avoid race conditions and ensure consistent processing.
     *
     * @param  Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        try {
            $logger = \OC::$server->get(LoggerInterface::class);
            $contactpersoonService = \OC::$server->get(ContactpersoonService::class);
            $settingsService = \OC::$server->get(SettingsService::class);
            
            $logger->debug('SoftwareCatalog: Processing event', [
                'eventType' => get_class($event),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            if ($event instanceof ObjectCreatedEvent) {
                $this->handleObjectCreated($event, $contactpersoonService, $settingsService, $logger);
            } elseif ($event instanceof ObjectUpdatedEvent) {
                $this->handleObjectUpdated($event, $contactpersoonService, $settingsService, $logger);
            } elseif ($event instanceof ObjectDeletedEvent) {
                $this->handleObjectDeleted($event, $contactpersoonService, $settingsService, $logger);
            } elseif ($event instanceof ObjectLockedEvent || $event instanceof ObjectUnlockedEvent || $event instanceof ObjectRevertedEvent) {
                $logger->debug('SoftwareCatalog: Ignoring object lifecycle event', [
                    'eventType' => get_class($event)
                ]);
            } else {
                $logger->debug('SoftwareCatalog: Unknown event type ignored', [
                    'eventType' => get_class($event)
                ]);
            }
        } catch (\Exception $e) {
            try {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->error('SoftwareCatalog: Error in event handler', [
                    'eventType' => get_class($event),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);
            } catch (\Exception $logException) {
                // Silently fail if logging fails - better than breaking the event system
            }
        }
    }



    /**
     * Handles object creation events
     *
     * @param ObjectCreatedEvent $event The creation event
     * @param ContactpersoonService $contactpersoonService The contact person service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectCreated(ObjectCreatedEvent $event, ContactpersoonService $contactpersoonService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectCreatedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        $objectRegisterId = $object->getRegister();
        
        // Convert schema ID to integer for consistent comparison
        $objectSchemaIdInt = (int) $objectSchemaId;
        
        $logger->info(
            'SoftwareCatalog: Processing object creation',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'registerId' => $objectRegisterId,
                'objectData' => json_encode($object->getObject())
            ]
        );

        // Get configuration for different object types
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        $gebruikSchemaId = $settingsService->getSchemaIdForObjectType('gebruik');

        $logger->debug(
            'SoftwareCatalog: Configuration lookup results',
            [
                'organisatieSchemaId' => $organisatieSchemaId,
                'contactpersoonSchemaId' => $contactpersoonSchemaId,
                'contactgegevensSchemaId' => $contactgegevensSchemaId,
                'gebruikSchemaId' => $gebruikSchemaId,
                'objectSchemaId' => $objectSchemaIdInt
            ]
        );

        // Check if this is an organization object
        if ($organisatieSchemaId && $objectSchemaIdInt === (int) $organisatieSchemaId) {
            $objectData = $object->getObject();
            $status = strtolower($objectData['status'] ?? '');
            
            // Only process active organizations
            if (in_array($status, ['actief', 'active'])) {
                $logger->info('SoftwareCatalog: Processing active organization creation', [
                    'objectId' => $objectId,
                    'status' => $status
                ]);
                
                try {
                    // Process organization with OrganizationSyncService
                    $organizationSyncService = \OC::$server->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
                    $result = $organizationSyncService->processSpecificOrganization($object);
                    
                    $logger->info('SoftwareCatalog: Successfully processed organization creation', [
                        'objectId' => $objectId,
                        'processResult' => $result
                    ]);
                } catch (\Exception $e) {
                    $logger->error('SoftwareCatalog: Failed to process organization creation', [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }
            } else {
                $logger->debug('SoftwareCatalog: Skipping non-active organization creation', [
                    'objectId' => $objectId,
                    'status' => $status
                ]);
            }
            return;
        }

        // Check if this is a contactpersoon object
        if ($contactpersoonSchemaId && $objectSchemaIdInt === (int) $contactpersoonSchemaId) {
            $logger->info('SoftwareCatalog: Processing contactpersoon creation', ['objectId' => $objectId]);
            $contactpersoonService->processContactpersoon($object);
            return;
        }

        // Check if this is a contactgegevens object (deprecated - use contactpersoon instead)
        if ($contactgegevensSchemaId && $objectSchemaIdInt === (int) $contactgegevensSchemaId) {
            $logger->info('SoftwareCatalog: Processing contactgegevens creation (deprecated)', ['objectId' => $objectId]);
            // Contactgegevens is deprecated, use contactpersoon instead
            return;
        }

        // Check if this is a gebruik object
        if ($gebruikSchemaId && $objectSchemaIdInt === (int) $gebruikSchemaId) {
            $logger->info('SoftwareCatalog: Processing gebruik creation', ['objectId' => $objectId]);
            
            try {
                // Process gebruik object with GebruikSyncService
                $gebruikSyncService = \OC::$server->get(GebruikSyncService::class);
                $result = $gebruikSyncService->processSpecificGebruik($object);
                
                $logger->info('SoftwareCatalog: Successfully processed gebruik creation', [
                    'objectId' => $objectId,
                    'processResult' => $result
                ]);
            } catch (\Exception $e) {
                $logger->error('SoftwareCatalog: Failed to process gebruik creation', [
                    'objectId' => $objectId,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            return;
        }

        // Log unhandled object types
        $logger->debug(
            'SoftwareCatalog: Object creation not handled - not a supported object type',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaIdInt,
                'registerId' => $objectRegisterId,
                'supportedSchemas' => [
                    'organisatie' => $organisatieSchemaId,
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId,
                    'gebruik' => $gebruikSchemaId
                ]
            ]
        );
    }

    /**
     * Handles object update events
     *
     * @param ObjectUpdatedEvent $event The update event
     * @param ContactpersoonService $contactpersoonService The contact person service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectUpdated(ObjectUpdatedEvent $event, ContactpersoonService $contactpersoonService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getNewObject();
        $oldObject = $event->getOldObject();
        
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectUpdatedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        $objectRegisterId = $object->getRegister();
        
        // Convert schema ID to integer for consistent comparison
        $objectSchemaIdInt = (int) $objectSchemaId;
        
        $logger->info(
            'SoftwareCatalog: Processing object update',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'registerId' => $objectRegisterId,
                'hasOldObject' => $oldObject !== null,
                'newObjectData' => $object->getObject(),
                'oldObjectData' => $oldObject ? $oldObject->getObject() : null
            ]
        );
        
        // Check if this is an organization update
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $organisatieSchemaIdInt = (int) $organisatieSchemaId;
        
        if ($organisatieSchemaId && $objectSchemaIdInt === $organisatieSchemaIdInt) {
            $objectData = $object->getObject();
            $status = strtolower($objectData['status'] ?? '');
            
            // Only process active organizations
            if (in_array($status, ['actief', 'active'])) {
                $logger->info('SoftwareCatalog: Processing active organization update', [
                    'objectId' => $objectId,
                    'status' => $status,
                    'schemaId' => $objectSchemaId
                ]);
                
                try {
                    // Process organization with OrganizationSyncService
                    $organizationSyncService = \OC::$server->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
                    $result = $organizationSyncService->processSpecificOrganization($object);
                    
                    $logger->info('SoftwareCatalog: Successfully processed organization update', [
                        'objectId' => $objectId,
                        'processResult' => $result
                    ]);
                } catch (\Exception $e) {
                    $logger->error('SoftwareCatalog: Failed to process organization update', [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }
            } else {
                $logger->debug('SoftwareCatalog: Skipping non-active organization update', [
                    'objectId' => $objectId,
                    'status' => $status,
                    'schemaId' => $objectSchemaId
                ]);
            }
            return;
        }
        
        // Handle contactpersoon updates
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        $contactpersoonSchemaIdInt = (int) $contactpersoonSchemaId;
        
        if ($contactpersoonSchemaId && $objectSchemaIdInt === $contactpersoonSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactpersoon schema - processing update',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $contactpersoonSchemaId
                ]
            );
            
            try {
                $contactpersoonService->handleContactpersoonUpdate($object, $oldObject);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon update',
                    [
                        'objectId' => $objectId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon update',
                    [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }
        
        // Handle contactgegevens updates (backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactgegevens schema - processing update (backward compatibility)',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $contactgegevensSchemaId
                ]
            );
            
            try {
                // Handle contactgegevens as contactpersoon (backward compatibility)
                $contactpersoonService->handleContactpersoonUpdate($object, $oldObject);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens update (as contactpersoon)',
                    [
                        'objectId' => $objectId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens update',
                    [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle gebruik updates
        $gebruikSchemaId = $settingsService->getSchemaIdForObjectType('gebruik');
        $gebruikSchemaIdInt = (int) $gebruikSchemaId;
        
        if ($gebruikSchemaId && $objectSchemaIdInt === $gebruikSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched gebruik schema - processing update',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $gebruikSchemaId
                ]
            );
            
            try {
                // Process gebruik object with GebruikSyncService
                $gebruikSyncService = \OC::$server->get(GebruikSyncService::class);
                $result = $gebruikSyncService->processSpecificGebruik($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruik update',
                    [
                        'objectId' => $objectId,
                        'processResult' => $result,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process gebruik update',
                    [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Log if we don't handle this schema type
        $logger->debug(
            'SoftwareCatalog: Object update not handled - focusing only on organisatie, contactpersonen, and gebruik',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'schemaIdType' => gettype($objectSchemaId),
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
                    'organisatie' => $organisatieSchemaId,
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId,
                    'gebruik' => $gebruikSchemaId
                ]
            ]
        );
    }

    /**
     * Handles object deletion events
     *
     * @param ObjectDeletedEvent $event The deletion event
     * @param ContactpersoonService $contactpersoonService The contact person service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectDeleted(ObjectDeletedEvent $event, ContactpersoonService $contactpersoonService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectDeletedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        $objectRegisterId = $object->getRegister();
        
        $logger->info(
            'SoftwareCatalog: Processing object deletion',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'registerId' => $objectRegisterId,
                'objectData' => $object->getObject()
            ]
        );
        
        // Check if this is an organization deletion
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $organisatieSchemaIdInt = (int) $organisatieSchemaId;
        $objectSchemaIdInt = (int) $objectSchemaId;
        
        if ($organisatieSchemaId && $objectSchemaIdInt === $organisatieSchemaIdInt) {
            $logger->info('SoftwareCatalog: Processing organization deletion', ['objectId' => $objectId]);
            
            try {
                // For deletions, we may need to handle cleanup regardless of status
                // The OrganizationSyncService can determine what cleanup is needed
                $organizationSyncService = \OC::$server->get('OCA\SoftwareCatalog\Service\OrganizationSyncService');
                
                // Note: processSpecificOrganization may handle cleanup for deleted organizations
                // The service can check if the organization exists and handle accordingly
                $result = $organizationSyncService->processSpecificOrganization($object);
                
                $logger->info('SoftwareCatalog: Successfully processed organization deletion', [
                    'objectId' => $objectId,
                    'processResult' => $result
                ]);
            } catch (\Exception $e) {
                $logger->error('SoftwareCatalog: Failed to process organization deletion', [
                    'objectId' => $objectId,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            return;
        }
        
        // Handle contactpersoon deletion
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        $contactpersoonSchemaIdInt = (int) $contactpersoonSchemaId;
        
        if ($contactpersoonSchemaId && $objectSchemaIdInt === $contactpersoonSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactpersoon schema - processing deletion',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $contactpersoonSchemaId
                ]
            );
            
            try {
                $contactpersoonService->handleContactDeletion($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon deletion',
                    [
                        'objectId' => $objectId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon deletion',
                    [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }
        
        // Handle contactgegevens deletion (backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactgegevens schema - processing deletion (backward compatibility)',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $contactgegevensSchemaId
                ]
            );
            
            try {
                $contactpersoonService->handleContactDeletion($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens deletion',
                    [
                        'objectId' => $objectId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens deletion',
                    [
                        'objectId' => $objectId,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle gebruik deletion
        $gebruikSchemaId = $settingsService->getSchemaIdForObjectType('gebruik');
        $gebruikSchemaIdInt = (int) $gebruikSchemaId;
        
        if ($gebruikSchemaId && $objectSchemaIdInt === $gebruikSchemaIdInt) {
            $objectData = $object->getObject();
            
            $logger->info(
                'SoftwareCatalog: Matched gebruik schema - processing deletion',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $gebruikSchemaId,
                    'afnemer' => $objectData['afnemer']['naam'] ?? 'Unknown',
                    'product' => $objectData['product']['naam'] ?? 'Unknown'
                ]
            );
            
            // For deletions, we mainly log the event since the object is being removed
            // No specific cleanup needed for gebruik objects currently
            $logger->info(
                'SoftwareCatalog: Gebruik object deleted - no specific cleanup required',
                [
                    'objectId' => $objectId,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
            return;
        }

        // Log if we don't handle this schema type
        $logger->debug(
            'SoftwareCatalog: Object deletion not handled - focusing only on organisatie, contactpersonen, and gebruik',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
                    'organisatie' => $organisatieSchemaId,
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId,
                    'gebruik' => $gebruikSchemaId
                ]
            ]
        );
    }

    /**
     * Handles object locking events
     *
     * @param ObjectLockedEvent $event The locking event
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectLocked(ObjectLockedEvent $event, SoftwareCatalogueService $softwareCatalogueService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectLockedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        
        $logger->info(
            'SoftwareCatalog: Processing object locking',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        // Currently no specific handling for locking events
        $logger->debug(
            'SoftwareCatalog: Object locking event received but no specific handling implemented',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId
            ]
        );
    }

    /**
     * Handles object unlocking events
     *
     * @param ObjectUnlockedEvent $event The unlocking event
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectUnlocked(ObjectUnlockedEvent $event, SoftwareCatalogueService $softwareCatalogueService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectUnlockedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        
        $logger->info(
            'SoftwareCatalog: Processing object unlocking',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        // Currently no specific handling for unlocking events
        $logger->debug(
            'SoftwareCatalog: Object unlocking event received but no specific handling implemented',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId
            ]
        );
    }

    /**
     * Handles object reversion events
     *
     * @param ObjectRevertedEvent $event The reversion event
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectReverted(ObjectRevertedEvent $event, SoftwareCatalogueService $softwareCatalogueService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectRevertedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        
        $logger->info(
            'SoftwareCatalog: Processing object reversion',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        // Currently no specific handling for reversion events
        $logger->debug(
            'SoftwareCatalog: Object reversion event received but no specific handling implemented',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId
            ]
        );
    }
} 