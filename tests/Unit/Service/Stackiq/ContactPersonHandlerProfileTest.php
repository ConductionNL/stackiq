<?php

/**
 * What a contact person's data writes onto its user account: the name parts,
 * the role (in the account manager, else the user config), the e-mail address,
 * and the maintainer role of an organisation's first contact.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\PropertyDoesNotExistException;
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
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerProfileTest extends TestCase {

	private IUserManager&MockObject $users;

	private IGroupManager&MockObject $groups;

	private ContainerInterface&MockObject $container;

	private IConfig&MockObject $config;

	private LoggerInterface&MockObject $logger;

	private ContactPersonHandler $handler;

	/**
	 * The handler over mocked Nextcloud services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new ContactPersonHandler(
			_userManager: $this->users,
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->groups,
			_config: $this->createMock(IAppConfig::class),
			_container: $this->container,
			_appManager: $this->createMock(IAppManager::class),
			_logger: $this->logger,
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->config,
		);
	}//end setUp()

	/**
	 * A user double.
	 *
	 * @param string      $uid   The uid.
	 * @param string|null $email The current e-mail address.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid, ?string $email = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}//end user()

	/**
	 * The name parts land in the user config, the role in the account manager, the new e-mail on the user.
	 *
	 * @return void
	 */
	public function testNameRoleAndEmailAreStoredOnTheAccount(): void {
		$user = $this->user(uid: 'anna@gemeente.nl', email: 'oud@gemeente.nl');
		$writes = [];
		$this->config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$writes): void {
				$writes[] = [$uid, $app, $key, $value];
			}
		);
		$property = $this->createMock(IAccountProperty::class);
		$property->expects(self::once())->method('setValue')->with('Informatiemanager');
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->with(IAccountManager::PROPERTY_ROLE)->willReturn($property);
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->with($user)->willReturn($account);
		$accounts->expects(self::once())->method('updateAccount')->with($account);
		$this->container->method('get')->with('OCP\Accounts\IAccountManager')->willReturn($accounts);
		$user->expects(self::once())->method('setEMailAddress')->with('anna@gemeente.nl');

		$this->handler->storeContactNameFields(
			$user,
			['voornaam' => 'Anna', 'tussenvoegsel' => 'van', 'achternaam' => 'Dijk', 'role' => 'Informatiemanager', 'e-mailadres' => 'anna@gemeente.nl']
		);

		self::assertSame(
			[
				['anna@gemeente.nl', 'core', 'firstName', 'Anna'],
				['anna@gemeente.nl', 'core', 'lastName', 'Dijk'],
				['anna@gemeente.nl', 'core', 'middleName', 'van'],
			],
			$writes
		);
	}//end testNameRoleAndEmailAreStoredOnTheAccount()

	/**
	 * A role the account manager cannot store is kept in the user config instead, with a warning.
	 *
	 * @return void
	 */
	public function testARoleTheAccountManagerRefusesFallsBackToTheUserConfig(): void {
		$user = $this->user(uid: 'piet', email: 'piet@gemeente.nl');
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willThrowException(new PropertyDoesNotExistException('role'));
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->willReturn($account);
		$accounts->expects(self::never())->method('updateAccount');
		$this->container->method('get')->willReturn($accounts);
		$this->config->expects(self::once())->method('setUserValue')->with('piet', 'core', 'role', 'Beheerder');
		$this->logger->expects(self::once())->method('warning')->with('Failed to store functie in AccountManager, stored in user config', self::anything());
		$user->expects(self::never())->method('setEMailAddress');

		$this->handler->storeContactNameFields($user, ['role' => 'Beheerder', 'email' => 'piet@gemeente.nl']);
	}//end testARoleTheAccountManagerRefusesFallsBackToTheUserConfig()

	/**
	 * Empty contact data writes nothing, and a failure on the user is logged, not thrown.
	 *
	 * @return void
	 */
	public function testEmptyDataWritesNothingAndAFailureIsLogged(): void {
		$this->config->expects(self::never())->method('setUserValue');
		$this->container->expects(self::never())->method('get');
		$this->handler->storeContactNameFields($this->user(uid: 'leeg'), []);

		$broken = $this->user(uid: 'kapot');
		$broken->method('setEMailAddress')->willThrowException(new RuntimeException('read-only backend'));
		$this->logger->expects(self::once())->method('error')->with('Failed to store contact name fields', ['userId' => 'kapot', 'error' => 'read-only backend']);
		$this->handler->storeContactNameFields($broken, ['email' => 'nieuw@gemeente.nl']);
	}//end testEmptyDataWritesNothingAndAFailureIsLogged()

	/**
	 * A contact person object double that records the data written back.
	 *
	 * @param array<string, mixed> $data The contact data.
	 *
	 * @return object
	 */
	private static function contact(array $data): object {
		return new class($data) {
			/**
			 * @param array<string, mixed> $data The contact data.
			 */
			public function __construct(public array $data) {
			}

			public function getId(): int {
				return 7;
			}

			public function getObject(): array {
				return $this->data;
			}

			public function setObject(array $data): void {
				$this->data = $data;
			}
		};
	}//end contact()

	/**
	 * The first contact gets the maintainer role, and the group is created when missing.
	 *
	 * @return void
	 */
	public function testTheFirstContactBecomesMaintainer(): void {
		$contact = self::contact(['roles' => ['Gebruiker']]);
		$user = $this->user(uid: 'anna');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects(self::once())->method('addUser')->with($user);
		$this->groups->method('get')->with('maintainer')->willReturn(null);
		$this->groups->expects(self::once())->method('createGroup')->with('maintainer')->willReturn($group);
		$this->users->method('get')->with('anna')->willReturn($user);

		$this->handler->assignBeheerderRole($contact, 'anna', 'org-1');

		self::assertSame(['Gebruiker', 'maintainer'], $contact->data['roles']);
	}//end testTheFirstContactBecomesMaintainer()

	/**
	 * A contact that already is maintainer, in any case, is left alone; roles that are no list start empty.
	 *
	 * @return void
	 */
	public function testAnExistingMaintainerIsLeftAloneAndBadRolesStartEmpty(): void {
		// Only the second contact reaches the group; the existing maintainer never does.
		$this->groups->expects(self::once())->method('get')->with('maintainer')->willReturn(null);
		$this->groups->expects(self::once())->method('createGroup')->with('maintainer')->willReturn(null);
		$this->users->expects(self::never())->method('get');
		$existing = self::contact(['roles' => ['MAINTAINER']]);

		$this->handler->assignBeheerderRole($existing, 'anna', 'org-1');
		self::assertSame(['MAINTAINER'], $existing->data['roles']);

		$broken = self::contact(['roles' => 'maintainer']);
		$this->handler->assignBeheerderRole($broken, 'piet', 'org-1');
		self::assertSame(['maintainer'], $broken->data['roles']);
	}//end testAnExistingMaintainerIsLeftAloneAndBadRolesStartEmpty()

	/**
	 * A user already in the maintainer group is not added again; a failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAMemberIsNotAddedTwiceAndAFailureIsLogged(): void {
		$user = $this->user(uid: 'anna');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$group->expects(self::never())->method('addUser');
		$this->groups->method('get')->willReturn($group);
		$this->users->method('get')->willReturnCallback(
			static function (string $uid) use ($user): IUser {
				if ($uid === 'anna') {
					return $user;
				}

				throw new RuntimeException('user backend down');
			}
		);

		$this->handler->assignBeheerderRole(self::contact([]), 'anna', 'org-1');

		$this->logger->expects(self::once())->method('error')->with('Failed to assign beheerder role: user backend down', self::anything());
		$this->handler->assignBeheerderRole(self::contact([]), 'piet', 'org-2');
	}//end testAMemberIsNotAddedTwiceAndAFailureIsLogged()
}//end class
