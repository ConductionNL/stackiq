<?php

/**
 * Unit tests for the OpenCatalogi-backed paths of FederationService.
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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Announce, peer discovery and the pull of every peer once OpenCatalogi is there.
 *
 * @covers \OCA\Stackiq\Service\Federation\FederationService
 * @uses   \OCA\Stackiq\Service\Federation\FederationConfig
 * @uses   \OCA\Stackiq\Service\Federation\FederationMerger
 * @uses   \OCA\Stackiq\Service\Federation\FederationPlanRun
 * @uses   \OCA\Stackiq\Service\ObjectDataBag
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
class FederationServiceOpenCatalogiTest extends TestCase {

	/**
	 * In-memory app config values by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	/**
	 * Container services by id.
	 *
	 * @var array<string,object>
	 */
	private array $services = [];

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the boundary doubles; OpenCatalogi counts as installed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists('OCA\\OpenCatalogi\\Service\\DirectoryService') === false) {
			eval('namespace OCA\\OpenCatalogi\\Service; class DirectoryService {}');
		}

		$this->store    = ['federation_enabled' => true];
		$this->services = [];
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param ConnectionReportService|null $reports The connection reports.
	 *
	 * @return FederationService
	 */
	private function service(?ConnectionReportService $reports = null): FederationService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->store[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->store[$key] ?? $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', FederationService::OPENCATALOGI_APP_ID]);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException('service '.$id.' not available');
				}

				return $this->services[$id];
			}
		);

		return new FederationService(
			container: $container,
			appManager: $appManager,
			config: new FederationConfig(appConfig: $appConfig),
			merger: new FederationMerger(),
			settingsService: null,
			logger: $this->logger,
			connectionReports: $reports
		);
	}//end service()

	/**
	 * A broadcast double recording the directory URLs it was given.
	 *
	 * @param \Throwable|null $failure Thrown on broadcast when set.
	 *
	 * @return object
	 */
	private function broadcast(?\Throwable $failure = null): object {
		return new class($failure) {
			/**
			 * Directory URLs broadcast to.
			 *
			 * @var array<int,string>
			 */
			public array $urls = [];

			/**
			 * Constructor.
			 *
			 * @param \Throwable|null $failure Thrown on broadcast when set.
			 */
			public function __construct(private ?\Throwable $failure) {
			}

			/**
			 * Record a broadcast.
			 *
			 * @param string $url The directory URL.
			 *
			 * @return void
			 */
			public function broadcast(string $url): void {
				$this->urls[] = $url;
				if ($this->failure !== null) {
					throw $this->failure;
				}
			}
		};
	}//end broadcast()

	/**
	 * A directory double answering getDirectory() with the given listing.
	 *
	 * @param mixed $listing The listing (a Throwable is thrown instead).
	 *
	 * @return object
	 */
	private function directory(mixed $listing): object {
		return new class($listing) {
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
	}//end directory()

	/**
	 * Announce broadcasts to the configured directory URL, or the default one.
	 *
	 * @return void
	 */
	public function testAnnounceBroadcastsToTheDirectory(): void {
		$broadcast = $this->broadcast();
		$this->services['OCA\\OpenCatalogi\\Service\\BroadcastService'] = $broadcast;
		$this->logger->expects($this->never())->method('error');

		$this->assertSame(expected: ['ok' => true, 'reason' => 'announced'], actual: $this->service()->announce());

		$this->store['federation_directory_url'] = '  https://dir.example.org  ';
		$this->assertSame(expected: ['ok' => true, 'reason' => 'announced'], actual: $this->service()->announce());

		$this->assertSame(
			expected: [FederationConfig::DEFAULT_DIRECTORY_URL, 'https://dir.example.org'],
			actual: $broadcast->urls
		);
	}//end testAnnounceBroadcastsToTheDirectory()

	/**
	 * A failing broadcast is logged and its message becomes the reason.
	 *
	 * @return void
	 */
	public function testAnnounceReportsAFailingBroadcast(): void {
		$this->services['OCA\\OpenCatalogi\\Service\\BroadcastService'] = $this->broadcast(failure: new RuntimeException('directory refused'));
		$this->logger->expects($this->once())->method('error')
			->with('[Federation] announce failed', ['error' => 'directory refused']);

		$this->assertSame(expected: ['ok' => false, 'reason' => 'directory refused'], actual: $this->service()->announce());
	}//end testAnnounceReportsAFailingBroadcast()

	/**
	 * Announce reports a missing BroadcastService instead of throwing.
	 *
	 * @return void
	 */
	public function testAnnounceWithoutTheBroadcastService(): void {
		$this->logger->expects($this->once())->method('error')
			->with('[Federation] announce failed', ['error' => 'service OCA\\OpenCatalogi\\Service\\BroadcastService not available']);

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'service OCA\\OpenCatalogi\\Service\\BroadcastService not available'],
			actual: $this->service()->announce()
		);
	}//end testAnnounceWithoutTheBroadcastService()

	/**
	 * Discovery takes `results` from a paged listing, a bare list as is, and
	 * anything else as no peers; the query is always empty.
	 *
	 * @return void
	 */
	public function testDiscoverPeersReadsEveryListingShape(): void {
		$peer = ['url' => 'https://peer.example.org', 'name' => 'Peer'];

		$paged = $this->directory(listing: ['results' => [$peer], 'total' => 1]);
		$this->services['OCA\\OpenCatalogi\\Service\\DirectoryService'] = $paged;
		$this->assertSame(expected: ['ok' => true, 'reason' => 'ok', 'peers' => [$peer]], actual: $this->service()->discoverPeers());
		$this->assertSame(expected: [[]], actual: $paged->queries);

		$this->services['OCA\\OpenCatalogi\\Service\\DirectoryService'] = $this->directory(listing: [$peer]);
		$this->assertSame(expected: ['ok' => true, 'reason' => 'ok', 'peers' => [$peer]], actual: $this->service()->discoverPeers());

		$this->services['OCA\\OpenCatalogi\\Service\\DirectoryService'] = $this->directory(listing: ['results' => 'none']);
		$this->assertSame(
			expected: ['ok' => true, 'reason' => 'ok', 'peers' => ['results' => 'none']],
			actual: $this->service()->discoverPeers()
		);

		$this->services['OCA\\OpenCatalogi\\Service\\DirectoryService'] = $this->directory(listing: 'not a listing');
		$this->assertSame(expected: ['ok' => true, 'reason' => 'ok', 'peers' => []], actual: $this->service()->discoverPeers());
	}//end testDiscoverPeersReadsEveryListingShape()

	/**
	 * A failing directory is logged and answers no peers.
	 *
	 * @return void
	 */
	public function testDiscoverPeersReportsAFailingDirectory(): void {
		$this->services['OCA\\OpenCatalogi\\Service\\DirectoryService'] = $this->directory(listing: new RuntimeException('directory down'));
		$this->logger->expects($this->once())->method('error')
			->with('[Federation] discoverPeers failed', ['error' => 'directory down']);

		$this->assertSame(
			expected: ['ok' => false, 'reason' => 'directory down', 'peers' => []],
			actual: $this->service()->discoverPeers()
		);
	}//end testDiscoverPeersReportsAFailingDirectory()

	/**
	 * Pulling all peers pulls each configured peer in order, keyed by its URL,
	 * and reports the whole result.
	 *
	 * @return void
	 */
	public function testPullAllPeersPullsEveryConfiguredPeer(): void {
		$this->store['federation_peers'] = json_encode(['http://192.168.0.10', 'https://peer.example.org']);
		$directory = $this->directory(listing: new RuntimeException('timeout'));
		$this->services['OCA\\OpenCatalogi\\Service\\DirectoryService'] = $directory;
		$empty    = ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'stale' => false];
		$expected = [
			'ok' => true,
			'reason' => 'ok',
			'peers' => [
				['peer' => 'http://192.168.0.10', 'ok' => false, 'reason' => 'peer host blocked by SSRF guard'] + $empty,
				['peer' => 'https://peer.example.org'] + $empty + ['ok' => false, 'reason' => 'timeout'],
			],
		];
		$reports = $this->createMock(originalClassName: ConnectionReportService::class);
		$reports->expects($this->once())->method('federationPulled')->with($expected);

		$this->assertSame(expected: $expected, actual: $this->service(reports: $reports)->pullAllPeers());
		$this->assertSame(expected: [['url' => 'https://peer.example.org']], actual: $directory->queries);
		$this->assertSame(expected: json_encode(['https://peer.example.org' => 1]), actual: $this->store['federation_peer_failures']);
	}//end testPullAllPeersPullsEveryConfiguredPeer()

	/**
	 * Without any configured peer the pull succeeds with an empty list.
	 *
	 * @return void
	 */
	public function testPullAllPeersWithoutPeers(): void {
		$this->assertSame(expected: ['ok' => true, 'reason' => 'ok', 'peers' => []], actual: $this->service()->pullAllPeers());
	}//end testPullAllPeersWithoutPeers()
}//end class
