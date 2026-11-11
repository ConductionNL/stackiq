<?php

/**
 * OpenRegister Events Debug Listener for Stackiq
 *
 * A comprehensive debug listener that handles all OpenRegister events for debugging purposes
 * within the Stackiq application. This listener logs detailed information about
 * OpenRegister events when debug mode is enabled.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Debug event listener for all OpenRegister events in Stackiq
 *
 * This listener provides comprehensive debugging information for all OpenRegister events
 * received by the Stackiq app. It logs event details at info level and can be
 * easily enabled/disabled.
 *
 * @template T of Event
 *
 * @implements IEventListener<T>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OpenRegisterEventsDebugListener implements IEventListener {

	/**
	 * Logger instance for debug logging
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Whether debug logging is enabled
	 *
	 * @var boolean
	 */
	private readonly bool $debugEnabled;

	/**
	 * Constructor for the debug listener
	 *
	 * @param LoggerInterface $logger Logger instance for debug output
	 * @param bool $debugEnabled Whether debug logging should be enabled
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function __construct(
		LoggerInterface $logger,
		bool $debugEnabled = true,
	) {
		$this->logger = $logger;
		$this->debugEnabled = $debugEnabled;

	}//end __construct()

	/**
	 * Handle any OpenRegister event for debugging purposes
	 *
	 * This method processes all OpenRegister events and logs detailed debug information
	 * including event type, object details, and any relevant metadata.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @phpstan-param T $event
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$eventType = $this->getEventTypeName(eventClass: $eventClass);

		$this->logTrigger(eventType: $eventType, eventClass: $eventClass);

		if ($this->debugEnabled === false) {
			$this->logger->warning('Stackiq OpenRegister Debug: Debug disabled, skipping detailed logging.');

			return;
		}

		$this->logEventDetails(eventType: $eventType, event: $event);

	}//end handle()

	/**
	 * Log that the listener was triggered.
	 *
	 * @param string $eventType  The simplified event type name
	 * @param string $eventClass The full event class name
	 *
	 * @return void
	 */
	private function logTrigger(string $eventType, string $eventClass): void {
		$this->logger->debug(
			'OpenRegister debug listener triggered',
			[
				'app' => 'stackiq',
				'eventType' => $eventType,
				'eventClass' => $eventClass,
				'debugEnabled' => $this->debugEnabled,
			]
		);
	}//end logTrigger()

	/**
	 * Log comprehensive debug information about the event.
	 *
	 * @param string $eventType The simplified event type name
	 * @param Event  $event     The event
	 *
	 * @return void
	 */
	private function logEventDetails(string $eventType, Event $event): void {
		$eventData = $this->extractEventData(event: $event);

		$this->logger->info(
			'[Stackiq] OPENREGISTER EVENT: {eventType} received from OpenRegister',
			[
				'app' => 'stackiq',
				'eventType' => $eventType,
				'eventClass' => get_class($event),
				'listenerClass' => self::class,
				'eventData' => $eventData,
				'timestamp' => date('Y-m-d H:i:s'),
				'source' => 'OpenRegister',
			]
		);
	}//end logEventDetails()

	/**
	 * Extract a human-readable event type name from the class name
	 *
	 * @param string $eventClass The full event class name
	 *
	 * @return string The simplified event type name
	 *
	 * @phpstan-return string
	 * @psalm-return   string
	 */
	private function getEventTypeName(string $eventClass): string {
		// Extract the class name without namespace.
		$className = substr($eventClass, strrpos($eventClass, '\\') + 1);

		// Remove 'Event' suffix if present.
		if (str_ends_with(haystack: $className, needle: 'Event') === true) {
			$className = substr($className, 0, -5);
		}

		return $className;
	}//end getEventTypeName()

	/**
	 * Extract relevant data from the event for debugging
	 *
	 * This method extracts useful information from different event types
	 * to provide comprehensive debug logging.
	 *
	 * @param Event $event The event to extract data from
	 *
	 * @return array<string, mixed> Array of extracted event data
	 *
	 * @phpstan-return array<string, mixed>
	 * @psalm-return   array<string, mixed>
	 */
	private function extractEventData(Event $event): array {
		$data = [
			'eventClass' => get_class($event),
		];

		$specific = $this->extractObjectEventData(event: $event);
		$specific ??= $this->extractRegisterEventData(event: $event);
		$specific ??= $this->extractSchemaEventData(event: $event);
		$specific ??= $this->extractOrganisationEventData(event: $event);

		if ($specific !== null) {
			return array_merge($data, $specific);
		}

		$data['eventType'] = 'Unknown';
		$data['note'] = 'Event type not specifically handled by Stackiq debug listener';
		return $data;
	}//end extractEventData()

	/**
	 * Project Object* events into a debug payload.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return array|null Payload, or null when the event is not an Object* event
	 */
	private function extractObjectEventData(Event $event): ?array {
		return $this->projectEvent(
			event: $event,
			projections: [
				ObjectCreatedEvent::class => $this->objectCreatedData(...),
				ObjectUpdatedEvent::class => $this->objectUpdatedData(...),
				ObjectDeletedEvent::class => $this->objectDeletedData(...),
				ObjectLockedEvent::class => $this->objectLockedData(...),
				ObjectUnlockedEvent::class => $this->objectUnlockedData(...),
				ObjectRevertedEvent::class => $this->objectRevertedData(...),
			]
		);
	}//end extractObjectEventData()

	/**
	 * Project an event with the first projection whose event class it is an instance of.
	 *
	 * @param Event                  $event       The dispatched event
	 * @param array<string, Closure> $projections Projection per event class, in check order
	 *
	 * @return array|null Payload, or null when no projection applies
	 */
	private function projectEvent(Event $event, array $projections): ?array {
		foreach ($projections as $eventClass => $project) {
			if ($event instanceof $eventClass) {
				return $project($event);
			}
		}

		return null;
	}//end projectEvent()

	/**
	 * The id, uuid, register and schema of an object.
	 *
	 * @param ObjectEntity $object The object
	 *
	 * @return array<string, mixed>
	 */
	private function objectIdentity(ObjectEntity $object): array {
		return [
			'objectId' => $object->getId(),
			'objectUuid' => $object->getUuid(),
			'registerId' => $object->getRegister(),
			'schemaId' => $object->getSchema(),
		];
	}//end objectIdentity()

	/**
	 * Payload of an ObjectCreated event.
	 *
	 * @param ObjectCreatedEvent $event The event
	 *
	 * @return array<string, mixed>
	 */
	private function objectCreatedData(ObjectCreatedEvent $event): array {
		$object = $event->getObject();
		return ['eventType' => 'ObjectCreated'] + $this->objectIdentity(object: $object) + [
			'owner' => $object->getOwner(),
			'created' => $object->getCreated()?->format('Y-m-d H:i:s'),
			'objectData' => $this->getSafeObjectData(objectData: $object->getObject()),
		];
	}//end objectCreatedData()

	/**
	 * Payload of an ObjectUpdated event: the new object, and the old one when known.
	 *
	 * @param ObjectUpdatedEvent $event The event
	 *
	 * @return array<string, mixed>
	 */
	private function objectUpdatedData(ObjectUpdatedEvent $event): array {
		$newObject = $event->getNewObject();
		$oldObject = $event->getOldObject();
		$oldObjectData = $oldObject?->getObject();

		return [
			'eventType' => 'ObjectUpdated',
			'newObjectId' => $newObject->getId(),
			'newObjectUuid' => $newObject->getUuid(),
		] + $this->oldObjectIdentity(oldObject: $oldObject) + $this->updatedObjectFacts(newObject: $newObject) + [
			'oldObjectData' => $oldObjectData,
		];
	}//end objectUpdatedData()

	/**
	 * Where the updated object lives, who owns it, when it changed and its (safe) data.
	 *
	 * @param ObjectEntity $newObject The object after the update
	 *
	 * @return array<string, mixed>
	 */
	private function updatedObjectFacts(ObjectEntity $newObject): array {
		return [
			'registerId' => $newObject->getRegister(),
			'schemaId' => $newObject->getSchema(),
			'owner' => $newObject->getOwner(),
			'updated' => $newObject->getUpdated()?->format('Y-m-d H:i:s'),
			'newObjectData' => $this->getSafeObjectData(objectData: $newObject->getObject()),
		];
	}//end updatedObjectFacts()

	/**
	 * The id and uuid of the object before an update, null when unknown.
	 *
	 * @param ObjectEntity|null $oldObject The object before the update
	 *
	 * @return array<string, mixed>
	 */
	private function oldObjectIdentity(?ObjectEntity $oldObject): array {
		return [
			'oldObjectId' => $oldObject?->getId(),
			'oldObjectUuid' => $oldObject?->getUuid(),
		];
	}//end oldObjectIdentity()

	/**
	 * Payload of an ObjectDeleted event.
	 *
	 * @param ObjectDeletedEvent $event The event
	 *
	 * @return array<string, mixed>
	 */
	private function objectDeletedData(ObjectDeletedEvent $event): array {
		$object = $event->getObject();
		return ['eventType' => 'ObjectDeleted'] + $this->objectIdentity(object: $object) + [
			'owner' => $object->getOwner(),
			'objectData' => $this->getSafeObjectData(objectData: $object->getObject()),
		];
	}//end objectDeletedData()

	/**
	 * Payload of an ObjectLocked event.
	 *
	 * @param ObjectLockedEvent $event The event
	 *
	 * @return array<string, mixed>
	 */
	private function objectLockedData(ObjectLockedEvent $event): array {
		$object = $event->getObject();
		return ['eventType' => 'ObjectLocked'] + $this->objectIdentity(object: $object) + [
			'lockedBy' => $object->getLockedBy(),
			'lockedAt' => null,
		];
	}//end objectLockedData()

	/**
	 * Payload of an ObjectUnlocked event.
	 *
	 * @param ObjectUnlockedEvent $event The event
	 *
	 * @return array<string, mixed>
	 */
	private function objectUnlockedData(ObjectUnlockedEvent $event): array {
		return ['eventType' => 'ObjectUnlocked'] + $this->objectIdentity(object: $event->getObject());
	}//end objectUnlockedData()

	/**
	 * Payload of an ObjectReverted event.
	 *
	 * @param ObjectRevertedEvent $event The event
	 *
	 * @return array<string, mixed>
	 */
	private function objectRevertedData(ObjectRevertedEvent $event): array {
		return ['eventType' => 'ObjectReverted'] + $this->objectIdentity(object: $event->getObject()) + [
			'revertedTo' => $event->getRevertPoint(),
		];
	}//end objectRevertedData()

	/**
	 * Project Register* events into a debug payload.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return array|null Payload, or null when not a Register* event
	 */
	private function extractRegisterEventData(Event $event): ?array {
		return $this->projectEvent(
			event: $event,
			projections: [
				RegisterCreatedEvent::class => fn (RegisterCreatedEvent $e) => $this->registerData(eventType: 'RegisterCreated', register: $e->getRegister()),
				RegisterUpdatedEvent::class => fn (RegisterUpdatedEvent $e) => $this->registerData(eventType: 'RegisterUpdated', register: $e->getNewRegister()),
				RegisterDeletedEvent::class => fn (RegisterDeletedEvent $e) => $this->registerData(eventType: 'RegisterDeleted', register: $e->getRegister()),
			]
		);
	}//end extractRegisterEventData()

	/**
	 * Payload of a Register* event.
	 *
	 * @param string $eventType The event type name
	 * @param object $register  The register
	 *
	 * @return array<string, mixed>
	 */
	private function registerData(string $eventType, object $register): array {
		return [
			'eventType' => $eventType,
			'registerId' => $register->getId(),
			'registerTitle' => $register->getTitle(),
			'registerSlug' => $register->getSlug(),
		];
	}//end registerData()

	/**
	 * Project Schema* events into a debug payload.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return array|null Payload, or null when not a Schema* event
	 */
	private function extractSchemaEventData(Event $event): ?array {
		return $this->projectEvent(
			event: $event,
			projections: [
				SchemaCreatedEvent::class => fn (SchemaCreatedEvent $e) => $this->schemaData(eventType: 'SchemaCreated', schema: $e->getSchema()),
				SchemaUpdatedEvent::class => fn (SchemaUpdatedEvent $e) => $this->schemaData(eventType: 'SchemaUpdated', schema: $e->getNewSchema()),
				SchemaDeletedEvent::class => fn (SchemaDeletedEvent $e) => $this->schemaData(eventType: 'SchemaDeleted', schema: $e->getSchema()),
			]
		);
	}//end extractSchemaEventData()

	/**
	 * Payload of a Schema* event.
	 *
	 * @param string $eventType The event type name
	 * @param object $schema    The schema
	 *
	 * @return array<string, mixed>
	 */
	private function schemaData(string $eventType, object $schema): array {
		return [
			'eventType' => $eventType,
			'schemaId' => $schema->getId(),
			'schemaTitle' => $schema->getTitle(),
			'schemaVersion' => $schema->getVersion(),
		];
	}//end schemaData()

	/**
	 * Project Organisation* events into a debug payload.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return array|null Payload, or null when not an Organisation* event
	 */
	private function extractOrganisationEventData(Event $event): ?array {
		if ($event instanceof OrganisationCreatedEvent) {
			$organisation = $event->getOrganisation();
			return [
				'eventType' => 'OrganisationCreated',
				'organisationId' => $organisation->getId(),
				'organisationTitle' => $organisation->getName(),
			];
		}

		return null;
	}//end extractOrganisationEventData()

	/**
	 * Get safe object data for logging (truncated if too large)
	 *
	 * @param mixed $objectData The object data to make safe for logging
	 *
	 * @return mixed The safe object data
	 *
	 * @phpstan-return mixed
	 * @psalm-return   mixed
	 */
	private function getSafeObjectData(mixed $objectData): mixed {
		// Convert to JSON string to check size.
		$jsonData = json_encode($objectData);

		// If the data is too large (>2KB), truncate it.
		if (strlen($jsonData) > 2048) {
			return [
				'_truncated' => true,
				'_originalSize' => strlen($jsonData),
				'_preview' => substr($jsonData, 0, 500) . '...',
				'_note' => 'Object data truncated for logging - too large to display fully',
			];
		}

		return $objectData;
	}//end getSafeObjectData()
}//end class
