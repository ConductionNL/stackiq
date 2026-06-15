<?php
/**
 * Unit tests for ContractApprovalService — the fail-closed contract-approval
 * delegation seam.
 *
 * The security core is fail-closed authorization (mirrors
 * hydra-gate-unsafe-auth-resolver): when decidesk's in-process event contract
 * (`OCA\Decidesk\Event\DecisionRequestedEvent`) is absent, the service NEVER
 * advances a contract — it throws on submit and never sets status=Actief. The
 * IDOR guard (isDecisionForContract) and the unknown-contract projection path
 * also fail closed.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/softwarecatalog-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ContractApprovalService;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
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
     * @var IEventDispatcher|MockObject
     */
    private IEventDispatcher|MockObject $eventDispatcher;

    /**
     * Set up the mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
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
            $this->eventDispatcher,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeService()

    /**
     * Delegation is NOT configured when decidesk's event class is absent.
     *
     * The decidesk app is not installed in the unit-test autoload scope, so the
     * class_exists guard returns false — delegation is reported unconfigured.
     *
     * @return void
     */
    public function testDelegationNotConfiguredWhenDecideskAbsent(): void
    {
        $this->assertFalse(class_exists(ContractApprovalService::DECISION_REQUESTED_EVENT));
        $this->assertFalse($this->makeService()->isDelegationConfigured());
    }//end testDelegationNotConfiguredWhenDecideskAbsent()

    /**
     * Submitting fails CLOSED (throws) when decidesk's event class is absent —
     * the contract is never advanced and status is never set to Actief, and the
     * event dispatcher is NEVER invoked.
     *
     * @return void
     */
    public function testSubmitFailsClosedWhenDelegationNotConfigured(): void
    {
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');

        $this->expectException(\RuntimeException::class);
        $this->makeService()->submitForApproval('contract-uuid', false);
    }//end testSubmitFailsClosedWhenDelegationNotConfigured()

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
     * Resolving a contract for an outcome fails closed on a blank decision id
     * with no subjectId/externalReference match (returns null).
     *
     * @return void
     */
    public function testResolveContractForOutcomeFailsClosedOnBlankDecisionId(): void
    {
        // ObjectService unavailable → no lookup possible → null.
        $this->container->method('get')->willThrowException(new \RuntimeException('no OR'));
        $this->assertNull($this->makeService()->resolveContractForOutcome('', '', ''));
    }//end testResolveContractForOutcomeFailsClosedOnBlankDecisionId()

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
}//end class
