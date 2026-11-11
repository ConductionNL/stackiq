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
	use ObjectEventPayload;

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
		$object = $this->extractObjectFromEvent(event: $event);
		if ($object === null || $this->isModuleObject(object: $object) === false) {
			return;
		}

		$this->registerModule(object: $object);
	}//end handle()

	/**
	 * Whether an object belongs to the configured module schema.
	 *
	 * @param object $object The created or updated object
	 *
	 * @return bool True when a module schema is configured and the object's schema is it.
	 */
	private function isModuleObject(object $object): bool {
		$objectSchemaId = $object->getSchema();

		$settingsService = $this->container->get(SettingsService::class);
		$moduleSchemaId  = $settingsService->getSchemaIdForObjectType('module');

		return $moduleSchemaId !== null && (int)$objectSchemaId === (int)$moduleSchemaId;
	}//end isModuleObject()

	/**
	 * Run the module registration, logging rather than throwing on failure.
	 *
	 * @param object $object The module object
	 *
	 * @return void
	 */
	private function registerModule(object $object): void {
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
	}//end registerModule()
}//end class
