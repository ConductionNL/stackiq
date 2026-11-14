<?php

/**
 * Object event payload.
 *
 * Shared by the subscribers that react to OpenRegister objects being
 * created or updated.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Shared event plumbing; carries no behaviour of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;

/**
 * Reads the object a created or updated event carries.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 */
trait ObjectEventPayload {

	/**
	 * Extract the object payload from a created or updated event.
	 *
	 * Returns null for unsupported event types or when the event carries no
	 * object (ObjectCreatedEvent → getObject(); ObjectUpdatedEvent →
	 * getNewObject()).
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return object|null The object payload or null
	 */
	private function extractObjectFromEvent(Event $event): ?object {
		if ($event instanceof ObjectCreatedEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end extractObjectFromEvent()
}//end trait
