<?php

/**
 * Unit tests for the daily contract-status background job.
 *
 * Runs ContractStatusJob::run() over the real ContractStatusService, with only
 * the container, the type locator, the app manager, the OpenRegister object
 * service and the logger mocked.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\BackgroundJob\ContractStatusJob;
use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ContractStatusJob.
 *
 * @covers \OCA\Stackiq\BackgroundJob\ContractStatusJob
 * @uses   \OCA\Stackiq\Service\ContractStatusService
 */
class ContractStatusJobTest extends TestCase {

	/**
	 * Every info and error log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * The DI container the service resolves the object service from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Resolves the contract register and schema.
	 *
	 * @var ObjectTypeLocatorInterface&MockObject
	 */
	private ObjectTypeLocatorInterface&MockObject $locator;

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The Nextcloud app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $apps;

	/**
	 * Logger that records info and error calls.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->locator = $this->createMock(ObjectTypeLocatorInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

	}//end setUp()

	/**
	 * The job under test, with the real ContractStatusService.
	 *
	 * @return ContractStatusJob
	 */
	private function job(): ContractStatusJob {
		$service = new ContractStatusService($this->container, $this->locator, $this->logger);
		return new ContractStatusJob(
			$this->createMock(ITimeFactory::class),
			$service,
			$this->apps,
			$this->logger
		);

	}//end job()

	/**
	 * Invoke the job's protected run().
	 *
	 * @param ContractStatusJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(ContractStatusJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);

	}//end runJob()

	/**
	 * Make OpenRegister installed, the object service resolvable and the contract
	 * register/schema configured.
	 *
	 * @return void
	 */
	private function openRegisterReady(): void {
		$this->apps->method('getInstalledApps')->willReturn(['files', 'openregister']);
		$this->container->method('get')->with(ObjectServiceInterface::class)->willReturn($this->objects);
		$this->locator->method('getRegisterIdForObjectType')->with('catalogContract')->willReturn(7);
		$this->locator->method('getSchemaIdForObjectType')->with('catalogContract')->willReturn(12);

	}//end openRegisterReady()

	/**
	 * A contract entity as the object service returns it.
	 *
	 * @param string $uuid The contract uuid.
	 * @param array  $data The contract data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function contract(string $uuid, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getRegister')->willReturn('7');
		$entity->method('getSchema')->willReturn('12');
		return $entity;

	}//end contract()

	/**
	 * The job runs once a day.
	 *
	 * @return void
	 */
	public function testRunsOnceADay(): void {
		$this->assertSame(86400, $this->job()->getInterval());

	}//end testRunsOnceADay()

	/**
	 * Without OpenRegister the job logs the skip and never reaches the service.
	 *
	 * @return void
	 */
	public function testSkipsWhenOpenRegisterIsNotInstalled(): void {
		$this->apps->method('getInstalledApps')->willReturn(['files', 'theming']);
		$this->container->expects($this->never())->method('get');
		$this->locator->expects($this->never())->method('getRegisterIdForObjectType');

		$this->runJob($this->job());

		$this->assertSame(
			[['info', '[ContractStatusJob] OpenRegister not installed, skipping run', []]],
			$this->logs
		);

	}//end testSkipsWhenOpenRegisterIsNotInstalled()

	/**
	 * A past-end-date active contract is saved as Expired; a future one is left alone,
	 * and the job logs how many contracts moved to each status.
	 *
	 * @return void
	 */
	public function testExpiresPastContractsAndLogsTheCount(): void {
		$this->openRegisterReady();
		$past = ['status' => 'Active', 'endDate' => '2000-01-31', 'name' => 'Old'];
		$future = ['status' => 'Active', 'endDate' => '2999-12-31', 'name' => 'New'];
		// contracts-expiry-and-owner: one pass over Active, then Expiring contracts.
		$this->objects->expects($this->exactly(2))
			->method('searchObjects')
			->willReturnCallback(
				function (array $query, bool $rbac, bool $multitenancy) use ($past, $future): array {
					$this->assertSame(['register' => 7, 'schema' => 12, '_limit' => 5000], array_diff_key($query, ['status' => true]));
					$this->assertFalse($rbac);
					$this->assertFalse($multitenancy);
					if ($query['status'] === 'Active') {
						return [$this->contract('u-past', $past), $this->contract('u-future', $future)];
					}

					$this->assertSame('Expiring', $query['status']);
					return [];
				}
			);
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(['status' => 'Expired', 'endDate' => '2000-01-31', 'name' => 'Old'], [], '7', '12', 'u-past');

		$this->runJob($this->job());

		$this->assertSame(
			['info', '[ContractStatusJob] Contract status pass complete', ['expiring' => 0, 'expired' => 1, 'active' => 0]],
			end($this->logs)
		);

	}//end testExpiresPastContractsAndLogsTheCount()

	/**
	 * With no contract register configured the pass completes with zero transitions.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemaCompletesWithZero(): void {
		$this->apps->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturn($this->objects);
		$this->locator->method('getRegisterIdForObjectType')->willReturn(null);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(12);
		$this->objects->expects($this->never())->method('searchObjects');

		$this->runJob($this->job());

		$this->assertSame(
			[
				['info', 'ContractStatusService: contract register/schema not configured, skipping run', []],
				['info', '[ContractStatusJob] Contract status pass complete', ['expiring' => 0, 'expired' => 0, 'active' => 0]],
			],
			$this->logs
		);

	}//end testUnconfiguredSchemaCompletesWithZero()

	/**
	 * A throwable escaping the service is caught and logged, so the cron pass survives.
	 *
	 * @return void
	 */
	public function testAThrowingPassIsLoggedNotRethrown(): void {
		$this->apps->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturn($this->objects);
		$this->locator->method('getRegisterIdForObjectType')
			->willThrowException(new RuntimeException('settings unreadable'));

		$this->runJob($this->job());

		$this->assertCount(1, $this->logs);
		[$level, $message, $context] = $this->logs[0];
		$this->assertSame('error', $level);
		$this->assertSame('[ContractStatusJob] Fatal error during contract status pass — cron pass protected', $message);
		$this->assertSame(['error', 'file', 'line'], array_keys($context));
		$this->assertSame('settings unreadable', $context['error']);
		$this->assertIsString($context['file']);
		$this->assertIsInt($context['line']);

	}//end testAThrowingPassIsLoggedNotRethrown()
}//end class
