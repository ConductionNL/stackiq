<?php
/**
 * Unit tests for FederationService.
 *
 * Covers the capability-degradation path (OpenCatalogi absent → clean no-op,
 * never error), the disabled-federation guard, the read-only peer-sourced
 * detection, and the SSRF host guard with the local-federation allowlist.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/federated-catalog-sync/specs/federated-catalog-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\Federation\FederationConfig;
use OCA\SoftwareCatalog\Service\Federation\FederationMerger;
use OCA\SoftwareCatalog\Service\Federation\FederationService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for FederationService degradation + guards.
 */
class FederationServiceTest extends TestCase
{
    /**
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * @var IAppManager|MockObject
     */
    private IAppManager|MockObject $appManager;

    /**
     * @var FederationConfig|MockObject
     */
    private FederationConfig|MockObject $config;

    /**
     * Build a FederationService with the given installed-apps + enabled state.
     *
     * @param array<int,string> $installedApps The installed app ids.
     * @param bool              $enabled       Whether federation is enabled.
     *
     * @return FederationService The service under test.
     */
    private function makeService(array $installedApps, bool $enabled = true): FederationService
    {
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->config     = $this->createMock(FederationConfig::class);
        $logger           = $this->createMock(LoggerInterface::class);

        $this->appManager->method('getInstalledApps')->willReturn($installedApps);
        $this->config->method('isEnabled')->willReturn($enabled);
        $this->config->method('getDirectoryUrl')->willReturn('https://directory.opencatalogi.nl');
        $this->config->method('getPeers')->willReturn([]);
        $this->config->method('getLocalFederationHosts')->willReturn([]);

        return new FederationService(
            $this->container,
            $this->appManager,
            $this->config,
            new FederationMerger(),
            $this->createMock(SettingsService::class),
            $logger
        );
    }//end makeService()

    /**
     * Without OpenCatalogi installed, federation is unavailable.
     *
     * @return void
     */
    public function testUnavailableWithoutOpenCatalogi(): void
    {
        $service = $this->makeService(['softwarecatalog']);
        $this->assertFalse($service->isAvailable());

        $status = $service->getStatus();
        $this->assertFalse($status['available']);
        $this->assertStringContainsString('requires OpenCatalogi', $status['message']);
    }//end testUnavailableWithoutOpenCatalogi()

    /**
     * Announce no-ops cleanly (no throw) when OpenCatalogi is missing.
     *
     * @return void
     */
    public function testAnnounceDegradesWithoutOpenCatalogi(): void
    {
        $service = $this->makeService(['softwarecatalog'], true);
        $result  = $service->announce();
        $this->assertFalse($result['ok']);
        $this->assertSame('OpenCatalogi unavailable', $result['reason']);
    }//end testAnnounceDegradesWithoutOpenCatalogi()

    /**
     * Announce no-ops when federation is disabled (even if OC present).
     *
     * @return void
     */
    public function testAnnounceNoopWhenDisabled(): void
    {
        $service = $this->makeService(['softwarecatalog', 'opencatalogi'], false);
        $result  = $service->announce();
        $this->assertFalse($result['ok']);
        $this->assertSame('federation disabled', $result['reason']);
    }//end testAnnounceNoopWhenDisabled()

    /**
     * The federation publication leg delegates to PublicationService — i.e. it
     * PUBLISHES the entry (sets publicatiedatum, the live OR RBAC gate) so
     * federated anonymous reads can see it. No bespoke published predicate.
     *
     * @return void
     */
    public function testPublishEntryForFederationDelegatesToPublicationService(): void
    {
        $container  = $this->createMock(ContainerInterface::class);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog', 'opencatalogi']);
        $config = $this->createMock(FederationConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        $publication = $this->createMock(\OCA\SoftwareCatalog\Service\PublicationService::class);
        $publication->expects($this->once())
            ->method('publish')
            ->with('dienst', 'uuid-9')
            ->willReturn(['ok' => true, 'reason' => 'published', 'publicatiedatum' => '2024-01-01T00:00:00+00:00']);

        $container->method('get')
            ->with(\OCA\SoftwareCatalog\Service\PublicationService::class)
            ->willReturn($publication);

        $service = new FederationService($container, $appManager, $config, new FederationMerger(), $this->createMock(SettingsService::class), $logger);
        $result  = $service->publishEntryForFederation('dienst', 'uuid-9');

        $this->assertTrue($result['ok']);
        $this->assertSame('published', $result['reason']);
    }//end testPublishEntryForFederationDelegatesToPublicationService()

    /**
     * The federation publication leg degrades cleanly (no throw) when the
     * PublicationService cannot be resolved.
     *
     * @return void
     */
    public function testPublishEntryForFederationDegradesWithoutPublicationService(): void
    {
        $container  = $this->createMock(ContainerInterface::class);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog']);
        $config = $this->createMock(FederationConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('no service'));

        $service = new FederationService($container, $appManager, $config, new FederationMerger(), $this->createMock(SettingsService::class), $logger);
        $result  = $service->publishEntryForFederation('dienst', 'uuid-9');

        $this->assertFalse($result['ok']);
        $this->assertSame('PublicationService unavailable', $result['reason']);
    }//end testPublishEntryForFederationDegradesWithoutPublicationService()

    /**
     * discoverPeers returns an empty list (no throw) when OpenCatalogi missing.
     *
     * @return void
     */
    public function testDiscoverPeersDegrades(): void
    {
        $service = $this->makeService(['softwarecatalog']);
        $result  = $service->discoverPeers();
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['peers']);
    }//end testDiscoverPeersDegrades()

    /**
     * Peer-sourced detection identifies foreign _source.instance objects.
     *
     * @return void
     */
    public function testIsPeerSourced(): void
    {
        $service = $this->makeService(['softwarecatalog']);
        $this->assertTrue($service->isPeerSourced(['_source' => ['instance' => 'https://peer.example']]));
        $this->assertFalse($service->isPeerSourced(['naam' => 'local']));
        $this->assertFalse($service->isPeerSourced(['_source' => ['instance' => '']]));
        $this->assertFalse($service->isPeerSourced(['_source' => 'not-an-array']));
    }//end testIsPeerSourced()

    /**
     * getStatus() surfaces a per-peer status object (failures + stale + allowed)
     * for the federation settings UI, comparing the streak to the threshold.
     *
     * @return void
     */
    public function testStatusReportsPerPeerStaleAndAllowedState(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog', 'opencatalogi']);

        $config = $this->createMock(FederationConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getDirectoryUrl')->willReturn('https://directory.opencatalogi.nl');
        $config->method('getStaleAfterFailures')->willReturn(3);
        $config->method('getLocalFederationHosts')->willReturn([]);
        $config->method('getPeers')->willReturn(['https://healthy.example', 'https://broken.example']);
        $config->method('getPeerFailures')->willReturnMap(
            [
                ['https://healthy.example', 1],
                ['https://broken.example', 4],
            ]
        );

        if (class_exists('OCA\\OpenCatalogi\\Service\\DirectoryService') === false) {
            eval('namespace OCA\\OpenCatalogi\\Service; class DirectoryService {}');
        }

        $service = new FederationService(
            $this->createMock(ContainerInterface::class),
            $appManager,
            $config,
            new FederationMerger(),
            $this->createMock(SettingsService::class),
            $this->createMock(LoggerInterface::class)
        );

        $status = $service->getStatus();
        $this->assertSame(3, $status['staleAfter']);
        $this->assertCount(2, $status['peers']);

        $this->assertSame('https://healthy.example', $status['peers'][0]['url']);
        $this->assertSame(1, $status['peers'][0]['failures']);
        $this->assertFalse($status['peers'][0]['stale']);
        $this->assertTrue($status['peers'][0]['allowed']);

        $this->assertSame('https://broken.example', $status['peers'][1]['url']);
        $this->assertTrue($status['peers'][1]['stale']);
    }//end testStatusReportsPerPeerStaleAndAllowedState()

    /**
     * addPeer() SSRF-guards the host, is idempotent, and persists via setPeers.
     *
     * @return void
     */
    public function testAddPeer(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog']);

        $stored = [];
        $config = $this->createMock(FederationConfig::class);
        $config->method('getLocalFederationHosts')->willReturn([]);
        $config->method('getPeers')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $config->method('setPeers')->willReturnCallback(static function (array $peers) use (&$stored): void {
            $stored = $peers;
        });

        $service = new FederationService(
            $this->createMock(ContainerInterface::class),
            $appManager,
            $config,
            new FederationMerger(),
            $this->createMock(SettingsService::class),
            $this->createMock(LoggerInterface::class)
        );

        // Empty url rejected.
        $this->assertFalse($service->addPeer('   ')['ok']);

        // Private/loopback host blocked by the SSRF guard.
        $blocked = $service->addPeer('http://127.0.0.1');
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('SSRF', $blocked['reason']);

        // A public host is added and persisted.
        $this->assertTrue($service->addPeer('https://peer.example')['ok']);
        $this->assertSame(['https://peer.example'], $stored);

        // Idempotent re-add.
        $again = $service->addPeer('https://peer.example');
        $this->assertTrue($again['ok']);
        $this->assertSame(['https://peer.example'], $stored);
    }//end testAddPeer()

    /**
     * removePeer() drops the peer, clears its failure streak, and reports a
     * not-found peer.
     *
     * @return void
     */
    public function testRemovePeer(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog']);

        $stored = ['https://peer.example'];
        $config = $this->createMock(FederationConfig::class);
        $config->method('getPeers')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $config->method('setPeers')->willReturnCallback(static function (array $peers) use (&$stored): void {
            $stored = $peers;
        });
        // Failure streak must be cleared on removal.
        $config->expects($this->once())->method('setPeerFailures')->with('https://peer.example', 0);

        $service = new FederationService(
            $this->createMock(ContainerInterface::class),
            $appManager,
            $config,
            new FederationMerger(),
            $this->createMock(SettingsService::class),
            $this->createMock(LoggerInterface::class)
        );

        $ok = $service->removePeer('https://peer.example');
        $this->assertTrue($ok['ok']);
        $this->assertSame([], $stored);

        $missing = $service->removePeer('https://nope.example');
        $this->assertFalse($missing['ok']);
        $this->assertSame('peer not found', $missing['reason']);
    }//end testRemovePeer()

    /**
     * SSRF guard blocks private/loopback hosts unless allowlisted.
     *
     * @return void
     */
    public function testPeerHostGuard(): void
    {
        $service = $this->makeService(['softwarecatalog']);
        $this->assertTrue($service->isPeerHostAllowed('https://directory.opencatalogi.nl'));
        $this->assertFalse($service->isPeerHostAllowed('http://localhost:8081'));
        $this->assertFalse($service->isPeerHostAllowed('http://127.0.0.1'));
        $this->assertFalse($service->isPeerHostAllowed('http://192.168.1.10'));
        $this->assertFalse($service->isPeerHostAllowed('not a url'));
    }//end testPeerHostGuard()

    /**
     * The local-federation allowlist lets a private host through (dev/test).
     *
     * @return void
     */
    public function testLocalFederationAllowlist(): void
    {
        $container  = $this->createMock(ContainerInterface::class);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog']);
        $config = $this->createMock(FederationConfig::class);
        $config->method('getLocalFederationHosts')->willReturn(['127.0.0.1', 'localhost']);
        $logger  = $this->createMock(LoggerInterface::class);
        $service = new FederationService($container, $appManager, $config, new FederationMerger(), $this->createMock(SettingsService::class), $logger);

        $this->assertTrue($service->isPeerHostAllowed('http://127.0.0.1'));
        $this->assertTrue($service->isPeerHostAllowed('http://localhost:8081'));
    }//end testLocalFederationAllowlist()

    /**
     * pullPeer fetches the peer catalog via OpenCatalogi's DirectoryService and
     * merges new entries as read-only, source-attributed local mirrors.
     *
     * @return void
     */
    public function testPullPeerMergesNewEntries(): void
    {
        $directory = new class {
            public function getDirectory(array $query = []): array
            {
                return [
                    'organisation' => 'Gemeente Peer',
                    'results'      => [['id' => 'p-1', 'naam' => 'Peer Service']],
                ];
            }
        };

        $saved = [];
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjects')->willReturn([]); // no local mirrors yet
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved) {
                $saved[] = $object;
                return $this->createStub(\OCA\OpenRegister\Db\ObjectEntity::class);
            }
        );

        $service = $this->makePullService($directory, $objectService, ['https://peer.example']);
        $result  = $service->pullPeer('https://peer.example');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['withdrawn']);

        // The persisted mirror carries provenance and is read-only (isPeerSourced).
        $this->assertNotEmpty($saved);
        $mirror = $saved[0];
        $this->assertSame('https://peer.example', $mirror['_source']['instance']);
        $this->assertTrue($service->isPeerSourced($mirror));
    }//end testPullPeerMergesNewEntries()

    /**
     * pullPeer never overwrites a locally-owned entry: only this peer's mirrors
     * are reconciled, and a stable peer id makes re-pulls idempotent.
     *
     * @return void
     */
    public function testPullPeerIsIdempotentAndLeavesLocalEntriesAlone(): void
    {
        $directory = new class {
            public function getDirectory(array $query = []): array
            {
                return ['organisation' => 'Gemeente Peer', 'results' => [['id' => 'p-1', 'naam' => 'Same']]];
            }
        };

        // Existing local mirror of this peer, identical payload.
        $existingMirror = [
            'id'      => 'or-uuid-1',
            'naam'    => 'Same',
            '_source' => [
                'instance'     => 'https://peer.example',
                'organisation' => 'Gemeente Peer',
                'peerEntryId'  => 'p-1',
                'syncedAt'     => '2026-06-01T00:00:00+00:00',
                'stale'        => false,
                'withdrawn'    => false,
            ],
        ];

        $entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entity->method('getObject')->willReturn($existingMirror);
        $entity->method('getUuid')->willReturn('or-uuid-1');

        $saved = [];
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjects')->willReturn([$entity]);
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved) {
                $saved[] = $object;
                return $this->createStub(\OCA\OpenRegister\Db\ObjectEntity::class);
            }
        );

        $service = $this->makePullService($directory, $objectService, ['https://peer.example']);
        $result  = $service->pullPeer('https://peer.example');

        $this->assertTrue($result['ok']);
        // Idempotent re-pull: nothing created/updated/withdrawn.
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['withdrawn']);
    }//end testPullPeerIsIdempotentAndLeavesLocalEntriesAlone()

    /**
     * pullPeer marks a peer's mirrors stale after the consecutive-failure
     * threshold is reached — never silently deleting them.
     *
     * @return void
     */
    public function testPullPeerMarksStaleAfterFailureThreshold(): void
    {
        // DirectoryService throws → fetch fails.
        $directory = new class {
            public function getDirectory(array $query = []): array
            {
                throw new \RuntimeException('peer unreachable');
            }
        };

        $staledMirror = null;
        $existingMirror = [
            'id'      => 'or-uuid-1',
            'naam'    => 'Service',
            '_source' => [
                'instance'    => 'https://peer.example',
                'peerEntryId' => 'p-1',
                'stale'       => false,
                'withdrawn'   => false,
            ],
        ];
        $entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entity->method('getObject')->willReturn($existingMirror);
        $entity->method('getUuid')->willReturn('or-uuid-1');

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjects')->willReturn([$entity]);
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$staledMirror) {
                $staledMirror = $object;
                return $this->createStub(\OCA\OpenRegister\Db\ObjectEntity::class);
            }
        );

        // Config: already 2 prior failures → this failure is the 3rd (threshold).
        $config = $this->createMock(FederationConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getPeers')->willReturn(['https://peer.example']);
        $config->method('getLocalFederationHosts')->willReturn([]);
        $config->method('getStaleAfterFailures')->willReturn(3);
        $config->method('getPeerFailures')->willReturn(2);

        $service = $this->makePullServiceWithConfig($directory, $objectService, $config);
        $result  = $service->pullPeer('https://peer.example');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['stale']);
        $this->assertNotNull($staledMirror);
        $this->assertTrue($staledMirror['_source']['stale']);
    }//end testPullPeerMarksStaleAfterFailureThreshold()

    /**
     * pullPeer refuses a peer whose host is blocked by the SSRF guard.
     *
     * @return void
     */
    public function testPullPeerBlockedBySsrfGuard(): void
    {
        $service = $this->makePullService(null, null, ['http://127.0.0.1']);
        $result  = $service->pullPeer('http://127.0.0.1');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SSRF', $result['reason']);
    }//end testPullPeerBlockedBySsrfGuard()

    /**
     * Build a FederationService wired for a pull: container resolves the OC
     * DirectoryService + OR ObjectService; SettingsService resolves the mirror
     * register/schema; federation enabled with the given peers.
     *
     * @param object|null       $directory     The fake OC DirectoryService (null = none).
     * @param object|null       $objectService The OR ObjectService mock (null = none).
     * @param array<int,string> $peers         The subscribed peers.
     *
     * @return FederationService The wired service.
     */
    private function makePullService(?object $directory, ?object $objectService, array $peers): FederationService
    {
        $config = $this->createMock(FederationConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getPeers')->willReturn($peers);
        $config->method('getLocalFederationHosts')->willReturn([]);
        $config->method('getStaleAfterFailures')->willReturn(3);
        $config->method('getPeerFailures')->willReturn(0);

        return $this->makePullServiceWithConfig($directory, $objectService, $config);
    }//end makePullService()

    /**
     * Build a FederationService for a pull with a caller-supplied config mock.
     *
     * @param object|null            $directory     The fake OC DirectoryService.
     * @param object|null            $objectService The OR ObjectService mock.
     * @param FederationConfig|MockObject $config    The federation config.
     *
     * @return FederationService The wired service.
     */
    private function makePullServiceWithConfig(?object $directory, ?object $objectService, $config): FederationService
    {
        $container  = $this->createMock(ContainerInterface::class);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['softwarecatalog', 'opencatalogi']);
        $logger = $this->createMock(LoggerInterface::class);

        $container->method('get')->willReturnCallback(
            function (string $id) use ($directory, $objectService) {
                if ($id === 'OCA\\OpenCatalogi\\Service\\DirectoryService' && $directory !== null) {
                    return $directory;
                }
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService' && $objectService !== null) {
                    return $objectService;
                }
                throw new \RuntimeException('not bound: '.$id);
            }
        );

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterIdForObjectType')->willReturn(1);
        $settings->method('getSchemaIdForObjectType')->willReturn(2);

        // isAvailable() needs the OC DirectoryService class to exist.
        if (class_exists('OCA\\OpenCatalogi\\Service\\DirectoryService') === false) {
            eval('namespace OCA\\OpenCatalogi\\Service; class DirectoryService {}');
        }

        return new FederationService($container, $appManager, $config, new FederationMerger(), $settings, $logger);
    }//end makePullServiceWithConfig()
}//end class
