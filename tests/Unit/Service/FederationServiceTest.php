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
use OCA\SoftwareCatalog\Service\Federation\FederationService;
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

        return new FederationService($this->container, $this->appManager, $this->config, $logger);
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
        $service = new FederationService($container, $appManager, $config, $logger);

        $this->assertTrue($service->isPeerHostAllowed('http://127.0.0.1'));
        $this->assertTrue($service->isPeerHostAllowed('http://localhost:8081'));
    }//end testLocalFederationAllowlist()
}//end class
