<?php

/**
 * Unit tests for the StackIQ MCP provider, contract gate and contract tools.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\Stackiq\Kernel\Contracts\ContractApprovalServiceInterface;
use OCA\Stackiq\Mcp\McpAdminGate;
use OCA\Stackiq\Mcp\McpArgumentValidator;
use OCA\Stackiq\Mcp\McpContractGate;
use OCA\Stackiq\Mcp\McpContractTools;
use OCA\Stackiq\Mcp\StackiqToolProvider;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The contract seam at MCP behaves as the REST routes do.
 *
 * @covers \OCA\Stackiq\Mcp\StackiqToolProvider
 * @covers \OCA\Stackiq\Mcp\McpContractTools
 * @covers \OCA\Stackiq\Mcp\McpContractGate
 * @covers \OCA\Stackiq\Mcp\McpAdminGate
 * @covers \OCA\Stackiq\Mcp\McpArgumentValidator
 */
class McpContractToolsTest extends TestCase {

	private const CONTRACT = '123e4567-e89b-42d3-a456-426614174000';

	/**
	 * @var ContractApprovalServiceInterface&MockObject
	 */
	private ContractApprovalServiceInterface&MockObject $approvals;

	/**
	 * Set up the approval service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->approvals = $this->createMock(ContractApprovalServiceInterface::class);
	}//end setUp()

	/**
	 * The provider over a session of the given user in the given groups and organisation.
	 *
	 * @param string|null  $uid    The signed-in uid, or null.
	 * @param list<string> $groups The user's group ids.
	 * @param string       $org    The active organisation.
	 * @param bool         $admin  Whether the user is an administrator.
	 *
	 * @return StackiqToolProvider
	 */
	private function provider(?string $uid, array $groups=[], string $org='', bool $admin=false): StackiqToolProvider {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);
		$groupManager->method('getUserGroups')->willReturn(
			array_map(
				function (string $gid): IGroup {
					$group = $this->createMock(IGroup::class);
					$group->method('getGID')->willReturn($gid);
					return $group;
				},
				$groups
			)
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $app, string $key): string => ($app === 'core' && $key === 'organisation') ? $org : ''
		);

		$adminGate = new McpAdminGate($session, $groupManager);
		$gate = new McpContractGate($adminGate, $this->approvals, $groupManager, $config);

		return new StackiqToolProvider(new McpContractTools($adminGate, $gate, new McpArgumentValidator(), $this->approvals));
	}//end provider()

	/**
	 * Every tool carries one scope and reach from the closed vocabularies; reads are read-only.
	 *
	 * @return void
	 */
	public function testEveryDescriptorCarriesItsGrantAnnotations(): void {
		$ids = array_column(StackiqToolProvider::TOOL_DESCRIPTORS, 'id');
		$this->assertSame($ids, array_values(array_unique($ids)));
		foreach (StackiqToolProvider::TOOL_DESCRIPTORS as $tool) {
			$this->assertMatchesRegularExpression('/^stackiq\.[a-z][A-Za-z]+$/', $tool['id']);
			$this->assertContains($tool['scope'], ['read', 'create', 'update', 'delete'], $tool['id']);
			$this->assertContains($tool['reach'], ['self', 'user', 'instance', 'external'], $tool['id']);
			$this->assertSame($tool['scope'] === 'read', $tool['annotations']['readOnlyHint'], $tool['id']);
		}

		$provider = $this->provider(uid: null);
		$this->assertInstanceOf(IMcpToolProvider::class, $provider);
		$this->assertSame('stackiq', $provider->getAppId());
		$this->assertSame('unknown_tool', $provider->invokeTool('stackiq.nope', [])['error']);
	}//end testEveryDescriptorCarriesItsGrantAnnotations()

	/**
	 * A non-owning caller is refused, the guard gets their real groups and organisation, and nothing is raised.
	 *
	 * @return void
	 */
	public function testANonOwningCallerIsRefusedAndNothingIsRaised(): void {
		$this->approvals->expects($this->exactly(2))->method('authorizeSubmit')
			->with(self::CONTRACT, ['aanbod-beheerder'], 'org-b')
			->willReturn(false);
		$this->approvals->expects($this->never())->method('submitForApproval');
		$provider = $this->provider(uid: 'bob', groups: ['aanbod-beheerder'], org: 'org-b');

		$this->assertSame('forbidden', $provider->invokeTool('stackiq.submitContractApproval', ['contractUuid' => self::CONTRACT])['error']);
		$this->assertSame('forbidden', $provider->invokeTool('stackiq.submitContractRenewal', ['contractUuid' => self::CONTRACT])['error']);
	}//end testANonOwningCallerIsRefusedAndNothingIsRaised()

	/**
	 * An owning caller submits; the renewal flag reaches the service.
	 *
	 * @return void
	 */
	public function testAnOwningCallerSubmits(): void {
		$this->approvals->method('authorizeSubmit')->willReturn(true);
		$this->approvals->expects($this->exactly(2))->method('submitForApproval')
			->willReturnCallback(static fn (string $contractUuid, bool $isRenewal): string => $isRenewal ? 'dec-renew' : 'dec-1');
		$provider = $this->provider(uid: 'alice', groups: ['aanbod-beheerder'], org: 'org-a');

		$this->assertSame(
			['contractUuid' => self::CONTRACT, 'decisionId' => 'dec-1', 'approvalState' => ContractApprovalServiceInterface::APPROVAL_PENDING],
			$provider->invokeTool('stackiq.submitContractApproval', ['contractUuid' => self::CONTRACT])
		);
		$this->assertSame('dec-renew', $provider->invokeTool('stackiq.submitContractRenewal', ['contractUuid' => self::CONTRACT])['decisionId']);
	}//end testAnOwningCallerSubmits()

	/**
	 * A bad argument is refused before any guard or service call.
	 *
	 * @return void
	 */
	public function testABadArgumentReachesNoService(): void {
		$this->approvals->expects($this->never())->method('authorizeSubmit');
		$this->approvals->expects($this->never())->method('submitForApproval');
		$provider = $this->provider(uid: 'alice');

		foreach ([[], ['contractUuid' => 'not-a-uuid'], ['contractUuid' => 42]] as $args) {
			$this->assertSame('validation_error', $provider->invokeTool('stackiq.submitContractApproval', $args)['error']);
		}
	}//end testABadArgumentReachesNoService()

	/**
	 * Without a signed-in user nothing passes.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingPasses(): void {
		$this->approvals->expects($this->never())->method('authorizeSubmit');
		$this->approvals->expects($this->never())->method('isDelegationConfigured');
		$provider = $this->provider(uid: null);

		$this->assertSame('unauthenticated', $provider->invokeTool('stackiq.submitContractApproval', ['contractUuid' => self::CONTRACT])['error']);
		$this->assertSame('unauthenticated', $provider->invokeTool('stackiq.getContractApprovalConfig', [])['error']);
	}//end testWithoutAUserNothingPasses()

	/**
	 * Unconfigured delegation fails closed with the service's reason; the config tool says so.
	 *
	 * @return void
	 */
	public function testUnconfiguredDelegationFailsClosed(): void {
		$this->approvals->method('authorizeSubmit')->willReturn(true);
		$this->approvals->method('submitForApproval')->willThrowException(new RuntimeException('decidesk is not configured'));
		$this->approvals->method('isDelegationConfigured')->willReturn(false);
		$provider = $this->provider(uid: 'root', groups: ['admin']);

		$this->assertSame(
			['isError' => true, 'error' => 'unavailable', 'message' => 'decidesk is not configured'],
			$provider->invokeTool('stackiq.submitContractApproval', ['contractUuid' => self::CONTRACT])
		);
		$this->assertSame(['configured' => false], $provider->invokeTool('stackiq.getContractApprovalConfig', []));
	}//end testUnconfiguredDelegationFailsClosed()

	/**
	 * The admin gate passes an administrator only, and a failing check denies.
	 *
	 * @return void
	 */
	public function testTheAdminGate(): void {
		$make = function (?string $uid, mixed $isAdmin): McpAdminGate {
			$session = $this->createMock(IUserSession::class);
			$user = null;
			if ($uid !== null) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
			}

			$session->method('getUser')->willReturn($user);
			$groups = $this->createMock(IGroupManager::class);
			if ($isAdmin instanceof \Throwable) {
				$groups->method('isAdmin')->willThrowException($isAdmin);
			} else {
				$groups->method('isAdmin')->willReturn($isAdmin);
			}

			return new McpAdminGate($session, $groups);
		};

		$this->assertNull($make('root', true)->requireAdmin());
		$this->assertSame('forbidden', $make('alice', false)->requireAdmin()['error']);
		$this->assertSame('forbidden', $make('alice', new RuntimeException('ldap down'))->requireAdmin()['error']);
		$this->assertSame('unauthenticated', $make(null, true)->requireAdmin()['error']);
	}//end testTheAdminGate()
}//end class
