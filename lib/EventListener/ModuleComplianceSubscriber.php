<?php
/**
 * Module Compliance Subscriber.
 *
 * This file contains the subscriber class for handling module compliance updates
 * in the SoftwareCatalog application.
 *
 * @category  EventListener
 * @package   OCA\SoftwareCatalog\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCA\SoftwareCatalog\Service\ModuleComplianceService;
use OCA\SoftwareCatalog\Service\ModuleVersionService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * Event subscriber for handling module compliance updates.
 *
 * This subscriber listens for module object updates and automatically
 * synchronizes the 'standaarden' property based on linked compliance objects.
 *
 * @category EventListener
 * @package  OCA\SoftwareCatalog\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 */
class ModuleComplianceSubscriber implements IEventListener
{
    /**
     * Constructor for ModuleComplianceSubscriber.
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function handle(Event $event): void
    {
        // Log when subscriber is called for debugging.
        $logger = $this->container->get(LoggerInterface::class);
        $logger->info(
                'ModuleComplianceSubscriber: SUBSCRIBER CALLED',
                [
                    'eventType' => get_class($event),
                    'timestamp' => date('Y-m-d H:i:s'),
                ]
                );

        // Only handle ObjectCreatedEvent and ObjectUpdatedEvent.
        if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
            return;
        }

        // Get object from event - different methods for different event types.
        $object = null;
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
        } else if ($event instanceof ObjectUpdatedEvent) {
            // Use getNewObject() for updated events.
            $object = $event->getNewObject();
        }

        if ($object === null) {
            return;
        }

        $objectId       = $object->getId();
        $objectSchemaId = $object->getSchema();

        // Get module schema ID from configuration.
        $settingsService = $this->container->get(SettingsService::class);
        $moduleSchemaId  = $settingsService->getSchemaIdForObjectType('module');

        if ($moduleSchemaId === null) {
            $logger->debug(
                    'ModuleComplianceSubscriber: Module schema not configured, skipping',
                    [
                        'objectId' => $objectId,
                        'schemaId' => $objectSchemaId,
                    ]
                    );
            return;
        }

        $moduleSchemaIdInt = (int) $moduleSchemaId;
        $objectSchemaIdInt = (int) $objectSchemaId;

        // Check if this is a module object.
        if ($objectSchemaIdInt !== $moduleSchemaIdInt) {
            return;
        }

        try {
            // Handle module compliance update.
            $complianceSvc = $this->container->get(ModuleComplianceService::class);
            $complianceSvc->handleModuleComplianceUpdate($object);

            $logger->info(
                    'ModuleComplianceSubscriber: Successfully processed module compliance update',
                    [
                        'objectId'  => $objectId,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                    );
        } catch (\Exception $e) {
            $logger->error(
                    'ModuleComplianceSubscriber: Failed to process module compliance update',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
        }//end try

        // Ensure the module has at least one version (default 1.0.0).
        try {
            $moduleVersionService = $this->container->get(ModuleVersionService::class);
            $moduleVersionService->ensureDefaultVersion($object);
        } catch (\Exception $e) {
            $logger->error(
                    'ModuleComplianceSubscriber: Failed to ensure default module version',
                    [
                        'objectId'  => $objectId,
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ]
                    );
        }
    }//end handle()
}//end class
