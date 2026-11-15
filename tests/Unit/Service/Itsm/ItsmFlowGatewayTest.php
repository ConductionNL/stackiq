<?php

/**
 * Unit tests for ItsmFlowGateway's inspect, run and object reads.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Itsm
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Itsm;

use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The gateway's reads and runs against OpenRegister's flow and object services.
 *
 * @covers \OCA\Stackiq\Service\Itsm\ItsmFlowGateway
 */
class ItsmFlowGatewayTest extends TestCase {

	/**
	 * The preflight's findings come back as two lists, re-indexed, with
	 * missing ones empty.
	 *
	 * @return void
	 */
	public function testInspectAnswersBlockingAndWarnings(): void {
		$preflight = new class {
			/**
			 * The flow inspected.
			 *
			 * @var array|null
			 */
			public ?array $seen = null;

			/**
			 * Inspect.
			 *
			 * @param array $flow The flow.
			 *
			 * @return array
			 */
			public function inspect(array $flow): array {
				$this->seen = $flow;
				return ['blocking' => [3 => 'no trigger'], 'other' => ['x']];
			}
		};

		$result = $this->gateway(['OCA\OpenRegister\Service\Flow\FlowNodePreflight' => $preflight])->inspect(['nodes' => []]);

		$this->assertSame(['blocking' => ['no trigger'], 'warnings' => []], $result);
		$this->assertSame(['nodes' => []], $preflight->seen);
	}//end testInspectAnswersBlockingAndWarnings()

	/**
	 * A run is started synchronously-off with the payload, and its uuid answered.
	 *
	 * @return void
	 */
	public function testRunStartsTheFlowWithThePayload(): void {
		$flows = new class {
			/**
			 * The run calls.
			 *
			 * @var array<int, array>
			 */
			public array $runs = [];

			/**
			 * Run a flow.
			 *
			 * @param string $uuid    The flow.
			 * @param array  $input   The input.
			 * @param array  $context The context.
			 * @param bool   $sync    Whether to run synchronously.
			 *
			 * @return object
			 */
			public function run(string $uuid, array $input, array $context, bool $sync): object {
				$this->runs[] = [$uuid, $input, $context, $sync];
				return new class {
					/**
					 * The run uuid.
					 *
					 * @return string
					 */
					public function getUuid(): string {
						return 'run-1';
					}
				};
			}
		};

		$answer = $this->gateway(['OCA\OpenRegister\Service\Flow\FlowService' => $flows])->run('flow-1', ['ticket' => 'T-1']);

		$this->assertSame('run-1', $answer);
		$this->assertSame([['flow-1', [], ['payload' => ['ticket' => 'T-1']], false]], $flows->runs);
	}//end testRunStartsTheFlowWithThePayload()

	/**
	 * A found object is serialised, unscoped, with its uuid added.
	 *
	 * @return void
	 */
	public function testFindObjectAnswersTheSerialisedObject(): void {
		$objects = $this->objects(found: $this->entity(['title' => 'Incident'], 'obj-1'));

		$answer = $this->gateway(['OCA\OpenRegister\Service\ObjectService' => $objects])->findObject('reg', 'ticket', 'obj-1');

		$this->assertSame(['title' => 'Incident', 'uuid' => 'obj-1'], $answer);
		$this->assertSame(
			[['id' => 'obj-1', '_extend' => [], 'files' => false, 'register' => 'reg', 'schema' => 'ticket', '_rbac' => false, '_multitenancy' => false]],
			$objects->calls
		);
	}//end testFindObjectAnswersTheSerialisedObject()

	/**
	 * A lookup that throws, answers nothing, or finds no service answers null.
	 *
	 * @return void
	 */
	public function testFindObjectAnswersNullWhenNothingIsFound(): void {
		$this->assertNull($this->gateway(['OCA\OpenRegister\Service\ObjectService' => $this->objects(found: new RuntimeException('gone'))])->findObject('r', 's', 'x'));
		$this->assertNull($this->gateway(['OCA\OpenRegister\Service\ObjectService' => $this->objects(found: null)])->findObject('r', 's', 'x'));
		$this->assertNull($this->gateway([])->findObject('r', 's', 'x'));
	}//end testFindObjectAnswersNullWhenNothingIsFound()

	/**
	 * A service the container cannot resolve is named in the error.
	 *
	 * @return void
	 */
	public function testAMissingServiceIsNamed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister does not provide OCA\OpenRegister\Service\Flow\FlowService: not registered');

		$this->gateway([])->run('flow-1', []);
	}//end testAMissingServiceIsNamed()

	/**
	 * A container entry that is not an object is refused too.
	 *
	 * @return void
	 */
	public function testANonObjectServiceIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister does not provide OCA\OpenRegister\Service\Flow\FlowNodePreflight.');

		$this->gateway(['OCA\OpenRegister\Service\Flow\FlowNodePreflight' => 'not a service'])->inspect([]);
	}//end testANonObjectServiceIsRefused()

	/**
	 * Availability follows whether OpenRegister's flow classes are loaded.
	 *
	 * @return void
	 */
	public function testAvailabilityFollowsTheFlowClasses(): void {
		$expected = class_exists('\OCA\OpenRegister\Service\Flow\FlowService') === true
			&& class_exists('\OCA\OpenRegister\Service\Flow\FlowNodePreflight') === true;

		$this->assertSame($expected, $this->gateway([])->available());
	}//end testAvailabilityFollowsTheFlowClasses()

	/**
	 * An object service double that answers find() with the given value.
	 *
	 * @param mixed $found An entity, null, or an exception to throw.
	 *
	 * @return object
	 */
	private function objects(mixed $found): object {
		return new class ($found) {
			/**
			 * The find calls.
			 *
			 * @var array<int, array>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $found What find() answers.
			 */
			public function __construct(private readonly mixed $found) {
			}

			/**
			 * Find one object.
			 *
			 * @param string $id            The id.
			 * @param array  $_extend       Extensions.
			 * @param bool   $files         Whether to load files.
			 * @param string $register      The register.
			 * @param string $schema        The schema.
			 * @param bool   $_rbac         RBAC.
			 * @param bool   $_multitenancy Multitenancy.
			 *
			 * @return mixed
			 */
			public function find(string $id, array $_extend, bool $files, string $register, string $schema, bool $_rbac, bool $_multitenancy): mixed {
				$this->calls[] = compact('id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy');
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}
		};
	}//end objects()

	/**
	 * An entity serialising to the data, with a uuid.
	 *
	 * @param array  $data The data.
	 * @param string $uuid The uuid.
	 *
	 * @return object
	 */
	private function entity(array $data, string $uuid): object {
		return new class ($data, $uuid) {
			/**
			 * Constructor.
			 *
			 * @param array  $data The data.
			 * @param string $uuid The uuid.
			 */
			public function __construct(private readonly array $data, private readonly string $uuid) {
			}

			/**
			 * Serialise.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end entity()

	/**
	 * A gateway over a container holding the given services.
	 *
	 * @param array<string, mixed> $services Services by id; others are not registered.
	 *
	 * @return ItsmFlowGateway
	 */
	private function gateway(array $services): ItsmFlowGateway {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('not registered');
				}

				return $services[$id];
			}
		);

		return new ItsmFlowGateway(container: $container);
	}//end gateway()
}//end class
