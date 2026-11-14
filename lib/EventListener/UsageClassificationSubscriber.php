<?php

/**
 * Usage Classification Subscriber
 *
 * Keeps a usage's overall BBN level and its classification stamp right on every save.
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
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\Service\OpenRegisterLookup;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\UsageClassification;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * On create and update of a usage, sets `bbnLevel` to the highest of its three
 * aspects and stamps `classifiedAt` and `classifiedBy` when an aspect changed
 * (security-baseline-classification D2). An API write keeps the level right
 * too, since every save passes here. The write-back is itself a save, so it
 * comes back as an update event; it then changes nothing and writes nothing.
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
 */
class UsageClassificationSubscriber implements IEventListener {
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
	 * Handle a created or updated object.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
	 */
	public function handle(Event $event): void {
		$object = $this->extractObjectFromEvent(event: $event);
		if ($object === null || $this->isUsage(object: $object) === false) {
			return;
		}

		$previous = [];
		if ($event instanceof ObjectUpdatedEvent && $event->getOldObject() !== null) {
			$previous = (array)$event->getOldObject()->getObject();
		}

		$usage = (array)$object->getObject();
		$patch = UsageClassification::patch(
			usage: $usage,
			previous: $previous,
			userId: $this->userId(),
			now: gmdate('Y-m-d\TH:i:s\Z')
		);
		if ($patch === []) {
			return;
		}

		try {
			$this->container->get(OpenRegisterLookup::class)->objectService()->saveObject(
				object: array_merge($usage, $patch),
				extend: [],
				register: $object->getRegister(),
				schema: $object->getSchema(),
				uuid: $object->getUuid()
			);
		} catch (\Throwable $exception) {
			$this->container->get(LoggerInterface::class)->error(
				'UsageClassificationSubscriber: could not write the derived BBN level',
				['objectId' => $object->getId(), 'exception' => $exception->getMessage()]
			);
		}
	}//end handle()

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

	/**
	 * The user who saved, or an empty string for a save without a session.
	 *
	 * @return string
	 */
	private function userId(): string {
		$user = $this->container->get(IUserSession::class)->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end userId()
}//end class
