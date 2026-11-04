<?php

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The role group of an organisation type, and adding a user to a group that exists.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 * @version  1.0.0
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerTest extends TestCase {

	/**
	 * The group manager.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface|MockObject $logger;

	/**
	 * The handler under test.
	 *
	 * @var ContactPersonHandler
	 */
	private ContactPersonHandler $handler;

	/**
	 * Build the handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
		$this->handler      = new ContactPersonHandler(
			$this->createMock(IUserManager::class),
			$this->createMock(ISecureRandom::class),
			$this->groupManager,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->logger,
			$this->createMock(SymfonyEmailService::class),
			$this->createMock(IConfig::class)
		);
	}//end setUp()

	/**
	 * The stored organisation types map whatever their case or surrounding space;
	 * the retired Dutch names and anything else map to no group.
	 *
	 * @return void
	 */
	public function testAnOrganisationTypeMapsToItsRoleGroup(): void {
		$method = new ReflectionMethod(ContactPersonHandler::class, 'getRoleGroupByOrganizationType');
		$cases  = [
			'Municipality'    => 'gebruik-beheerder',
			'  municipality ' => 'gebruik-beheerder',
			'Collaboration'   => 'gebruik-beheerder',
			'Supplier'        => 'aanbod-beheerder',
			'Community'       => 'aanbod-beheerder',
			'Gemeente'        => '',
			'leverancier'     => '',
			'samenwerking'    => '',
			'UnknownType'     => '',
			''                => '',
		];
		foreach ($cases as $type => $group) {
			self::assertSame($group, $method->invoke($this->handler, (string) $type), 'type "' . $type . '"');
		}
	}//end testAnOrganisationTypeMapsToItsRoleGroup()

	/**
	 * A user who is not in an existing group is added, and that is logged.
	 *
	 * @return void
	 */
	public function testAUserIsAddedToAnExistingGroup(): void {
		$user  = $this->user();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('existing-group')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->logger->expects($this->once())->method('info')->with(
			'Added user to existing group',
			['username' => 'testuser', 'groupName' => 'existing-group', 'type' => 'test-type']
		);

		$this->addToGroup(user: $user, group: 'existing-group');
	}//end testAUserIsAddedToAnExistingGroup()

	/**
	 * A group that does not exist is not created; the user is left out and it is logged.
	 *
	 * @return void
	 */
	public function testAMissingGroupIsNotCreated(): void {
		$this->groupManager->method('get')->with('missing-group')->willReturn(null);
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->logger->expects($this->once())->method('warning')->with(
			'Group does not exist, skipping user assignment',
			['username' => 'testuser', 'groupName' => 'missing-group', 'type' => 'test-type']
		);

		$this->addToGroup(user: $this->user(), group: 'missing-group');
	}//end testAMissingGroupIsNotCreated()

	/**
	 * A user already in the group is not added again.
	 *
	 * @return void
	 */
	public function testAUserInTheGroupIsNotAddedAgain(): void {
		$user  = $this->user();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groupManager->method('get')->willReturn($group);

		$this->addToGroup(user: $user, group: 'existing-group');
	}//end testAUserInTheGroupIsNotAddedAgain()

	/**
	 * A failing group manager is logged and does not stop the caller.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndDoesNotEscape(): void {
		$this->groupManager->method('get')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to add user to group with check: down',
			$this->callback(static fn (array $context): bool => $context['username'] === 'testuser' && $context['groupName'] === 'existing-group')
		);

		$this->addToGroup(user: $this->user(), group: 'existing-group');
	}//end testAFailureIsLoggedAndDoesNotEscape()

	/**
	 * Call the private group helper.
	 *
	 * @param IUser  $user  The user.
	 * @param string $group The group name.
	 *
	 * @return void
	 */
	private function addToGroup(IUser $user, string $group): void {
		(new ReflectionMethod(ContactPersonHandler::class, 'addUserToGroupWithCheck'))->invoke($this->handler, $user, $group, 'test-type');
	}//end addToGroup()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		return $user;
	}//end user()
}//end class
