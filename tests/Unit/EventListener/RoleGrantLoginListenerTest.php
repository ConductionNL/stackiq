<?php

/**
 * Unit tests for RoleGrantLoginListener.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener;

use OCA\Stackiq\EventListener\RoleGrantLoginListener;
use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sign-in syncs the person's role groups, once the role groups are seeded.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
#[CoversClass(RoleGrantLoginListener::class)]
final class RoleGrantLoginListenerTest extends TestCase {

	/**
	 * The sync double.
	 *
	 * @var RoleGrantSync&MockObject
	 */
	private RoleGrantSync&MockObject $sync;

	/**
	 * The role map double.
	 *
	 * @var RoleGroupMap&MockObject
	 */
	private RoleGroupMap&MockObject $roleGroupMap;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The signing-in user.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sync = $this->createMock(originalClassName: RoleGrantSync::class);
		$this->roleGroupMap = $this->createMock(originalClassName: RoleGroupMap::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->user = $this->createMock(originalClassName: IUser::class);
		$this->user->method('getUID')->willReturn('ann');
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return RoleGrantLoginListener
	 */
	private function listener(): RoleGrantLoginListener {
		return new RoleGrantLoginListener(roleGrantSync: $this->sync, roleGroupMap: $this->roleGroupMap, logger: $this->logger);
	}//end listener()

	/**
	 * A real sign-in event syncs that user.
	 *
	 * @return void
	 */
	public function testASignInSyncsTheUser(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(true);
		$this->sync->expects($this->once())->method('syncCatalogueUser')->with(
			$this->identicalTo(value: $this->user)
		)->willReturn(['added' => [], 'removed' => []]);

		$this->listener()->handle(new UserLoggedInEvent($this->user, 'ann', null, false));
	}//end testASignInSyncsTheUser()

	/**
	 * Before the role groups are seeded a sign-in changes nothing.
	 *
	 * @return void
	 */
	public function testNothingIsSyncedBeforeTheSeeding(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(false);
		$this->sync->expects($this->never())->method('syncCatalogueUser');

		$this->listener()->handle(new UserLoggedInEvent($this->user, 'ann', null, false));
	}//end testNothingIsSyncedBeforeTheSeeding()

	/**
	 * A failing sync is logged and does not stop the sign-in.
	 *
	 * @return void
	 */
	public function testAFailingSyncIsLoggedAndSwallowed(): void {
		$this->roleGroupMap->method('grantsSeeded')->willReturn(true);
		$this->sync->method('syncCatalogueUser')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'RoleGrantLoginListener: role groups not synced at sign-in',
			['username' => 'ann', 'error' => 'register down']
		);

		$this->listener()->handle(new UserLoggedInEvent($this->user, 'ann', null, false));
	}//end testAFailingSyncIsLoggedAndSwallowed()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->roleGroupMap->expects($this->never())->method('grantsSeeded');
		$this->sync->expects($this->never())->method('syncCatalogueUser');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsIgnored()
}//end class
