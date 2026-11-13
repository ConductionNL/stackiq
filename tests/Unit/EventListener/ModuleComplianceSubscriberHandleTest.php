<?php

/**
 * Unit tests for ModuleComplianceSubscriber::handle().
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\ModuleComplianceSubscriber;
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which saved objects trigger a compliance update and a default version, and what a failure does.
 *
 * @covers \OCA\Stackiq\EventListener\ModuleComplianceSubscriber
 */
class ModuleComplianceSubscriberHandleTest extends TestCase {
	/**
	 * The compliance service.
	 *
	 * @var ModuleComplianceService&MockObject
	 */
	private ModuleComplianceService&MockObject $compliance;

	/**
	 * The module version service.
	 *
	 * @var ModuleVersionService&MockObject
	 */
	private ModuleVersionService&MockObject $versions;

	/**
	 * The catalogue locator.
	 *
	 * @var CatalogueLocator&MockObject
	 */
	private CatalogueLocator&MockObject $settings;

	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->compliance = $this->createMock(ModuleComplianceService::class);
		$this->versions   = $this->createMock(ModuleVersionService::class);
		$this->settings   = $this->createMock(CatalogueLocator::class);
		$this->logger     = new class extends AbstractLogger {
			/**
			 * Recorded calls.
			 *
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
	}

	/**
	 * The subscriber under test.
	 *
	 * @return ModuleComplianceSubscriber
	 */
	private function subscriber(): ModuleComplianceSubscriber {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				LoggerInterface::class => $this->logger,
				CatalogueLocator::class => $this->settings,
				ModuleComplianceService::class => $this->compliance,
				ModuleVersionService::class => $this->versions,
			}
		);

		return new ModuleComplianceSubscriber($container);
	}

	/**
	 * A saved object in a schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity&MockObject The object.
	 */
	private function object(string $schema): ObjectEntity&MockObject {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getId')->willReturn(3);
		$object->method('getSchema')->willReturn($schema);
		return $object;
	}

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string> The messages.
	 */
	private function messages(string $level): array {
		$records = array_filter($this->logger->records, static fn (array $r): bool => $r[0] === $level);

		return array_values(array_map(static fn (array $r): string => $r[1], $records));
	}

	/**
	 * A created or updated module runs the compliance update and ensures its default version.
	 *
	 * @return void
	 */
	public function testACreatedOrUpdatedModuleIsProcessed(): void {
		$this->settings->method('getSchemaIdForObjectType')->with('module')->willReturn(12);
		$created = $this->object('12');
		$updated = $this->object('12');
		$seen    = [];
		$this->compliance->expects($this->exactly(2))->method('handleModuleComplianceUpdate')
			->willReturnCallback(static function (object $o) use (&$seen): void {
				$seen[] = $o;
			});
		$this->versions->expects($this->exactly(2))->method('ensureDefaultVersion');

		$this->subscriber()->handle(new ObjectCreatedEvent($created));
		$this->subscriber()->handle(new ObjectUpdatedEvent($updated, $this->object('12')));

		self::assertSame([$created, $updated], $seen);
		self::assertSame(
			[
				'ModuleComplianceSubscriber: SUBSCRIBER CALLED',
				'ModuleComplianceSubscriber: Successfully processed module compliance update',
				'ModuleComplianceSubscriber: SUBSCRIBER CALLED',
				'ModuleComplianceSubscriber: Successfully processed module compliance update',
			],
			$this->messages('info')
		);
	}

	/**
	 * Objects of another schema, other events and an unconfigured module schema are ignored.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreIgnored(): void {
		$this->settings->method('getSchemaIdForObjectType')->willReturnOnConsecutiveCalls(12, null);
		$this->compliance->expects($this->never())->method('handleModuleComplianceUpdate');
		$this->versions->expects($this->never())->method('ensureDefaultVersion');

		$this->subscriber()->handle(new ObjectCreatedEvent($this->object('7')));
		$this->subscriber()->handle(new ObjectCreatedEvent($this->object('12')));
		$this->subscriber()->handle(new Event());

		self::assertSame(['ModuleComplianceSubscriber: Module schema not configured, skipping'], $this->messages('debug'));
	}

	/**
	 * A failing compliance update still ensures the default version, and each failure is logged.
	 *
	 * @return void
	 */
	public function testFailuresAreLoggedIndependently(): void {
		$this->settings->method('getSchemaIdForObjectType')->willReturn(12);
		$this->compliance->method('handleModuleComplianceUpdate')->willThrowException(new RuntimeException('register gone'));
		$this->versions->expects($this->once())->method('ensureDefaultVersion')->willThrowException(new RuntimeException('no versions schema'));

		$this->subscriber()->handle(new ObjectCreatedEvent($this->object('12')));

		self::assertSame(
			[
				'ModuleComplianceSubscriber: Failed to process module compliance update',
				'ModuleComplianceSubscriber: Failed to ensure default module version',
			],
			$this->messages('error')
		);
		$errors = array_values(array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'error'));
		self::assertSame(['register gone', 'no versions schema'], [$errors[0][2]['exception'], $errors[1][2]['exception']]);
		self::assertSame(3, $errors[0][2]['objectId']);
	}
}
