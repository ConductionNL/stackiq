<?php

/**
 * ProgressStore
 *
 * Where ProgressTracker keeps progress snapshots and cancel requests: a store
 * every request reads, whatever the instance's memcache configuration.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

/**
 * Entries that live STORE_TTL seconds and that every request can read.
 *
 * The store is Nextcloud's distributed cache when every server and the CLI
 * share it (Redis, Memcached). Without such a cache (no memcache at all, or
 * only APCu, which each node and the CLI keep to themselves) the app config
 * stands in: the database every request reads. Writes of a running operation
 * there are spaced out to one per second, cancel requests are read at most
 * once a second, and every read goes to the database rather than to the
 * config cache of the reading request.
 *
 * That read costs a reload of the whole app config, lazy values of every app
 * included (a normal request loads only the non-lazy rows), and with APCu it
 * drops the node's cached copy. A progress stream does it once a second; a
 * running import about twice a second, as the write after a cancel read
 * reloads it again. That is accepted as the price of a store every request
 * sees, on instances that run without a shared cache; a dedicated table is
 * the alternative if it ever shows up.
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
 */
class ProgressStore {

	/**
	 * How long an entry lives after its last write, in seconds.
	 */
	public const STORE_TTL = 3600;

	/**
	 * Cache classes not shared between servers or between the web server and the CLI.
	 */
	private const UNSHARED_CACHES = [
		'',
		'OC\Memcache\APCu',
		'OC\Memcache\ArrayCache',
		'OC\Memcache\NullCache',
	];

	/**
	 * Key prefix of the app config entries that stand in for the cache.
	 */
	private const CONFIG_PREFIX = 'op_';

	/**
	 * Least number of seconds between two app config writes or cancel reads of one operation.
	 */
	private const CONFIG_INTERVAL = 1;

	/**
	 * The shared cache, or null when the app config stands in for it.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Per operation: when this request last wrote its snapshot to the app config, and in which phase and status.
	 *
	 * @var array<string, array{at: int, state: string}>
	 */
	private array $lastWrites = [];

	/**
	 * Per operation: the cancel answer this request last read from the app config.
	 *
	 * @var array<string, array{at: int, cancelled: bool}>
	 */
	private array $cancelChecks = [];

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory The cache factory.
	 * @param IConfig       $config       System config, which names the distributed cache class.
	 * @param IAppConfig    $appConfig    App config, the store when no shared cache is configured.
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		IConfig $config,
		private readonly IAppConfig $appConfig,
	) {
		if (self::hasSharedCache(cacheFactory: $cacheFactory, config: $config) === true) {
			$this->cache = $cacheFactory->createDistributed(prefix: 'stackiq_progress');
		}
	}//end __construct()

	/**
	 * Whether the distributed cache is one every server and the CLI share.
	 *
	 * Nextcloud falls back to the local cache class when `memcache.distributed`
	 * is not set, and to a cache that keeps nothing when no memcache is set at all.
	 *
	 * @param ICacheFactory $cacheFactory The cache factory.
	 * @param IConfig       $config       The system config.
	 *
	 * @return bool True for a shared cache such as Redis or Memcached.
	 */
	private static function hasSharedCache(ICacheFactory $cacheFactory, IConfig $config): bool {
		if ($cacheFactory->isAvailable() === false) {
			return false;
		}

		$class = $config->getSystemValueString('memcache.distributed', '');
		if ($class === '') {
			$class = $config->getSystemValueString('memcache.local', '');
		}

		return in_array(ltrim($class, '\\'), self::UNSHARED_CACHES, true) === false;
	}//end hasSharedCache()

	/**
	 * Store an operation's snapshot.
	 *
	 * In the app config a running operation that stays in the same phase is
	 * written at most once a second; a new phase or status is always written.
	 *
	 * @param string               $operationId The operation.
	 * @param array<string, mixed> $progress    The snapshot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function setProgress(string $operationId, array $progress): void {
		if ($this->cache === null) {
			$state = ($progress['status'] ?? '') . '/' . ($progress['phase'] ?? '');
			$last  = ($this->lastWrites[$operationId] ?? null);
			if ($last !== null && ($progress['status'] ?? null) === 'running' && $last['state'] === $state && time() - $last['at'] < self::CONFIG_INTERVAL) {
				return;
			}

			$this->lastWrites[$operationId] = ['at' => time(), 'state' => $state];
		}

		$this->set(key: 'progress_' . $operationId, value: $progress);
	}//end setProgress()

	/**
	 * Read an operation's snapshot.
	 *
	 * @param string $operationId The operation.
	 *
	 * @return array<string, mixed>|null The snapshot, or null when there is none or it expired.
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function getProgress(string $operationId): ?array {
		$progress = $this->get(key: 'progress_' . $operationId);
		if (is_array($progress) === true) {
			return $progress;
		}

		return null;
	}//end getProgress()

	/**
	 * Record a cancel request for an operation.
	 *
	 * @param string $operationId The operation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function requestCancel(string $operationId): void {
		$this->set(key: 'cancel_' . $operationId, value: true);
	}//end requestCancel()

	/**
	 * Whether a cancel was requested for an operation.
	 *
	 * An import asks between every few rows; the app config is read at most
	 * once a second per operation, and a cancel once seen stays seen.
	 *
	 * @param string $operationId The operation.
	 *
	 * @return bool True when a cancel was requested.
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function isCancelRequested(string $operationId): bool {
		if ($this->cache !== null) {
			return $this->cache->get(key: 'cancel_' . $operationId) === true;
		}

		$last = ($this->cancelChecks[$operationId] ?? null);
		if ($last !== null && ($last['cancelled'] === true || time() - $last['at'] < self::CONFIG_INTERVAL)) {
			return $last['cancelled'];
		}

		$cancelled = $this->get(key: 'cancel_' . $operationId) === true;
		$this->cancelChecks[$operationId] = ['at' => time(), 'cancelled' => $cancelled];

		return $cancelled;
	}//end isCancelRequested()

	/**
	 * Forget the cancel request of an operation that stopped.
	 *
	 * @param string $operationId The operation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function clearCancel(string $operationId): void {
		unset($this->cancelChecks[$operationId]);
		if ($this->cache !== null) {
			$this->cache->remove(key: 'cancel_' . $operationId);
			return;
		}

		$this->appConfig->deleteKey(Application::APP_ID, self::configKey(key: 'cancel_' . $operationId));
	}//end clearCancel()

	/**
	 * Delete app config entries whose time is up; the cache drops its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function removeExpired(): void {
		if ($this->cache !== null) {
			return;
		}

		foreach ($this->appConfig->searchKeys(Application::APP_ID, self::CONFIG_PREFIX, lazy: true) as $configKey) {
			$entry = $this->appConfig->getValueArray(Application::APP_ID, $configKey, [], lazy: true);
			if ((int) ($entry['expires'] ?? 0) < time()) {
				$this->appConfig->deleteKey(Application::APP_ID, $configKey);
			}
		}
	}//end removeExpired()

	/**
	 * Read an entry.
	 *
	 * The app config is read from the database, not from this request's config
	 * cache, so a value another request wrote a moment ago is seen.
	 *
	 * @param string $key The entry.
	 *
	 * @return mixed The value, or null when there is none or it expired.
	 */
	private function get(string $key): mixed {
		if ($this->cache !== null) {
			return $this->cache->get(key: $key);
		}

		$this->appConfig->clearCache();
		$entry = $this->appConfig->getValueArray(Application::APP_ID, self::configKey(key: $key), [], lazy: true);
		if ((int) ($entry['expires'] ?? 0) < time()) {
			return null;
		}

		return ($entry['value'] ?? null);
	}//end get()

	/**
	 * Write an entry that lives STORE_TTL seconds.
	 *
	 * @param string $key   The entry.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 */
	private function set(string $key, mixed $value): void {
		if ($this->cache !== null) {
			$this->cache->set(key: $key, value: $value, ttl: self::STORE_TTL);
			return;
		}

		$this->appConfig->setValueArray(
			Application::APP_ID,
			self::configKey(key: $key),
			['expires' => time() + self::STORE_TTL, 'value' => $value],
			lazy: true
		);
	}//end set()

	/**
	 * The app config key of an entry, hashed: an operation id may be longer than a key may be (64).
	 *
	 * @param string $key The entry, `progress_<id>` or `cancel_<id>`.
	 *
	 * @return string The app config key.
	 */
	private static function configKey(string $key): string {
		[$kind, $operationId] = array_pad(explode('_', $key, 2), 2, '');

		return self::CONFIG_PREFIX . $kind . '_' . sha1($operationId);
	}//end configKey()
}//end class
