<?php

/**
 * Unit tests for the decomposed HierarchyHandler helpers.
 *
 * Covers method-decomposition task 8.5 — split setupManagerRelationships()
 * into resolvePrimaryManager() + assignManagerForCurrentUser() +
 * assignManagerForOtherBeheerders().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-5
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the private helpers extracted from
 * HierarchyHandler::setupManagerRelationships.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\Stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-5
 */
class HierarchyHandlerDecompositionTest extends TestCase {

	/**
	 * Build a handler with stub collaborators.
	 *
	 * @param ContactPersonHandler|null $contactPerson Optional ContactPersonHandler mock
	 *
	 * @return HierarchyHandler
	 */
	private function makeHandler(?ContactPersonHandler $contactPerson = null): HierarchyHandler {
		return new HierarchyHandler(
			$this->createMock(OrganizationHandler::class),
			$contactPerson ?? $this->createMock(ContactPersonHandler::class),
			new NullLogger(),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
		);

	}//end makeHandler()

	/**
	 * resolvePrimaryManager picks the first beheerder.
	 *
	 * @return void
	 */
	public function testResolvePrimaryManagerPicksFirst(): void {
		$handler = $this->makeHandler();
		$reflection = new \ReflectionMethod($handler, 'resolvePrimaryManager');
		$reflection->setAccessible(true);

		$this->assertSame('alice', $reflection->invoke($handler, ['alice', 'bob', 'carol']));

	}//end testResolvePrimaryManagerPicksFirst()

	/**
	 * assignManagerForCurrentUser delegates when the user is not already a
	 * beheerder.
	 *
	 * @return void
	 */
	public function testAssignManagerForCurrentUserDelegatesWhenNotBeheerder(): void {
		$contactPerson = $this->createMock(ContactPersonHandler::class);
		$contactPerson->expects($this->once())
			->method('setUserManager')
			->with($this->equalTo('dave'), $this->equalTo('alice'));

		$handler = $this->makeHandler($contactPerson);
		$reflection = new \ReflectionMethod($handler, 'assignManagerForCurrentUser');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, 'dave', ['alice', 'bob'], 'alice');

	}//end testAssignManagerForCurrentUserDelegatesWhenNotBeheerder()

	/**
	 * assignManagerForCurrentUser is a no-op when the user is already a
	 * beheerder.
	 *
	 * @return void
	 */
	public function testAssignManagerForCurrentUserNoopWhenBeheerder(): void {
		$contactPerson = $this->createMock(ContactPersonHandler::class);
		$contactPerson->expects($this->never())->method('setUserManager');

		$handler = $this->makeHandler($contactPerson);
		$reflection = new \ReflectionMethod($handler, 'assignManagerForCurrentUser');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, 'alice', ['alice', 'bob'], 'alice');
		$this->addToAssertionCount(1);

	}//end testAssignManagerForCurrentUserNoopWhenBeheerder()

	/**
	 * assignManagerForOtherBeheerders is a no-op when there is only one
	 * beheerder (the primary manager themselves).
	 *
	 * @return void
	 */
	public function testAssignManagerForOtherBeheerdersSingleNoop(): void {
		$contactPerson = $this->createMock(ContactPersonHandler::class);
		$contactPerson->expects($this->never())->method('setUserManager');

		$handler = $this->makeHandler($contactPerson);
		$reflection = new \ReflectionMethod($handler, 'assignManagerForOtherBeheerders');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, ['alice'], 'alice');
		$this->addToAssertionCount(1);

	}//end testAssignManagerForOtherBeheerdersSingleNoop()

	/**
	 * assignManagerForOtherBeheerders points every non-primary at the
	 * primary manager and skips the primary themselves.
	 *
	 * @return void
	 */
	public function testAssignManagerForOtherBeheerdersPointsAtPrimary(): void {
		$contactPerson = $this->createMock(ContactPersonHandler::class);
		$contactPerson->expects($this->exactly(2))
			->method('setUserManager')
			->willReturnCallback(
				function (string $username, string $managerUsername): void {
					static $calls = 0;
					$expectedUsernames = ['bob', 'carol'];
					$this->assertSame('alice', $managerUsername);
					$this->assertSame($expectedUsernames[$calls], $username);
					$calls++;
				}
			);

		$handler = $this->makeHandler($contactPerson);
		$reflection = new \ReflectionMethod($handler, 'assignManagerForOtherBeheerders');
		$reflection->setAccessible(true);

		$reflection->invoke($handler, ['alice', 'bob', 'carol'], 'alice');

	}//end testAssignManagerForOtherBeheerdersPointsAtPrimary()

}//end class
