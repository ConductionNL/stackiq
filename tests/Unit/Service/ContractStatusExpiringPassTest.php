<?php

/**
 * The daily contract pass moving contracts into and out of Expiring
 * (contracts-expiry-and-owner REQ-CEO-001, REQ-CEO-002).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Active to Expiring inside the window, Active or Expiring to Expired, Expiring back to Active.
 *
 * @covers \OCA\Stackiq\Service\ContractStatusService
 */
class ContractStatusExpiringPassTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The saves made, uuid => status.
	 *
	 * @var array<string, string>
	 */
	private array $saved = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saved = [];
	}//end setUp()

	/**
	 * The service with the given window setting (null: no app config at all).
	 *
	 * @param int|null $window The stored `contract_expiry_window_days`.
	 *
	 * @return ContractStatusService
	 */
	private function service(?int $window = 90): ContractStatusService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectServiceInterface::class)->willReturn($this->objects);
		$locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$locator->method('getRegisterIdForObjectType')->willReturn(7);
		$locator->method('getSchemaIdForObjectType')->willReturn(9);
		$config = null;
		if ($window !== null) {
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueInt')->with('stackiq', 'contract_expiry_window_days', 90)->willReturn($window);
		}

		return new ContractStatusService($container, $locator, $this->logger, $config);
	}//end service()

	/**
	 * A fixed "now".
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-06-14T12:00:00+00:00');
	}//end now()

	/**
	 * A contract double.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The object body.
	 *
	 * @return ObjectEntityInterface
	 */
	private function contract(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn('7');
		$entity->method('getSchema')->willReturn('9');
		return $entity;
	}//end contract()

	/**
	 * Serve these contracts per queried status and record every save.
	 *
	 * @param array<string, array<int, ObjectEntityInterface>> $byStatus Contracts per status.
	 *
	 * @return void
	 */
	private function serve(array $byStatus): void {
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy) use ($byStatus): array {
				$this->assertSame(['register' => 7, 'schema' => 9, 'status' => $query['status'], '_limit' => 5000], $query);
				$this->assertFalse($_rbac);
				return $byStatus[$query['status']] ?? [];
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, array $extend, $register, $schema, $uuid) {
				$this->saved[$uuid] = $object['status'];
				return $this->contract(uuid: $uuid, data: $object);
			}
		);
	}//end serve()

	/**
	 * The acceptance table of task 2, in one pass.
	 *
	 * @return void
	 */
	public function testOnePassMovesEachContractToItsStatus(): void {
		$this->serve([
			'Active' => [
				$this->contract(uuid: 'ends-in-30', data: ['status' => 'Active', 'endDate' => '2026-07-14']),
				$this->contract(uuid: 'ends-today', data: ['status' => 'Active', 'endDate' => '2026-06-14']),
				$this->contract(uuid: 'ends-in-200', data: ['status' => 'Active', 'endDate' => '2026-12-31']),
				$this->contract(uuid: 'ended', data: ['status' => 'Active', 'endDate' => '2026-06-01']),
				$this->contract(uuid: 'open', data: ['status' => 'Active']),
			],
			'Expiring' => [
				$this->contract(uuid: 'expiring-ended-yesterday', data: ['status' => 'Expiring', 'endDate' => '2026-06-13']),
				$this->contract(uuid: 'extended-two-years', data: ['status' => 'Expiring', 'endDate' => '2028-06-14']),
				$this->contract(uuid: 'still-expiring', data: ['status' => 'Expiring', 'endDate' => '2026-08-01']),
			],
		]);

		$counts = $this->service()->updateContractStatuses($this->now());

		$this->assertSame(
			[
				'ends-in-30' => 'Expiring',
				'ends-today' => 'Expiring',
				'ended' => 'Expired',
				'expiring-ended-yesterday' => 'Expired',
				'extended-two-years' => 'Active',
			],
			$this->saved
		);
		$this->assertSame(['expiring' => 2, 'expired' => 2, 'active' => 1], $counts);
	}//end testOnePassMovesEachContractToItsStatus()

	/**
	 * In negotiation and Expired contracts are never fetched, so never saved.
	 *
	 * @return void
	 */
	public function testOnlyActiveAndExpiringAreQueried(): void {
		$queried = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query) use (&$queried): array {
				$queried[] = $query['status'];
				return [];
			}
		);
		$this->objects->expects($this->never())->method('saveObject');

		$this->service()->updateContractStatuses($this->now());

		$this->assertSame(['Active', 'Expiring'], $queried);
	}//end testOnlyActiveAndExpiringAreQueried()

	/**
	 * An empty, zero or negative window, or no app config, uses 90 days.
	 *
	 * @return void
	 */
	public function testANonPositiveWindowUsesNinetyDays(): void {
		foreach ([0, -5, null] as $window) {
			$this->assertSame(90, $this->service(window: $window)->windowDays(), var_export($window, true));
		}

		$this->assertSame(30, $this->service(window: 30)->windowDays());
	}//end testANonPositiveWindowUsesNinetyDays()

	/**
	 * The window bounds Expiring: 31 days out is Expiring at 31 and not at 30.
	 *
	 * @return void
	 */
	public function testTheWindowBoundsExpiring(): void {
		$contract = ['status' => 'Active', 'endDate' => '2026-07-15'];

		$this->assertTrue($this->service()->shouldStartExpiring($contract, $this->now(), 31));
		$this->assertFalse($this->service()->shouldStartExpiring($contract, $this->now(), 30));
		$this->assertTrue($this->service()->shouldReturnToActive(['status' => 'Expiring', 'endDate' => '2026-07-15'], $this->now(), 30));
		$this->assertFalse($this->service()->shouldReturnToActive(['status' => 'Expiring', 'endDate' => '2026-07-15'], $this->now(), 31));
	}//end testTheWindowBoundsExpiring()

	/**
	 * Unparseable or missing end dates never move a contract.
	 *
	 * @return void
	 */
	public function testAnUnreadableEndDateMovesNothing(): void {
		$service = $this->service();
		foreach ([['status' => 'Active'], ['status' => 'Active', 'endDate' => 'soon'], ['status' => 'Expiring', 'endDate' => '']] as $data) {
			$this->assertFalse($service->shouldStartExpiring($data, $this->now(), 90));
			$this->assertFalse($service->shouldReturnToActive($data, $this->now(), 90));
			$this->assertFalse($service->shouldExpire($data, $this->now()));
		}
	}//end testAnUnreadableEndDateMovesNothing()

	/**
	 * A failing query for one status still runs the other; a failing save is logged and not counted.
	 *
	 * @return void
	 */
	public function testFailuresAreLoggedAndTheRunContinues(): void {
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				if ($query['status'] === 'Active') {
					throw new RuntimeException('db down');
				}

				return [$this->contract(uuid: 'x', data: ['status' => 'Expiring', 'endDate' => '2026-01-01'])];
			}
		);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message) use (&$errors): void {
				$errors[] = $message;
			}
		);

		$this->assertSame(['expiring' => 0, 'expired' => 0, 'active' => 0], $this->service()->updateContractStatuses($this->now()));
		$this->assertSame(
			['ContractStatusService: failed to query contracts', 'ContractStatusService: failed to move contract'],
			$errors
		);
	}//end testFailuresAreLoggedAndTheRunContinues()

	/**
	 * Without OpenRegister nothing runs and nothing is counted.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingRuns(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no app'));
		$service = new ContractStatusService($container, $this->createMock(ObjectTypeLocatorInterface::class), $this->logger);

		$this->assertSame(['expiring' => 0, 'expired' => 0, 'active' => 0], $service->updateContractStatuses($this->now()));
	}//end testWithoutOpenRegisterNothingRuns()
}//end class
