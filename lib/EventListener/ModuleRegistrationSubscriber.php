<?php
/**
 * Module Registration Subscriber.
 *
 * Event subscriber that auto-sets geregistreerdDoor on module objects
 * based on the owning organisation's type.
 *
 * @category  EventListener
 * @package   OCA\SoftwareCatalog\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

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
 *
 * @category EventListener
 * @package  OCA\SoftwareCatalog\EventListener
 */
class ModuleRegistrationSubscriber implements IEventListener
{
    /**
     * Constructor for ModuleRegistrationSubscriber.
     *
     * @param ContainerInterface $container The DI container
     */
    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }//end __construct()

    /**
     * Handle the event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
            return;
        }

        $object = null;
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
        } else if ($event instanceof ObjectUpdatedEvent) {
            $object = $event->getNewObject();
        }

        if ($object === null) {
            return;
        }

        $objectSchemaId = $object->getSchema();

        // Check if this is a module object.
        $settingsService = $this->container->get(SettingsService::class);
        $moduleSchemaId  = $settingsService->getSchemaIdForObjectType('module');

        if ($moduleSchemaId === null || (int) $objectSchemaId !== (int) $moduleSchemaId) {
            return;
        }

        try {
            $registrationSvc = $this->container->get(ModuleRegistrationService::class);
            $registrationSvc->handleModuleRegistration($object);
        } catch (\Exception $e) {
            $logger = $this->container->get(LoggerInterface::class);
            $logger->error(
                    'ModuleRegistrationSubscriber: Failed to handle module registration',
                    [
                        'objectId'  => $object->getId(),
                        'exception' => $e->getMessage(),
                    ]
                    );
        }
    }//end handle()
}//end class
