<?php

/**
 * Golden test for ModuleRegistrationSubscriber::handle().
 *
 * Records, per event, which services the subscriber looked up, whether the
 * module registration ran, and what was logged when it failed.
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
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\ModuleRegistrationSubscriber;
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins what the module registration subscriber does per event.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\EventListener
 */
class ModuleRegistrationSubscriberGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/module-registration/handle.json';

	/**
	 * The cases: event kind, object schema, module schema id, failure.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			'plain event'              => ['event' => 'plain'],
			'deleted event'            => ['event' => 'deleted', 'schema' => '5', 'module' => 5],
			'created module'           => ['event' => 'created', 'schema' => '5', 'module' => 5],
			'updated module'           => ['event' => 'updated', 'schema' => '5', 'module' => 5],
			'created other schema'     => ['event' => 'created', 'schema' => '6', 'module' => 5],
			'updated other schema'     => ['event' => 'updated', 'schema' => '6', 'module' => 5],
			'no module schema'         => ['event' => 'created', 'schema' => '5', 'module' => null],
			'null schema, module 0'    => ['event' => 'created', 'schema' => null, 'module' => 0],
			'slug schema, module 0'    => ['event' => 'updated', 'schema' => 'module', 'module' => 0],
			'padded schema'            => ['event' => 'created', 'schema' => '05', 'module' => 5],
			'registration fails'       => ['event' => 'created', 'schema' => '5', 'module' => 5, 'fail' => true],
			'update registration fails' => ['event' => 'updated', 'schema' => '5', 'module' => 5, 'fail' => true],
		];
	}//end cases()

	/**
	 * Run one case and record the trace.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function record(array $case): array {
		$trace  = [];
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getSchema')->willReturnCallback(function () use (&$trace, $case): ?string {
			$trace[] = ['getSchema'];
			return ($case['schema'] ?? null);
		});
		$object->method('getId')->willReturn(42);

		$settings = $this->createMock(CatalogueLocator::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(function (string $type) use (&$trace, $case): ?int {
			$trace[] = ['getSchemaIdForObjectType', $type];
			return ($case['module'] ?? null);
		});
		$registration = $this->createMock(ModuleRegistrationService::class);
		$registration->method('handleModuleRegistration')->willReturnCallback(function (object $obj) use (&$trace, $case, $object): void {
			$trace[] = ['handleModuleRegistration', $obj === $object];
			if (($case['fail'] ?? false) === true) {
				throw new \RuntimeException('registration down');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$trace): void {
			$trace[] = ['error', $message, $context];
		});

		$services  = [CatalogueLocator::class => $settings, ModuleRegistrationService::class => $registration, LoggerInterface::class => $logger];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$trace, $services): object {
			$trace[] = ['container.get', $id];
			return $services[$id];
		});

		$events = [
			'plain'   => fn (): Event => new Event(),
			'deleted' => fn (): Event => new ObjectDeletedEvent($object),
			'created' => fn (): Event => new ObjectCreatedEvent($object),
			'updated' => fn (): Event => new ObjectUpdatedEvent($object, null),
		];

		(new ModuleRegistrationSubscriber($container))->handle($events[$case['event']]());

		return $trace;
	}//end record()

	/**
	 * Every case matches the recorded trace.
	 *
	 * @return void
	 */
	public function testHandleMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->record(case: $case);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testHandleMatchesGolden()
}//end class
