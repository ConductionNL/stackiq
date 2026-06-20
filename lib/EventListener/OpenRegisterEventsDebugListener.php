<?php

/**
 * OpenRegister Events Debug Listener for SoftwareCatalog
 *
 * A comprehensive debug listener that handles all OpenRegister events for debugging purposes
 * within the SoftwareCatalog application. This listener logs detailed information about
 * OpenRegister events when debug mode is enabled.
 *
 * @category EventListener
 * @package  OCA\SoftwareCatalog\EventListener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @version GIT: <git_id>
 *
 * @link https://SoftwareCatalog.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\EventListener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
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
use Psr\Log\LoggerInterface;

/**
 * Debug event listener for all OpenRegister events in SoftwareCatalog
 *
 * This listener provides comprehensive debugging information for all OpenRegister events
 * received by the SoftwareCatalog app. It logs event details at info level and can be
 * easily enabled/disabled.
 *
 * @template T of Event
 *
 * @implements IEventListener<T>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OpenRegisterEventsDebugListener implements IEventListener
{

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
     * @param LoggerInterface $logger       Logger instance for debug output
     * @param bool            $debugEnabled Whether debug logging should be enabled
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __construct(
        LoggerInterface $logger,
        bool $debugEnabled=true
    ) {
        $this->logger       = $logger;
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
     */
    public function handle(Event $event): void
    {
        $eventClass = get_class($event);
        $eventType  = $this->getEventTypeName(eventClass: $eventClass);

        $this->logger->debug(
                'OpenRegister debug listener triggered',
                [
                    'app'          => 'softwarecatalog',
                    'eventType'    => $eventType,
                    'eventClass'   => $eventClass,
                    'debugEnabled' => $this->debugEnabled,
                ]
                );

        if ($this->debugEnabled === false) {
            $this->logger->warning('SoftwareCatalog OpenRegister Debug: Debug disabled, skipping detailed logging.');

            return;
        }

        $eventData = $this->extractEventData(event: $event);

        // Log comprehensive debug information.
        $this->logger->info(
            '[SoftwareCatalog] OPENREGISTER EVENT: {eventType} received from OpenRegister',
            [
                'app'           => 'softwarecatalog',
                'eventType'     => $eventType,
                'eventClass'    => $eventClass,
                'listenerClass' => self::class,
                'eventData'     => $eventData,
                'timestamp'     => date('Y-m-d H:i:s'),
                'source'        => 'OpenRegister',
            ]
        );

    }//end handle()

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
    private function getEventTypeName(string $eventClass): string
    {
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
    private function extractEventData(Event $event): array
    {
        $data = [
            'eventClass' => get_class($event),
        ];

        $specific = $this->extractObjectEventData(event: $event) ?? $this->extractRegisterEventData(event: $event) ?? $this->extractSchemaEventData(event: $event) ?? $this->extractOrganisationEventData(event: $event);

        if ($specific !== null) {
            return array_merge($data, $specific);
        }

        $data['eventType'] = 'Unknown';
        $data['note']      = 'Event type not specifically handled by SoftwareCatalog debug listener';
        return $data;

    }//end extractEventData()

    /**
     * Project Object* events into a debug payload.
     *
     * @param Event $event The dispatched event
     *
     * @return array|null Payload, or null when the event is not an Object* event
     */
    private function extractObjectEventData(Event $event): ?array
    {
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
            return [
                'eventType'  => 'ObjectCreated',
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId'   => $object->getSchema(),
                'owner'      => $object->getOwner(),
                'created'    => $object->getCreated()?->format('Y-m-d H:i:s'),
                'objectData' => $this->getSafeObjectData(objectData: $object->getObject()),
            ];
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $newObject = $event->getNewObject();
            $oldObject = $event->getOldObject();
            return [
                'eventType'     => 'ObjectUpdated',
                'newObjectId'   => $newObject->getId(),
                'newObjectUuid' => $newObject->getUuid(),
                'oldObjectId'   => $oldObject?->getId(),
                'oldObjectUuid' => $oldObject?->getUuid(),
                'registerId'    => $newObject->getRegister(),
                'schemaId'      => $newObject->getSchema(),
                'owner'         => $newObject->getOwner(),
                'updated'       => $newObject->getUpdated()?->format('Y-m-d H:i:s'),
                'newObjectData' => $this->getSafeObjectData(objectData: $newObject->getObject()),
                'oldObjectData' => $oldObject !== null ? $oldObject->getObject() : null,
            ];
        }

        if ($event instanceof ObjectDeletedEvent) {
            $object = $event->getObject();
            return [
                'eventType'  => 'ObjectDeleted',
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId'   => $object->getSchema(),
                'owner'      => $object->getOwner(),
                'objectData' => $this->getSafeObjectData(objectData: $object->getObject()),
            ];
        }

        if ($event instanceof ObjectLockedEvent) {
            $object = $event->getObject();
            return [
                'eventType'  => 'ObjectLocked',
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId'   => $object->getSchema(),
                'lockedBy'   => $object->getLockedBy(),
                'lockedAt'   => null,
            ];
        }

        if ($event instanceof ObjectUnlockedEvent) {
            $object = $event->getObject();
            return [
                'eventType'  => 'ObjectUnlocked',
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId'   => $object->getSchema(),
            ];
        }

        if ($event instanceof ObjectRevertedEvent) {
            $object = $event->getObject();
            return [
                'eventType'  => 'ObjectReverted',
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'registerId' => $object->getRegister(),
                'schemaId'   => $object->getSchema(),
                'revertedTo' => $event->getRevertPoint(),
            ];
        }

        return null;

    }//end extractObjectEventData()

    /**
     * Project Register* events into a debug payload.
     *
     * @param Event $event The dispatched event
     *
     * @return array|null Payload, or null when not a Register* event
     */
    private function extractRegisterEventData(Event $event): ?array
    {
        if ($event instanceof RegisterCreatedEvent) {
            $register = $event->getRegister();
            return [
                'eventType'     => 'RegisterCreated',
                'registerId'    => $register->getId(),
                'registerTitle' => $register->getTitle(),
                'registerSlug'  => $register->getSlug(),
            ];
        }

        if ($event instanceof RegisterUpdatedEvent) {
            $register = $event->getNewRegister();
            return [
                'eventType'     => 'RegisterUpdated',
                'registerId'    => $register->getId(),
                'registerTitle' => $register->getTitle(),
                'registerSlug'  => $register->getSlug(),
            ];
        }

        if ($event instanceof RegisterDeletedEvent) {
            $register = $event->getRegister();
            return [
                'eventType'     => 'RegisterDeleted',
                'registerId'    => $register->getId(),
                'registerTitle' => $register->getTitle(),
                'registerSlug'  => $register->getSlug(),
            ];
        }

        return null;

    }//end extractRegisterEventData()

    /**
     * Project Schema* events into a debug payload.
     *
     * @param Event $event The dispatched event
     *
     * @return array|null Payload, or null when not a Schema* event
     */
    private function extractSchemaEventData(Event $event): ?array
    {
        if ($event instanceof SchemaCreatedEvent) {
            $schema = $event->getSchema();
            return [
                'eventType'     => 'SchemaCreated',
                'schemaId'      => $schema->getId(),
                'schemaTitle'   => $schema->getTitle(),
                'schemaVersion' => $schema->getVersion(),
            ];
        }

        if ($event instanceof SchemaUpdatedEvent) {
            $schema = $event->getNewSchema();
            return [
                'eventType'     => 'SchemaUpdated',
                'schemaId'      => $schema->getId(),
                'schemaTitle'   => $schema->getTitle(),
                'schemaVersion' => $schema->getVersion(),
            ];
        }

        if ($event instanceof SchemaDeletedEvent) {
            $schema = $event->getSchema();
            return [
                'eventType'     => 'SchemaDeleted',
                'schemaId'      => $schema->getId(),
                'schemaTitle'   => $schema->getTitle(),
                'schemaVersion' => $schema->getVersion(),
            ];
        }

        return null;

    }//end extractSchemaEventData()

    /**
     * Project Organisation* events into a debug payload.
     *
     * @param Event $event The dispatched event
     *
     * @return array|null Payload, or null when not an Organisation* event
     */
    private function extractOrganisationEventData(Event $event): ?array
    {
        if ($event instanceof OrganisationCreatedEvent) {
            $organisation = $event->getOrganisation();
            return [
                'eventType'         => 'OrganisationCreated',
                'organisationId'    => $organisation->getId(),
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
    private function getSafeObjectData(mixed $objectData): mixed
    {
        // Convert to JSON string to check size.
        $jsonData = json_encode($objectData);

        // If the data is too large (>2KB), truncate it.
        if (strlen($jsonData) > 2048) {
            return [
                '_truncated'    => true,
                '_originalSize' => strlen($jsonData),
                '_preview'      => substr($jsonData, 0, 500).'...',
                '_note'         => 'Object data truncated for logging - too large to display fully',
            ];
        }

        return $objectData;

    }//end getSafeObjectData()
}//end class
