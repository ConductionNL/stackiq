<?php

/**
 * Golden test for the Stackiq object event listener.
 *
 * Runs handle() over created, updated and deleted events for every catalog
 * schema and records every settings, contact person, sync, object service and
 * logger call, in order. Clock, trace and source location values are masked.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

require_once __DIR__ . '/../Support/FindCallShape.php';

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Stackiq\EventListener\StackiqEventListener;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Golden cases for StackiqEventListener::handle().
 *
 * @covers \OCA\Stackiq\EventListener\StackiqEventListener
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class StackiqEventListenerGoldenTest extends TestCase {

	/**
	 * The configured catalog schema ids.
	 */
	private const SCHEMAS = ['organization' => 1, 'contactPerson' => 2, 'contactgegevens' => 3, 'usage' => 4];

	/**
	 * Context keys whose values depend on the clock or the run.
	 */
	private const VOLATILE = ['timestamp', 'trace', 'file', 'line'];

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * The cases: event kind, schema, data, old data and the doubles' behaviour.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array, 3: array|null, 4: array<string, mixed>}>
	 */
	public static function cases(): array {
		$active   = ['name' => 'Gemeente A', 'status' => 'Actief'];
		$inactive = ['name' => 'Gemeente A', 'status' => 'inactief'];
		$usage    = ['consumer' => ['name' => 'Gemeente A'], 'product' => ['name' => 'Zaaksysteem']];
		return [
			'created organisation active'          => ['created', '1', $active, null, []],
			'created organisation active english'  => ['created', '1', ['status' => 'ACTIVE'], null, []],
			'created organisation inactive'        => ['created', '1', $inactive, null, []],
			'created organisation no status'       => ['created', '1', [], null, []],
			'created organisation sync fails'      => ['created', '1', $active, null, ['orgSyncThrows' => true]],
			'created contact person'               => ['created', '2', ['email' => 'a@a.nl'], null, []],
			'created contact person fails'         => ['created', '2', ['email' => 'a@a.nl'], null, ['contactThrows' => true]],
			'created contactgegevens'              => ['created', '3', [], null, []],
			'created usage'                        => ['created', '4', $usage, null, []],
			'created usage sync fails'             => ['created', '4', $usage, null, ['usageSyncThrows' => true]],
			'created other schema'                 => ['created', '9', ['x' => 1], null, []],
			'created, no schemas configured'       => ['created', '1', $active, null, ['noSchemas' => true]],
			'created, schema id as string'         => ['created', '01', $active, null, []],
			'created, two types share a schema'    => ['created', '4', ['email' => 'a@a.nl'], null, ['schemas' => ['organization' => 1, 'contactPerson' => 4, 'contactgegevens' => 3, 'usage' => 4]]],
			'updated organisation activated'       => ['updated', '1', $active, $inactive, []],
			'updated organisation, no old object'  => ['updated', '1', $active, null, []],
			'updated organisation still active'    => ['updated', '1', $active, $active, []],
			'updated organisation deactivated'     => ['updated', '1', $inactive, $active, []],
			'updated organisation, refetch fails'  => ['updated', '1', $active, $inactive, ['findThrows' => true]],
			'updated organisation, sync fails'     => ['updated', '1', $active, $inactive, ['orgSyncThrows' => true]],
			'updated organisation, schema \'01\''  => ['updated', '01', $active, $inactive, []],
			'updated contact person'               => ['updated', '2', ['email' => 'a@a.nl'], ['email' => 'b@a.nl'], []],
			'updated contact person fails'         => ['updated', '2', ['email' => 'a@a.nl'], null, ['contactThrows' => true]],
			'updated contact person, Error escapes' => ['updated', '2', ['email' => 'a@a.nl'], null, ['contactThrows' => 'error']],
			'updated contactgegevens'              => ['updated', '3', ['email' => 'a@a.nl'], null, []],
			'updated contactgegevens fails'        => ['updated', '3', ['email' => 'a@a.nl'], null, ['contactThrows' => true]],
			'updated usage'                        => ['updated', '4', $usage, null, []],
			'updated other schema'                 => ['updated', '9', ['x' => 1], null, []],
			'updated, no schemas configured'       => ['updated', '2', [], null, ['noSchemas' => true]],
			'deleted organisation'                 => ['deleted', '1', $active, null, []],
			'deleted organisation, sync fails'     => ['deleted', '1', $active, null, ['orgSyncThrows' => true]],
			'deleted contact person'               => ['deleted', '2', ['email' => 'a@a.nl'], null, []],
			'deleted contact person fails'         => ['deleted', '2', ['email' => 'a@a.nl'], null, ['contactThrows' => true]],
			'deleted contactgegevens'              => ['deleted', '3', [], null, []],
			'deleted contactgegevens fails'        => ['deleted', '3', [], null, ['contactThrows' => true]],
			'deleted usage'                        => ['deleted', '4', $usage, null, []],
			'deleted usage without names'          => ['deleted', '4', [], null, []],
			'deleted other schema'                 => ['deleted', '9', ['x' => 1], null, []],
			'deleted, no schemas configured'       => ['deleted', '4', [], null, ['noSchemas' => true]],
		];
	}//end cases()

	/**
	 * Each case matches its recording.
	 *
	 * @param string               $kind    created, updated or deleted.
	 * @param string               $schema  The object's schema id.
	 * @param array                $data    The object data.
	 * @param array|null           $oldData The previous object data, for an update.
	 * @param array<string, mixed> $plan    The behaviour of the doubles.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testHandleMatchesTheRecording(string $kind, string $schema, array $data, ?array $oldData, array $plan): void {
		$this->plan  = $plan;
		$this->trace = [];
		$object      = $this->entity(uuid: 'obj-1', schema: $schema, data: $data);
		$events      = [
			'created' => fn () => new ObjectCreatedEvent($object),
			'updated' => fn () => new ObjectUpdatedEvent($object, $oldData === null ? null : $this->entity(uuid: 'obj-1', schema: $schema, data: $oldData)),
			'deleted' => fn () => new ObjectDeletedEvent($object),
		];

		try {
			(new StackiqEventListener($this->container()))->handle($events[$kind]());
		} catch (\Throwable $e) {
			$this->trace[] = ['escaped', get_class($e), $e->getMessage()];
		}

		$this->check(key: $this->dataName(), value: $this->trace);
	}//end testHandleMatchesTheRecording()

	/**
	 * An object entity double.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $schema The schema id.
	 * @param array  $data   The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schema, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getRegister')->willReturn('5');
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A container with recording doubles for every service the listener resolves.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $objectType): ?int {
				$this->trace[] = ['settings.getSchemaIdForObjectType', $objectType];
				if (isset($this->plan['noSchemas']) === true) {
					return null;
				}

				return ($this->plan['schemas'] ?? self::SCHEMAS)[$objectType] ?? null;
			}
		);
		$settings->method('getVoorzieningenConfig')->willReturnCallback(
			function (): array {
				$this->trace[] = ['settings.getVoorzieningenConfig'];
				return ['register' => '5', 'organisatie_schema' => '1'];
			}
		);

		$contacts = $this->createMock(ContactpersoonService::class);
		foreach (['processContactpersoon', 'handleContactpersoonUpdate', 'handleContactDeletion'] as $method) {
			$contacts->method($method)->willReturnCallback(
				function (...$args) use ($method) {
					$this->trace[] = ['contacts.' . $method, array_map(fn ($arg) => is_object($arg) === true ? $arg->getUuid() : $arg, $args)];
					if (($this->plan['contactThrows'] ?? null) === 'error') {
						throw new \Error('contact broke');
					}

					if (isset($this->plan['contactThrows']) === true) {
						throw new \RuntimeException('contact failed');
					}

					return true;
				}
			);
		}

		$orgSync = $this->createMock(OrganizationSyncService::class);
		$orgSync->method('processSpecificOrganization')->willReturnCallback(
			function ($object): array {
				$this->trace[] = ['orgSync.processSpecificOrganization', $object->getUuid(), $object->getObject()];
				if (isset($this->plan['orgSyncThrows']) === true) {
					throw new \RuntimeException('org sync failed');
				}

				return ['organizationsProcessed' => 1];
			}
		);

		$usageSync = $this->createMock(GebruikSyncService::class);
		$usageSync->method('processSpecificGebruik')->willReturnCallback(
			function ($object): array {
				$this->trace[] = ['usageSync.processSpecificGebruik', $object->getUuid()];
				if (isset($this->plan['usageSyncThrows']) === true) {
					throw new \RuntimeException('usage sync failed');
				}

				return ['processed' => 1];
			}
		);

		$objects = new class($this) {
			/**
			 * @param StackiqEventListenerGoldenTest $test The test, for the trace.
			 */
			public function __construct(private StackiqEventListenerGoldenTest $test) {
			}

			/**
			 * @param mixed ...$args The lookup, by name.
			 *
			 * @return ObjectEntity
			 */
			public function find(...$args): ObjectEntity {
				// The recording lists the arguments in the order the old call wrote them.
				$named = FindCallShape::named(args: $args);
				$order = array_intersect_key(array_flip(['id', 'register', 'schema', '_extend', '_rbac', '_multitenancy']), $named);

				return $this->test->refetch(args: array_merge($order, $named));
			}
		};

		$services = [
			LoggerInterface::class                      => $this->logger(),
			ContactpersoonService::class                => $contacts,
			SettingsService::class                      => $settings,
			'OCA\Stackiq\Service\OrganizationSyncService' => $orgSync,
			GebruikSyncService::class                   => $usageSync,
			'OCA\OpenRegister\Service\ObjectService'    => $objects,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				$this->trace[] = ['container.get', $id];
				return $services[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * Record a refetch of the organisation and answer it.
	 *
	 * @param array<string, mixed> $args The find() arguments, by name.
	 *
	 * @return ObjectEntity The refetched organisation.
	 */
	public function refetch(array $args): ObjectEntity {
		$this->trace[] = ['objects.find', $args];
		if (isset($this->plan['findThrows']) === true) {
			throw new \RuntimeException('find failed');
		}

		return $this->entity(uuid: 'obj-1', schema: '1', data: ['status' => 'actief', 'contactpersonen' => [['email' => 'a@a.nl'], ['email' => 'b@a.nl']]]);
	}//end refetch()

	/**
	 * A logger that records every line, with volatile values masked.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$trace = &$this->trace;
		return new class($trace, self::VOLATILE) extends AbstractLogger {
			/**
			 * @param array              $trace    The shared trace.
			 * @param array<int, string> $volatile The keys to mask.
			 */
			public function __construct(private array &$trace, private array $volatile) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				foreach ($this->volatile as $key) {
					if (array_key_exists($key, $context) === true) {
						$context[$key] = '(' . $key . ')';
					}
				}

				if (isset($context['eventType']) === true) {
					$context['eventType'] = preg_replace('/Mock\w+/', 'Mock', (string) $context['eventType']);
				}

				$this->trace[] = ['log.' . $level, (string) $message, $context];
			}
		};
	}//end logger()

	/**
	 * Compare a case with the golden file, or record it.
	 *
	 * @param string $key   The case.
	 * @param array  $value What the case observed.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
		$file  = __DIR__ . '/golden/stackiqEventListener.json';
		$saved = [];
		if (file_exists($file) === true) {
			$saved = json_decode((string) file_get_contents($file), true);
		}

		if (getenv('RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			if (is_dir(dirname($file)) === false) {
				mkdir(dirname($file));
			}

			file_put_contents($file, json_encode($saved, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);
	}//end check()
}//end class
