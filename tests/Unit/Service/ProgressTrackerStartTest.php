<?php

/**
 * Characterisation tests for ProgressTracker::startOperation.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ProgressTracker;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What starting an operation stores and logs.
 */
class ProgressTrackerStartTest extends TestCase {

	/**
	 * The distributed cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $sharedCache = [];

	/**
	 * Info log calls, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A tracker on the shared cache, signed in as `admin`.
	 *
	 * @return ProgressTracker The tracker.
	 */
	private function tracker(): ProgressTracker {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => $this->sharedCache[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0): bool {
				$this->sharedCache[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$factory->method('isAvailable')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (...$args): void {
				$this->logged[] = $args;
			}
		);

		return new ProgressTracker(
			cacheFactory: $factory,
			userSession: $userSession,
			logger: $logger,
			config: $this->createConfiguredMock(IConfig::class, ['getSystemValueString' => '\\OC\\Memcache\\Redis']),
			appConfig: $this->createMock(IAppConfig::class)
		);
	}//end tracker()

	/**
	 * The stored progress is pinned key for key, and one info line is logged.
	 *
	 * @return void
	 */
	public function testTheStartedProgressIsPinnedKeyForKey(): void {
		$before = time();
		$id     = $this->tracker()->startOperation(
			operationType: 'import',
			options: ['total_items' => 12, 'statistics' => ['schemas' => 3]],
			ownerUid: 'jan',
			operationId: 'import_fixed'
		);
		$after = time();

		$progress = $this->tracker()->getProgress('import_fixed');
		$this->assertSame('import_fixed', $id);
		$this->assertGreaterThanOrEqual($before, $progress['start_time']);
		$this->assertLessThanOrEqual($after, $progress['start_time']);
		$progress['start_time'] = 'T';
		$this->assertSame(
			[
				'operation_id' => 'import_fixed',
				'operation_type' => 'import',
				'owner_uid' => 'jan',
				'status' => 'running',
				'phase' => 'initializing',
				'phase_description' => 'Initializing',
				'total_items' => 12,
				'processed_items' => 0,
				'current_item_type' => null,
				'current_item_name' => null,
				'percentage' => 0,
				'start_time' => 'T',
				'estimated_completion' => null,
				'errors' => [],
				'warnings' => [],
				'statistics' => ['schemas' => 3],
			],
			$progress
		);
		$this->assertSame(
			[['Started progress tracking', ['operation_id' => 'import_fixed', 'operation_type' => 'import', 'total_items' => 12]]],
			$this->logged
		);
	}//end testTheStartedProgressIsPinnedKeyForKey()

	/**
	 * Without an id or owner, the id is generated from the type and the owner is the signed-in user.
	 *
	 * @return void
	 */
	public function testDefaultsTheIdAndTheOwner(): void {
		$id = $this->tracker()->startOperation(operationType: 'export');

		$this->assertMatchesRegularExpression('/^export_[0-9a-f]+\\.[0-9]+$/', $id);
		$progress = $this->tracker()->getProgress($id);
		$this->assertSame('admin', $progress['owner_uid']);
		$this->assertSame(0, $progress['total_items']);
		$this->assertSame([], $progress['statistics']);
	}//end testDefaultsTheIdAndTheOwner()
}//end class
