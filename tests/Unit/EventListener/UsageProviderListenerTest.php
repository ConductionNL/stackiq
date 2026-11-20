<?php

/**
 * A usage saved on the usage form carries its application's supplier.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Stackiq\EventListener\UsageProviderListener;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\UsageProviderSync;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers UsageProviderListener with the OpenRegister pre-save events.
 *
 * @covers \OCA\Stackiq\EventListener\UsageProviderListener
 * @uses   \OCA\Stackiq\Service\UsageProviderSync
 * @uses   \OCA\Stackiq\Service\RelationRefs
 */
final class UsageProviderListenerTest extends TestCase {

	/**
	 * The listener; module m1's supplier is org-s; usage schema 12.
	 *
	 * @return UsageProviderListener
	 */
	private function listener(): UsageProviderListener {
		$sync = $this->createMock(originalClassName: UsageProviderSync::class);
		$sync->method('supplierFor')->willReturnCallback(fn (array $usage) => ['m1' => 'org-s'][(string)($usage['module'] ?? '')] ?? null);
		$types = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $t) => ['usage' => 12][$t] ?? null);
		$services = [
			UsageProviderSync::class => $sync,
			ObjectTypeLocatorInterface::class => $types,
			LoggerInterface::class => $this->createMock(originalClassName: LoggerInterface::class),
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $services[$id]);

		return new UsageProviderListener(container: $container);
	}//end listener()

	/**
	 * An object of a schema.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param int                  $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data, int $schema=12): ObjectEntity {
		$entity = $this->getMockBuilder(className: ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$entity->method('getSchema')->willReturn((string)$schema);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end object()

	/**
	 * Ausage created on the form gets the supplier as provider.
	 *
	 * @return void
	 */
	public function testAUsageCreatedOnTheFormGetsTheSupplierAsProvider(): void {
		$event = new ObjectCreatingEvent($this->object(data: ['module' => 'm1', 'consumer' => 'org-gemeente']));
		$this->listener()->handle($event);

		$this->assertSame(expected: ['provider' => 'org-s'], actual: $event->getModifiedData());
	}

	/**
	 * An update that moves the usage to another application follows it.
	 *
	 * @return void
	 */
	public function testAnUpdateThatMovesTheUsageToAnotherApplicationFollowsIt(): void {
		$event = new ObjectUpdatingEvent(
			$this->object(data: ['module' => 'm1', 'provider' => 'org-old']),
			$this->object(data: ['module' => 'm0', 'provider' => 'org-old'])
		);
		$this->listener()->handle($event);

		$this->assertSame(expected: ['provider' => 'org-s'], actual: $event->getModifiedData());
	}

	/**
	 * Amatching provider an unknown supplier and other schemas are left alone.
	 *
	 * @return void
	 */
	public function testAMatchingProviderAnUnknownSupplierAndOtherSchemasAreLeftAlone(): void {
		$events = [
			new ObjectCreatingEvent($this->object(data: ['module' => 'm1', 'provider' => 'org-s'])),
			new ObjectCreatingEvent($this->object(data: ['module' => 'm9'])),
			new ObjectCreatingEvent($this->object(data: ['module' => 'm1'], schema: 99)),
		];
		foreach ($events as $event) {
			$this->listener()->handle($event);
		}

		$this->assertSame(expected: [[], [], []], actual: array_map(static fn (ObjectCreatingEvent $e): array => $e->getModifiedData(), $events));
	}
}
