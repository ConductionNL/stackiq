<?php

/**
 * A contact person's user account: its username, its manager and whether it is enabled.
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
 * Username generation and validation, the manager preference, enabling and
 * disabling the account, and what deleting the contact does to it.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerAccountsTest extends TestCase {

	private IUserManager&MockObject $users;

	private IConfig&MockObject $config;

	private LoggerInterface&MockObject $logger;

	/**
	 * The handler over mocked Nextcloud services.
	 *
	 * @return ContactPersonHandler
	 */
	private function handler(): ContactPersonHandler {
		$this->users  = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new ContactPersonHandler(
			_userManager: $this->users,
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $this->createMock(ContainerInterface::class),
			_appManager: $this->createMock(IAppManager::class),
			_logger: $this->logger,
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->config,
		);
	}//end handler()

	/**
	 * A contact object as OpenRegister hands it over.
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
			public function __construct(private array $data) {
			}

			public function getId(): int {
				return 7;
			}

			public function getObject(): array {
				return $this->data;
			}
		};
	}//end contact()

	public function testTheEmailWithoutItsSubaddressIsTheUsername(): void {
		$handler = $this->handler();

		$this->assertSame('anna@gemeente.nl', $handler->generateUsernameFromContactData(['email' => ' Anna+crm@Gemeente.NL ', 'voornaam' => 'Anna', 'achternaam' => 'Bakker']));
		$this->assertSame('piet@gemeente.nl', $handler->generateUsernameFromContactData(['e-mailadres' => 'piet@gemeente.nl']));
	}//end testTheEmailWithoutItsSubaddressIsTheUsername()

	public function testAnUnusableEmailFallsBackToFirstDotLastName(): void {
		$handler = $this->handler();

		$this->assertSame(
			'jan.devriesbakker',
			$handler->generateUsernameFromContactData(['email' => '-jan@gemeente.nl', 'voornaam' => 'Jan', 'tussenvoegsel' => 'de', 'achternaam' => 'de Vries-Bakker'])
		);
		$this->assertSame('jan.bakker', $handler->generateUsernameFromContactData(['email' => 'no-at-sign', 'voornaam' => 'Jan', 'achternaam' => 'Bakker']));
	}//end testAnUnusableEmailFallsBackToFirstDotLastName()

	public function testATooLongDottedNameDropsTheDot(): void {
		$first = str_repeat('a', 30);
		$last  = str_repeat('b', 34);

		$this->assertSame($first.$last, $this->handler()->generateUsernameFromContactData(['voornaam' => $first, 'achternaam' => $last]));
	}//end testATooLongDottedNameDropsTheDot()

	public function testWithoutEmailOrFullNameAUserNumberIsMade(): void {
		$handler = $this->handler();
		$this->logger->expects($this->never())->method('error');

		$this->assertMatchesRegularExpression('/^user\d+$/', $handler->generateUsernameFromContactData([]));
		$this->assertMatchesRegularExpression('/^user\d+$/', $handler->generateUsernameFromContactData(['voornaam' => 'Anna']));
		$this->assertMatchesRegularExpression('/^user\d+$/', $handler->generateUsernameFromContactData(['voornaam' => 'Ö', 'achternaam' => 'Ü']));
	}//end testWithoutEmailOrFullNameAUserNumberIsMade()

	public function testSanitisingKeepsOnlyUsernameCharacters(): void {
		$handler = $this->handler();

		$this->assertSame('nnabakker@x.nl', $handler->sanitizeEmailForUsername('Ánna Bakker+tag+more@X.nl'));
		$this->assertSame('ab', $handler->sanitizeEmailForUsername('a+b'));
		$this->assertSame('a_b-c.d@e.nl', $handler->sanitizeEmailForUsername('a_b-c.d@e.nl'));
	}//end testSanitisingKeepsOnlyUsernameCharacters()

	public function testAnEmailThatCannotBeAUsernameIsExplained(): void {
		$handler = $this->handler();
		$long    = str_repeat('a', 60).'@gemeente.nl';

		$this->assertSame('No email address found on the contact person.', $handler->validateEmailForUsername(''));
		$this->assertSame('The email address "anna" is not a valid email address (missing @).', $handler->validateEmailForUsername('anna'));
		$this->assertSame('The email address "é@" is too short to be used as a username (minimum 3 characters).', $handler->validateEmailForUsername('é@'));
		$this->assertSame('The email address "'.$long.'" is too long to be used as a username (maximum 64 characters).', $handler->validateEmailForUsername($long));
		$this->assertNull($handler->validateEmailForUsername('Anna+crm@Gemeente.nl'));
	}//end testAnEmailThatCannotBeAUsernameIsExplained()

	public function testTheManagerIsStoredAsAPreferenceWhenBothUsersExist(): void {
		$handler = $this->handler();
		$this->users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->createMock(IUser::class));
		$this->config->expects($this->once())->method('setUserValue')->with('anna', 'stackiq', 'manager', 'piet');

		$handler->setUserManager('anna', 'piet');
	}//end testTheManagerIsStoredAsAPreferenceWhenBothUsersExist()

	public function testAMissingManagerIsNotStored(): void {
		$handler = $this->handler();
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'anna' ? $this->createMock(IUser::class) : null));
		$this->config->expects($this->never())->method('setUserValue');
		$this->logger->expects($this->once())->method('warning')->with(
			'Cannot set manager - user or manager not found',
			['username' => 'anna', 'manager' => 'ghost', 'userExists' => true, 'managerExists' => false]
		);

		$handler->setUserManager('anna', 'ghost');
	}//end testAMissingManagerIsNotStored()

	public function testAFailingStoreIsLoggedNotThrown(): void {
		$handler = $this->handler();
		$this->users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->createMock(IUser::class));
		$this->config->method('setUserValue')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('Failed to set user manager: db down');

		$handler->setUserManager('anna', 'piet');
	}//end testAFailingStoreIsLoggedNotThrown()

	public function testTheManagerIsReadBackOrNull(): void {
		$handler = $this->handler();
		$this->config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $default): string {
				if ($uid === 'broken') {
					throw new RuntimeException('db down');
				}

				return ($uid === 'anna' && $app === 'stackiq' && $key === 'manager' ? 'piet' : $default);
			}
		);

		$this->assertSame('piet', $handler->getUserManager('anna'));
		$this->assertNull($handler->getUserManager('kees'));
		$this->assertNull($handler->getUserManager('broken'));
	}//end testTheManagerIsReadBackOrNull()

	public function testAccountsAreEnabledAndDisabledWhenTheyExist(): void {
		$handler = $this->handler();
		$anna    = $this->createMock(IUser::class);
		$anna->expects($this->exactly(2))->method('setEnabled')->willReturnCallback(
			static function (bool $enabled): void {
				static $expected = [false, true];
				TestCase::assertSame(array_shift($expected), $enabled);
			}
		);
		$this->users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'anna' ? $anna : null));

		$this->assertTrue($handler->setUserInactive('anna'));
		$this->assertTrue($handler->setUserActive('anna'));
		$this->assertFalse($handler->setUserInactive('ghost'));
		$this->assertFalse($handler->setUserActive('ghost'));
	}//end testAccountsAreEnabledAndDisabledWhenTheyExist()

	public function testAFailingSwitchAnswersFalse(): void {
		$handler = $this->handler();
		$this->users->method('get')->willThrowException(new RuntimeException('ldap down'));

		$this->assertFalse($handler->setUserInactive('anna'));
		$this->assertFalse($handler->setUserActive('anna'));
	}//end testAFailingSwitchAnswersFalse()

	public function testDeletingAContactDisablesItsAccount(): void {
		$handler = $this->handler();
		$anna    = $this->createMock(IUser::class);
		$anna->method('getUID')->willReturn('anna');
		$anna->expects($this->once())->method('setEnabled')->with(false);
		$anna->expects($this->never())->method('delete');
		$this->users->expects($this->once())->method('get')->with('anna')->willReturn($anna);

		$handler->handleContactDeletion(self::contact(['username' => 'anna']));
	}//end testDeletingAContactDisablesItsAccount()

	public function testDeletingAContactWithoutAnAccountTouchesNoUser(): void {
		$handler = $this->handler();
		$this->users->expects($this->never())->method('get');

		$handler->handleContactDeletion(self::contact(['username' => '']));
		$handler->handleContactDeletion(self::contact([]));
	}//end testDeletingAContactWithoutAnAccountTouchesNoUser()

	public function testDeletingAContactWhoseAccountIsGoneDoesNothing(): void {
		$handler = $this->handler();
		$this->users->method('get')->willReturn(null);
		$this->logger->expects($this->never())->method('error');

		$handler->handleContactDeletion(self::contact(['username' => 'ghost']));
	}//end testDeletingAContactWhoseAccountIsGoneDoesNothing()
}//end class
