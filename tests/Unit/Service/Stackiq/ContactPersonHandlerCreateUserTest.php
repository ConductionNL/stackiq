<?php

/**
 * Creating, or reusing, the user account of a contact person.
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
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
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
 * createUserAccount() without an organisation: refused without an email,
 * reuses an account found by email or username, otherwise creates one with
 * the contact's details, writes the username back and sends the mail.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerCreateUserTest extends TestCase {

	private IUserManager&MockObject $users;

	private IConfig&MockObject $config;

	private ISecureRandom&MockObject $random;

	private SymfonyEmailService&MockObject $mail;

	private ContainerInterface&MockObject $container;

	/**
	 * Every user preference written, as `uid/app/key` => value.
	 *
	 * @var array<string, string>
	 */
	private array $preferences = [];

	/**
	 * The handler over mocked Nextcloud services.
	 *
	 * @return ContactPersonHandler
	 */
	private function handler(): ContactPersonHandler {
		$this->users     = $this->createMock(IUserManager::class);
		$this->config    = $this->createMock(IConfig::class);
		$this->random    = $this->createMock(ISecureRandom::class);
		$this->mail      = $this->createMock(SymfonyEmailService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $value): void {
				$this->preferences[$uid.'/'.$app.'/'.$key] = (string)$value;
			}
		);

		return new ContactPersonHandler(
			_userManager: $this->users,
			_secureRandom: $this->random,
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $this->container,
			_appManager: $this->createMock(IAppManager::class),
			_logger: $this->createMock(LoggerInterface::class),
			_emailService: $this->mail,
			config: $this->config,
		);
	}//end handler()

	/**
	 * A contact object that records what is written back to it.
	 *
	 * @param array<string, mixed> $data The contact data.
	 *
	 * @return object
	 */
	private static function contact(array $data): object {
		return new class($data) {
			public ?array $written = null;

			/**
			 * @param array<string, mixed> $data The contact data.
			 */
			public function __construct(private array $data) {
			}

			public function getId(): int {
				return 12;
			}

			public function getObject(): array {
				return $this->data;
			}

			public function setObject(array $data): void {
				$this->written = $data;
			}
		};
	}//end contact()

	/**
	 * A user with this uid and email.
	 *
	 * @param string $uid   The uid.
	 * @param string $email The email address.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid, string $email = ''): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn('Anna van Bakker');
		return $user;
	}//end user()

	public function testAContactWithoutEmailGetsNoAccount(): void {
		$handler = $this->handler();
		$this->users->expects($this->never())->method('createUser');
		$this->users->expects($this->never())->method('userExists');

		$this->assertNull($handler->createUserAccount(self::contact(['voornaam' => 'Anna'])));
	}//end testAContactWithoutEmailGetsNoAccount()

	public function testAnAccountNamedByTheEmailIsReusedAndUpdated(): void {
		$handler = $this->handler();
		$anna    = $this->user(uid: 'anna@gemeente.nl', email: 'anna@gemeente.nl');
		$this->users->method('userExists')->with('anna@gemeente.nl')->willReturn(true);
		$this->users->method('get')->with('anna@gemeente.nl')->willReturn($anna);
		$this->users->expects($this->never())->method('createUser');
		$this->mail->expects($this->never())->method('sendUserCreationEmail');

		$contact = self::contact(['email' => 'anna@gemeente.nl', 'voornaam' => 'Anna', 'achternaam' => 'Bakker']);

		$this->assertSame($anna, $handler->createUserAccount($contact));
		$this->assertSame(['anna@gemeente.nl/core/firstName' => 'Anna', 'anna@gemeente.nl/core/lastName' => 'Bakker'], $this->preferences);
		$this->assertNull($contact->written);
	}//end testAnAccountNamedByTheEmailIsReusedAndUpdated()

	public function testAnAccountFoundByUsernameIsReusedAndTakesTheContactsEmail(): void {
		$handler = $this->handler();
		$anna    = $this->user(uid: 'anna', email: 'old@gemeente.nl');
		$anna->expects($this->once())->method('setEMailAddress')->with('anna@gemeente.nl');
		$this->users->method('userExists')->willReturn(false);
		$this->users->method('get')->with('anna')->willReturn($anna);
		$this->users->expects($this->never())->method('createUser');

		$this->assertSame($anna, $handler->createUserAccount(self::contact(['email' => 'anna@gemeente.nl', 'username' => 'anna', 'tussenvoegsel' => 'van'])));
		$this->assertSame(['anna/core/middleName' => 'van'], $this->preferences);
	}//end testAnAccountFoundByUsernameIsReusedAndTakesTheContactsEmail()

	public function testANewAccountIsCreatedFromTheContact(): void {
		$handler = $this->handler();
		$this->random->method('generate')->willReturnCallback(
			static fn (int $length, string $characters): string => match ($characters) {
				ISecureRandom::CHAR_UPPER => 'ABCD',
				ISecureRandom::CHAR_LOWER => 'efgh',
				ISecureRandom::CHAR_DIGITS => '12',
				default => '!-',
			}
		);
		$anna = $this->user(uid: 'anna@gemeente.nl', email: 'anna@gemeente.nl');
		$anna->expects($this->once())->method('setDisplayName')->with('Anna van Bakker');
		$this->users->method('userExists')->willReturn(false);
		$this->users->method('get')->willReturn(null);
		$this->users->expects($this->once())->method('createUser')->with('anna@gemeente.nl', 'ABCDefgh12!-')->willReturn($anna);
		$this->mail->expects($this->once())->method('sendUserCreationEmail')->with(
			[
				'username' => 'anna@gemeente.nl',
				'email' => 'anna@gemeente.nl',
				'displayName' => 'Anna van Bakker',
				'voornaam' => 'Anna',
				'achternaam' => 'Bakker',
				'roles' => ['Ambtenaar'],
			],
			[]
		)->willReturn(true);

		$contact = self::contact(['e-mailadres' => 'Anna+crm@gemeente.nl', 'voornaam' => 'Anna', 'tussenvoegsel' => 'van', 'achternaam' => 'Bakker', 'roles' => ['Ambtenaar']]);

		$this->assertSame($anna, $handler->createUserAccount($contact, true));
		$this->assertSame('anna@gemeente.nl', $contact->written['username']);
		$this->assertSame(['Ambtenaar'], $contact->written['roles']);
		$this->assertSame(
			['anna@gemeente.nl/core/firstName' => 'Anna', 'anna@gemeente.nl/core/lastName' => 'Bakker', 'anna@gemeente.nl/core/middleName' => 'van'],
			$this->preferences
		);
	}//end testANewAccountIsCreatedFromTheContact()

	public function testAFailedCreationAnswersNull(): void {
		$handler = $this->handler();
		$this->random->method('generate')->willReturn('x');
		$this->users->method('userExists')->willReturn(false);
		$this->users->method('get')->willReturn(null);
		$this->users->method('createUser')->willReturn(null);
		$this->mail->expects($this->never())->method('sendUserCreationEmail');

		$contact = self::contact(['email' => 'anna@gemeente.nl']);

		$this->assertNull($handler->createUserAccount($contact));
		$this->assertNull($contact->written);
	}//end testAFailedCreationAnswersNull()

	public function testAThrowingUserBackendAnswersNull(): void {
		$handler = $this->handler();
		$this->users->method('userExists')->willThrowException(new RuntimeException('ldap down'));
		$this->users->expects($this->never())->method('createUser');

		$this->assertNull($handler->createUserAccount(self::contact(['email' => 'anna@gemeente.nl'])));
	}//end testAThrowingUserBackendAnswersNull()

	public function testARoleFallsBackToAPreferenceWithoutTheAccountManager(): void {
		$handler = $this->handler();
		$this->container->method('get')->willThrowException(new RuntimeException('no account manager'));

		$handler->storeContactNameFields($this->user(uid: 'anna', email: 'anna@gemeente.nl'), ['role' => 'Adviseur', 'email' => 'anna@gemeente.nl']);

		$this->assertSame(['anna/core/role' => 'Adviseur'], $this->preferences);
	}//end testARoleFallsBackToAPreferenceWithoutTheAccountManager()
}//end class
