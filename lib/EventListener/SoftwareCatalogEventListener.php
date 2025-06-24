<?php

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
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenConnector
 * @version  1.0.0
 * @todo     This listener should be moved to the software catalog app
 */
class SoftwareCatalogEventListener implements IEventListener
{
    /**
     * Constructor for SoftwareCatalogEventListener
     *
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param SettingsService         $settingsService         The settings service
     * @param LoggerInterface         $logger                  The logger instance
     */
    public function __construct(
        private readonly SoftwareCatalogueService $softwareCatalogueService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handles events related to software catalog objects
     *
     * @param Event $event The event to handle
     * @return void
     */
    public function handle(Event $event): void
    {
        // Handle object creation
        if ($event instanceof ObjectCreatedEvent) {
            $this->handleObjectCreated($event);
            return;
        }

        // Handle object updates
        if ($event instanceof ObjectUpdatedEvent) {
            $this->handleObjectUpdated($event);
            return;
        }

        // Handle object deletion
        if ($event instanceof ObjectDeletedEvent) {
            $this->handleObjectDeleted($event);
            return;
        }

        // Handle object locking
        if ($event instanceof ObjectLockedEvent) {
            $this->handleObjectLocked($event);
            return;
        }

        // Handle object unlocking
        if ($event instanceof ObjectUnlockedEvent) {
            $this->handleObjectUnlocked($event);
            return;
        }

        // Handle object reversion
        if ($event instanceof ObjectRevertedEvent) {
            $this->handleObjectReverted($event);
            return;
        }
    }

    /**
     * Handles object creation events
     *
     * @param ObjectCreatedEvent $event The creation event
     * @return void
     */
    private function handleObjectCreated(ObjectCreatedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();
        
        // Handle organization creation - send welcome email
        $organizationSchemaId = $this->settingsService->getSchemaIdForObjectType('organization');
        if ($organizationSchemaId && $objectSchemaId === $organizationSchemaId) {
            try {
                $this->softwareCatalogueService->handleNewOrganization($object);
                $this->softwareCatalogueService->sendOrganizationWelcomeEmail($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to handle new organization: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
            return;
        }

        // Handle contact creation - create user if none exists
        $contactSchemaId = $this->settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            try {
                $this->softwareCatalogueService->handleNewContact($object);
                $this->softwareCatalogueService->createUserForContactIfNotExists($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to handle new contact: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) creation - send welcome email
        $gebruikerSchemaId = $this->settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            try {
                $this->softwareCatalogueService->handleNewGebruiker($object);
                $this->softwareCatalogueService->sendGebruikerWelcomeEmail($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to handle new gebruiker: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
        }
    }

    /**
     * Handles object update events
     *
     * @param ObjectUpdatedEvent $event The update event
     * @return void
     */
    private function handleObjectUpdated(ObjectUpdatedEvent $event): void
    {
        $object = $event->getNewObject();
        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Handle contact updates - create user if none exists
        $contactSchemaId = $this->settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            try {
                $this->softwareCatalogueService->handleContactUpdate($object);
                $this->softwareCatalogueService->createUserForContactIfNotExists($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to handle contact update: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) updates
        $gebruikerSchemaId = $this->settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            try {
                $this->softwareCatalogueService->handleGebruikerUpdate($object, $event->getOldObject());
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to handle gebruiker update: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
        }
    }

    /**
     * Handles object deletion events
     *
     * @param ObjectDeletedEvent $event The deletion event
     * @return void
     */
    private function handleObjectDeleted(ObjectDeletedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Handle contact deletion
        $contactSchemaId = $this->settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            try {
                $this->softwareCatalogueService->handleContactDeletion($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to handle contact deletion: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) deletion - block the user
        $gebruikerSchemaId = $this->settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            try {
                $this->softwareCatalogueService->blockUserForGebruiker($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to block user for deleted gebruiker: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
        }
    }

    /**
     * Handles object locking events
     *
     * @param ObjectLockedEvent $event The locking event
     * @return void
     */
    private function handleObjectLocked(ObjectLockedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Handle gebruiker (user) locking - temporarily block user access
        $gebruikerSchemaId = $this->settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            try {
                $this->softwareCatalogueService->temporarilyBlockUserForGebruiker($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to temporarily block user for locked gebruiker: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
        }
    }

    /**
     * Handles object unlocking events
     *
     * @param ObjectUnlockedEvent $event The unlocking event
     * @return void
     */
    private function handleObjectUnlocked(ObjectUnlockedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Handle gebruiker (user) unlocking - restore user access
        $gebruikerSchemaId = $this->settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            try {
                $this->softwareCatalogueService->restoreUserAccessForGebruiker($object);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to restore user access for unlocked gebruiker: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object
                    ]
                );
            }
        }
    }

    /**
     * Handles object reversion events
     *
     * @param ObjectRevertedEvent $event The reversion event
     * @return void
     */
    private function handleObjectReverted(ObjectRevertedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Handle contact reversion - sync user state with reverted contact
        $contactSchemaId = $this->settingsService->getSchemaIdForObjectType('contact');
        if ($contactSchemaId && $objectSchemaId === $contactSchemaId) {
            try {
                $this->softwareCatalogueService->syncUserWithRevertedContact($object, $event->getRevertPoint());
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to sync user with reverted contact: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object,
                        'revertPoint' => $event->getRevertPoint()
                    ]
                );
            }
            return;
        }

        // Handle gebruiker (user) reversion - update user based on reverted state
        $gebruikerSchemaId = $this->settingsService->getSchemaIdForObjectType('gebruiker');
        if ($gebruikerSchemaId && $objectSchemaId === $gebruikerSchemaId) {
            try {
                $this->softwareCatalogueService->updateUserFromRevertedGebruiker($object, $event->getRevertPoint());
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to update user from reverted gebruiker: ' . $e->getMessage(),
                    [
                        'exception' => $e,
                        'object' => $object,
                        'revertPoint' => $event->getRevertPoint()
                    ]
                );
            }
        }
    }
} 