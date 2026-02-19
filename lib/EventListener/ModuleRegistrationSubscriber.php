<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCA\SoftwareCatalog\Service\ModuleRegistrationService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * Event subscriber that auto-sets geregistreerdDoor on module objects
 * based on the owning organisation's type.
 */
class ModuleRegistrationSubscriber implements IEventListener
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof ObjectCreatedEvent) && !($event instanceof ObjectUpdatedEvent)) {
            return;
        }

        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
        } elseif ($event instanceof ObjectUpdatedEvent) {
            $object = $event->getNewObject();
        } else {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Check if this is a module object.
        $settingsService = $this->container->get(SettingsService::class);
        $moduleSchemaId = $settingsService->getSchemaIdForObjectType('module');

        if (!$moduleSchemaId || (int) $objectSchemaId !== (int) $moduleSchemaId) {
            return;
        }

        try {
            $moduleRegistrationService = $this->container->get(ModuleRegistrationService::class);
            $moduleRegistrationService->handleModuleRegistration($object);
        } catch (\Exception $e) {
            $logger = $this->container->get(LoggerInterface::class);
            $logger->error('ModuleRegistrationSubscriber: Failed to handle module registration', [
                'objectId' => $object->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
