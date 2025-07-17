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
        // Log all incoming events for debugging
        $eventType = get_class($event);
        error_log('SoftwareCatalog EventListener: Received event - ' . $eventType);

        try {
            // Get services from the server container like OpenCatalogi does
            $softwareCatalogueService = \OC::$server->get(SoftwareCatalogueService::class);
            $settingsService = \OC::$server->get(SettingsService::class);
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            $logger->info(
                'SoftwareCatalog: Received event',
                [
                    'eventType' => $eventType,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
            
            // Log current configuration to debug schema mapping
            try {
                $currentSettings = $settingsService->getSettings();
                $logger->info(
                    'SoftwareCatalog: Current configuration at event time',
                    [
                        'eventType' => $eventType,
                        'organizationSchemaId' => $settingsService->getSchemaIdForObjectType('organization'),
                        'organisatieSchemaId' => $settingsService->getSchemaIdForObjectType('organisatie'),
                        'contactSchemaId' => $settingsService->getSchemaIdForObjectType('contact'),
                        'gebruikerSchemaId' => $settingsService->getSchemaIdForObjectType('gebruiker'),
                        'contactgegevensSchemaId' => $settingsService->getSchemaIdForObjectType('contactgegevens'),
                        'configuration' => $currentSettings['configuration'] ?? 'No configuration found'
                    ]
                );
            } catch (\Exception $e) {
                $logger->warning(
                    'SoftwareCatalog: Failed to get current configuration',
                    [
                        'eventType' => $eventType,
                        'error' => $e->getMessage()
                    ]
                );
            }

            // Add extra debug for update events
            if ($event instanceof ObjectUpdatedEvent) {
                $object = $event->getNewObject();
                if ($object) {
                    $logger->info(
                        'SoftwareCatalog: ObjectUpdatedEvent details',
                        [
                            'objectId' => $object->getUuid(),
                            'schemaId' => $object->getSchema(),
                            'registerId' => $object->getRegister(),
                            'objectData' => json_encode($object->getObject())
                        ]
                    );
                }
            }

            // Handle object creation
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
                    'eventType' => $eventType
                ]
            );

        } catch (\Exception $e) {
            // Log unexpected errors and continue gracefully
            error_log('SoftwareCatalog EventListener: Exception - ' . $e->getMessage());
            try {
                $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
                $logger->error(
                    'SoftwareCatalog: Exception in event listener',
                    [
                        'exception' => $e->getMessage(),
                        'eventType' => $eventType,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            } catch (\Exception $logException) {
                error_log('SoftwareCatalog EventListener: Logger exception - ' . $logException->getMessage());
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
        
        // Convert schema ID to integer for consistent comparison
        $objectSchemaIdInt = (int) $objectSchemaId;
        
        $logger->info(
            'SoftwareCatalog: Processing object creation',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId
            ]
        );
        
        // Handle contactpersoon creation - create inactive user
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        
        // Fix potential type mismatch by ensuring both are integers
        $contactpersoonSchemaIdInt = (int) $contactpersoonSchemaId;
        
        if ($contactpersoonSchemaId && $objectSchemaIdInt === $contactpersoonSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing contactpersoon creation');
            try {
                $softwareCatalogueService->processContactpersoon($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon creation',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contactgegevens creation - process username (for backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        
        // Fix potential type mismatch by ensuring both are integers
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing contactgegevens creation (backward compatibility)');
            try {
                $softwareCatalogueService->processContactgegevens($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens creation',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
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
        if ($object === null) {
            $logger->warning('SoftwareCatalog: ObjectUpdatedEvent received with null object');
            return;
        }

        $objectSchemaId = $object->getSchema();
        $objectId = $object->getUuid();
        
        // Convert object schema ID to integer for consistent comparison
        $objectSchemaIdInt = (int) $objectSchemaId;
        
        $logger->info(
            'SoftwareCatalog: Processing object update',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt
            ]
        );

        // Handle contactpersoon updates - create inactive user if needed
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        
        // Fix potential type mismatch by ensuring both are integers
        $contactpersoonSchemaIdInt = (int) $contactpersoonSchemaId;
        
        if ($contactpersoonSchemaId && $objectSchemaIdInt === $contactpersoonSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing contactpersoon update');
            try {
                $oldObject = $event->getOldObject();
                if ($oldObject) {
                    // Use the new method that checks for role changes
                    $softwareCatalogueService->handleContactpersoonUpdate($object, $oldObject);
                } else {
                    // Fallback to regular processing if no old object
                    $softwareCatalogueService->processContactpersoon($object);
                }
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon update',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactpersoon update',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contactgegevens updates - process username and role changes (for backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        
        // Fix potential type mismatch by ensuring both are integers
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing contactgegevens update (backward compatibility)');
            try {
                $oldObject = $event->getOldObject();
                if ($oldObject) {
                    // Use the new method that checks for role changes
                    $softwareCatalogueService->handleContactgegevensUpdate($object, $oldObject);
                } else {
                    // Fallback to regular processing if no old object
                    $softwareCatalogueService->processContactgegevens($object);
                }
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens update',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process contactgegevens update',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Log if we don't handle this schema type
        $logger->info(
            'SoftwareCatalog: Object update not handled - focusing only on contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'schemaIdInt' => $objectSchemaIdInt,
                'schemaIdType' => gettype($objectSchemaId),
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
        
        $logger->info(
            'SoftwareCatalog: Processing object deletion',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId
            ]
        );

        // Handle contactpersoon deletion
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        if ($contactpersoonSchemaId && $objectSchemaId === $contactpersoonSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contactpersoon deletion');
            try {
                $softwareCatalogueService->handleContactDeletion($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon deletion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle contactpersoon deletion',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contactgegevens deletion (for backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        if ($contactgegevensSchemaId && $objectSchemaId === $contactgegevensSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contactgegevens deletion');
            try {
                $softwareCatalogueService->handleContactDeletion($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens deletion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle contactgegevens deletion',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
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
                'schemaId' => $objectSchemaId
            ]
        );

        // Log if we don't handle this schema type (we don't handle locking for contactpersonen)
        $logger->debug(
            'SoftwareCatalog: Object locking not handled - focusing only on contactpersonen creation/updates',
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
                'schemaId' => $objectSchemaId
            ]
        );

        // Log if we don't handle this schema type (we don't handle unlocking for contactpersonen)
        $logger->debug(
            'SoftwareCatalog: Object unlocking not handled - focusing only on contactpersonen creation/updates',
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
                'revertPoint' => $event->getRevertPoint()
            ]
        );

        // Handle contactpersoon reversion - sync user state with reverted contact
        $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
        if ($contactpersoonSchemaId && $objectSchemaId === $contactpersoonSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contactpersoon reversion');
            try {
                $softwareCatalogueService->syncUserWithRevertedContact($object, $event->getRevertPoint());
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactpersoon reversion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to sync user with reverted contactpersoon',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'revertPoint' => $event->getRevertPoint(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contactgegevens reversion (for backward compatibility)
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        if ($contactgegevensSchemaId && $objectSchemaId === $contactgegevensSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contactgegevens reversion');
            try {
                $softwareCatalogueService->syncUserWithRevertedContact($object, $event->getRevertPoint());
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contactgegevens reversion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to sync user with reverted contactgegevens',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'revertPoint' => $event->getRevertPoint(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Log if we don't handle this schema type
        $logger->debug(
            'SoftwareCatalog: Object reversion not handled - focusing only on contactpersonen',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'handledSchemas' => [
                    'contactpersoon' => $contactpersoonSchemaId,
                    'contactgegevens' => $contactgegevensSchemaId
                ]
            ]
        );
    }
} 