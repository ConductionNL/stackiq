<?php

/**
 * Characterisation tests for the HierarchyHandler read methods.
 *
 * Pins getUserHierarchy(), getOrganizationStructure(), the maintainer-group
 * check and the subordinate search (including a failure part-way through)
 * before they are split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the HierarchyHandler read methods.
 */
class HierarchyHandlerReadsTest extends TestCase {

	/**
	 * Organization handler double.
	 *
	 * @var OrganizationHandler|MockObject
	 */
	private OrganizationHandler|MockObject $organizations;

	/**
	 * Contact person handler double.
	 *
	 * @var ContactPersonHandler|MockObject
	 */
	private ContactPersonHandler|MockObject $contactPersons;

	/**
	 * User manager double.
	 *
	 * @var IUserManager|MockObject
	 */
	private IUserManager|MockObject $users;

	/**
	 * Group manager double.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * Recorded error log calls as [message, context keys].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $errors = [];

	/**
	 * Build the handler with fresh doubles.
	 *
	 * @return HierarchyHandler
	 */
	private function handler(): HierarchyHandler {
		$this->organizations  = $this->createMock(OrganizationHandler::class);
		$this->contactPersons = $this->createMock(ContactPersonHandler::class);
		$this->users          = $this->createMock(IUserManager::class);
		$this->groupManager   = $this->createMock(IGroupManager::class);
		$logger               = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new HierarchyHandler($this->organizations, $this->contactPersons, $logger, $this->users, $this->groupManager);

	}//end handler()

	/**
	 * A user double with the given uid.
	 *
	 * @param string $uid The uid
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()

	/**
	 * Make "bob" a member (or not) of an existing maintainer group.
	 *
	 * @param bool $member Membership
	 *
	 * @return void
	 */
	private function maintainerGroup(bool $member): void {
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn($member);
		$this->groupManager->method('get')->with('maintainer')->willReturn($group);
		$this->users->method('get')->willReturn($this->user('bob'));

	}//end maintainerGroup()

	/**
	 * A primary manager: no manager, subordinates, in the maintainer group.
	 *
	 * @return void
	 */
	public function testPrimaryManagerHierarchy(): void {
		$handler = $this->handler();
		$this->users->method('search')->with('')->willReturn([$this->user('ann'), $this->user('bob'), $this->user('cid')]);
		$this->contactPersons->method('getUserManager')->willReturnMap(
			[
				['bob', null],
				['ann', 'bob'],
				['cid', 'bob'],
			]
		);
		$this->maintainerGroup(true);

		$this->assertSame(
			[
				'username' => 'bob',
				'manager' => null,
				'subordinates' => ['ann', 'cid'],
				'organization' => null,
				'isBeheerder' => true,
				'isPrimaryManager' => true,
			],
			$handler->getUserHierarchy('bob')
		);

	}//end testPrimaryManagerHierarchy()

	/**
	 * A managed user without subordinates outside the maintainer group.
	 *
	 * @return void
	 */
	public function testManagedUserHierarchy(): void {
		$handler = $this->handler();
		$this->users->method('search')->willReturn([$this->user('ann')]);
		$this->contactPersons->method('getUserManager')->willReturn('zed');
		$this->maintainerGroup(false);

		$this->assertSame(
			[
				'username' => 'bob',
				'manager' => 'zed',
				'subordinates' => [],
				'organization' => null,
				'isBeheerder' => false,
				'isPrimaryManager' => false,
			],
			$handler->getUserHierarchy('bob')
		);

	}//end testManagedUserHierarchy()

	/**
	 * A failing manager lookup yields [] and an error log.
	 *
	 * @return void
	 */
	public function testHierarchyFailureYieldsEmptyArray(): void {
		$handler = $this->handler();
		$this->contactPersons->method('getUserManager')->willThrowException(new \RuntimeException('down'));

		$this->assertSame([], $handler->getUserHierarchy('bob'));
		$this->assertSame('Failed to get user hierarchy: down', $this->errors[0][0]);
		$this->assertSame(['username', 'exception'], array_keys($this->errors[0][1]));

	}//end testHierarchyFailureYieldsEmptyArray()

	/**
	 * A failure part-way through the user scan keeps the subordinates found so far.
	 *
	 * @return void
	 */
	public function testSubordinateScanKeepsPartialResultOnFailure(): void {
		$handler = $this->handler();
		$this->users->method('search')->willReturn([$this->user('ann'), $this->user('bob'), $this->user('cid')]);
		$this->contactPersons->method('getUserManager')->willReturnCallback(
			function (string $uid): ?string {
				if ($uid === 'cid') {
					throw new \RuntimeException('cid broke');
				}

				return ['ann' => 'bob', 'bob' => null][$uid];
			}
		);
		$this->groupManager->method('get')->willReturn(null);

		$result = $handler->getUserHierarchy('bob');

		$this->assertSame(['ann'], $result['subordinates']);
		$this->assertFalse($result['isBeheerder']);
		$this->assertTrue($result['isPrimaryManager']);
		$this->assertSame('Failed to find subordinates: cid broke', $this->errors[0][0]);
		$this->assertSame(['username' => 'bob'], array_intersect_key($this->errors[0][1], ['username' => 1]));

	}//end testSubordinateScanKeepsPartialResultOnFailure()

	/**
	 * The maintainer check refuses an unknown user and logs-and-refuses a failure.
	 *
	 * @return void
	 */
	public function testMaintainerCheckRefusals(): void {
		$handler = $this->handler();
		$method  = new \ReflectionMethod($handler, 'isUserMaintainer');
		$method->setAccessible(true);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('inGroup');
		$this->groupManager->method('get')->willReturn($group);
		$this->users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'boom') {
					throw new \RuntimeException('lookup failed');
				}

				return null;
			}
		);

		$this->assertFalse($method->invoke($handler, 'ghost'));
		$this->assertFalse($method->invoke($handler, 'boom'));
		$this->assertSame('Failed to check if user is beheerder: lookup failed', $this->errors[0][0]);

	}//end testMaintainerCheckRefusals()

	/**
	 * The organisation structure lists the beheerders, the first as primary, with each hierarchy.
	 *
	 * @return void
	 */
	public function testOrganizationStructure(): void {
		$handler = $this->handler();
		$this->organizations->method('getOrganizationBeheerders')->with('org-1')->willReturn(['bob']);
		$this->users->method('search')->willReturn([]);
		$this->contactPersons->method('getUserManager')->willReturn(null);
		$this->maintainerGroup(true);

		$this->assertSame(
			[
				'organization' => 'org-1',
				'beheerders' => ['bob'],
				'primaryManager' => 'bob',
				'hierarchy' => [
					[
						'username' => 'bob',
						'manager' => null,
						'subordinates' => [],
						'organization' => null,
						'isBeheerder' => true,
						'isPrimaryManager' => false,
					],
				],
			],
			$handler->getOrganizationStructure('org-1')
		);

	}//end testOrganizationStructure()

	/**
	 * No beheerders: no primary manager and an empty hierarchy; a failure yields [].
	 *
	 * @return void
	 */
	public function testOrganizationStructureEmptyAndFailure(): void {
		$handler = $this->handler();
		$this->organizations->method('getOrganizationBeheerders')->willReturnCallback(
			function (string $uuid): array {
				if ($uuid === 'bad') {
					throw new \RuntimeException('no org');
				}

				return [];
			}
		);

		$this->assertSame(
			[
				'organization' => 'org-2',
				'beheerders' => [],
				'primaryManager' => null,
				'hierarchy' => [],
			],
			$handler->getOrganizationStructure('org-2')
		);
		$this->assertSame([], $handler->getOrganizationStructure('bad'));
		$this->assertSame('Failed to get organization structure: no org', $this->errors[0][0]);
		$this->assertSame(['organization', 'exception'], array_keys($this->errors[0][1]));

	}//end testOrganizationStructureEmptyAndFailure()
}//end class
