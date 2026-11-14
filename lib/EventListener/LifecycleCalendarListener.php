<?php

/**
 * LifecycleCalendarListener
 *
 * Hands every saved contract and module version of the stackiq register to
 * the lifecycle calendar sync.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Service\LifecycleCalendarService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the lifecycle calendar events of saved contracts and module versions.
 *
 * The contract and module version schemas and registers are the ones the
 * settings resolve, never hard-coded ids. Any other object is ignored.
 *
 * @template-implements IEventListener<Event>
 */
class LifecycleCalendarListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LifecycleCalendarService   $calendar The lifecycle calendar sync.
	 * @param ObjectTypeLocatorInterface $settings Resolves the contract and module version schemas.
	 * @param LoggerInterface            $logger   The logger.
	 */
	public function __construct(
		private readonly LifecycleCalendarService $calendar,
		private readonly ObjectTypeLocatorInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-integration-leaves/tasks.md#2.2
	 */
	public function handle(Event $event): void {
		$object = null;
		if ($event instanceof ObjectCreatedEvent) {
			$object = $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$object = $event->getNewObject();
		}

		if ($object instanceof ObjectEntityInterface === false) {
			return;
		}

		try {
			if ($this->isOfType(object: $object, objectType: 'catalogContract') === true) {
				$this->calendar->syncContract(object: $this->payloadOf(object: $object));
				return;
			}

			if ($this->isOfType(object: $object, objectType: 'moduleVersion') === true) {
				$this->calendar->syncModuleVersion(object: $this->payloadOf(object: $object));
			}
		} catch (Throwable $e) {
			$this->logger->warning('LifecycleCalendarListener: could not sync the lifecycle calendar', ['error' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Whether an object is in the register and schema the settings give an object type.
	 *
	 * @param ObjectEntityInterface $object     The saved object.
	 * @param string                $objectType The object type.
	 *
	 * @return bool
	 */
	private function isOfType(ObjectEntityInterface $object, string $objectType): bool {
		$schema   = $this->settings->getSchemaIdForObjectType(objectType: $objectType);
		$register = $this->settings->getRegisterIdForObjectType(objectType: $objectType);
		if ($schema === null || $register === null) {
			return false;
		}

		return (string) $object->getSchema() === (string) $schema && (string) $object->getRegister() === (string) $register;
	}//end isOfType()

	/**
	 * The object as the calendar sync reads it.
	 *
	 * @param ObjectEntityInterface $object The saved object.
	 *
	 * @return array{uuid: string, register: string, schema: string, data: array<string, mixed>}
	 */
	private function payloadOf(ObjectEntityInterface $object): array {
		return [
			'uuid'     => (string) $object->getUuid(),
			'register' => (string) $object->getRegister(),
			'schema'   => (string) $object->getSchema(),
			'data'     => $object->getObject(),
		];
	}//end payloadOf()
}//end class
