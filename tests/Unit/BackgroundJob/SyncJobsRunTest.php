<?php

/**
 * Characterisation tests for the EOL and federation sync jobs' run().
 *
 * Pins the log lines of a normal pass and of a pass that throws (the cron
 * pass is protected), and the federation job's skip when OpenCatalogi is
 * unavailable, before run() is split into named steps.
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

use OCA\Stackiq\BackgroundJob\EolSyncJob;
use OCA\Stackiq\BackgroundJob\FederationSyncJob;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EolSyncJob::run() and FederationSyncJob::run().
 */
class SyncJobsRunTest extends TestCase {

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * A logger recording info and error calls.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return $logger;

	}//end logger()

	/**
	 * Invoke a job's protected run().
	 *
	 * @param object $job The job
	 *
	 * @return void
	 */
	private function runJob(object $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);

	}//end runJob()

	/**
	 * The error context with file and line made comparable.
	 *
	 * @param int $index Log index
	 *
	 * @return array
	 */
	private function errorContext(int $index): array {
		$context = $this->logs[$index][2];
		$this->assertIsString($context['file']);
		$this->assertIsInt($context['line']);
		return ['error' => $context['error'], 'keys' => array_keys($context)];

	}//end errorContext()

	/**
	 * The EOL job logs the run status, or the fatal error when the run throws.
	 *
	 * @return void
	 */
	public function testEolSyncRun(): void {
		$service = $this->createMock(EolSyncService::class);
		$service->method('getConfig')->willReturn([]);
		$service->method('run')->willReturnOnConsecutiveCalls(['checked' => 3], $this->throwException(new \RuntimeException('feed down')));
		$job = new EolSyncJob($this->createMock(ITimeFactory::class), $service, $this->logger());

		$this->runJob($job);
		$this->runJob($job);

		$this->assertSame(['info', '[EolSyncJob] EOL sync run completed', ['checked' => 3]], $this->logs[0]);
		$this->assertSame(['error', '[EolSyncJob] Fatal error during EOL sync — cron pass protected'], array_slice($this->logs[1], 0, 2));
		$this->assertSame(['error' => 'feed down', 'keys' => ['error', 'file', 'line']], $this->errorContext(1));
		$this->assertCount(2, $this->logs);

	}//end testEolSyncRun()

	/**
	 * The federation job skips without OpenCatalogi, logs both passes, or the fatal error.
	 *
	 * @return void
	 */
	public function testFederationSyncRun(): void {
		$federation = $this->createMock(FederationService::class);
		$federation->method('isAvailable')->willReturnOnConsecutiveCalls(false, true, true);
		$federation->method('announce')->willReturnOnConsecutiveCalls(['announced' => 1], $this->throwException(new \RuntimeException('peer gone')));
		$federation->expects($this->once())->method('pullAllPeers')->willReturn(['pulled' => 2]);
		$config = $this->createMock(FederationConfig::class);
		$config->method('getSyncInterval')->willReturn(3600);
		$job = new FederationSyncJob($this->createMock(ITimeFactory::class), $federation, $config, $this->logger());

		$this->runJob($job);
		$this->runJob($job);
		$this->runJob($job);

		$this->assertSame(
			[
				['info', '[FederationSyncJob] OpenCatalogi unavailable, skipping run', []],
				['info', '[FederationSyncJob] Announce pass complete', ['result' => ['announced' => 1]]],
				['info', '[FederationSyncJob] Peer-pull pass complete', ['result' => ['pulled' => 2]]],
			],
			array_slice($this->logs, 0, 3)
		);
		$this->assertSame(['error', '[FederationSyncJob] Fatal error during federation pass — cron pass protected'], array_slice($this->logs[3], 0, 2));
		$this->assertSame(['error' => 'peer gone', 'keys' => ['error', 'file', 'line']], $this->errorContext(3));
		$this->assertCount(4, $this->logs);

	}//end testFederationSyncRun()
}//end class
