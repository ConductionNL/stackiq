<?php

/**
 * Unit tests for FederationService.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Federation
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Federation;

use OCA\Stackiq\Service\ConnectionReportService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationMerger;
use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Peer management, the SSRF guard, provenance and the pull of one peer.
 *
 * @covers \OCA\Stackiq\Service\Federation\FederationService
 * @uses   \OCA\Stackiq\Service\Federation\FederationConfig
 * @uses   \OCA\Stackiq\Service\Federation\FederationMerger
 * @uses   \OCA\Stackiq\Service\Federation\FederationPlanRun
 * @uses   \OCA\Stackiq\Service\ObjectDataBag
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
class FederationServiceTest extends TestCase {

	private const PEER = 'https://peer.example.org';

	/**
	 * In-memory app config values by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var ObjectTypeLocatorInterface&MockObject
	 */
	private ObjectTypeLocatorInterface&MockObject $locator;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The OpenCatalogi directory double, or null when the container lacks it.
	 *
	 * @var object|null
	 */
	private ?object $directory = null;

	/**
	 * The OpenRegister object service fake, or null when the container lacks it.
	 *
	 * @var FakeFederationObjectService|null
	 */
	private ?FakeFederationObjectService $objects = null;

	/**
	 * Build the boundary doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store     = [];
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->store[$key] ?? $default)
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->store[$key] ?? $default)
		);
		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$this->appManager = $this->createMock(originalClassName: IAppManager::class);
		$this->locator    = $this->createMock(originalClassName: ObjectTypeLocatorInterface::class);
		$this->logger     = $this->createMock(originalClassName: LoggerInterface::class);
		$this->container  = $this->createMock(originalClassName: ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				$service = match ($id) {
					'OCA\\OpenCatalogi\\Service\\DirectoryService' => $this->directory,
					'OCA\\OpenRegister\\Service\\ObjectService' => $this->objects,
					default => null,
				};
				if ($service === null) {
					throw new RuntimeException('service '.$id.' not available');
				}

				return $service;
			}
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param ObjectTypeLocatorInterface|null $locator The settings locator (null: none).
	 * @param ConnectionReportService|null    $reports The connection reports.
	 *
	 * @return FederationService
	 */
	private function service(?ObjectTypeLocatorInterface $locator = null, ?ConnectionReportService $reports = null): FederationService {
		return new FederationService(
			container: $this->container,
			appManager: $this->appManager,
			config: new FederationConfig(appConfig: $this->appConfig),
			merger: new FederationMerger(),
			settingsService: $locator,
			logger: $this->logger,
			connectionReports: $reports
		);
	}//end service()

	/**
	 * Point the mirror target at register 7 / schema 9.
	 *
	 * @return ObjectTypeLocatorInterface&MockObject
	 */
	private function configuredLocator(): ObjectTypeLocatorInterface&MockObject {
		$this->locator->method('getRegisterIdForObjectType')->with('organization')->willReturn(7);
		$this->locator->method('getSchemaIdForObjectType')->with('organization')->willReturn(9);
		return $this->locator;
	}//end configuredLocator()

	/**
	 * A directory double answering getDirectory() with the given listing.
	 *
	 * @param mixed $listing The listing (a Throwable is thrown instead).
	 *
	 * @return void
	 */
	private function directoryReturning(mixed $listing): void {
		$this->directory = new class($listing) {
			/**
			 * The queries received.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $queries = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $listing The canned listing.
			 */
			public function __construct(private mixed $listing) {
			}

			/**
			 * Answer a directory query.
			 *
			 * @param array<string,mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function getDirectory(array $query): mixed {
				$this->queries[] = $query;
				if ($this->listing instanceof \Throwable) {
					throw $this->listing;
				}

				return $this->listing;
			}
		};
	}//end directoryReturning()

	/**
	 * The SSRF guard refuses hosts without a name, loopback, .local and private
	 * or reserved IPs, and accepts public hosts and allowlisted ones.
	 *
	 * @return void
	 */
	public function testPeerHostGuard(): void {
		$service = $this->service();

		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'not a url'));
		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'http://localhost:8080'));
		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'https://nas.local/catalog'));
		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'http://10.0.0.5'));
		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'http://192.168.1.1'));
		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'http://127.0.0.1'));
		$this->assertTrue(condition: $service->isPeerHostAllowed(url: 'http://8.8.8.8'));
		$this->assertTrue(condition: $service->isPeerHostAllowed(url: self::PEER));

		$this->store['local_federation_hosts'] = 'localhost, nas.local';
		$this->assertTrue(condition: $service->isPeerHostAllowed(url: 'http://localhost:8080'));
		$this->assertTrue(condition: $service->isPeerHostAllowed(url: 'https://nas.local'));
		$this->assertFalse(condition: $service->isPeerHostAllowed(url: 'http://10.0.0.5'));
	}//end testPeerHostGuard()

	/**
	 * Only data whose provenance names a non-blank instance is peer-sourced.
	 *
	 * @return void
	 */
	public function testIsPeerSourced(): void {
		$service = $this->service();

		$this->assertTrue(condition: $service->isPeerSourced(objectData: ['_source' => ['instance' => self::PEER]]));
		$this->assertFalse(condition: $service->isPeerSourced(objectData: ['name' => 'local']));
		$this->assertFalse(condition: $service->isPeerSourced(objectData: ['_source' => 'x']));
		$this->assertFalse(condition: $service->isPeerSourced(objectData: ['_source' => ['instance' => '  ']]));
		$this->assertFalse(condition: $service->isPeerSourced(objectData: ['_source' => ['instance' => 5]]));
	}//end testIsPeerSourced()

	/**
	 * Without OpenCatalogi installed federation is unavailable and the status says so.
	 *
	 * @return void
	 */
	public function testStatusWithoutOpenCatalogi(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->store['federation_peers']         = json_encode([self::PEER, 'http://10.0.0.1']);
		$this->store['federation_peer_failures'] = json_encode([self::PEER => 3]);
		$this->store['federation_enabled']       = true;

		$status = $this->service()->getStatus();

		$this->assertSame(
			expected: [
				'available' => false,
				'enabled' => true,
				'staleAfter' => 3,
				'directoryUrl' => $status['directoryUrl'],
				'peers' => [
					['url' => self::PEER, 'failures' => 3, 'stale' => true, 'allowed' => true],
					['url' => 'http://10.0.0.1', 'failures' => 0, 'stale' => false, 'allowed' => false],
				],
				'message' => 'Federation unavailable — requires OpenCatalogi',
			],
			actual: $status
		);
	}//end testStatusWithoutOpenCatalogi()

	/**
	 * Adding a peer validates it, stores it once and reports the change.
	 *
	 * @return void
	 */
	public function testAddPeer(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->once())->method('federationPeersChanged');
		$service = $this->service(reports: $reports);

		$this->assertSame(expected: ['ok' => false, 'reason' => 'peer url is required'], actual: $service->addPeer(peerUrl: '   '));
		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'peer host blocked by SSRF guard'],
			actual: $service->addPeer(peerUrl: 'http://127.0.0.1')
		);
		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer added'], actual: $service->addPeer(peerUrl: ' '.self::PEER.' '));
		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer already present'], actual: $service->addPeer(peerUrl: self::PEER));
		$this->assertSame(expected: json_encode([self::PEER]), actual: $this->store['federation_peers']);
	}//end testAddPeer()

	/**
	 * Removing a peer drops it, resets its failure streak and reports the change.
	 *
	 * @return void
	 */
	public function testRemovePeer(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);
		$this->store['federation_peers']         = json_encode([self::PEER, 'https://other.example.org']);
		$this->store['federation_peer_failures'] = json_encode([self::PEER => 2]);
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->once())->method('federationPeersChanged');
		$service = $this->service(reports: $reports);

		$this->assertSame(expected: ['ok' => false, 'reason' => 'peer not found'], actual: $service->removePeer(peerUrl: 'https://nope.example.org'));
		$this->assertSame(expected: ['ok' => true, 'reason' => 'peer removed'], actual: $service->removePeer(peerUrl: self::PEER));
		$this->assertSame(expected: json_encode(['https://other.example.org']), actual: $this->store['federation_peers']);
		$this->assertSame(expected: '[]', actual: $this->store['federation_peer_failures']);
	}//end testRemovePeer()

	/**
	 * Announce refuses when federation is disabled or OpenCatalogi is missing.
	 *
	 * @return void
	 */
	public function testAnnounceRefusesWhenDisabledOrUnavailable(): void {
		$this->appManager->method('getInstalledApps')->willReturn([]);
		$service = $this->service();

		$this->assertSame(expected: ['ok' => false, 'reason' => 'federation disabled'], actual: $service->announce());

		$this->store['federation_enabled'] = true;
		$this->logger->expects($this->once())->method('info')->with('[Federation] announce skipped — OpenCatalogi unavailable');
		$this->assertSame(expected: ['ok' => false, 'reason' => 'OpenCatalogi unavailable'], actual: $service->announce());
		$this->assertSame(expected: ['ok' => false, 'reason' => 'OpenCatalogi unavailable', 'peers' => []], actual: $service->discoverPeers());
	}//end testAnnounceRefusesWhenDisabledOrUnavailable()

	/**
	 * Pulling all peers refuses when disabled and still reports the pull.
	 *
	 * @return void
	 */
	public function testPullAllPeersRefusesWhenDisabled(): void {
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->once())->method('federationPulled')
			->with(['ok' => false, 'reason' => 'federation disabled', 'peers' => []]);

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'federation disabled', 'peers' => []],
			actual: $this->service(reports: $reports)->pullAllPeers()
		);
	}//end testPullAllPeersRefusesWhenDisabled()

	/**
	 * A blocked peer host is refused before anything is fetched.
	 *
	 * @return void
	 */
	public function testPullPeerRefusesABlockedHost(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'peer host blocked by SSRF guard', 'created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false],
			actual: $this->service()->pullPeer(peerUrl: 'http://192.168.0.10')
		);
	}//end testPullPeerRefusesABlockedHost()

	/**
	 * A failed fetch increments the failure streak, below the threshold not stale.
	 *
	 * @return void
	 */
	public function testFailedFetchCountsAFailure(): void {
		$this->directoryReturning(listing: new RuntimeException(message: 'timeout'));
		$this->logger->expects($this->once())->method('error')->with('[Federation] peer fetch failed', ['peer' => self::PEER, 'error' => 'timeout']);

		$result = $this->service()->pullPeer(peerUrl: self::PEER);

		$this->assertSame(
			expected: ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false, 'ok' => false, 'reason' => 'timeout'],
			actual: $result
		);
		$this->assertSame(expected: json_encode([self::PEER => 1]), actual: $this->store['federation_peer_failures']);
	}//end testFailedFetchCountsAFailure()

	/**
	 * Reaching the threshold marks every stored mirror of the peer stale.
	 *
	 * @return void
	 */
	public function testFailureAtThresholdMarksMirrorsStale(): void {
		$this->directoryReturning(listing: new RuntimeException(message: 'down'));
		$this->store['federation_peer_failures']       = json_encode([self::PEER => 1]);
		$this->store['federation_stale_after_failures'] = 2;
		$this->objects = new FakeFederationObjectService(stored: [
			['id' => 'm-1', 'name' => 'A', '_source' => ['instance' => self::PEER, 'peerEntryId' => 'p-1', 'stale' => false]],
			['id' => 'm-2', 'name' => 'Other', '_source' => ['instance' => 'https://other.example.org']],
			['id' => 'm-3', 'name' => 'Local'],
		]);
		$this->logger->expects($this->once())->method('warning')->with(
			'[Federation] peer marked stale after consecutive failures',
			['peer' => self::PEER, 'failures' => 2, 'reason' => 'down']
		);

		$result = $this->service(locator: $this->configuredLocator())->pullPeer(peerUrl: self::PEER);

		$this->assertTrue(condition: $result['stale']);
		$this->assertFalse(condition: $result['ok']);
		$this->assertSame(
			expected: [[
				'object' => ['id' => 'm-1', 'name' => 'A', '_source' => ['instance' => self::PEER, 'peerEntryId' => 'p-1', 'stale' => true]],
				'register' => 7,
				'schema' => 9,
				'uuid' => 'm-1',
			]],
			actual: $this->objects->saved
		);
		$this->assertSame(expected: self::PEER, actual: $this->objects->queries[0]['_source.instance']);
		$this->assertSame(expected: ['register' => 7, 'schema' => 9], actual: $this->objects->queries[0]['@self']);
	}//end testFailureAtThresholdMarksMirrorsStale()

	/**
	 * Without a configured mirror register/schema the pull stops after the fetch.
	 *
	 * @return void
	 */
	public function testPullPeerNeedsAMirrorTarget(): void {
		$this->directoryReturning(listing: ['results' => []]);

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'mirror register/schema not configured', 'created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false],
			actual: $this->service()->pullPeer(peerUrl: self::PEER)
		);

		$this->locator->method('getRegisterIdForObjectType')->willReturn(7);
		$this->locator->method('getSchemaIdForObjectType')->willReturn(null);
		$this->assertSame(
			expected: 'mirror register/schema not configured',
			actual: $this->service(locator: $this->locator)->pullPeer(peerUrl: self::PEER)['reason']
		);
	}//end testPullPeerNeedsAMirrorTarget()

	/**
	 * Without the OpenRegister object service the pull stops and logs why.
	 *
	 * @return void
	 */
	public function testPullPeerNeedsTheObjectService(): void {
		$this->directoryReturning(listing: ['results' => []]);
		$this->logger->expects($this->once())->method('error')
			->with('[Federation] ObjectService unavailable', ['error' => 'service OCA\\OpenRegister\\Service\\ObjectService not available']);

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'ObjectService unavailable', 'created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false],
			actual: $this->service(locator: $this->configuredLocator())->pullPeer(peerUrl: self::PEER)
		);
	}//end testPullPeerNeedsTheObjectService()

	/**
	 * A successful pull saves creates, updates and withdrawals, resets the
	 * failure streak and clears staleness on surviving mirrors only.
	 *
	 * @return void
	 */
	public function testSuccessfulPullAppliesThePlan(): void {
		$this->directoryReturning(listing: [
			'organisation' => 'Peer Gemeente',
			'results' => [
				['id' => 'new', 'name' => 'Nieuw'],
				['id' => 'chg', 'name' => 'Gewijzigd'],
			],
		]);
		$this->store['federation_peer_failures'] = json_encode([self::PEER => 2]);
		$source = static fn (string $peerId, bool $stale = false, bool $withdrawn = false): array => [
			'instance' => self::PEER,
			'organisation' => 'Peer Gemeente',
			'peerEntryId' => $peerId,
			'syncedAt' => '2026-01-01T00:00:00+00:00',
			'stale' => $stale,
			'withdrawn' => $withdrawn,
		];
		$this->objects = new FakeFederationObjectService(stored: [
			['id' => 'm-chg', 'name' => 'Oud', '_source' => $source('chg', true)],
			['id' => 'm-gone', 'name' => 'Weg', '_source' => $source('gone')],
		]);

		$result = $this->service(locator: $this->configuredLocator())->pullPeer(peerUrl: self::PEER);

		$this->assertSame(
			expected: ['ok' => true, 'reason' => 'pulled', 'created' => 1, 'updated' => 1, 'withdrawn' => 1, 'stale' => false],
			actual: $result
		);
		$this->assertSame(expected: [['url' => self::PEER]], actual: $this->directory->queries);
		$this->assertSame(expected: '[]', actual: $this->store['federation_peer_failures']);

		$saved = $this->objects->saved;
		$this->assertCount(expectedCount: 4, haystack: $saved);
		$this->assertSame(expected: '', actual: $saved[0]['uuid']);
		$this->assertSame(expected: 'Nieuw', actual: $saved[0]['object']['name']);
		$this->assertSame(expected: 'Peer Gemeente', actual: $saved[0]['object']['_source']['organisation']);
		$this->assertSame(expected: 'm-chg', actual: $saved[1]['uuid']);
		$this->assertSame(expected: 'Gewijzigd', actual: $saved[1]['object']['name']);
		$this->assertSame(expected: 'm-gone', actual: $saved[2]['uuid']);
		$this->assertTrue(condition: $saved[2]['object']['_source']['withdrawn']);
		// The stale-clearing pass re-reads the (unchanged) store: only the
		// stale, not-withdrawn mirror is un-staled.
		$this->assertSame(expected: 'm-chg', actual: $saved[3]['uuid']);
		$this->assertFalse(condition: $saved[3]['object']['_source']['stale']);
		$this->assertSame(expected: 'Oud', actual: $saved[3]['object']['name']);
		foreach ($saved as $call) {
			$this->assertSame(expected: [7, 9], actual: [$call['register'], $call['schema']]);
		}
	}//end testSuccessfulPullAppliesThePlan()

	/**
	 * A bare-list listing without organisation falls back to the peer URL, and a
	 * failing save is logged and not counted.
	 *
	 * @return void
	 */
	public function testBareListingAndFailingSave(): void {
		$this->directoryReturning(listing: [['uuid' => 'u-1', 'name' => 'Een']]);
		$this->objects = new FakeFederationObjectService(stored: [], failSaves: true);
		$this->logger->expects($this->once())->method('error')->with('[Federation] mirror save failed', ['error' => 'save refused']);

		$result = $this->service(locator: $this->configuredLocator())->pullPeer(peerUrl: self::PEER);

		$this->assertSame(
			expected: ['ok' => true, 'reason' => 'pulled', 'created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false],
			actual: $result
		);
		$this->assertSame(expected: self::PEER, actual: $this->objects->attempted[0]['_source']['organisation']);
	}//end testBareListingAndFailingSave()

	/**
	 * A failing mirror search is logged and treated as no mirrors.
	 *
	 * @return void
	 */
	public function testFailingMirrorSearchMeansNoMirrors(): void {
		$this->directoryReturning(listing: ['results' => [['id' => 'p-1', 'name' => 'A']]]);
		$this->objects = new FakeFederationObjectService(stored: [], failSearch: true);
		$this->logger->expects($this->atLeastOnce())->method('error')
			->with('[Federation] mirror load failed', ['peer' => self::PEER, 'error' => 'search down']);

		$result = $this->service(locator: $this->configuredLocator())->pullPeer(peerUrl: self::PEER);

		$this->assertSame(expected: 1, actual: $result['created']);
		$this->assertSame(expected: '', actual: $this->objects->saved[0]['uuid']);
	}//end testFailingMirrorSearchMeansNoMirrors()
}//end class

/**
 * In-memory stand-in for the OpenRegister ObjectService boundary.
 */
class FakeFederationObjectService {
	/**
	 * Successful saves.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $saved = [];

	/**
	 * Every object a save was attempted for.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $attempted = [];

	/**
	 * Search queries received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $queries = [];

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $stored     The stored objects searches return.
	 * @param bool                           $failSaves  Whether saves throw.
	 * @param bool                           $failSearch Whether searches throw.
	 */
	public function __construct(
		private array $stored,
		private bool $failSaves = false,
		private bool $failSearch = false,
	) {
	}

	/**
	 * Answer a search with every stored object.
	 *
	 * @param array<string,mixed> $query         The query.
	 * @param bool                $_rbac         RBAC flag.
	 * @param bool                $_multitenancy Multitenancy flag.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = $query;
		if ($this->failSearch === true) {
			throw new RuntimeException('search down');
		}

		return $this->stored;
	}

	/**
	 * Record a save.
	 *
	 * @param array<string,mixed> $object   The object.
	 * @param int                 $register The register id.
	 * @param int                 $schema   The schema id.
	 * @param string              $uuid     The uuid.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object, int $register, int $schema, string $uuid): array {
		$this->attempted[] = $object;
		if ($this->failSaves === true) {
			throw new RuntimeException('save refused');
		}

		$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
		return $object;
	}
}//end class
