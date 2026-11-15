<?php

/**
 * Unit tests for the ObjectEventPayload trait.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\ObjectEventPayload;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Which object a created or updated event hands its subscribers.
 *
 * @covers \OCA\Stackiq\EventListener\ObjectEventPayload
 */
class ObjectEventPayloadTest extends TestCase {

	/**
	 * Run the trait's extraction through a class that uses it.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null What the trait extracted.
	 */
	private function extract(Event $event): ?object {
		$subject = new class {
			use ObjectEventPayload;
		};

		return (new ReflectionMethod($subject, 'extractObjectFromEvent'))->invoke($subject, $event);
	}//end extract()

	/**
	 * A created event yields the created object.
	 *
	 * @return void
	 */
	public function testACreatedEventYieldsItsObject(): void {
		$object = $this->createMock(originalClassName: ObjectEntity::class);

		$this->assertSame($object, $this->extract(new ObjectCreatedEvent($object)));
	}//end testACreatedEventYieldsItsObject()

	/**
	 * An updated event yields the new object, never the old one.
	 *
	 * @return void
	 */
	public function testAnUpdatedEventYieldsTheNewObject(): void {
		$new = $this->createMock(originalClassName: ObjectEntity::class);
		$old = $this->createMock(originalClassName: ObjectEntity::class);

		$this->assertSame($new, $this->extract(new ObjectUpdatedEvent($new, $old)));
	}//end testAnUpdatedEventYieldsTheNewObject()

	/**
	 * Any other event yields nothing.
	 *
	 * @return void
	 */
	public function testAnyOtherEventYieldsNothing(): void {
		$this->assertNull($this->extract(new Event()));
		if (class_exists(ObjectDeletedEvent::class) === true) {
			$this->assertNull($this->extract(new ObjectDeletedEvent($this->createMock(originalClassName: ObjectEntity::class))));
		}
	}//end testAnyOtherEventYieldsNothing()
}//end class
