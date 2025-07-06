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
            $logger = \OC::$server->get(LoggerInterface::class);

            $logger->info(
                'SoftwareCatalog: Received event',
                [
                    'eventType' => $eventType
                ]
            );

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
                $logger = \OC::$server->get(LoggerInterface::class);
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
        
        // Handle organization creation - process groups and send welcome email
        $organizationSchemaId = $settingsService->getSchemaIdForObjectType('organization');
        // Fix potential type mismatch by ensuring both are integers
        $organizationSchemaIdInt = (int) $organizationSchemaId;
        if ($organizationSchemaId && $objectSchemaIdInt === $organizationSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing organization creation');
            try {
                $softwareCatalogueService->processOrganization($object);
                $softwareCatalogueService->handleNewOrganization($object);
                $softwareCatalogueService->sendOrganizationWelcomeEmail($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed organization creation',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle new organization',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contact creation - create user if none exists
        $contactSchemaId = $settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contact creation');
            try {
                $softwareCatalogueService->handleNewContact($object);
                $softwareCatalogueService->createUserForContactIfNotExists($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contact creation',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle new contact',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) creation - send welcome email
        $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            $logger->debug('SoftwareCatalog: Processing gebruiker creation');
            try {
                $softwareCatalogueService->handleNewGebruiker($object);
                $softwareCatalogueService->sendGebruikerWelcomeEmail($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruiker creation',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle new gebruiker',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contactgegevens creation - process username
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        
        // Fix potential type mismatch by ensuring both are integers
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing contactgegevens creation');
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
            'SoftwareCatalog: Object creation not handled - no matching schema',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'knownSchemas' => [
                    'organization' => $organizationSchemaId,
                    'contact' => $contactSchemaId,
                    'gebruiker' => $gebruikerSchemaId,
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
        
        $logger->info(
            'SoftwareCatalog: Processing object update',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId
            ]
        );

        // Handle contact updates - create user if none exists
        $contactSchemaId = $settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contact update');
            try {
                $softwareCatalogueService->handleContactUpdate($object);
                $softwareCatalogueService->createUserForContactIfNotExists($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contact update',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle contact update',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle organization updates - process groups and check for beoordeling changes
        $organizationSchemaId = $settingsService->getSchemaIdForObjectType('organization');
        // Fix potential type mismatch by ensuring both are integers
        $organizationSchemaIdInt = (int) $organizationSchemaId;
        if ($organizationSchemaId && $objectSchemaIdInt === $organizationSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing organization update');
            try {
                $oldObject = $event->getOldObject();
                if ($oldObject) {
                    // Use the new method that checks for beoordeling changes
                    $softwareCatalogueService->handleOrganizationUpdate($object, $oldObject);
                } else {
                    // Fallback to regular processing if no old object
                    $softwareCatalogueService->processOrganization($object);
                }
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed organization update',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to process organization update',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) updates
        $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            $logger->debug('SoftwareCatalog: Processing gebruiker update');
            try {
                $softwareCatalogueService->handleGebruikerUpdate($object, $event->getOldObject());
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruiker update',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle gebruiker update',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle contactgegevens updates - process username and role changes
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        
        // Fix potential type mismatch by ensuring both are integers
        $objectSchemaIdInt = (int) $objectSchemaId;
        $contactgegevensSchemaIdInt = (int) $contactgegevensSchemaId;
        
        if ($contactgegevensSchemaId && $objectSchemaIdInt === $contactgegevensSchemaIdInt) {
            $logger->debug('SoftwareCatalog: Processing contactgegevens update');
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
        $logger->debug(
            'SoftwareCatalog: Object update not handled - no matching schema',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'knownSchemas' => [
                    'contact' => $contactSchemaId,
                    'organization' => $organizationSchemaId,
                    'gebruiker' => $gebruikerSchemaId,
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

        // Handle contact deletion
        $contactSchemaId = $settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contact deletion');
            try {
                $softwareCatalogueService->handleContactDeletion($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contact deletion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to handle contact deletion',
                    [
                        'exception' => $e->getMessage(),
                        'objectId' => $objectId,
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) deletion - block the user
        $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            $logger->debug('SoftwareCatalog: Processing gebruiker deletion');
            try {
                $softwareCatalogueService->blockUserForGebruiker($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruiker deletion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to block user for deleted gebruiker',
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
            'SoftwareCatalog: Object deletion not handled - no matching schema',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'knownSchemas' => [
                    'contact' => $contactSchemaId,
                    'gebruiker' => $gebruikerSchemaId
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

        // Handle gebruiker (user) locking - temporarily block user access
        $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            $logger->debug('SoftwareCatalog: Processing gebruiker locking');
            try {
                $softwareCatalogueService->temporarilyBlockUserForGebruiker($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruiker locking',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to temporarily block user for locked gebruiker',
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
            'SoftwareCatalog: Object locking not handled - no matching schema',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'knownSchemas' => [
                    'gebruiker' => $gebruikerSchemaId
                ]
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

        // Handle gebruiker (user) unlocking - restore user access
        $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            $logger->debug('SoftwareCatalog: Processing gebruiker unlocking');
            try {
                $softwareCatalogueService->restoreUserAccessForGebruiker($object);
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruiker unlocking',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to restore user access for unlocked gebruiker',
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
            'SoftwareCatalog: Object unlocking not handled - no matching schema',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'knownSchemas' => [
                    'gebruiker' => $gebruikerSchemaId
                ]
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

        // Handle contact reversion - sync user state with reverted contact
        $contactSchemaId = $settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            $logger->debug('SoftwareCatalog: Processing contact reversion');
            try {
                $softwareCatalogueService->syncUserWithRevertedContact($object, $event->getRevertPoint());
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed contact reversion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to sync user with reverted contact',
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

        // Handle gebruiker (user) reversion - update user based on reverted state
        $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            $logger->debug('SoftwareCatalog: Processing gebruiker reversion');
            try {
                $softwareCatalogueService->updateUserFromRevertedGebruiker($object, $event->getRevertPoint());
                
                $logger->info(
                    'SoftwareCatalog: Successfully processed gebruiker reversion',
                    [
                        'objectId' => $objectId
                    ]
                );
            } catch (\Exception $e) {
                $logger->error(
                    'SoftwareCatalog: Failed to update user from reverted gebruiker',
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
            'SoftwareCatalog: Object reversion not handled - no matching schema',
            [
                'objectId' => $objectId,
                'schemaId' => $objectSchemaId,
                'knownSchemas' => [
                    'contact' => $contactSchemaId,
                    'gebruiker' => $gebruikerSchemaId
                ]
            ]
        );
    }
} 