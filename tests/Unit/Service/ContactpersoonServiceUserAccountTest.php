<?php

/**
 * Unit tests for the user-account operations of ContactpersoonService.
 *
 * Covers the group update and delegations to the handlers, disabling the
 * user of a deleted contact person, enabling and disabling the user of a
 * contact person by id, the bulk user info lookup, and the update handler's
 * role-change path.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the user-account operations of ContactpersoonService.
 */
#[CoversClass(ContactpersoonService::class)]
class ContactpersoonServiceUserAccountTest extends TestCase {

	/**
	 * Log calls as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * Users the user manager knows, by uid.
	 *
	 * @var array<string, IUser>
	 */
	private array $users = [];

	/**
	 * Whether OpenRegister is enabled.
	 *
	 * @var boolean
	 */
	private bool $openRegisterEnabled = true;

	/**
	 * The contact person handler mock.
	 *
	 * @var ContactPersonHandler&MockObject
	 */
	private ContactPersonHandler&MockObject $contactPersonHandler;

	/**
	 * The hierarchy handler mock.
	 *
	 * @var HierarchyHandler&MockObject
	 */
	private HierarchyHandler&MockObject $hierarchyHandler;

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The config reader mock.
	 *
	 * @var RegisterConfigReaderInterface&MockObject
	 */
	private RegisterConfigReaderInterface&MockObject $settings;

	/**
	 * The group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contactPersonHandler = $this->createMock(ContactPersonHandler::class);
		$this->hierarchyHandler     = $this->createMock(HierarchyHandler::class);
		$this->objectService        = $this->createMock(ObjectServiceInterface::class);
		$this->settings             = $this->createMock(RegisterConfigReaderInterface::class);
		$this->groupManager         = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * A user the user manager knows.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether the account is enabled.
	 *
	 * @return IUser&MockObject
	 */
	private function addUser(string $uid, bool $enabled = true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		$user->method('getDisplayName')->willReturn('Display ' . $uid);
		$user->method('getLastLogin')->willReturn(1700000000);
		$this->users[$uid] = $user;
		return $user;
	}//end addUser()

	/**
	 * A contact person object as the event listeners pass it.
	 *
	 * @param string|int $id   The id.
	 * @param array      $data The object data.
	 *
	 * @return object
	 */
	private function contactPerson(string|int $id, array $data): object {
		return new class($id, $data) {
			/**
			 * @param string|int $id   The id.
			 * @param array      $data The data.
			 */
			public function __construct(private string|int $id, private array $data) {
			}

			/**
			 * @return string|int
			 */
			public function getId(): string|int {
				return $this->id;
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return $this->data;
			}
		};
	}//end contactPerson()

	/**
	 * An OpenRegister object entity holding contact data.
	 *
	 * @param array $data The object data.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Build the service under test.
	 *
	 * @return ContactpersoonService
	 */
	private function service(): ContactpersoonService {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->users[$uid] ?? null));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCP\IUserManager' => $userManager,
				'OCP\IGroupManager' => $this->groupManager,
				'OCA\OpenRegister\Service\ObjectService' => $this->objectService,
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			fn (string $app): bool => ($app === 'openregister' && $this->openRegisterEnabled)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['trace']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ContactpersoonService(
			contactPersonHandler: $this->contactPersonHandler,
			groupHandler: $this->createMock(GroupHandler::class),
			hierarchyHandler: $this->hierarchyHandler,
			logger: $logger,
			container: $container,
			appManager: $appManager,
			config: $this->createMock(IAppConfig::class),
			settingsService: $this->settings
		);
	}//end service()

	/**
	 * The messages logged at one level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string>
	 */
	private function messages(string $level): array {
		return array_values(
			array_map(
				fn (array $log): string => $log[1],
				array_filter($this->logs, fn (array $log): bool => $log[0] === $level)
			)
		);
	}//end messages()

	/**
	 * The groups of a known user are updated from the contact data.
	 *
	 * @return void
	 */
	public function testUpdateUserGroupsPassesTheContactDataToTheHandler(): void {
		$user = $this->addUser('anna@example.nl');
		$this->contactPersonHandler->expects($this->once())
			->method('updateUserGroupsFromContactData')
			->with($user, ['roles' => ['beheerder']]);

		$this->service()->updateUserGroups($this->contactPerson(1, ['roles' => ['beheerder']]), 'anna@example.nl');
	}//end testUpdateUserGroupsPassesTheContactDataToTheHandler()

	/**
	 * An unknown user gets no group update and a warning.
	 *
	 * @return void
	 */
	public function testUpdateUserGroupsSkipsAnUnknownUser(): void {
		$this->contactPersonHandler->expects($this->never())->method('updateUserGroupsFromContactData');

		$this->service()->updateUserGroups($this->contactPerson(1, []), 'ghost@example.nl');

		$this->assertSame(['User not found for group update'], $this->messages('warning'));
		$this->assertSame(['username' => 'ghost@example.nl'], $this->logs[0][2]);
	}//end testUpdateUserGroupsSkipsAnUnknownUser()

	/**
	 * The beheerder check and the manager lookup go to their handlers.
	 *
	 * @return void
	 */
	public function testBeheerderAndManagerLookupsDelegate(): void {
		$contact = $this->contactPerson(3, ['organisation' => 'org-1']);
		$this->hierarchyHandler->expects($this->once())
			->method('ensureOrganizationBeheerder')
			->with($contact, 'anna@example.nl');
		$this->contactPersonHandler->expects($this->once())
			->method('getUserManager')
			->with('anna@example.nl')
			->willReturn('boss@example.nl');

		$service = $this->service();
		$service->ensureOrganizationBeheerder($contact, 'anna@example.nl');

		$this->assertSame('boss@example.nl', $service->getUserManager('anna@example.nl'));
	}//end testBeheerderAndManagerLookupsDelegate()

	/**
	 * Deleting a contact person disables, never deletes, its user; the
	 * username comes from email, then e-mailadres, then username.
	 *
	 * @return void
	 */
	public function testContactDeletionDisablesTheUser(): void {
		$byEmail    = $this->addUser('a@example.nl');
		$byMailadres = $this->addUser('b@example.nl');
		$byUsername = $this->addUser('c-user');
		$byEmail->expects($this->once())->method('setEnabled')->with(false);
		$byMailadres->expects($this->once())->method('setEnabled')->with(false);
		$byUsername->expects($this->once())->method('setEnabled')->with(false);

		$service = $this->service();
		$service->handleContactDeletion($this->contactPerson(1, ['email' => 'a@example.nl', 'username' => 'c-user']));
		$service->handleContactDeletion($this->contactPerson(2, ['e-mailadres' => 'b@example.nl']));
		$service->handleContactDeletion($this->contactPerson(3, ['username' => 'c-user']));

		$this->assertCount(3, array_keys($this->messages('info'), 'ContactpersoonService: Disabled user for deleted contact', true));
		$this->assertSame([], $this->messages('warning'));
	}//end testContactDeletionDisablesTheUser()

	/**
	 * A deleted contact person without a username, or whose user is gone,
	 * only logs a warning.
	 *
	 * @return void
	 */
	public function testContactDeletionWithoutAUserOnlyWarns(): void {
		$service = $this->service();
		$service->handleContactDeletion($this->contactPerson(1, []));
		$service->handleContactDeletion($this->contactPerson(2, ['email' => 'gone@example.nl']));

		$this->assertSame(
			[
				'ContactpersoonService: Contact deletion - no username found',
				'ContactpersoonService: User not found for deleted contact',
			],
			$this->messages('warning')
		);
		$this->assertSame(['contactId' => 2, 'username' => 'gone@example.nl'], $this->logs[2][2]);
	}//end testContactDeletionWithoutAUserOnlyWarns()

	/**
	 * A failure while disabling is logged, not thrown.
	 *
	 * @return void
	 */
	public function testContactDeletionLogsAFailure(): void {
		$user = $this->addUser('a@example.nl');
		$user->method('setEnabled')->willThrowException(new \RuntimeException('backend down'));

		$this->service()->handleContactDeletion($this->contactPerson(7, ['email' => 'a@example.nl']));

		$this->assertSame(['ContactpersoonService: Failed to handle contact deletion'], $this->messages('error'));
		$this->assertSame(['contactId' => 7, 'error' => 'backend down'], end($this->logs)[2]);
	}//end testContactDeletionLogsAFailure()

	/**
	 * Enabling and disabling find the contact person in the stackiq register
	 * without RBAC and switch its user.
	 *
	 * @return void
	 */
	public function testEnableAndDisableSwitchTheUser(): void {
		$user = $this->addUser('anna@example.nl');
		$this->objectService->expects($this->exactly(2))
			->method('find')
			->with('cp-1', [], false, 'stackiq', 'contactPerson', false, false)
			->willReturn($this->entity(['username' => 'anna@example.nl']));
		$switched = [];
		$user->method('setEnabled')->willReturnCallback(
			function (bool $enabled) use (&$switched): void {
				$switched[] = $enabled;
			}
		);

		$service = $this->service();
		$service->enableUserForContactpersoon('cp-1');
		$service->disableUserForContactpersoon('cp-1');

		$this->assertSame([true, false], $switched);
		$this->assertContains('ContactpersoonService: User enabled successfully', $this->messages('info'));
		$this->assertContains('ContactpersoonService: User disabled successfully', $this->messages('info'));
	}//end testEnableAndDisableSwitchTheUser()

	/**
	 * Each missing piece stops enabling with its own message.
	 *
	 * @return void
	 */
	public function testEnableRefusesWhatItCannotResolve(): void {
		$this->objectService->method('find')->willReturnCallback(
			fn (string $id): ?ObjectEntityInterface => match ($id) {
				'missing' => null,
				'no-username' => $this->entity(['email' => 'x@example.nl']),
				'unknown-user' => $this->entity(['username' => 'ghost@example.nl']),
			}
		);
		$service = $this->service();

		$failures = [];
		foreach (['missing', 'no-username', 'unknown-user'] as $id) {
			try {
				$service->enableUserForContactpersoon($id);
				$failures[] = 'no exception';
			} catch (\Exception $e) {
				$failures[] = $e->getMessage();
			}
		}

		$this->assertSame(['Contactpersoon not found', 'No username found for contactpersoon', 'User not found in Nextcloud'], $failures);
		$this->assertCount(3, $this->messages('error'));
	}//end testEnableRefusesWhatItCannotResolve()

	/**
	 * Without OpenRegister nothing can be disabled.
	 *
	 * @return void
	 */
	public function testDisableWithoutOpenRegisterThrows(): void {
		$this->openRegisterEnabled = false;
		$this->objectService->expects($this->never())->method('find');

		$this->expectExceptionMessage('ObjectService not available');
		try {
			$this->service()->disableUserForContactpersoon('cp-1');
		} finally {
			$this->assertSame(['ContactpersoonService: Failed to disable user for contactpersoon'], $this->messages('error'));
		}
	}//end testDisableWithoutOpenRegisterThrows()

	/**
	 * Disabling a contact person without a username throws.
	 *
	 * @return void
	 */
	public function testDisableWithoutUsernameThrows(): void {
		$this->objectService->method('find')->willReturn($this->entity(['username' => '']));

		$this->expectExceptionMessage('No username found for contactpersoon');
		$this->service()->disableUserForContactpersoon('cp-2');
	}//end testDisableWithoutUsernameThrows()

	/**
	 * Bulk user info reads each contact person from the configured register
	 * and reports its user, groups, state and last login.
	 *
	 * @return void
	 */
	public function testBulkUserInfoReportsEachContactPerson(): void {
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => '4', 'contactpersoon_schema' => '9']);
		$anna = $this->addUser('anna@example.nl', false);
		$group = $this->createMock(IGroup::class);
		$this->groupManager->method('getUserGroups')->with($anna)->willReturn(['stackiq-beheerder' => $group, 'admin' => $group]);

		$found = [];
		$this->objectService->method('findSilent')->willReturnCallback(
			function (string|int $id, array $_extend, bool $files, $register, $schema) use (&$found): ObjectEntityInterface {
				$found[] = [$id, $register, $schema];
				return match ($id) {
					'with-user' => $this->entity(['username' => 'anna@example.nl']),
					'no-user' => $this->entity([]),
					'gone-user' => $this->entity(['username' => 'ghost@example.nl']),
					'deleted' => throw new DoesNotExistException('gone'),
					'broken' => throw new \RuntimeException('read failed'),
				};
			}
		);

		$info = $this->service()->getBulkUserInfo(['with-user', 'no-user', 'gone-user', 'deleted', 'broken']);

		$this->assertSame([['with-user', 4, 9], ['no-user', 4, 9], ['gone-user', 4, 9], ['deleted', 4, 9], ['broken', 4, 9]], $found);
		$this->assertSame(
			[
				'with-user' => [
					'hasUser' => true,
					'username' => 'anna@example.nl',
					'groups' => ['stackiq-beheerder', 'admin'],
					'enabled' => false,
					'displayName' => 'Display anna@example.nl',
					'lastLogin' => 1700000000,
				],
				'no-user' => ['hasUser' => false, 'username' => null, 'groups' => []],
				'gone-user' => ['hasUser' => true, 'username' => 'ghost@example.nl', 'groups' => []],
				'deleted' => ['hasUser' => false, 'username' => null, 'groups' => []],
				'broken' => ['hasUser' => false, 'username' => null, 'groups' => [], 'error' => 'read failed'],
			],
			$info
		);
		$summary = end($this->logs);
		$this->assertSame('ContactpersoonService: Successfully retrieved bulk user info', $summary[1]);
		$this->assertSame(['totalContactpersonen' => 5, 'successfulRetrievals' => 4], $summary[2]);
	}//end testBulkUserInfoReportsEachContactPerson()

	/**
	 * Without a readable config the bulk lookup falls back to register 2,
	 * schema 25.
	 *
	 * @return void
	 */
	public function testBulkUserInfoFallsBackToDefaultRegisterAndSchema(): void {
		$this->settings->method('getVoorzieningenConfig')->willThrowException(new \RuntimeException('no config'));
		$this->objectService->expects($this->once())
			->method('findSilent')
			->with('cp-1', [], false, 2, 25)
			->willReturn($this->entity([]));

		$info = $this->service()->getBulkUserInfo(['cp-1']);

		$this->assertSame(['cp-1' => ['hasUser' => false, 'username' => null, 'groups' => []]], $info);
		$this->assertContains('Could not get contact person schema config, using defaults', $this->messages('warning'));
	}//end testBulkUserInfoFallsBackToDefaultRegisterAndSchema()

	/**
	 * Without OpenRegister every contact person is skipped.
	 *
	 * @return void
	 */
	public function testBulkUserInfoWithoutOpenRegisterSkipsEveryone(): void {
		$this->openRegisterEnabled = false;
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);

		$this->assertSame([], $this->service()->getBulkUserInfo(['a', 'b']));
		$this->assertCount(2, array_keys($this->messages('warning'), 'ContactpersoonService: ObjectService not available for bulk user info', true));
	}//end testBulkUserInfoWithoutOpenRegisterSkipsEveryone()

	/**
	 * An update whose roles changed refreshes the user's groups, and an
	 * update without an old object or with the same roles does not.
	 *
	 * @return void
	 */
	public function testContactUpdateRefreshesGroupsOnlyWhenRolesChange(): void {
		$user = $this->addUser('anna');
		$this->contactPersonHandler->expects($this->once())
			->method('updateUserGroupsFromContactData')
			->with($user, ['username' => 'anna', 'roles' => ['beheerder']]);

		$service = $this->service();
		$new     = $this->contactPerson(5, ['username' => 'anna', 'roles' => ['beheerder']]);
		$service->handleContactpersoonUpdate($new, $this->contactPerson(5, ['username' => 'anna', 'roles' => []]));
		$service->handleContactpersoonUpdate($new, $this->contactPerson(5, ['username' => 'anna', 'roles' => ['beheerder']]));
		$service->handleContactpersoonUpdate($new);

		$this->assertCount(3, array_keys($this->messages('info'), 'ContactpersoonService: Successfully handled contactpersoon update', true));
		$this->assertCount(1, array_keys($this->messages('info'), 'ContactpersoonService: Roles changed, updating user groups', true));
	}//end testContactUpdateRefreshesGroupsOnlyWhenRolesChange()

	/**
	 * A failure during an update is logged, not thrown.
	 *
	 * @return void
	 */
	public function testContactUpdateLogsAFailure(): void {
		$this->addUser('anna');
		$this->contactPersonHandler->method('updateUserGroupsFromContactData')->willThrowException(new \RuntimeException('group backend down'));

		$this->service()->handleContactpersoonUpdate(
			$this->contactPerson(6, ['username' => 'anna', 'roles' => ['x']]),
			$this->contactPerson(6, ['username' => 'anna'])
		);

		$this->assertSame(['ContactpersoonService: Failed to handle contactpersoon update'], $this->messages('error'));
		$this->assertSame(['contactId' => 6, 'error' => 'group backend down'], end($this->logs)[2]);
	}//end testContactUpdateLogsAFailure()
}//end class
