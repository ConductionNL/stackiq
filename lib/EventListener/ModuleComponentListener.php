<?php

/**
 * Stackiq Module Component Listener
 *
 * Stops a module save that would make an application part of itself or of one of its components.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\Service\ModuleComponentGuard;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On a module's create and update, asks {@see ModuleComponentGuard} and, on a
 * refusal, stops the save with its message; nothing is stored.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
 */
class ModuleComponentListener implements IEventListener {

	/**
	 * @param ModuleComponentGuard       $guard   Decides whether the `partOf` may be saved.
	 * @param ObjectTypeLocatorInterface $locator Tells a module from other objects.
	 */
	public function __construct(
		private readonly ModuleComponentGuard $guard,
		private readonly ObjectTypeLocatorInterface $locator,
	) {
	}//end __construct()

	/**
	 * Check a module about to be created or updated.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$object = $event->getObject();
		} elseif ($event instanceof ObjectUpdatingEvent) {
			$object = $event->getNewObject();
		} else {
			return;
		}

		$moduleSchema = $this->locator->getSchemaIdForObjectType('module');
		if ($moduleSchema === null || (int)$object->getSchema() !== $moduleSchema) {
			return;
		}

		$data = array_merge(($object->getObject() ?? []), $event->getModifiedData());
		$refusal = $this->guard->refusal(data: $data, moduleUuid: $object->getUuid());
		if ($refusal === null) {
			return;
		}

		$event->setErrors(['message' => $refusal]);
		$event->stopPropagation();
	}//end handle()
}//end class
