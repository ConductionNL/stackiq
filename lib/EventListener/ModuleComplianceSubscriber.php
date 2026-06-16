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
     * On a module create/update event this derives the module's `standaarden`
     * from its linked compliancy records (see ModuleComplianceService) and
     * ensures the module has at least a default version.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
     */
    public function handle(Event $event): void
    {
        $logger = $this->container->get(LoggerInterface::class);
        $logger->info(
                'ModuleComplianceSubscriber: SUBSCRIBER CALLED',
                [
                    'eventType' => get_class($event),
                    'timestamp' => date('Y-m-d H:i:s'),
                ]
                );

        $object = $this->extractObjectFromEvent($event);
        if ($object === null) {
            return;
        }

        if ($this->isModuleObject($object, $logger) === false) {
            return;
        }

        $this->dispatchComplianceUpdate($object, $logger);
        $this->dispatchEnsureDefaultVersion($object, $logger);
    }//end handle()


    /**
     * Extract the object payload from a supported event type.
     *
     * Returns null for unsupported event types or when the event carries no
     * object (ObjectCreatedEvent → getObject(); ObjectUpdatedEvent →
     * getNewObject()).
     *
     * @param Event $event The dispatched event
     *
     * @return object|null The object payload or null
     */
    private function extractObjectFromEvent(Event $event): ?object
    {
        if ($event instanceof ObjectCreatedEvent) {
            return $event->getObject();
        }

        if ($event instanceof ObjectUpdatedEvent) {
            return $event->getNewObject();
        }

        return null;
    }//end extractObjectFromEvent()


    /**
     * Decide whether the given object is a module — i.e. its schema id
     * matches the configured module schema id.
     *
     * @param object          $object The object to check
     * @param LoggerInterface $logger Logger for the not-configured branch
     *
     * @return bool True when the object is a module
     */
    private function isModuleObject(object $object, LoggerInterface $logger): bool
    {
        $settingsService = $this->container->get(SettingsService::class);
        $moduleSchemaId  = $settingsService->getSchemaIdForObjectType('module');

        if ($moduleSchemaId === null) {
            $logger->debug(
                    'ModuleComplianceSubscriber: Module schema not configured, skipping',
                    [
                        'objectId' => $object->getId(),
                        'schemaId' => $object->getSchema(),
                    ]
                    );
            return false;
        }

        return ((int) $object->getSchema()) === ((int) $moduleSchemaId);
    }//end isModuleObject()


    /**
     * Dispatch the compliance-update flow with consistent error handling.
     *
     * @param object          $object The module object
     * @param LoggerInterface $logger Logger for success/error reporting
     *
     * @return void
     */
    private function dispatchComplianceUpdate(object $object, LoggerInterface $logger): void
    {
        try {
            $complianceSvc = $this->container->get(ModuleComplianceService::class);
            $complianceSvc->handleModuleComplianceUpdate($object);

            $logger->info(
                    'ModuleComplianceSubscriber: Successfully processed module compliance update',
                    [
                        'objectId'  => $object->getId(),
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                    );
        } catch (\Exception $e) {
            $logger->error(
                    'ModuleComplianceSubscriber: Failed to process module compliance update',
                    [
                        'objectId'  => $object->getId(),
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'trace'     => $e->getTraceAsString(),
                    ]
                    );
        }
    }//end dispatchComplianceUpdate()


    /**
     * Ensure the module has at least one (default 1.0.0) version.
     *
     * @param object          $object The module object
     * @param LoggerInterface $logger Logger for error reporting
     *
     * @return void
     */
    private function dispatchEnsureDefaultVersion(object $object, LoggerInterface $logger): void
    {
        try {
            $moduleVersionService = $this->container->get(ModuleVersionService::class);
            $moduleVersionService->ensureDefaultVersion($object);
        } catch (\Exception $e) {
            $logger->error(
                    'ModuleComplianceSubscriber: Failed to ensure default module version',
                    [
                        'objectId'  => $object->getId(),
                        'exception' => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ]
                    );
        }
    }//end dispatchEnsureDefaultVersion()
}//end class
