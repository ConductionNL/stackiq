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

use OCA\SoftwareCatalog\Service\SoftwareCatalogueService;
use OCA\SoftwareCatalog\Service\SettingsService;
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
     * @param  Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // Enhanced debug logging
        error_log("SoftwareCatalog: Event received - " . get_class($event) . " at " . date('Y-m-d H:i:s'));
        
        try {
            // Get services from the server container with detailed logging
            error_log("SoftwareCatalog: Attempting to get services from container");
            
            $softwareCatalogueService = \OC::$server->get(SoftwareCatalogueService::class);
            error_log("SoftwareCatalog: SoftwareCatalogueService retrieved successfully");
            
            $settingsService = \OC::$server->get(SettingsService::class);
            error_log("SoftwareCatalog: SettingsService retrieved successfully");
            
            $logger = \OC::$server->get(LoggerInterface::class);
            error_log("SoftwareCatalog: Logger retrieved successfully");
            
            // Log event details
            $logger->info('SoftwareCatalog: Event received and services retrieved', [
                'eventClass' => get_class($event),
                'timestamp' => date('Y-m-d H:i:s'),
                'servicesRetrieved' => json_encode([
                    'softwareCatalogueService' => $softwareCatalogueService !== null,
                    'settingsService' => $settingsService !== null,
                    'logger' => $logger !== null
                ])
            ]);

            // Handle different event types
            if ($event instanceof ObjectCreatedEvent) {
                error_log("SoftwareCatalog: Processing ObjectCreatedEvent");
                $logger->info('SoftwareCatalog: Processing ObjectCreatedEvent');
                $this->handleObjectCreated($event, $softwareCatalogueService, $settingsService, $logger);
            } elseif ($event instanceof ObjectUpdatedEvent) {
                error_log("SoftwareCatalog: Processing ObjectUpdatedEvent");
                $logger->info('SoftwareCatalog: Processing ObjectUpdatedEvent');
                $this->handleObjectUpdated($event, $softwareCatalogueService, $settingsService, $logger);
            } elseif ($event instanceof ObjectDeletedEvent) {
                error_log("SoftwareCatalog: Processing ObjectDeletedEvent");
                $logger->info('SoftwareCatalog: Processing ObjectDeletedEvent');
                $this->handleObjectDeleted($event, $softwareCatalogueService, $settingsService, $logger);
            } else {
                error_log("SoftwareCatalog: Unknown event type: " . get_class($event));
                $logger->warning('SoftwareCatalog: Unknown event type', [
                    'eventClass' => get_class($event)
                ]);
            }
            
        } catch (\Exception $e) {
            error_log("SoftwareCatalog: Error in event handler: " . $e->getMessage());
            error_log("SoftwareCatalog: Error trace: " . $e->getTraceAsString());
            
            // Try to get logger for error logging
            try {
                $logger = \OC::$server->get(LoggerInterface::class);
                if ($logger) {
                    $logger->error('SoftwareCatalog: Error in event handler: ' . $e->getMessage(), [
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } catch (\Exception $logError) {
                error_log("SoftwareCatalog: Failed to log error: " . $logError->getMessage());
            }
        }
    }



    /**
     * Handles object creation events
     *
     * @param ObjectCreatedEvent $event The creation event
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectCreated(ObjectCreatedEvent $event, SoftwareCatalogueService $softwareCatalogueService, SettingsService $settingsService, LoggerInterface $logger): void
    {
        $object = $event->getObject();
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectCreatedEvent received with null object');
            error_log("SoftwareCatalog: ObjectCreatedEvent received with null object");
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
        
        error_log("SoftwareCatalog: Processing object creation - ObjectId: $objectId, SchemaId: $objectSchemaId, RegisterId: $objectRegisterId");

        // Get configuration for different object types
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');

        $logger->info(
            'SoftwareCatalog: Configuration lookup results',
            [
                'organisatieSchemaId' => $organisatieSchemaId,
                'contactpersoonSchemaId' => $contactpersoonSchemaId,
                'contactgegevensSchemaId' => $contactgegevensSchemaId,
                'objectSchemaId' => $objectSchemaIdInt
            ]
        );
        
        error_log("SoftwareCatalog: Configuration - Organisatie: $organisatieSchemaId, Contactpersoon: $contactpersoonSchemaId, Contactgegevens: $contactgegevensSchemaId");

        // Check if this is an organization object
        if ($organisatieSchemaId && $objectSchemaIdInt === (int) $organisatieSchemaId) {
            $logger->info('SoftwareCatalog: Processing organization creation', ['objectId' => $objectId]);
            error_log("SoftwareCatalog: Processing organization creation for object: $objectId");
            $softwareCatalogueService->handleNewOrganization($object);
            return;
        }

        // Check if this is a contactpersoon object
        if ($contactpersoonSchemaId && $objectSchemaIdInt === (int) $contactpersoonSchemaId) {
            $logger->info('SoftwareCatalog: Processing contactpersoon creation', ['objectId' => $objectId]);
            error_log("SoftwareCatalog: Processing contactpersoon creation for object: $objectId");
            $softwareCatalogueService->processContactpersoon($object);
            return;
        }

        // Check if this is a contactgegevens object (deprecated - use contactpersoon instead)
        if ($contactgegevensSchemaId && $objectSchemaIdInt === (int) $contactgegevensSchemaId) {
            $logger->info('SoftwareCatalog: Processing contactgegevens creation (deprecated)', ['objectId' => $objectId]);
            error_log("SoftwareCatalog: Processing contactgegevens creation (deprecated) for object: $objectId");
            // Contactgegevens is deprecated, use contactpersoon instead
            return;
        }

        // Log unhandled object types
        $logger->info(
            'SoftwareCatalog: Object creation not handled - not a supported object type',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaIdInt,
                'registerId' => $objectRegisterId,
                'supportedSchemas' => [
                    'organisatie' => $organisatieSchemaId,
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId
                ]
            ]
        );
        
        error_log("SoftwareCatalog: Object creation not handled - SchemaId: $objectSchemaIdInt not in supported schemas");
    }

    /**
     * Handles object update events
     *
     * @param ObjectUpdatedEvent $event The update event
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectUpdated(ObjectUpdatedEvent $event, SoftwareCatalogueService $softwareCatalogueService, SettingsService $settingsService, LoggerInterface $logger): void
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
        
        // Handle organisation updates - sync with OpenRegister and manage user status
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $organisatieSchemaIdInt = (int) $organisatieSchemaId;
        
        if ($organisatieSchemaId && $objectSchemaIdInt === $organisatieSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched organisatie schema - processing update',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $organisatieSchemaId
                ]
            );
            
            try {
                $softwareCatalogueService->handleOrganizationUpdate($object, $oldObject);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed organisatie update',
                    [
                        'objectId' => $objectId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process organisatie update',
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
                $softwareCatalogueService->handleContactpersoonUpdate($object, $oldObject);
                
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
                $softwareCatalogueService->handleContactgegevensUpdate($object, $oldObject);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens update',
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

        // Log if we don't handle this schema type
        $logger->debug(
            'SoftwareCatalog: Object update not handled - focusing only on organisatie and contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'schemaIdType' => gettype($objectSchemaId),
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
                    'organisatie' => $organisatieSchemaId,
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId
                ]
            ]
        );
    }

    /**
     * Handles object deletion events
     *
     * @param ObjectDeletedEvent $event The deletion event
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger The logger instance
     * @return void
     */
    private function handleObjectDeleted(ObjectDeletedEvent $event, SoftwareCatalogueService $softwareCatalogueService, SettingsService $settingsService, LoggerInterface $logger): void
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
        
        // Handle organisation deletion - deactivate users
        $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $organisatieSchemaIdInt = (int) $organisatieSchemaId;
        $objectSchemaIdInt = (int) $objectSchemaId;
        
        if ($organisatieSchemaId && $objectSchemaIdInt === $organisatieSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched organisatie schema - processing deletion',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $organisatieSchemaId
                ]
            );
            
            try {
                $softwareCatalogueService->handleOrganizationDeletion($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed organisatie deletion',
                    [
                        'objectId' => $objectId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process organisatie deletion',
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
                $softwareCatalogueService->handleContactDeletion($object);
                
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
                $softwareCatalogueService->handleContactDeletion($object);
                
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

        // Log if we don't handle this schema type
        $logger->debug(
            'SoftwareCatalog: Object deletion not handled - focusing only on organisatie and contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
                    'organisatie' => $organisatieSchemaId,
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId
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