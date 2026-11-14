<?php

/**
 * LifecycleCalendarListenerTest
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\LifecycleCalendarListener;
use OCA\Stackiq\Service\LifecycleCalendarService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fires only for contract and module version saves in the stackiq register.
 *
 * @spec openspec/changes/adopt-integration-leaves/tasks.md#4.2
 */
#[CoversClass(LifecycleCalendarListener::class)]
final class LifecycleCalendarListenerTest extends TestCase {

	/**
	 * The calendar sync.
	 *
	 * @var LifecycleCalendarService&MockObject
	 */
	private LifecycleCalendarService $calendar;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var LifecycleCalendarListener
	 */
	private LifecycleCalendarListener $listener;

	/**
	 * Wire the listener over settings giving contracts schema 11 and module versions 12, both in register 3.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$settings = $this->createMock(ObjectTypeLocatorInterface::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			static fn (string $objectType): ?int => ['catalogContract' => 11, 'moduleVersion' => 12][$objectType] ?? null
		);
		$settings->method('getRegisterIdForObjectType')->willReturnCallback(
			static fn (string $objectType): ?int => in_array($objectType, ['catalogContract', 'moduleVersion'], true) ? 3 : null
		);
		$this->calendar = $this->createMock(LifecycleCalendarService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->listener = new LifecycleCalendarListener(calendar: $this->calendar, settings: $settings, logger: $this->logger);
	}//end setUp()

	/**
	 * An object in a register and schema.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $register, string $schema): ObjectEntity {
		$object = $this->getMockBuilder(ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$object->method('getUuid')->willReturn('obj-1');
		$object->method('getRegister')->willReturn($register);
		$object->method('getSchema')->willReturn($schema);
		$object->method('getObject')->willReturn(['endDate' => '2027-03-31']);
		return $object;
	}//end object()

	/**
	 * A created contract is synced with its uuid, register, schema and data.
	 *
	 * @return void
	 */
	public function testACreatedContractIsSynced(): void {
		$this->calendar->expects($this->once())->method('syncContract')->with(
			['uuid' => 'obj-1', 'register' => '3', 'schema' => '11', 'data' => ['endDate' => '2027-03-31']]
		);
		$this->calendar->expects($this->never())->method('syncModuleVersion');

		$this->listener->handle(new ObjectCreatedEvent($this->object('3', '11')));
	}//end testACreatedContractIsSynced()

	/**
	 * An updated module version is synced from its new state.
	 *
	 * @return void
	 */
	public function testAnUpdatedModuleVersionIsSynced(): void {
		$this->calendar->expects($this->once())->method('syncModuleVersion')->with(
			$this->callback(static fn (array $object): bool => $object['schema'] === '12' && $object['uuid'] === 'obj-1')
		);
		$this->calendar->expects($this->never())->method('syncContract');

		$this->listener->handle(new ObjectUpdatedEvent($this->object('3', '12'), $this->object('3', '12')));
	}//end testAnUpdatedModuleVersionIsSynced()

	/**
	 * Other schemas, the same schema id in another register, and other events are ignored.
	 *
	 * @return void
	 */
	public function testOtherObjectsAndEventsAreIgnored(): void {
		$this->calendar->expects($this->never())->method('syncContract');
		$this->calendar->expects($this->never())->method('syncModuleVersion');

		$this->listener->handle(new ObjectCreatedEvent($this->object('3', '40')));
		$this->listener->handle(new ObjectCreatedEvent($this->object('8', '11')));
		$this->listener->handle(new Event());
	}//end testOtherObjectsAndEventsAreIgnored()

	/**
	 * A failing sync is logged and does not reach the save.
	 *
	 * @return void
	 */
	public function testAFailingSyncIsLoggedNotThrown(): void {
		$this->calendar->method('syncContract')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')->with(
			'LifecycleCalendarListener: could not sync the lifecycle calendar',
			['error' => 'boom']
		);

		$this->listener->handle(new ObjectCreatedEvent($this->object('3', '11')));
	}//end testAFailingSyncIsLoggedNotThrown()
}//end class
