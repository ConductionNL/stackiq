<?php

/**
 * Golden tests for AangebodenGebruikController::isUserInGroup.
 *
 * Pins the allowed and refused outcomes of the session-user group check,
 * and its log calls, before the check is split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\AangebodenGebruikController;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the session-user group membership check.
 */
final class AangebodenGebruikControllerGroupCheckGoldenTest extends TestCase {

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * Run isUserInGroup('ambtenaar') against the given session and group doubles.
	 *
	 * @param IUserSession  $session The session double
	 * @param IGroupManager $groups  The group manager double
	 *
	 * @return bool
	 */
	private function check(IUserSession $session, IGroupManager $groups): bool {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$controller = new AangebodenGebruikController(
			'stackiq',
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(AangebodenGebruikService::class),
			$logger,
			$groups
		);

		$method = new \ReflectionMethod($controller, 'isUserInGroup');
		$method->setAccessible(true);
		return $method->invoke($controller, 'ambtenaar');

	}//end check()

	/**
	 * A session holding the given user.
	 *
	 * @param IUser|null $user The user
	 *
	 * @return IUserSession
	 */
	private function session(?IUser $user): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;

	}//end session()

	/**
	 * A user with uid "u1".
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		return $user;

	}//end user()

	/**
	 * A group manager whose ambtenaar group answers inGroup with $member.
	 *
	 * @param bool $member Whether the user is a member
	 *
	 * @return IGroupManager
	 */
	private function groupManager(bool $member): IGroupManager {
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn($member);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('ambtenaar')->willReturn($group);
		return $groups;

	}//end groupManager()

	/**
	 * A member of the group is allowed.
	 *
	 * @return void
	 */
	public function testMemberIsAllowed(): void {
		$this->assertTrue($this->check($this->session($this->user()), $this->groupManager(true)));
		$this->assertSame(
			[['debug', 'User group membership check', ['user' => 'u1', 'group' => 'ambtenaar', 'isMember' => true]]],
			$this->logs
		);

	}//end testMemberIsAllowed()

	/**
	 * A non-member is refused.
	 *
	 * @return void
	 */
	public function testNonMemberIsRefused(): void {
		$this->assertFalse($this->check($this->session($this->user()), $this->groupManager(false)));
		$this->assertSame(
			[['debug', 'User group membership check', ['user' => 'u1', 'group' => 'ambtenaar', 'isMember' => false]]],
			$this->logs
		);

	}//end testNonMemberIsRefused()

	/**
	 * No session user is refused without asking the group manager.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefused(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('get');

		$this->assertFalse($this->check($this->session(null), $groups));
		$this->assertSame([['debug', 'No user in session for group check', ['group' => 'ambtenaar']]], $this->logs);

	}//end testAnonymousIsRefused()

	/**
	 * A group that does not exist has no members.
	 *
	 * @return void
	 */
	public function testMissingGroupIsRefused(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn(null);

		$this->assertFalse($this->check($this->session($this->user()), $groups));
		$this->assertSame([['warning', 'Group does not exist', ['group' => 'ambtenaar']]], $this->logs);

	}//end testMissingGroupIsRefused()

	/**
	 * A failing lookup is refused and logged as an error.
	 *
	 * @return void
	 */
	public function testFailureIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willThrowException(new \RuntimeException('no session'));

		$this->assertFalse($this->check($session, $this->groupManager(true)));
		$this->assertSame(
			[['error', 'Failed to check user group membership', ['group' => 'ambtenaar', 'error' => 'no session']]],
			$this->logs
		);

	}//end testFailureIsRefused()
}//end class
