<?php

/**
 * ProgressStore keeps operation progress and cancel flags in a shared cache when one exists, and in app config with an expiry when not.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ProgressStore;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProgressStore.
 */
#[CoversClass(ProgressStore::class)]
class ProgressStoreTest extends TestCase {

	/**
	 * The in-memory app config rows, key => value.
	 *
	 * @var array<string,array>
	 */
	private array $rows = [];

	/**
	 * The in-memory cache entries, key => [value, ttl].
	 *
	 * @var array<string,array{0:mixed,1:int}>
	 */
	private array $cached = [];

	/**
	 * Whether the store used the app config at all.
	 *
	 * @var bool
	 */
	private bool $configTouched = false;

	/**
	 * Build a store over the given cache setup.
	 *
	 * @param bool   $available   Whether a cache is available.
	 * @param string $distributed The memcache.distributed class.
	 * @param string $local       The memcache.local class.
	 *
	 * @return ProgressStore
	 */
	private function store(bool $available, string $distributed = '', string $local = ''): ProgressStore {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key][0] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->cached[$key] = [$value, $ttl];
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cached[$key]);
				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->method('createDistributed')->willReturn($cache);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'memcache.distributed' ? $distributed : $local)
		);

		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueArray')->willReturnCallback(
			function (string $appId, string $key, array $default = []): array {
				$this->configTouched = true;
				return ($this->rows[$key] ?? $default);
			}
		);
		$app->method('setValueArray')->willReturnCallback(
			function (string $appId, string $key, array $value): bool {
				$this->configTouched = true;
				$this->rows[$key] = $value;
				return true;
			}
		);
		$app->method('deleteKey')->willReturnCallback(
			function (string $appId, string $key): void {
				$this->configTouched = true;
				unset($this->rows[$key]);
			}
		);
		$app->method('searchKeys')->willReturnCallback(
			function (string $appId, string $prefix = ''): array {
				$this->configTouched = true;
				return array_values(array_filter(array_keys($this->rows), static fn (string $k): bool => str_starts_with($k, $prefix)));
			}
		);

		return new ProgressStore($factory, $config, $app);
	}//end store()

	/**
	 * A shared cache (Redis) holds progress and cancel flags with the store TTL,
	 * and app config is never used.
	 *
	 * @return void
	 */
	public function testASharedCacheHoldsEverything(): void {
		$store = $this->store(available: true, distributed: '\OC\Memcache\Redis');

		$store->setProgress('op-1', ['status' => 'running', 'phase' => 'a']);
		$store->requestCancel('op-1');

		$this->assertSame(['status' => 'running', 'phase' => 'a'], $store->getProgress('op-1'));
		$this->assertSame(ProgressStore::STORE_TTL, $this->cached['progress_op-1'][1]);
		$this->assertTrue($store->isCancelRequested('op-1'));

		$store->clearCancel('op-1');
		$this->assertFalse($store->isCancelRequested('op-1'));

		$store->removeExpired();
		$this->assertFalse($this->configTouched);
		$this->assertSame([], $this->rows);
	}//end testASharedCacheHoldsEverything()

	/**
	 * A local-only or unavailable cache is not shared, so app config is used.
	 *
	 * @return void
	 */
	public function testAnUnsharedCacheFallsBackToAppConfig(): void {
		foreach ([[true, '', '\OC\Memcache\APCu'], [true, 'OC\Memcache\ArrayCache', ''], [false, '\OC\Memcache\Redis', '']] as [$available, $distributed, $local]) {
			$this->rows = [];
			$this->cached = [];
			$store = $this->store($available, $distributed, $local);

			$store->setProgress('op-1', ['status' => 'done']);

			$this->assertSame([], $this->cached);
			$this->assertCount(1, $this->rows);
			$this->assertSame(['status' => 'done'], $store->getProgress('op-1'));
		}
	}//end testAnUnsharedCacheFallsBackToAppConfig()

	/**
	 * In app config a value is stored under a hashed key with an expiry, and
	 * an expired value reads as absent.
	 *
	 * @return void
	 */
	public function testAppConfigEntriesExpire(): void {
		$store = $this->store(available: false);

		$store->setProgress('op-1', ['status' => 'done']);

		$key = 'op_progress_' . sha1('op-1');
		$this->assertArrayHasKey($key, $this->rows);
		$this->assertGreaterThan(time(), $this->rows[$key]['expires']);
		$this->assertSame(['status' => 'done'], $this->rows[$key]['value']);

		$this->rows[$key]['expires'] = time() - 10;
		$this->assertNull($store->getProgress('op-1'));
		$this->assertNull($store->getProgress('never-started'));
	}//end testAppConfigEntriesExpire()

	/**
	 * A change of status is always written, even straight after another write.
	 *
	 * @return void
	 */
	public function testAStatusChangeIsAlwaysWritten(): void {
		$store = $this->store(available: false);

		$store->setProgress('op-1', ['status' => 'running', 'phase' => 'a']);
		$store->setProgress('op-1', ['status' => 'running', 'phase' => 'b']);
		$this->assertSame(['status' => 'running', 'phase' => 'b'], $store->getProgress('op-1'));

		$store->setProgress('op-1', ['status' => 'done', 'phase' => 'b']);
		$this->assertSame(['status' => 'done', 'phase' => 'b'], $store->getProgress('op-1'));
	}//end testAStatusChangeIsAlwaysWritten()

	/**
	 * A cancel request is seen through app config, stays seen, and is cleared
	 * by deleting its key.
	 *
	 * @return void
	 */
	public function testCancelThroughAppConfig(): void {
		$store = $this->store(available: false);

		$store->requestCancel('op-1');
		$this->assertTrue($store->isCancelRequested('op-1'));
		$this->assertTrue($store->isCancelRequested('op-1'));

		$store->clearCancel('op-1');
		$this->assertArrayNotHasKey('op_cancel_' . sha1('op-1'), $this->rows);
		$this->assertFalse($store->isCancelRequested('op-1'));
	}//end testCancelThroughAppConfig()

	/**
	 * removeExpired deletes only expired store rows.
	 *
	 * @return void
	 */
	public function testRemoveExpiredKeepsLiveRows(): void {
		$this->rows = [
			'op_progress_old' => ['expires' => time() - 5, 'value' => []],
			'op_progress_new' => ['expires' => time() + 600, 'value' => []],
			'other_setting' => ['expires' => 0],
		];

		$this->store(available: false)->removeExpired();

		$this->assertSame(['op_progress_new', 'other_setting'], array_keys($this->rows));
	}//end testRemoveExpiredKeepsLiveRows()
}//end class
