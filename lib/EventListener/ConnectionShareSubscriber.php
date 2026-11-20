<?php

/**
 * Connection Share Subscriber
 *
 * Keeps a connection's participant organisations right on every usage save and delete.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Service\ConnectionShareSync;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * On create, update and delete of a usage that has or had participants,
 * recomputes `sharedWithOrganisations` on every connection it lists now or
 * listed before (ConnectionShareSync). A usage without participants never
 * changes a connection's sharing, so its save does nothing here.
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 * @SuppressWarnings(PHPMD.StaticAccess) ConnectionShareSync's static helpers are pure readers of the saved object.
 */
class ConnectionShareSubscriber implements IEventListener {
	use ObjectEventPayload;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Handle a created, updated or deleted object.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public function handle(Event $event): void {
		[$object, $deleted] = $this->changedUsage(event: $event);
		if ($object === null) {
			return;
		}

		[$usage, $previous] = $this->usageStates(event: $event, object: $object, deleted: $deleted);

		$connections = ConnectionShareSync::affectedConnections(usage: $usage, previous: $previous);
		if ($connections === []) {
			return;
		}

		try {
			$this->container->get(ConnectionShareSync::class)->sync(connections: $connections, deletedUsage: $deleted);
		} catch (Throwable $exception) {
			$this->container->get(LoggerInterface::class)->error(
				'ConnectionShareSubscriber: could not update the connections of a shared usage',
				['usage' => $object->getUuid(), 'exception' => $exception->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The usage an event is about, with its uuid when the event deleted it.
	 *
	 * @param Event $event The object event.
	 *
	 * @return array{0: ?object, 1: ?string} The usage (null when the event is not about one) and the deleted uuid.
	 */
	private function changedUsage(Event $event): array {
		$deleted = null;
		$object  = $this->extractObjectFromEvent(event: $event);
		if ($object === null && $event instanceof ObjectDeletedEvent) {
			$object  = $event->getObject();
			$deleted = $object->getUuid();
		}

		if ($object === null || $this->isUsage(object: $object) === false) {
			return [null, null];
		}

		return [$object, $deleted];

	}//end changedUsage()

	/**
	 * The usage after and before the event: a deleted usage has only a before.
	 *
	 * @param Event        $event   The object event.
	 * @param object       $object  The usage.
	 * @param string|null  $deleted The deleted uuid, or null.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The usage now and before.
	 */
	private function usageStates(Event $event, object $object, ?string $deleted): array {
		$usage    = [];
		$previous = (array)$object->getObject();
		if ($deleted === null) {
			$usage    = $previous;
			$previous = [];
		}

		if ($event instanceof ObjectUpdatedEvent && $event->getOldObject() !== null) {
			$previous = (array)$event->getOldObject()->getObject();
		}

		return [$usage, $previous];

	}//end usageStates()

	/**
	 * Whether the object is a usage: its schema is the configured usage schema.
	 *
	 * @param object $object The object.
	 *
	 * @return bool
	 */
	private function isUsage(object $object): bool {
		$schemaId = $this->container->get(ObjectTypeLocatorInterface::class)->getSchemaIdForObjectType('usage');
		if ($schemaId === null) {
			return false;
		}

		return ((int)$object->getSchema()) === ((int)$schemaId);
	}//end isUsage()
}//end class
