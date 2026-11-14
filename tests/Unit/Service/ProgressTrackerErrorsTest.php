<?php

/**
 * Unit tests for ProgressTracker::addError(), addWarning() and
 * cleanupOldProgress().
 *
 * Errors and warnings are stored with the operation's progress, so a request
 * reading it later sees them; an error is also logged with the operation id.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ProgressStore;
use OCA\Stackiq\Service\ProgressTracker;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the error and warning log of ProgressTracker.
 */
#[CoversClass(ProgressTracker::class)]
#[UsesClass(ProgressStore::class)]
class ProgressTrackerErrorsTest extends TestCase {

	/**
	 * The distributed cache all trackers share.
	 *
	 * @var array<string, mixed>
	 */
	private array $sharedCache = [];

	/**
	 * Log calls as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * A tracker of one request, sharing the distributed cache.
	 *
	 * @return ProgressTracker
	 */
	private function tracker(): ProgressTracker {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => ($this->sharedCache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0): bool {
				$this->sharedCache[$key] = $value;
				return true;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(true);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'memcache.distributed' ? '\\OC\\Memcache\\Redis' : $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ProgressTracker(
			cacheFactory: $cacheFactory,
			userSession: $this->createMock(IUserSession::class),
			logger: $logger,
			config: $config,
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end tracker()

	/**
	 * Errors and warnings are stored in order, readable from another request.
	 *
	 * @return void
	 */
	public function testErrorsAndWarningsAreStoredWithTheProgress(): void {
		$tracker     = $this->tracker();
		$operationId = $tracker->startOperation(operationType: 'archimate_import', ownerUid: 'admin', operationId: 'op-1');

		$before = time();
		$tracker->addError('Element skipped', ['element' => 'e-1']);
		$tracker->addError('Relation skipped');
		$tracker->addWarning('Unknown property', ['property' => 'colour']);
		$after = time();

		$progress = $this->tracker()->getProgress($operationId);

		$this->assertSame('op-1', $operationId);
		$this->assertSame(
			[['Element skipped', ['element' => 'e-1']], ['Relation skipped', []]],
			array_map(fn (array $error): array => [$error['message'], $error['context']], $progress['errors'])
		);
		$this->assertSame(
			[['Unknown property', ['property' => 'colour']]],
			array_map(fn (array $warning): array => [$warning['message'], $warning['context']], $progress['warnings'])
		);
		foreach ([...$progress['errors'], ...$progress['warnings']] as $entry) {
			$this->assertGreaterThanOrEqual($before, $entry['timestamp']);
			$this->assertLessThanOrEqual($after, $entry['timestamp']);
		}
	}//end testErrorsAndWarningsAreStoredWithTheProgress()

	/**
	 * An error is logged with the operation id; a warning is not logged.
	 *
	 * @return void
	 */
	public function testOnlyErrorsAreLogged(): void {
		$tracker = $this->tracker();
		$tracker->startOperation(operationType: 'archimate_import', ownerUid: 'admin', operationId: 'op-2');
		$this->logs = [];

		$tracker->addError('Element skipped', ['element' => 'e-1']);
		$tracker->addWarning('Unknown property');

		$this->assertSame(
			[['error', 'Progress tracking error', ['operation_id' => 'op-2', 'message' => 'Element skipped', 'context' => ['element' => 'e-1']]]],
			$this->logs
		);
	}//end testOnlyErrorsAreLogged()

	/**
	 * Cleanup only logs: stored entries expire by themselves.
	 *
	 * @return void
	 */
	public function testCleanupOnlyLogsTheRequest(): void {
		$tracker = $this->tracker();
		$tracker->startOperation(operationType: 'archimate_import', ownerUid: 'admin', operationId: 'op-3');
		$stored     = $this->sharedCache;
		$this->logs = [];

		$tracker->cleanupOldProgress(7200);

		$this->assertSame(
			[['debug', 'Progress cleanup requested; stored entries expire on their own', ['max_age' => 7200]]],
			$this->logs
		);
		$this->assertSame($stored, $this->sharedCache);
	}//end testCleanupOnlyLogsTheRequest()
}//end class
