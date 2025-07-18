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
        // Simple debug logging
        error_log("SoftwareCatalog: Event received - " . get_class($event));
        
        try {
            // Get services from the server container
            $softwareCatalogueService = \OC::$server->get(SoftwareCatalogueService::class);
            $settingsService = \OC::$server->get(SettingsService::class);
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            
            // Handle different event types
            if ($event instanceof ObjectCreatedEvent) {
                $logger->debug('SoftwareCatalog: Processing ObjectCreatedEvent');
                $this->handleObjectCreated($event, $softwareCatalogueService, $settingsService, $logger);
                return;
            }

            // Handle object updates
            if ($event instanceof ObjectUpdatedEvent) {
                $logger->debug('SoftwareCatalog: Processing ObjectUpdatedEvent');
                $this->handleObjectUpdated($event, $softwareCatalogueService, $settingsService, $logger);
                return;
            }

            // Handle object deletion
            if ($event instanceof ObjectDeletedEvent) {
                $logger->debug('SoftwareCatalog: Processing ObjectDeletedEvent');
                $this->handleObjectDeleted($event, $softwareCatalogueService, $settingsService, $logger);
                return;
            }

            // Handle object locking
            if ($event instanceof ObjectLockedEvent) {
                $logger->debug('SoftwareCatalog: Processing ObjectLockedEvent');
                $this->handleObjectLocked($event, $softwareCatalogueService, $settingsService, $logger);
                return;
            }

            // Handle object unlocking
            if ($event instanceof ObjectUnlockedEvent) {
                $logger->debug('SoftwareCatalog: Processing ObjectUnlockedEvent');
                $this->handleObjectUnlocked($event, $softwareCatalogueService, $settingsService, $logger);
                return;
            }

            // Handle object reversion
            if ($event instanceof ObjectRevertedEvent) {
                $logger->debug('SoftwareCatalog: Processing ObjectRevertedEvent');
                $this->handleObjectReverted($event, $softwareCatalogueService, $settingsService, $logger);
                return;
            }

            // Log if we receive an unexpected event type
            $logger->warning(
                'SoftwareCatalog: Received unexpected event type',
                [
                    'eventType' => $eventType,
                    'timestamp' => $timestamp
                ]
            );

        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully
            $errorMessage = "SoftwareCatalog EventListener: [{$timestamp}] Exception - {$e->getMessage()}";
            error_log($errorMessage);
            
            try {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
                $logger->error(
                    'SoftwareCatalog: Critical exception in event listener',
                    [
                        'eventType' => $eventType,
                        'timestamp' => $timestamp,
                        'exception' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            } catch (\Exception $logException) {
                error_log("SoftwareCatalog EventListener: [{$timestamp}] Logger exception - {$logException->getMessage()}");
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
                'objectType' => gettype($object),
                'objectMethods' => get_class_methods($object)
            ]
        );
        
        // Handle contactpersoon creation - create inactive user
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        
        // Fix potential type mismatch by ensuring both are integers
        $contactpersoonSchemaIdInt = (int) $contactpersoonSchemaId;
        
        if ($contactpersoonSchemaId && $objectSchemaIdInt === $contactpersoonSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactpersoon schema - processing creation',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $contactpersoonSchemaId,
                    'objectData' => $object->getObject()
                ]
            );
            
            try {
                $result = $softwareCatalogueService->processContactpersoon($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon creation',
                    [
                        'objectId' => $objectId,
                        'result' => $result,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon creation',
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
        
        // Handle contactgegevens creation (backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->info(
                'SoftwareCatalog: Matched contactgegevens schema - processing creation (backward compatibility)',
                [
                    'objectId' => $objectId,
                    'schemaId' => $objectSchemaId,
                    'configuredSchemaId' => $contactgegevensSchemaId,
                    'objectData' => $object->getObject()
                ]
            );
            
            try {
                $result = $softwareCatalogueService->processContactgegevens($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens creation',
                    [
                        'objectId' => $objectId,
                        'result' => $result,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens creation',
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
            'SoftwareCatalog: Object creation not handled - focusing only on contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId
                ]
            ]
        );
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
            'SoftwareCatalog: Object update not handled - focusing only on contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'schemaIdType' => gettype($objectSchemaId),
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
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
        
        // Handle contactpersoon deletion
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        $contactpersoonSchemaIdInt = (int) $contactpersoonSchemaId;
        $objectSchemaIdInt = (int) $objectSchemaId;
        
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
            'SoftwareCatalog: Object deletion not handled - focusing only on contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'registerId' => $objectRegisterId,
                'handledSchemas' => [
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