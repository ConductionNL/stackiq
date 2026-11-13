<?php

/**
 * Characterisation tests for ItsmFlowGateway::saveAndPublish.
 *
 * Pins how a flow is created or updated, when a draft is opened first, and
 * that it is published and switched on, with every OpenRegister flow call
 * in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Itsm
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Itsm;

use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Cases for saving and publishing a flow.
 *
 * @covers \OCA\Stackiq\Service\Itsm\ItsmFlowGateway
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
class ItsmFlowGatewaySaveTest extends TestCase {

	/**
	 * Every flow call, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $trace = [];

	/**
	 * Save a flow with the stored flows given.
	 *
	 * @param array       $stored The stored flows: uuid => lifecycle status.
	 * @param string|null $uuid   The flow to update.
	 *
	 * @return string The uuid answered.
	 */
	private function save(array $stored, ?string $uuid): string {
		$this->trace = [];
		$test        = $this;
		$flows       = new class($test, $stored) {
			/**
			 * Build the double.
			 *
			 * @param ItsmFlowGatewaySaveTest $test   The test, for its trace.
			 * @param array                   $stored The stored flows.
			 */
			public function __construct(private ItsmFlowGatewaySaveTest $test, private array $stored) {
			}

			/**
			 * @param string $uuid The flow.
			 *
			 * @return object
			 */
			public function find(string $uuid): object {
				$this->test->trace[] = ['find', $uuid];
				if (isset($this->stored[$uuid]) === false) {
					throw new \RuntimeException('no flow ' . $uuid);
				}

				return ItsmFlowGatewaySaveTestFlows::flow(uuid: $uuid, status: $this->stored[$uuid]);
			}

			/**
			 * @param array       $flow The flow document.
			 * @param string|null $uuid The flow to update.
			 *
			 * @return object
			 */
			public function save(array $flow, ?string $uuid): object {
				$this->test->trace[] = ['save', $flow, $uuid];
				return ItsmFlowGatewaySaveTestFlows::flow(uuid: ($uuid ?? 'f-new'), status: 'draft');
			}
		};
		$versions = new class($test) {
			/**
			 * Build the double.
			 *
			 * @param ItsmFlowGatewaySaveTest $test The test, for its trace.
			 */
			public function __construct(private ItsmFlowGatewaySaveTest $test) {
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return void
			 */
			public function createDraft(object $flow): void {
				$this->test->trace[] = ['createDraft', $flow->getUuid()];
			}

			/**
			 * @param object $flow The flow.
			 *
			 * @return void
			 */
			public function publish(object $flow): void {
				$this->test->trace[] = ['publish', $flow->getUuid()];
			}
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($flows, $versions) {
				$this->trace[] = ['get', substr($class, strrpos($class, '\\') + 1)];
				if (str_ends_with($class, 'FlowService') === true) {
					return $flows;
				}

				return $versions;
			}
		);

		return (new ItsmFlowGateway($container))->saveAndPublish(['name' => 'flow'], $uuid);

	}//end save()

	/**
	 * A new flow is saved, published and switched on.
	 *
	 * @return void
	 */
	public function testANewFlowIsSavedPublishedAndSwitchedOn(): void {
		$this->assertSame('f-new', $this->save(stored: [], uuid: null));
		$this->assertSame(
			[
				['get', 'FlowService'],
				['save', ['name' => 'flow'], null],
				['get', 'FlowVersionService'],
				['publish', 'f-new'],
				['save', ['enabled' => true], 'f-new'],
			],
			$this->trace
		);

	}//end testANewFlowIsSavedPublishedAndSwitchedOn()

	/**
	 * A flow that no longer exists is created anew.
	 *
	 * @return void
	 */
	public function testAGoneFlowIsCreatedAnew(): void {
		$this->assertSame('f-new', $this->save(stored: [], uuid: 'f-gone'));
		$this->assertSame(
			[
				['get', 'FlowService'],
				['get', 'FlowService'],
				['find', 'f-gone'],
				['save', ['name' => 'flow'], null],
				['get', 'FlowVersionService'],
				['publish', 'f-new'],
				['save', ['enabled' => true], 'f-new'],
			],
			$this->trace
		);

	}//end testAGoneFlowIsCreatedAnew()

	/**
	 * A published flow gets a draft first; a draft is edited as it is.
	 *
	 * @return void
	 */
	public function testAPublishedFlowGetsADraftFirst(): void {
		$this->assertSame('f-1', $this->save(stored: ['f-1' => 'published'], uuid: 'f-1'));
		$this->assertSame(
			[
				['get', 'FlowService'],
				['get', 'FlowService'],
				['find', 'f-1'],
				['find', 'f-1'],
				['get', 'FlowVersionService'],
				['createDraft', 'f-1'],
				['save', ['name' => 'flow'], 'f-1'],
				['get', 'FlowVersionService'],
				['publish', 'f-1'],
				['save', ['enabled' => true], 'f-1'],
			],
			$this->trace
		);

		$this->assertSame('f-1', $this->save(stored: ['f-1' => 'draft'], uuid: 'f-1'));
		$this->assertSame(
			[
				['get', 'FlowService'],
				['get', 'FlowService'],
				['find', 'f-1'],
				['find', 'f-1'],
				['save', ['name' => 'flow'], 'f-1'],
				['get', 'FlowVersionService'],
				['publish', 'f-1'],
				['save', ['enabled' => true], 'f-1'],
			],
			$this->trace
		);

	}//end testAPublishedFlowGetsADraftFirst()
}//end class

/**
 * Builds stored flow doubles for ItsmFlowGatewaySaveTest's flow service double.
 */
final class ItsmFlowGatewaySaveTestFlows {

	/**
	 * A stored flow double.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $status The lifecycle status.
	 *
	 * @return object
	 */
	public static function flow(string $uuid, string $status): object {
		return new class($uuid, $status) {
			/**
			 * Build the double.
			 *
			 * @param string $uuid   The uuid.
			 * @param string $status The lifecycle status.
			 */
			public function __construct(public string $uuid, public string $status) {
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * @return string
			 */
			public function getLifecycleStatus(): string {
				return $this->status;
			}
		};

	}//end flow()
}//end class
