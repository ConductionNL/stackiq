<?php

/**
 * Unit tests for the decomposed GroupHandler helpers.
 *
 * Covers method-decomposition task 9.4 (extract resolveOrganisationData and
 * assignOrganizationGroup from the duplicated logic in
 * updateOrganizationGroups / updateGemeenteGroups).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service\SoftwareCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-4
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service\SoftwareCatalogue;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from
 * GroupHandler::updateOrganizationGroups.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service\SoftwareCatalogue
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-4
 */
class GroupHandlerDecompositionTest extends TestCase {

	/**
	 * Build a handler with stub collaborators.
	 *
	 * @param IGroupManager|null $groupManager Optional group manager mock
	 *
	 * @return GroupHandler
	 */
	private function makeHandler(?IGroupManager $groupManager = null): GroupHandler {
		return new GroupHandler(
			$groupManager ?? $this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end makeHandler()

	/**
	 * assignOrganizationGroup short-circuits when the group ID is empty.
	 *
	 * @return void
	 */
	public function testAssignOrganizationGroupSkipsEmptyGroupId(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->never())->method('get');

		$handler = $this->makeHandler($groupManager);
		$reflection = new \ReflectionMethod($handler, 'assignOrganizationGroup');
		$reflection->setAccessible(true);

		$user = $this->createMock(IUser::class);
		$reflection->invoke($handler, $user, '', 'org-uuid');
		$this->addToAssertionCount(1);

	}//end testAssignOrganizationGroupSkipsEmptyGroupId()

	/**
	 * assignOrganizationGroup adds the user when not already a member.
	 *
	 * @return void
	 */
	public function testAssignOrganizationGroupAddsUserWhenNotMember(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('org-group')->willReturn($group);

		$handler = $this->makeHandler($groupManager);
		$reflection = new \ReflectionMethod($handler, 'assignOrganizationGroup');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, $user, 'org-group', 'org-uuid');

	}//end testAssignOrganizationGroupAddsUserWhenNotMember()

	/**
	 * assignOrganizationGroup is a no-op when the user is already a member.
	 *
	 * @return void
	 */
	public function testAssignOrganizationGroupSkipsExistingMember(): void {
		$user = $this->createMock(IUser::class);

		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(true);
		$group->expects($this->never())->method('addUser');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('org-group')->willReturn($group);

		$handler = $this->makeHandler($groupManager);
		$reflection = new \ReflectionMethod($handler, 'assignOrganizationGroup');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, $user, 'org-group', 'org-uuid');
		$this->addToAssertionCount(1);

	}//end testAssignOrganizationGroupSkipsExistingMember()

	/**
	 * assignOrganizationGroup is a no-op when the group does not exist.
	 *
	 * @return void
	 */
	public function testAssignOrganizationGroupSkipsMissingGroup(): void {
		$user = $this->createMock(IUser::class);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('missing')->willReturn(null);

		$handler = $this->makeHandler($groupManager);
		$reflection = new \ReflectionMethod($handler, 'assignOrganizationGroup');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, $user, 'missing', 'org-uuid');
		$this->addToAssertionCount(1);

	}//end testAssignOrganizationGroupSkipsMissingGroup()

}//end class
