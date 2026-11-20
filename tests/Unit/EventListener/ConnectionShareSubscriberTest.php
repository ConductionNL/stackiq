<?php

/**
 * The subscriber recomputes the connections of a shared usage on create, update and delete.
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
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\ConnectionShareSubscriber;
use OCA\Stackiq\Service\ConnectionShareSync;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers ConnectionShareSubscriber with the real OpenRegister event classes.
 *
 * @covers \OCA\Stackiq\EventListener\ConnectionShareSubscriber
 * @uses   \OCA\Stackiq\Service\ConnectionShareSync
 * @uses   \OCA\Stackiq\Service\RelationRefs
 */
final class ConnectionShareSubscriberTest extends TestCase {

	/**
	 * The sync calls made, as connections and deleted usage.
	 *
	 * @var list<array{0: list<string>, 1: string|null}>
	 */
	private array $calls = [];

	/**
	 * The subscriber over a container whose usage schema is 12.
	 *
	 * @param bool $syncFails Whether the sync throws.
	 *
	 * @return ConnectionShareSubscriber
	 */
	private function subscriber(bool $syncFails=false): ConnectionShareSubscriber {
		$sync = $this->createMock(originalClassName: ConnectionShareSync::class);
		$sync->method('sync')->willReturnCallback(
			function (array $connections, ?string $deletedUsage=null) use ($syncFails) {
				if ($syncFails === true) {
					throw new \RuntimeException('down');
				}

				$this->calls[] = [$connections, $deletedUsage];
				return count($connections);
			}
		);
		$types = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$types->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type) => ['usage' => 12][$type] ?? null);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		if ($syncFails === true) {
			$logger->expects($this->once())->method('error');
		}

		$services = [ConnectionShareSync::class => $sync, ObjectTypeLocatorInterface::class => $types, LoggerInterface::class => $logger];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $services[$id]);

		return new ConnectionShareSubscriber(container: $container);
	}//end subscriber()

	/**
	 * A usage object.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param int                  $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function usage(array $data, int $schema=12): ObjectEntity {
		$entity = $this->getMockBuilder(className: ObjectEntity::class)->disableOriginalConstructor()->getMockForAbstractClass();
		$entity->method('getUuid')->willReturn('usage-1');
		$entity->method('getSchema')->willReturn((string)$schema);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end usage()

	/**
	 * Ashared usage created syncs its connections.
	 *
	 * @return void
	 */
	public function testASharedUsageCreatedSyncsItsConnections(): void {
		$this->subscriber()->handle(new ObjectCreatedEvent($this->usage(data: ['koppelingen' => ['c1'], 'participants' => ['org-b']])));

		$this->assertSame(expected: [[['c1'], null]], actual: $this->calls);
	}

	/**
	 * An update syncs the connections listed before and after.
	 *
	 * @return void
	 */
	public function testAnUpdateSyncsTheConnectionsListedBeforeAndAfter(): void {
		$this->subscriber()->handle(new ObjectUpdatedEvent(
			$this->usage(data: ['koppelingen' => ['c2'], 'participants' => ['org-b']]),
			$this->usage(data: ['koppelingen' => ['c1'], 'participants' => ['org-b']])
		));

		$this->assertSame(expected: [[['c2', 'c1'], null]], actual: $this->calls);
	}

	/**
	 * Adeleted shared usage syncs without itself.
	 *
	 * @return void
	 */
	public function testADeletedSharedUsageSyncsWithoutItself(): void {
		$this->subscriber()->handle(new ObjectDeletedEvent($this->usage(data: ['koppelingen' => ['c1'], 'participants' => ['org-b']])));

		$this->assertSame(expected: [[['c1'], 'usage-1']], actual: $this->calls);
	}

	/**
	 * An unshared usage and another schema do nothing.
	 *
	 * @return void
	 */
	public function testAnUnsharedUsageAndAnotherSchemaDoNothing(): void {
		$subscriber = $this->subscriber();
		$subscriber->handle(new ObjectCreatedEvent($this->usage(data: ['koppelingen' => ['c1']])));
		$subscriber->handle(new ObjectCreatedEvent($this->usage(data: ['koppelingen' => ['c1'], 'participants' => ['org-b']], schema: 99)));

		$this->assertSame(expected: [], actual: $this->calls);
	}

	/**
	 * Afailing sync is logged and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingSyncIsLoggedAndDoesNotEscape(): void {
		$this->subscriber(syncFails: true)->handle(
			new ObjectCreatedEvent($this->usage(data: ['koppelingen' => ['c1'], 'participants' => ['org-b']]))
		);

		$this->assertSame(expected: [], actual: $this->calls);
	}

	/**
	 * It is registered for create update and delete.
	 *
	 * @return void
	 */
	public function testItIsRegisteredForCreateUpdateAndDelete(): void {
		$registrar = (string)file_get_contents(__DIR__ . '/../../../lib/EventListener/ListenerRegistrar.php');

		foreach (['ObjectCreatedEvent', 'ObjectUpdatedEvent', 'ObjectDeletedEvent'] as $event) {
			$this->assertStringContainsString(
				needle: 'registerEventListener(' . $event . '::class, ConnectionShareSubscriber::class)',
				haystack: $registrar
			);
		}
	}
}
