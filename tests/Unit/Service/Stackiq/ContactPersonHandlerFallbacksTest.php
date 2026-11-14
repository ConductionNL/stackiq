<?php

/**
 * The fallbacks of a contact person's user account: the username strategies
 * after the e-mail address, and the failures a contact deletion survives.
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
 * @covers \OCA\Stackiq\Service\Stackiq\ContactPersonHandler
 */
class ContactPersonHandlerFallbacksTest extends TestCase {

	private IUserManager&MockObject $users;

	private LoggerInterface&MockObject $logger;

	private ContactPersonHandler $handler;

	/**
	 * The handler over mocked Nextcloud services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new ContactPersonHandler(
			_userManager: $this->users,
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $this->createMock(ContainerInterface::class),
			_appManager: $this->createMock(IAppManager::class),
			_logger: $this->logger,
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->createMock(IConfig::class),
		);
	}//end setUp()

	/**
	 * An e-mail address whose username would not start with a letter or digit
	 * gives way to firstname.lastname.
	 *
	 * @return void
	 */
	public function testAnEmailThatCannotBeAUsernameFallsBackToTheName(): void {
		self::assertSame(
			'anna.dijk',
			$this->handler->generateUsernameFromContactData(
				['email' => '.anna@gemeente.nl', 'voornaam' => 'Anna', 'achternaam' => 'Dijk']
			)
		);
	}//end testAnEmailThatCannotBeAUsernameFallsBackToTheName()

	/**
	 * When firstname.lastname is one character over the limit, the name without the dot is used.
	 *
	 * @return void
	 */
	public function testALongNameDropsTheDot(): void {
		$first = str_repeat('a', 32);
		$last = str_repeat('b', 32);

		self::assertSame(
			$first . $last,
			$this->handler->generateUsernameFromContactData(['voornaam' => $first, 'achternaam' => $last])
		);
	}//end testALongNameDropsTheDot()

	/**
	 * A deleted contact whose data cannot be read is logged with its id, and no account is touched.
	 *
	 * @return void
	 */
	public function testADeletionWhoseDataCannotBeReadIsLogged(): void {
		$contact = new class {
			/**
			 * The id.
			 *
			 * @return int The id.
			 */
			public function getId(): int {
				return 12;
			}

			/**
			 * The data, which cannot be read.
			 *
			 * @return array Never.
			 */
			public function getObject(): array {
				throw new RuntimeException('object store offline');
			}
		};
		$this->users->expects(self::never())->method('get');
		$this->logger->expects(self::once())->method('error')->with(
			'Failed to handle contact deletion: object store offline',
			self::callback(static fn (array $context): bool => $context['objectId'] === 12 && $context['exception'] instanceof RuntimeException)
		);

		$this->handler->handleContactDeletion($contact);
	}//end testADeletionWhoseDataCannotBeReadIsLogged()

	/**
	 * The account of a deleted contact is disabled even when its suspension notice
	 * cannot be prepared; that failure is logged with the account.
	 *
	 * @return void
	 */
	public function testAFailingSuspensionNoticeStillDisablesTheAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('piet');
		$user->method('getEMailAddress')->willReturn('piet@gemeente.nl');
		$user->method('getDisplayName')->willThrowException(new RuntimeException('backend gone'));
		$user->expects(self::once())->method('setEnabled')->with(false);
		$this->users->method('get')->with('piet')->willReturn($user);
		$contact = new class {
			/**
			 * The id.
			 *
			 * @return int The id.
			 */
			public function getId(): int {
				return 7;
			}

			/**
			 * The data.
			 *
			 * @return array The data.
			 */
			public function getObject(): array {
				return ['username' => 'piet'];
			}
		};
		$this->logger->expects(self::once())->method('error')->with(
			'Exception sending account suspension email: backend gone',
			self::callback(
				static fn (array $context): bool => $context['username'] === 'piet'
					&& $context['email'] === 'piet@gemeente.nl'
					&& $context['exception'] instanceof RuntimeException
			)
		);

		$this->handler->handleContactDeletion($contact);
	}//end testAFailingSuspensionNoticeStillDisablesTheAccount()
}//end class
