<?php

/**
 * Golden test for the debug payloads of OpenRegisterEventsDebugListener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

require_once __DIR__ . '/openregister-debug-event-doubles.php';

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
use OCA\Stackiq\EventListener\OpenRegisterEventsDebugListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the payload extracted for every OpenRegister event family.
 */
class OpenRegisterEventsDebugListenerGoldenTest extends TestCase {
	/**
	 * Build an object entity double.
	 *
	 * @param string $suffix  Distinguishes the values.
	 * @param array  $payload The object payload.
	 *
	 * @return DebugListenerObjectEntity
	 */
	private function entity(string $suffix, array $payload): DebugListenerObjectEntity {
		return new DebugListenerObjectEntity(
			[
				'id' => 'id-' . $suffix,
				'uuid' => 'uuid-' . $suffix,
				'register' => 'reg-' . $suffix,
				'schema' => 'sch-' . $suffix,
				'owner' => 'owner-' . $suffix,
				'object' => $payload,
				'created' => new \DateTime('2026-01-02 03:04:05'),
				'updated' => null,
				'lockedBy' => 'locker-' . $suffix,
			]
		);
	}

	/**
	 * Every event family, with small and truncated payloads.
	 *
	 * @return void
	 */
	public function testPayloadPerEvent(): void {
		$small    = ['name' => 'Small'];
		$large    = ['blob' => str_repeat('x', 3000)];
		$register = new DebugListenerEntity(['id' => 7, 'title' => 'Reg', 'slug' => 'reg']);
		$schema   = new DebugListenerEntity(['id' => 8, 'title' => 'Sch', 'version' => '1.2']);
		$org      = new DebugListenerEntity(['id' => 9, 'name' => 'Org']);
		$events   = [
			'created'       => new ObjectCreatedEvent($this->entity('a', $small)),
			'created-large' => new ObjectCreatedEvent($this->entity('b', $large)),
			'updated'       => new ObjectUpdatedEvent($this->entity('n', $large), $this->entity('o', $small)),
			'updated-new'   => new ObjectUpdatedEvent($this->entity('n', $small)),
			'deleted'       => new ObjectDeletedEvent($this->entity('d', $small)),
			'locked'        => new ObjectLockedEvent(['getObject' => $this->entity('l', $small)]),
			'unlocked'      => new ObjectUnlockedEvent(['getObject' => $this->entity('u', $small)]),
			'reverted'      => new ObjectRevertedEvent(['getObject' => $this->entity('r', $small), 'getRevertPoint' => 'audit-3']),
			'reg-created'   => new RegisterCreatedEvent(['getRegister' => $register]),
			'reg-updated'   => new RegisterUpdatedEvent(['getNewRegister' => $register]),
			'reg-deleted'   => new RegisterDeletedEvent(['getRegister' => $register]),
			'sch-created'   => new SchemaCreatedEvent(['getSchema' => $schema]),
			'sch-updated'   => new SchemaUpdatedEvent(['getNewSchema' => $schema]),
			'sch-deleted'   => new SchemaDeletedEvent(['getSchema' => $schema]),
			'org-created'   => new OrganisationCreatedEvent(['getOrganisation' => $org]),
			'unknown'       => new class extends Event {
			},
		];

		$listener = new OpenRegisterEventsDebugListener(new NullLogger(), true);
		$extract  = new \ReflectionMethod($listener, 'extractEventData');
		$extract->setAccessible(true);
		$out = [];
		foreach ($events as $name => $event) {
			$data = $extract->invoke($listener, $event);
			if ($name === 'unknown') {
				$data['eventClass'] = 'anonymous';
			}

			$out[$name] = $data;
		}

		$path = dirname(__DIR__, 2) . '/fixtures/debug-listener/payloads.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $out);
	}
}
