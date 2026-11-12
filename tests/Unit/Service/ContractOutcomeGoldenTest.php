<?php

/**
 * Golden test for the decision outcome path of ContractApprovalService.
 *
 * Pins, per case, the contract the external-reference fallback of
 * resolveContractForOutcome matches (and refuses), and what projectOutcome
 * writes and logs, with every object service, locator and logger call in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/SaveCallShape.php';

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Tests\Unit\Support\SaveCallShape;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Golden cases for matching and projecting a concluded decision outcome.
 *
 * @covers \OCA\Stackiq\Service\ContractApprovalService
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
class ContractOutcomeGoldenTest extends TestCase {

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
	 * The default plan, overridden per case.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultPlan(): array {
		return [
			'objectService' => true,
			'registerId'    => 11,
			'schemaId'      => 12,
			'searchThrows'  => false,
			'contracts'     => [],
			'found'         => null,
		];

	}//end defaultPlan()

	/**
	 * A contract double.
	 *
	 * @param array  $data The contract data.
	 * @param string $uuid The contract uuid.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function contract(array $data, string $uuid): ObjectEntityInterface {
		return new class($data, $uuid) implements ObjectEntityInterface {
			/**
			 * Build the double.
			 *
			 * @param array  $data The contract data.
			 * @param string $uuid The contract uuid.
			 */
			public function __construct(private array $data, private string $uuid) {
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @return string|null
			 */
			public function getUuid(): ?string {
				return $this->uuid;
			}

			/**
			 * @return string|null
			 */
			public function getRegister(): ?string {
				return 'reg-of-contract';
			}

			/**
			 * @return string|null
			 */
			public function getSchema(): ?string {
				return 'schema-of-contract';
			}

			/**
			 * @return string|null
			 */
			public function getOrganisation(): ?string {
				return null;
			}

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return null;
			}

			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};

	}//end contract()

	/**
	 * The object service double.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy) {
				$this->trace[] = ['searchObjects', $query, $_rbac, $_multitenancy];
				if ($this->plan['searchThrows'] === true) {
					throw new \RuntimeException('search down');
				}

				return $this->plan['contracts'];
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy) {
				$this->trace[] = ['find', $id, $register, $schema, $_rbac, $_multitenancy];
				return $this->plan['found'];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) {
				[$object, , $register, $schema, $uuid] = SaveCallShape::flat(args: $args);
				$this->trace[] = ['saveObject', $object, $register, $schema, $uuid];
				return self::contract(data: $object, uuid: $uuid);
			}
		);

		return $objects;

	}//end objectService()

	/**
	 * Build the service with the plan's doubles.
	 *
	 * @return ContractApprovalService
	 */
	private function service(): ContractApprovalService {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				$this->trace[] = ['container.get', $id];
				if ($this->plan['objectService'] === false) {
					throw new \RuntimeException('no openregister');
				}

				return $this->objectService();
			}
		);

		$locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturnCallback(
			function (string $objectType) {
				$this->trace[] = ['registerId', $objectType];
				return $this->plan['registerId'];
			}
		);
		$locator->method('getSchemaIdForObjectType')->willReturnCallback(
			function (string $objectType) {
				$this->trace[] = ['schemaId', $objectType];
				return $this->plan['schemaId'];
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

		return new ContractApprovalService(
			$container,
			$locator,
			$this->createMock(originalClassName: IEventDispatcher::class),
			$logger
		);

	}//end service()

	/**
	 * Resolve through the external reference only (no subject id).
	 *
	 * @param array  $plan              Overrides of the default plan.
	 * @param string $externalReference The carried contract number.
	 * @param string $decisionId        The concluded decision id.
	 *
	 * @return array{0: string|null, 1: array}
	 */
	private function resolve(array $plan, string $externalReference, string $decisionId): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);
		$result      = $this->service()->resolveContractForOutcome('', $externalReference, $decisionId);

		return [$result, $this->trace];

	}//end resolve()

	/**
	 * Project an outcome onto the plan's found contract.
	 *
	 * @param array  $plan          Overrides of the default plan.
	 * @param string $outcomeStatus The outcome status.
	 *
	 * @return array{0: bool, 1: array}
	 */
	private function project(array $plan, string $outcomeStatus): array {
		$this->trace = [];
		$this->plan  = array_merge(self::defaultPlan(), $plan);
		$result      = $this->service()->projectOutcome('contract-uuid', $outcomeStatus);

		return [$result, $this->trace];

	}//end project()

	/**
	 * The warning resolveContractForOutcome logs when nothing matches.
	 *
	 * @param string $externalReference The carried contract number.
	 * @param string $decisionId        The concluded decision id.
	 *
	 * @return array
	 */
	private static function noMatch(string $externalReference, string $decisionId): array {
		return [
			'log',
			'warning',
			'ContractApprovalService: concluded outcome did not match any contract (decision/contract mismatch)',
			['subjectId' => '', 'externalReference' => $externalReference, 'decisionId' => $decisionId],
		];

	}//end noMatch()

	/**
	 * The calls before the contract search.
	 *
	 * @return array
	 */
	private static function lookup(): array {
		return [
			['container.get', ObjectServiceInterface::class],
			['registerId', 'catalogContract'],
			['schemaId', 'catalogContract'],
		];

	}//end lookup()

	/**
	 * The search for contracts of a decision.
	 *
	 * @param string $decisionId The concluded decision id.
	 *
	 * @return array
	 */
	private static function search(string $decisionId): array {
		return [
			'searchObjects',
			['register' => 11, 'schema' => 12, 'approvalDecisionId' => $decisionId, '_limit' => 50],
			false,
			false,
		];

	}//end search()

	/**
	 * The matching contract number is allowed, first match wins, and the number is compared as a string.
	 *
	 * @return void
	 */
	public function testMatchingContractNumberIsResolved(): void {
		$contracts = [
			self::contract(data: ['contractNumber' => 'C-1'], uuid: 'other'),
			self::contract(data: [], uuid: 'no-number'),
			self::contract(data: ['contractNumber' => 42], uuid: 'numeric'),
			self::contract(data: ['contractNumber' => '42'], uuid: 'second'),
		];

		[$result, $trace] = $this->resolve(plan: ['contracts' => $contracts], externalReference: '42', decisionId: 'dec-1');
		$this->assertSame('numeric', $result);
		$this->assertSame(array_merge(self::lookup(), [self::search(decisionId: 'dec-1')]), $trace);

		[$result] = $this->resolve(plan: ['contracts' => $contracts], externalReference: 'C-1', decisionId: 'dec-1');
		$this->assertSame('other', $result);

	}//end testMatchingContractNumberIsResolved()

	/**
	 * A contract number that matches none of the decision's contracts is refused.
	 *
	 * @return void
	 */
	public function testMismatchedContractNumberIsRefused(): void {
		$contracts = [
			self::contract(data: ['contractNumber' => 'C-1'], uuid: 'other'),
			self::contract(data: ['contractNumber' => 'c-2'], uuid: 'case'),
		];

		[$result, $trace] = $this->resolve(plan: ['contracts' => $contracts], externalReference: 'C-2', decisionId: 'dec-1');
		$this->assertNull($result);
		$this->assertSame(
			array_merge(self::lookup(), [self::search(decisionId: 'dec-1'), self::noMatch(externalReference: 'C-2', decisionId: 'dec-1')]),
			$trace
		);

		[$result, $trace] = $this->resolve(plan: ['contracts' => $contracts], externalReference: '', decisionId: 'dec-1');
		$this->assertNull($result);
		$this->assertSame([self::noMatch(externalReference: '', decisionId: 'dec-1')], $trace);

		[$result, $trace] = $this->resolve(plan: ['contracts' => $contracts], externalReference: 'C-1', decisionId: '');
		$this->assertNull($result);
		$this->assertSame([self::noMatch(externalReference: 'C-1', decisionId: '')], $trace);

	}//end testMismatchedContractNumberIsRefused()

	/**
	 * Missing OpenRegister, register, schema or a failing search refuse the match.
	 *
	 * @return void
	 */
	public function testUnavailableLookupIsRefused(): void {
		$contracts = [self::contract(data: ['contractNumber' => 'C-1'], uuid: 'other')];

		[$result, $trace] = $this->resolve(plan: ['contracts' => $contracts, 'objectService' => false], externalReference: 'C-1', decisionId: 'd');
		$this->assertNull($result);
		$this->assertSame(
			[
				['container.get', ObjectServiceInterface::class],
				['log', 'debug', 'ContractApprovalService: ObjectService not resolvable', ['error' => 'no openregister']],
				self::noMatch(externalReference: 'C-1', decisionId: 'd'),
			],
			$trace
		);

		foreach ([['registerId' => null], ['schemaId' => null]] as $missing) {
			[$result, $trace] = $this->resolve(plan: array_merge(['contracts' => $contracts], $missing), externalReference: 'C-1', decisionId: 'd');
			$this->assertNull($result);
			$this->assertSame(array_merge(self::lookup(), [self::noMatch(externalReference: 'C-1', decisionId: 'd')]), $trace);
		}

		[$result, $trace] = $this->resolve(plan: ['contracts' => $contracts, 'searchThrows' => true], externalReference: 'C-1', decisionId: 'd');
		$this->assertNull($result);
		$this->assertSame(
			array_merge(
				self::lookup(),
				[
					self::search(decisionId: 'd'),
					['log', 'error', 'ContractApprovalService: failed to query contract by decision id', ['error' => 'search down']],
					self::noMatch(externalReference: 'C-1', decisionId: 'd'),
				]
			),
			$trace
		);

	}//end testUnavailableLookupIsRefused()

	/**
	 * The calls a projection makes up to loading the contract.
	 *
	 * @return array
	 */
	private static function load(): array {
		return array_merge(self::lookup(), [['find', 'contract-uuid', 11, 12, false, false]]);

	}//end load()

	/**
	 * The trace of a projection that writes.
	 *
	 * @param array  $data          The written data.
	 * @param string $outcomeStatus The outcome status.
	 *
	 * @return array
	 */
	private static function written(array $data, string $outcomeStatus): array {
		return array_merge(
			self::load(),
			[
				['container.get', ObjectServiceInterface::class],
				['saveObject', $data, 'reg-of-contract', 'schema-of-contract', 'uuid-1'],
				['log', 'info', 'ContractApprovalService: outcome projected onto contract', ['contractUuid' => 'contract-uuid', 'outcomeStatus' => $outcomeStatus]],
			]
		);

	}//end written()

	/**
	 * Approved activates the contract; rejected and withdrawn reject it without touching the status.
	 *
	 * @return void
	 */
	public function testTerminalOutcomesAreProjected(): void {
		$cases = [
			['approved', ['title' => 'x'], ['title' => 'x', 'approvalState' => 'approved', 'status' => 'Active']],
			['approved', ['status' => 'In negotiation', 'approvalState' => 'pending'], ['status' => 'Active', 'approvalState' => 'approved']],
			['approved', ['approvalState' => 'approved', 'status' => null], ['approvalState' => 'approved', 'status' => 'Active']],
			['approved', ['approvalState' => 'approved', 'status' => 'In negotiation'], ['approvalState' => 'approved', 'status' => 'Active']],
			['approved', ['status' => 'Active', 'approvalState' => null], ['status' => 'Active', 'approvalState' => 'approved']],
			['rejected', ['status' => 'In negotiation'], ['status' => 'In negotiation', 'approvalState' => 'rejected']],
			['withdrawn', ['approvalState' => 'pending', 'status' => 'In negotiation'], ['approvalState' => 'rejected', 'status' => 'In negotiation']],
			['rejected', ['approvalState' => 'approved', 'status' => 'Active'], ['approvalState' => 'rejected', 'status' => 'Active']],
		];
		foreach ($cases as [$outcome, $data, $expected]) {
			[$result, $trace] = $this->project(plan: ['found' => self::contract(data: $data, uuid: 'uuid-1')], outcomeStatus: $outcome);
			$this->assertTrue($result, $outcome);
			$this->assertSame(self::written(data: $expected, outcomeStatus: $outcome), $trace, $outcome);
		}

	}//end testTerminalOutcomesAreProjected()

	/**
	 * Re-received, pending, unknown and differently-cased outcomes write nothing.
	 *
	 * @return void
	 */
	public function testNoOpOutcomesWriteNothing(): void {
		$cases = [
			['approved', ['approvalState' => 'approved', 'status' => 'Active']],
			['rejected', ['approvalState' => 'rejected', 'status' => 'In negotiation']],
			['withdrawn', ['approvalState' => 'rejected']],
			['pending', ['approvalState' => 'none']],
			['Approved', []],
			['', []],
			['concluded', ['approvalState' => 'pending']],
		];
		foreach ($cases as [$outcome, $data]) {
			[$result, $trace] = $this->project(plan: ['found' => self::contract(data: $data, uuid: 'uuid-1')], outcomeStatus: $outcome);
			$this->assertFalse($result, $outcome);
			$this->assertSame(self::load(), $trace, $outcome);
		}

	}//end testNoOpOutcomesWriteNothing()

	/**
	 * An outcome for a contract that cannot be loaded is ignored with a warning.
	 *
	 * @return void
	 */
	public function testUnknownContractIsIgnored(): void {
		[$result, $trace] = $this->project(plan: ['found' => null], outcomeStatus: 'approved');

		$this->assertFalse($result);
		$this->assertSame(
			array_merge(
				self::load(),
				[['log', 'warning', 'ContractApprovalService: outcome for unknown contract ignored', ['contractUuid' => 'contract-uuid']]]
			),
			$trace
		);

	}//end testUnknownContractIsIgnored()
}//end class
