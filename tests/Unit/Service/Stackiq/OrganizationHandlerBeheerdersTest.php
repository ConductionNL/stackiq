<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden tests for OrganizationHandler::getOrganizationBeheerders: which maintainers
 * are listed for an organisation (allowed and refused), their order and the logs.
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
class OrganizationHandlerBeheerdersTest extends TestCase {
	/**
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * @var IUserManager|MockObject
	 */
	private IUserManager|MockObject $userManager;

	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return OrganizationHandler
	 */
	private function handler(): OrganizationHandler {
		return new OrganizationHandler(
			$this->groupManager,
			$this->userManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->logger
		);
	}//end handler()

	/**
	 * A user mock.
	 *
	 * @param string $uid       The user id.
	 * @param int    $lastLogin The last login timestamp.
	 *
	 * @return IUser|MockObject
	 */
	private function user(string $uid, int $lastLogin): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getLastLogin')->willReturn($lastLogin);
		return $user;
	}//end user()

	/**
	 * A group mock.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup|MockObject
	 */
	private function group(string $gid): IGroup|MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		return $group;
	}//end group()

	/**
	 * Without a maintainer group nobody is listed and nothing is logged.
	 *
	 * @return void
	 */
	public function testNoMaintainerGroup(): void {
		$this->groupManager->method('get')->willReturn(null);

		$this->assertSame([], $this->handler()->getOrganizationBeheerders('Org-1'));
		$this->assertSame([], $this->logger->records);
	}//end testNoMaintainerGroup()

	/**
	 * Members of the organisation group, or of a group naming the organisation, are listed oldest login
	 * first; other maintainers are refused.
	 *
	 * @return void
	 */
	public function testListsOnlyMembersSortedByLastLogin(): void {
		$recent = $this->user('recent', 300);
		$early = $this->user('early', 100);
		$byGroupName = $this->user('by-group-name', 200);
		$outsider = $this->user('outsider', 50);
		$neverLoggedIn = $this->user('never', 0);
		$gone = $this->user('gone', 999);

		$maintainers = $this->group('maintainer');
		$maintainers->method('getUsers')->willReturn([$recent, $outsider, $early, $byGroupName, $neverLoggedIn, $gone]);
		$orgGroup = $this->group('org-1');
		$orgGroup->method('inGroup')->willReturnCallback(
			fn (IUser $user): bool => in_array($user->getUID(), ['recent', 'early', 'never', 'gone'], true)
		);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ['maintainer' => $maintainers, 'org-1' => $orgGroup][$gid] ?? null
		);
		$this->groupManager->method('getUserGroups')->willReturnCallback(
			fn (IUser $user): array => [
				'by-group-name' => [$this->group('admins'), $this->group('beheer-Org-1')],
				'outsider' => [$this->group('other-org')],
			][$user->getUID()] ?? []
		);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => ['recent' => $recent, 'early' => $early, 'by-group-name' => $byGroupName, 'never' => $neverLoggedIn][$uid] ?? null
		);

		$result = $this->handler()->getOrganizationBeheerders('Org-1');

		$this->assertSame(['never', 'gone', 'early', 'by-group-name', 'recent'], $result);
		$this->assertSame(
			[['info', 'Found organization beheerders', ['organization' => 'Org-1', 'beheerders' => $result]]],
			$this->logger->records
		);
	}//end testListsOnlyMembersSortedByLastLogin()

	/**
	 * A failure while listing logs an error and lists nobody.
	 *
	 * @return void
	 */
	public function testFailureListsNobody(): void {
		$failure = new RuntimeException('directory down');
		$this->groupManager->method('get')->willThrowException($failure);

		$this->assertSame([], $this->handler()->getOrganizationBeheerders('Org-1'));
		$this->assertSame(
			[['error', 'Failed to get organization beheerders: directory down', ['organization' => 'Org-1', 'exception' => $failure]]],
			$this->logger->records
		);
	}//end testFailureListsNobody()
	/**
	 * Membership: the organisation group admits its members; without it a group naming the
	 * organisation admits; anything else is refused.
	 *
	 * @return void
	 */
	public function testMembershipAllowedAndRefused(): void {
		$member = $this->user('member', 1);
		$named = $this->user('named', 1);
		$outsider = $this->user('outsider', 1);
		$orgGroup = $this->group('org-2');
		$orgGroup->method('inGroup')->willReturnCallback(fn (IUser $user): bool => $user->getUID() === 'member');
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ['org-2' => $orgGroup][$gid] ?? null
		);
		$this->groupManager->method('getUserGroups')->willReturnCallback(
			fn (IUser $user): array => ['named' => [$this->group('x-Org-2-y')], 'outsider' => [$this->group('org-2'), $this->group('Org-3')]][$user->getUID()] ?? []
		);

		$handler = $this->handler();
		$this->assertTrue($handler->userBelongsToOrganization($member, 'Org-2'));
		$this->assertTrue($handler->userBelongsToOrganization($named, 'Org-2'));
		$this->assertFalse($handler->userBelongsToOrganization($outsider, 'Org-2'), 'the GID match is case-sensitive on the raw uuid');
		$this->assertSame([], $this->logger->records);
	}//end testMembershipAllowedAndRefused()

	/**
	 * Without the organisation group only a group naming the organisation admits.
	 *
	 * @return void
	 */
	public function testMembershipWithoutOrganisationGroup(): void {
		$named = $this->user('named', 1);
		$outsider = $this->user('outsider', 1);
		$this->groupManager->method('get')->willReturn(null);
		$this->groupManager->method('getUserGroups')->willReturnCallback(
			fn (IUser $user): array => ['named' => [$this->group('Org-2')]][$user->getUID()] ?? []
		);

		$handler = $this->handler();
		$this->assertTrue($handler->userBelongsToOrganization($named, 'Org-2'));
		$this->assertFalse($handler->userBelongsToOrganization($outsider, 'Org-2'));
	}//end testMembershipWithoutOrganisationGroup()

	/**
	 * A failure while checking refuses membership and logs an error.
	 *
	 * @return void
	 */
	public function testMembershipFailureRefuses(): void {
		$this->groupManager->method('get')->willReturn(null);
		$this->groupManager->method('getUserGroups')->willThrowException(new RuntimeException('ldap down'));

		$this->assertFalse($this->handler()->userBelongsToOrganization($this->user('someone', 1), 'Org-2'));
		$this->assertSame(
			[['error', 'Failed to check user organization membership: ldap down', ['username' => 'someone', 'organization' => 'Org-2']]],
			$this->logger->records
		);
	}//end testMembershipFailureRefuses()
}//end class
