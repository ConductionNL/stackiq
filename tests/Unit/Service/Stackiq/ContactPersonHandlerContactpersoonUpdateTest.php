<?php

/**
 * Unit tests for the contactpersoon-update path of ContactPersonHandler:
 * which changes move a user's role groups, and how the group update refuses
 * when the organisation, its type or the role group is missing.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Contact person handler internals; behaviour pinned here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\SettingsService;
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
use RuntimeException;

/**
 * Tests for ContactPersonHandler::handleContactpersoonUpdate() and updateUserGroupsFromContactData().
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerContactpersoonUpdateTest extends TestCase {

	/**
	 * The organisation uuid.
	 */
	private const ORG = 'org-uuid-1';

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The settings double.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The app manager double.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every warning logged, as message and context.
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Every group membership change, as "add:<group>" or "remove:<group>".
	 *
	 * @var list<string>
	 */
	private array $changes = [];

	/**
	 * The handler under test.
	 *
	 * @var ContactPersonHandler
	 */
	private ContactPersonHandler $handler;

	/**
	 * Set up the handler with its collaborators doubled.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->warnings      = [];
		$this->changes       = [];
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->settings      = $this->createMock(originalClassName: SettingsService::class);
		$this->appManager    = $this->createMock(originalClassName: IAppManager::class);
		$this->userManager   = $this->createMock(originalClassName: IUserManager::class);
		$this->groupManager  = $this->createMock(originalClassName: IGroupManager::class);
		$this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				RegisterConfigReaderInterface::class => $this->settings,
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
			}
		);

		$this->handler = new ContactPersonHandler(
			_userManager: $this->userManager,
			_secureRandom: $this->createMock(originalClassName: ISecureRandom::class),
			_groupManager: $this->groupManager,
			_config: $this->createMock(originalClassName: IAppConfig::class),
			_container: $container,
			_appManager: $this->appManager,
			_logger: $this->logger,
			_emailService: $this->createMock(originalClassName: SymfonyEmailService::class),
			config: $this->createMock(originalClassName: IConfig::class)
		);
	}//end setUp()

	/**
	 * OpenRegister is installed and the organisation has the given type.
	 *
	 * @param string|null $type The organisation type; null when the organisation is not found.
	 *
	 * @return void
	 */
	private function organisationOfType(?string $type): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister', 'stackiq']);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => 'reg', 'organisatie_schema' => 'org']);
		$this->settings->method('getVoorzieningenRegisterId')->willReturn(4);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(5);
		$organisation = null;
		if ($type !== null) {
			$organisation = $this->createMock(originalClassName: ObjectEntity::class);
			$organisation->method('getObject')->willReturn(['type' => $type, 'users' => ['jan']]);
		}

		$this->objectService->method('find')->willReturn($organisation);
	}//end organisationOfType()

	/**
	 * The role groups exist; the user is in the listed ones.
	 *
	 * @param list<string> $memberOf The role groups the user is in.
	 * @param list<string> $missing  The role groups that do not exist.
	 *
	 * @return void
	 */
	private function roleGroups(array $memberOf, array $missing = []): void {
		$this->groupManager->method('get')->willReturnCallback(
			function (string $name) use ($memberOf, $missing): ?IGroup {
				if (in_array($name, $missing, true) === true) {
					return null;
				}

				$group = $this->createMock(originalClassName: IGroup::class);
				$group->method('inGroup')->willReturn(in_array($name, $memberOf, true));
				$group->method('addUser')->willReturnCallback(
					function () use ($name): void {
						$this->changes[] = 'add:' . $name;
					}
				);
				$group->method('removeUser')->willReturnCallback(
					function () use ($name): void {
						$this->changes[] = 'remove:' . $name;
					}
				);
				return $group;
			}
		);
	}//end roleGroups()

	/**
	 * A user double.
	 *
	 * @return IUser&MockObject
	 */
	private function jan(): IUser&MockObject {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jan');
		return $user;
	}//end jan()

	/**
	 * A contact person object with the accessors the handler reads.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return object
	 */
	private function contact(array $data): object {
		return new class($data) {
			/**
			 * @param array<string,mixed> $data The object data.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The database id.
			 *
			 * @return integer
			 */
			public function getId(): int {
				return 11;
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'contact-uuid-11';
			}

			/**
			 * The object data.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * Replace the object data.
			 *
			 * @param array<string,mixed> $data The new data.
			 *
			 * @return void
			 */
			public function setObject(array $data): void {
				$this->data = $data;
			}
		};
	}//end contact()

	/**
	 * Messages of the warnings logged.
	 *
	 * @return list<string>
	 */
	private function warningMessages(): array {
		return array_column($this->warnings, 0);
	}//end warningMessages()

	/**
	 * A role change moves the user to the role group of the organisation's type and out of the other one.
	 *
	 * @return void
	 */
	public function testARoleChangeMovesTheUserToTheOrganisationTypesRoleGroup(): void {
		$this->organisationOfType(type: 'Supplier');
		$this->roleGroups(memberOf: ['gebruik-beheerder']);
		$this->userManager->expects($this->once())->method('get')->with('jan')->willReturn($this->jan());

		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG, 'roles' => ['maintainer']]),
			oldContactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG, 'roles' => []])
		);

		$this->assertSame(['remove:gebruik-beheerder', 'add:aanbod-beheerder'], $this->changes);
	}//end testARoleChangeMovesTheUserToTheOrganisationTypesRoleGroup()

	/**
	 * An organisation change counts as a change also when spelled `organization`.
	 *
	 * @return void
	 */
	public function testAnOrganisationChangeUnderEitherSpellingUpdatesTheGroups(): void {
		$this->organisationOfType(type: 'Municipality');
		$this->roleGroups(memberOf: []);
		$this->userManager->expects($this->once())->method('get')->with('jan')->willReturn($this->jan());

		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: ['username' => 'jan', 'organization' => self::ORG]),
			oldContactPersonObject: $this->contact(data: ['username' => 'jan', 'organization' => 'org-old'])
		);

		$this->assertSame(['add:gebruik-beheerder'], $this->changes);
	}//end testAnOrganisationChangeUnderEitherSpellingUpdatesTheGroups()

	/**
	 * A single role and the same role in a list are the same roles: nothing changes.
	 *
	 * @return void
	 */
	public function testAScalarRoleEqualsTheSameRoleInAList(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$this->userManager->expects($this->never())->method('get');
		$this->groupManager->expects($this->never())->method('get');

		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG, 'roles' => ['viewer']]),
			oldContactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG, 'roles' => 'viewer'])
		);
	}//end testAScalarRoleEqualsTheSameRoleInAList()

	/**
	 * A change on a contact without a username, or whose account is gone, touches no group.
	 *
	 * @return void
	 */
	public function testAChangeWithoutAnAccountTouchesNoGroup(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$this->userManager->expects($this->once())->method('get')->with('ghost')->willReturn(null);
		$this->groupManager->expects($this->never())->method('get');

		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: ['organisation' => self::ORG, 'roles' => ['a']]),
			oldContactPersonObject: $this->contact(data: ['organisation' => self::ORG, 'roles' => ['b']])
		);
		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: ['username' => 'ghost', 'organisation' => self::ORG, 'roles' => ['a']]),
			oldContactPersonObject: $this->contact(data: ['username' => 'ghost', 'organisation' => self::ORG, 'roles' => ['b']])
		);
	}//end testAChangeWithoutAnAccountTouchesNoGroup()

	/**
	 * A failure while reading the old contact is logged with the new contact's id and goes no further.
	 *
	 * @return void
	 */
	public function testAFailingUpdateIsLogged(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$old = new class {
			/**
			 * The old object data cannot be read.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				throw new RuntimeException('old version gone');
			}
		};
		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$errors): void {
				$errors[] = [$message, $context];
			}
		);
		$this->userManager->expects($this->never())->method('get');

		$this->handler->handleContactpersoonUpdate(
			contactPersonObject: $this->contact(data: ['username' => 'jan', 'organisation' => self::ORG]),
			oldContactPersonObject: $old
		);

		$last = end($errors);
		$this->assertSame('Failed to handle contactpersoon update: old version gone', $last[0]);
		$this->assertSame(11, $last[1]['objectId']);
		$this->assertInstanceOf(RuntimeException::class, $last[1]['exception']);
		$this->assertCount(1, array_filter($errors, static fn (array $e): bool => str_starts_with($e[0], 'Failed to handle contactpersoon update')));
	}//end testAFailingUpdateIsLogged()

	/**
	 * Without an organisation the group update stops with a warning.
	 *
	 * @return void
	 */
	public function testWithoutAnOrganisationNoGroupChanges(): void {
		$this->groupManager->expects($this->never())->method('get');

		$this->handler->updateUserGroupsFromContactData(user: $this->jan(), contactData: ['organisation' => '']);

		$this->assertSame([['No organization ID found for user group update', ['username' => 'jan']]], $this->warnings);
	}//end testWithoutAnOrganisationNoGroupChanges()

	/**
	 * An organisation that is not found, or whose type maps to no role group, changes no group.
	 *
	 * @return void
	 */
	public function testAnOrganisationWithoutARoleGroupChangesNoGroup(): void {
		$this->organisationOfType(type: null);
		$this->groupManager->expects($this->never())->method('get');

		$this->handler->updateUserGroupsFromContactData(user: $this->jan(), contactData: ['organization' => self::ORG]);

		$this->assertSame(['Organization not found', 'No role group mapping found for organization type during update'], $this->warningMessages());
		$this->assertSame(
			['username' => 'jan', 'organizationId' => self::ORG, 'organizationType' => ''],
			$this->warnings[1][1]
		);
	}//end testAnOrganisationWithoutARoleGroupChangesNoGroup()

	/**
	 * Without OpenRegister the type cannot be read, so no group changes.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNoGroupChanges(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['stackiq']);
		$this->groupManager->expects($this->never())->method('get');

		$this->handler->updateUserGroupsFromContactData(user: $this->jan(), contactData: ['organisation' => self::ORG]);

		$this->assertSame(
			[
				'Could not determine organization type (org may not be synced yet): OpenRegister service is not available.',
				'No role group mapping found for organization type during update',
			],
			$this->warningMessages()
		);
	}//end testWithoutOpenRegisterNoGroupChanges()

	/**
	 * A user already in the target role group is not added again, and a missing target group is skipped.
	 *
	 * @return void
	 */
	public function testAnExistingOrMissingTargetGroupIsNotAddedTo(): void {
		$this->organisationOfType(type: 'community');
		$this->roleGroups(memberOf: ['aanbod-beheerder']);

		$this->handler->updateUserGroupsFromContactData(user: $this->jan(), contactData: ['organisation' => self::ORG]);
		$this->assertSame([], $this->changes);

		$this->setUp();
		$this->organisationOfType(type: 'Collaboration');
		$this->roleGroups(memberOf: [], missing: ['gebruik-beheerder']);

		$this->handler->updateUserGroupsFromContactData(user: $this->jan(), contactData: ['organisation' => self::ORG]);
		$this->assertSame([], $this->changes);
		$this->assertSame(
			[['Group does not exist, skipping user assignment', ['username' => 'jan', 'groupName' => 'gebruik-beheerder', 'type' => 'organization-type-role-update']]],
			$this->warnings
		);
	}//end testAnExistingOrMissingTargetGroupIsNotAddedTo()

	/**
	 * A group manager that fails while the old role group is checked is logged and stops the update.
	 *
	 * @return void
	 */
	public function testAFailingGroupLookupIsLogged(): void {
		$this->organisationOfType(type: 'Supplier');
		$this->groupManager->method('get')->willThrowException(new RuntimeException('ldap down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to update user groups from contact data: ldap down',
			$this->callback(static fn (array $context): bool => $context['username'] === 'jan')
		);

		$this->handler->updateUserGroupsFromContactData(user: $this->jan(), contactData: ['organisation' => self::ORG]);
	}//end testAFailingGroupLookupIsLogged()
}//end class
