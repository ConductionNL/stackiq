<?php
/**
 * Unit tests for ContractApprovalService — the fail-closed contract-approval
 * delegation seam.
 *
 * The security core is fail-closed authorization (mirrors
 * hydra-gate-unsafe-auth-resolver): when no decidesk endpoint resolves through
 * the integration registry, the service NEVER advances a contract — it throws
 * on submit and degrades to a no-op on reconcile, and never sets status=Actief.
 * The IDOR guard (isDecisionForContract) and the unknown-contract projection
 * path also fail closed.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ContractApprovalService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for the fail-closed contract-approval delegation.
 */
class ContractApprovalServiceTest extends TestCase
{
    /**
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * @var SettingsService|MockObject
     */
    private SettingsService|MockObject $settingsService;

    /**
     * @var IAppManager|MockObject
     */
    private IAppManager|MockObject $appManager;

    /**
     * @var IClientService|MockObject
     */
    private IClientService|MockObject $clientService;

    /**
     * @var IURLGenerator|MockObject
     */
    private IURLGenerator|MockObject $urlGenerator;

    /**
     * Set up the mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->clientService   = $this->createMock(IClientService::class);
        $this->urlGenerator    = $this->createMock(IURLGenerator::class);
    }//end setUp()

    /**
     * Build the service with the current mocks.
     *
     * @return ContractApprovalService The service under test.
     */
    private function makeService(): ContractApprovalService
    {
        return new ContractApprovalService(
            $this->container,
            $this->settingsService,
            $this->appManager,
            $this->clientService,
            $this->urlGenerator,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeService()

    /**
     * Delegation is NOT configured when decidesk is not installed.
     *
     * @return void
     */
    public function testDelegationNotConfiguredWhenDecideskAbsent(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->assertFalse($this->makeService()->isDelegationConfigured());
    }//end testDelegationNotConfiguredWhenDecideskAbsent()

    /**
     * Submitting fails CLOSED (throws) when no decidesk endpoint resolves —
     * the contract is never advanced and status is never set to Actief.
     *
     * @return void
     */
    public function testSubmitFailsClosedWhenDelegationNotConfigured(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);

        $this->expectException(\RuntimeException::class);
        $this->makeService()->submitForApproval('contract-uuid', false);
    }//end testSubmitFailsClosedWhenDelegationNotConfigured()

    /**
     * Reconcile degrades to 0 (no-op) when delegation is not configured.
     *
     * @return void
     */
    public function testReconcileDegradesWhenDelegationNotConfigured(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->assertSame(0, $this->makeService()->reconcilePendingApprovals());
    }//end testReconcileDegradesWhenDelegationNotConfigured()

    /**
     * The IDOR guard fails closed on a blank decision id.
     *
     * @return void
     */
    public function testIsDecisionForContractRejectsBlankId(): void
    {
        $this->assertFalse($this->makeService()->isDecisionForContract('contract-uuid', ''));
    }//end testIsDecisionForContractRejectsBlankId()

    /**
     * Projecting an outcome onto an unknown contract is a safe no-op (false).
     *
     * @return void
     */
    public function testProjectOutcomeUnknownContractIsNoop(): void
    {
        // ObjectService unavailable → loadContract returns null → no-op.
        $this->container->method('get')->willThrowException(new \RuntimeException('no OR'));
        $this->assertFalse($this->makeService()->projectOutcome('unknown-uuid', 'approved'));
    }//end testProjectOutcomeUnknownContractIsNoop()

    /**
     * refreshOutcome returns null when delegation is not configured (fail closed).
     *
     * @return void
     */
    public function testRefreshOutcomeReturnsNullWhenNotConfigured(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->assertNull($this->makeService()->refreshOutcome('contract-uuid'));
    }//end testRefreshOutcomeReturnsNullWhenNotConfigured()
}//end class
