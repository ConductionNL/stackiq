<?php

/**
 * Golden tests for HierarchyHandler::ensureOrganizationBeheerder() and
 * setupManagerRelationships().
 *
 * Pins who is made beheerder (only the first contact of an organisation
 * without one), who gets which manager, and every log call, before both
 * methods are split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the beheerder assignment and manager relationships.
 */
class HierarchyHandlerBeheerderGoldenTest extends TestCase {

	/**
	 * Every collaborator and log call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

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
	 * Build the handler with recording doubles.
	 *
	 * @return HierarchyHandler
	 */
	private function handler(): HierarchyHandler {
		$this->organizations  = $this->createMock(OrganizationHandler::class);
		$this->contactPersons = $this->createMock(ContactPersonHandler::class);
		$this->contactPersons->method('assignBeheerderRole')->willReturnCallback(
			function (object $contact, string $username, string $organizationUuid): void {
				$this->calls[] = ['assignBeheerderRole', $username, $organizationUuid];
			}
		);
		$this->contactPersons->method('setUserManager')->willReturnCallback(
			function (string $username, string $managerUsername): void {
				$this->calls[] = ['setUserManager', $username, $managerUsername];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['exception']);
					$this->calls[] = ['log.' . $level, $message, $context];
				}
			);
		}

		return new HierarchyHandler($this->organizations, $this->contactPersons, $logger, $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class));

	}//end handler()

	/**
	 * A contactgegevens object double.
	 *
	 * @param array $data Object data
	 *
	 * @return object
	 */
	private function contact(array $data): object {
		return new class($data) {
			/**
			 * @param array $data Data
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}
		};

	}//end contact()

	/**
	 * Without a linked organisation only a debug line is logged.
	 *
	 * @return void
	 */
	public function testNoOrganisation(): void {
		$handler = $this->handler();
		$this->organizations->expects($this->never())->method('getOrganizationBeheerders');

		$handler->ensureOrganizationBeheerder($this->contact([]), 'ann');

		$this->assertSame([['log.debug', 'No organization linked to contactgegevens.', []]], $this->calls);

	}//end testNoOrganisation()

	/**
	 * The first contact of an organisation without beheerders becomes its beheerder.
	 *
	 * @return void
	 */
	public function testFirstContactBecomesBeheerder(): void {
		$handler = $this->handler();
		$this->organizations->method('getOrganizationBeheerders')->with('org-1')->willReturn([]);

		$handler->ensureOrganizationBeheerder($this->contact(['organization' => 'org-1']), 'ann');

		$this->assertSame(
			[
				['assignBeheerderRole', 'ann', 'org-1'],
				['log.info', 'Set up manager relationships', ['organization' => 'org-1', 'primaryManager' => 'ann', 'allBeheerders' => ['ann'], 'processedUser' => 'ann']],
			],
			$this->calls
		);

	}//end testFirstContactBecomesBeheerder()

	/**
	 * An existing beheerder team: no role is assigned, the user and the other beheerders report to the first.
	 *
	 * @return void
	 */
	public function testExistingBeheerdersManageTheNewContact(): void {
		$handler = $this->handler();
		$this->organizations->method('getOrganizationBeheerders')->with('org-2')->willReturn(['bob', 'cid', 'dee']);

		$handler->ensureOrganizationBeheerder($this->contact(['organisation' => 'org-2', 'organization' => 'ignored']), 'ann');

		$this->assertSame(
			[
				['setUserManager', 'ann', 'bob'],
				['setUserManager', 'cid', 'bob'],
				['setUserManager', 'dee', 'bob'],
				['log.info', 'Set up manager relationships', ['organization' => 'org-2', 'primaryManager' => 'bob', 'allBeheerders' => ['bob', 'cid', 'dee'], 'processedUser' => 'ann']],
			],
			$this->calls
		);

	}//end testExistingBeheerdersManageTheNewContact()

	/**
	 * Failures are logged, never thrown: in the lookup, and inside the manager set-up.
	 *
	 * @return void
	 */
	public function testFailuresAreLogged(): void {
		$handler = $this->handler();
		$this->organizations->method('getOrganizationBeheerders')->willThrowException(new \RuntimeException('no org'));
		$handler->ensureOrganizationBeheerder($this->contact(['organization' => 'org-3']), 'ann');
		$this->assertSame([['log.error', 'Failed to ensure organization beheerder: no org', ['username' => 'ann']]], $this->calls);

		$this->calls = [];
		$handler     = $this->handler();
		$this->contactPersons->method('setUserManager')->willThrowException(new \RuntimeException('no user'));
		$handler->setupManagerRelationships('ann', ['bob'], 'org-4');
		$handler->setupManagerRelationships('ann', [], 'org-4');
		$this->assertSame(
			[
				['setUserManager', 'ann', 'bob'],
				['log.error', 'Failed to setup manager relationships: no user', ['username' => 'ann', 'organization' => 'org-4']],
			],
			$this->calls
		);

	}//end testFailuresAreLogged()
}//end class
