<?php

/**
 * ModuleComponentListener: a module save the guard refuses is stopped with its message.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\EventListener\ModuleComponentListener;
use OCA\Stackiq\Service\ModuleComponentGuard;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The guard is a double; the events are OpenRegister's shape.
 *
 * @covers \OCA\Stackiq\EventListener\ModuleComponentListener
 */
class ModuleComponentListenerTest extends TestCase {

	/**
	 * What the guard was asked.
	 *
	 * @var array<int,array{data:array<string,mixed>,uuid:?string}>
	 */
	private array $asked = [];

	/**
	 * The listener over a guard that refuses partOf "zaak" for module "zaak".
	 *
	 * @return ModuleComponentListener
	 */
	private function listener(): ModuleComponentListener {
		$guard = $this->createMock(ModuleComponentGuard::class);
		$guard->method('refusal')->willReturnCallback(
			function (array $data, ?string $moduleUuid): ?string {
				$this->asked[] = ['data' => $data, 'uuid' => $moduleUuid];
				return (($data['partOf'] ?? null) === 'portaal' && $moduleUuid === 'zaak') ? 'An application cannot be part of one of its own components.' : null;
			}
		);

		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getSchemaIdForObjectType')->with('module')->willReturn(42);

		return new ModuleComponentListener($guard, $locator);
	}//end listener()

	/**
	 * An entity.
	 *
	 * @param array<string,mixed> $data   The object.
	 * @param string              $schema The schema id.
	 * @param string|null         $uuid   The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = '42', ?string $uuid = 'zaak'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);

		return $entity;
	}//end entity()

	/**
	 * Setting A part of its component C is stopped on update, with the guard's message.
	 *
	 * @return void
	 */
	public function testACycleStopsTheUpdate(): void {
		$event = new ObjectUpdatingEvent($this->entity(data: ['name' => 'Zaaksysteem', 'partOf' => 'portaal']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => 'An application cannot be part of one of its own components.'], $event->getErrors());
	}//end testACycleStopsTheUpdate()

	/**
	 * The guard reads the object with the changes the save carries.
	 *
	 * @return void
	 */
	public function testTheModifiedDataIsChecked(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['name' => 'Zaaksysteem']));
		$event->setModifiedData(['partOf' => 'portaal']);

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame([['data' => ['name' => 'Zaaksysteem', 'partOf' => 'portaal'], 'uuid' => 'zaak']], $this->asked);
	}//end testTheModifiedDataIsChecked()

	/**
	 * An allowed parent passes, and other schemas and events are not checked.
	 *
	 * @return void
	 */
	public function testOtherSavesPass(): void {
		$allowed = new ObjectUpdatingEvent($this->entity(data: ['partOf' => 'zaak'], uuid: 'portaal'));
		$other   = new ObjectUpdatingEvent($this->entity(data: ['partOf' => 'portaal'], schema: '7'));

		$listener = $this->listener();
		$listener->handle($allowed);
		$listener->handle($other);
		$listener->handle(new Event());

		$this->assertFalse($allowed->isPropagationStopped());
		$this->assertFalse($other->isPropagationStopped());
		$this->assertCount(1, $this->asked);
	}//end testOtherSavesPass()
}//end class
