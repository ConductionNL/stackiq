<?php

/**
 * Module Registration Subscriber.
 *
 * Event subscriber that auto-sets registeredBy on module objects
 * based on the owning organisation's type.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Event subscriber that auto-sets registeredBy on module objects
 * based on the owning organisation's type.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 */
class ModuleRegistrationSubscriber implements IEventListener {
	/**
	 * Constructor for ModuleRegistrationSubscriber.
	 *
	 * @param ContainerInterface $container The DI container
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$object = null;
		if ($event instanceof ObjectCreatedEvent) {
			$object = $event->getObject();
		} elseif ($event instanceof ObjectUpdatedEvent) {
			$object = $event->getNewObject();
		}

		if ($object === null) {
			return;
		}

		$objectSchemaId = $object->getSchema();

		// Check if this is a module object.
		$settingsService = $this->container->get(SettingsService::class);
		$moduleSchemaId = $settingsService->getSchemaIdForObjectType('module');

		if ($moduleSchemaId === null || (int)$objectSchemaId !== (int)$moduleSchemaId) {
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
					'objectId' => $object->getId(),
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end handle()
}//end class
