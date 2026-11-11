<?php

/**
 * Unit tests for ContractStatusService::expirePastContracts().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-administration/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The cron run that moves active contracts past their end date to Expired.
 *
 * @covers \OCA\Stackiq\Service\ContractStatusService
 *
 * @spec openspec/specs/contract-administration/spec.md
 */
class ContractStatusServiceExpiryRunTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var ObjectTypeLocatorInterface&MockObject
	 */
	private ObjectTypeLocatorInterface&MockObject $locator;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->locator = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ContractStatusService
	 */
	private function service(): ContractStatusService {
		return new ContractStatusService(container: $this->container, settingsService: $this->locator, logger: $this->logger);
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
	 * Wire the container to the object service and configure register 7 / schema 9.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->container->method('get')->with(ObjectServiceInterface::class)->willReturn($this->objects);
		$this->locator->method('getRegisterIdForObjectType')->with('catalogContract')->willReturn(7);
		$this->locator->method('getSchemaIdForObjectType')->with('catalogContract')->willReturn(9);
	}//end configured()

	/**
	 * A contract object double.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The object body.
	 *
	 * @return ObjectEntityInterface
	 */
	private function contract(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn('7');
		$entity->method('getSchema')->willReturn('9');
		return $entity;
	}//end contract()

	/**
	 * Without a resolvable object service nothing is touched.
	 *
	 * @return void
	 */
	public function testNoObjectServiceSkipsTheRun(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('not registered'));
		$this->locator->expects($this->never())->method('getRegisterIdForObjectType');

		$this->assertSame(expected: 0, actual: $this->service()->expirePastContracts($this->now()));
	}//end testNoObjectServiceSkipsTheRun()

	/**
	 * A container entry that is not the object service contract is treated as unavailable.
	 *
	 * @return void
	 */
	public function testWrongContainerEntrySkipsTheRun(): void {
		$this->container->method('get')->willReturn(new \stdClass());
		$this->locator->expects($this->never())->method('getRegisterIdForObjectType');

		$this->assertSame(expected: 0, actual: $this->service()->expirePastContracts($this->now()));
	}//end testWrongContainerEntrySkipsTheRun()

	/**
	 * Without a configured contract schema nothing is queried.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemaSkipsTheRun(): void {
		$this->container->method('get')->willReturn($this->objects);
		$this->locator->method('getRegisterIdForObjectType')->willReturn(7);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(null);
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame(expected: 0, actual: $this->service()->expirePastContracts($this->now()));
	}//end testUnconfiguredSchemaSkipsTheRun()

	/**
	 * A failing query is logged and expires nothing.
	 *
	 * @return void
	 */
	public function testFailingQueryExpiresNothing(): void {
		$this->configured();
		$this->objects->method('searchObjects')->willThrowException(new RuntimeException('db down'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('error')
			->with('ContractStatusService: failed to query active contracts', ['error' => 'db down']);

		$this->assertSame(expected: 0, actual: $this->service()->expirePastContracts($this->now()));
	}//end testFailingQueryExpiresNothing()

	/**
	 * Only active contracts past their end date are saved, as Expired, with RBAC off and a bounded query.
	 *
	 * @return void
	 */
	public function testOnlyPastActiveContractsAreExpired(): void {
		$this->configured();
		$this->objects->expects($this->once())->method('searchObjects')
			->with(
				['register' => 7, 'schema' => 9, 'status' => 'Active', '_limit' => 5000],
				false,
				false
			)
			->willReturn(
				[
					$this->contract(uuid: 'past', data: ['status' => 'Active', 'endDate' => '2026-06-01', 'name' => 'A']),
					$this->contract(uuid: 'future', data: ['status' => 'Active', 'endDate' => '2027-01-01']),
					$this->contract(uuid: 'open', data: ['status' => 'Active']),
					$this->contract(uuid: 'garbled', data: ['status' => 'Active', 'endDate' => 'not a date']),
				]
			);

		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['status' => 'Expired', 'endDate' => '2026-06-01', 'name' => 'A'],
				[],
				'7',
				'9',
				'past'
			);

		$this->assertSame(expected: 1, actual: $this->service()->expirePastContracts($this->now()));
	}//end testOnlyPastActiveContractsAreExpired()

	/**
	 * A save that fails is logged, not counted, and does not stop the run.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsNotCountedAndTheRunContinues(): void {
		$this->configured();
		$this->objects->method('searchObjects')->willReturn(
			[
				$this->contract(uuid: 'first', data: ['status' => 'Active', 'endDate' => '2026-01-01']),
				$this->contract(uuid: 'second', data: ['status' => 'Active', 'endDate' => '2026-02-01']),
			]
		);
		$saved = $this->contract(uuid: 'second', data: []);
		$this->objects->expects($this->exactly(count: 2))->method('saveObject')
			->willReturnCallback(
				static function (array $object, array $extend, $register, $schema, $uuid) use ($saved) {
					if ($uuid === 'first') {
						throw new RuntimeException('locked');
					}

					return $saved;
				}
			);
		$this->logger->expects($this->once())->method('error')
			->with('ContractStatusService: failed to expire contract', ['uuid' => 'first', 'error' => 'locked']);

		$this->assertSame(expected: 1, actual: $this->service()->expirePastContracts($this->now()));
	}//end testAFailedSaveIsNotCountedAndTheRunContinues()
}//end class
