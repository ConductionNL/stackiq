<?php

/**
 * Characterisation tests for ContractApprovalService::submitForApproval.
 *
 * Pins the decision request a contract submission raises, what is stored on
 * the contract when the decision app handles it, and every fail-closed
 * refusal. Each case runs in its own process: it declares the decision
 * app's request event, which the other contract tests require to be absent.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Cases for submitting a contract for approval.
 *
 * @covers \OCA\Stackiq\Service\ContractApprovalService
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ContractSubmitForApprovalTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Declare the decision app's request event, recording its arguments.
	 *
	 * @return void
	 */
	private static function declareRequestEvent(): void {
		require_once __DIR__ . '/fixtures/DecisionRequestedEventDouble.php';

	}//end declareRequestEvent()

	/**
	 * Submit contract `c-1` with the doubles' behaviour.
	 *
	 * @param array $plan found: the contract data or null; dispatch: handled|unhandled|throws; decisionId; renewal.
	 *
	 * @return array{0: mixed, 1: array, 2: array} The result or the exception, the trace and the events made.
	 */
	private function submit(array $plan): array {
		self::declareRequestEvent();
		$this->trace = [];
		\OCA\Decidiq\Event\DecisionRequestedEvent::$made = [];
		$plan = array_merge(['found' => ['contractNumber' => 77, 'title' => 'Hosting'], 'dispatch' => 'handled', 'decisionId' => 'dec-9', 'renewal' => false], $plan);

		$contract = null;
		if ($plan['found'] !== null) {
			$contract = $this->createMock(originalClassName: ObjectEntityInterface::class);
			$contract->method('getObject')->willReturn($plan['found']);
			$contract->method('getRegister')->willReturn('reg-c');
			$contract->method('getSchema')->willReturn('schema-c');
			$contract->method('getUuid')->willReturn('c-1');
		}

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($contract) {
				$this->trace[] = ['find', $id];
				return $contract;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $extend, $register, $schema, $uuid) use ($contract) {
				$this->trace[] = ['saveObject', $object, $register, $schema, $uuid];
				return $contract;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn(11);
		$locator->method('getSchemaIdForObjectType')->willReturn(12);

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event) use ($plan) {
				$this->trace[] = ['dispatchTyped'];
				if ($plan['dispatch'] === 'throws') {
					throw new \RuntimeException('listener broke');
				}

				$event->handled    = $plan['dispatch'] === 'handled';
				$event->decisionId = $plan['decisionId'];
			}
		);

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$service = new ContractApprovalService($container, $locator, $dispatcher, $logger);
		try {
			$result = $service->submitForApproval('c-1', $plan['renewal']);
		} catch (\RuntimeException $e) {
			$result = [get_class($e), $e->getMessage(), $e->getPrevious()?->getMessage()];
		}

		return [$result, $this->trace, \OCA\Decidiq\Event\DecisionRequestedEvent::$made];

	}//end submit()

	/**
	 * The request event raised for contract `c-1`.
	 *
	 * @param string $decisionType The decision type.
	 *
	 * @return array
	 */
	private static function request(string $decisionType): array {
		$label = '77';
		return ['stackiq', 'stackiq', 'catalogContract', 'c-1', $label, $decisionType, '', ['title' => $label], '77', 'c-1'];

	}//end request()

	/**
	 * A handled approval stores the decision id and the pending state, never the status.
	 *
	 * @return void
	 */
	public function testAHandledApprovalIsRecordedAsPending(): void {
		[$result, $trace, $made] = $this->submit(plan: []);

		$this->assertSame('dec-9', $result);
		$this->assertSame([self::request(decisionType: 'contract')], $made);
		$this->assertSame(
			[
				['find', 'c-1'],
				['dispatchTyped'],
				['saveObject', ['contractNumber' => 77, 'title' => 'Hosting', 'approvalDecisionId' => 'dec-9', 'approvalState' => 'pending'], 'reg-c', 'schema-c', 'c-1'],
				['log', 'info', 'ContractApprovalService: contract submitted for approval', ['contractUuid' => 'c-1', 'decisionId' => 'dec-9', 'decisionType' => 'contract']],
			],
			$trace
		);

	}//end testAHandledApprovalIsRecordedAsPending()

	/**
	 * A renewal raises the renewal decision type.
	 *
	 * @return void
	 */
	public function testARenewalRaisesTheRenewalType(): void {
		[$result, $trace, $made] = $this->submit(plan: ['renewal' => true, 'found' => ['title' => 'Hosting', 'contractNumber' => 77, 'approvalDecisionId' => 'old']]);

		$this->assertSame('dec-9', $result);
		$this->assertSame([self::request(decisionType: 'contract-renewal')], $made);
		$this->assertSame(['saveObject', ['title' => 'Hosting', 'contractNumber' => 77, 'approvalDecisionId' => 'dec-9', 'approvalState' => 'pending'], 'reg-c', 'schema-c', 'c-1'], $trace[2]);
		$this->assertSame('contract-renewal', $trace[3][3]['decisionType']);

	}//end testARenewalRaisesTheRenewalType()

	/**
	 * A contract that cannot be loaded is refused before any request.
	 *
	 * @return void
	 */
	public function testAMissingContractIsRefused(): void {
		[$result, $trace, $made] = $this->submit(plan: ['found' => null]);

		$this->assertSame([\RuntimeException::class, 'Contract not found: c-1', null], $result);
		$this->assertSame([['find', 'c-1']], $trace);
		$this->assertSame([], $made);

	}//end testAMissingContractIsRefused()

	/**
	 * A failing listener, an unhandled request or a missing decision id leave the contract alone.
	 *
	 * @return void
	 */
	public function testAnUnansweredRequestLeavesTheContractAlone(): void {
		[$result, $trace] = $this->submit(plan: ['dispatch' => 'throws']);
		$this->assertSame([\RuntimeException::class, 'Failed to raise the approval decision in decidesk.', 'listener broke'], $result);
		$this->assertSame(
			[
				['find', 'c-1'],
				['dispatchTyped'],
				[
					'log',
					'error',
					'ContractApprovalService: dispatching decidesk decision request failed — contract left in negotiation',
					['contractUuid' => 'c-1', 'error' => 'listener broke'],
				],
			],
			$trace
		);

		[$result, $trace] = $this->submit(plan: ['dispatch' => 'unhandled']);
		$this->assertSame([\RuntimeException::class, 'decidesk did not handle the approval request; contract left in negotiation.', null], $result);
		$this->assertSame([['find', 'c-1'], ['dispatchTyped']], $trace);

		foreach ([null, ''] as $decisionId) {
			[$result, $trace] = $this->submit(plan: ['decisionId' => $decisionId]);
			$this->assertSame([\RuntimeException::class, 'decidesk did not return a decision id; contract left in negotiation.', null], $result);
			$this->assertSame([['find', 'c-1'], ['dispatchTyped']], $trace);
		}

	}//end testAnUnansweredRequestLeavesTheContractAlone()
}//end class
