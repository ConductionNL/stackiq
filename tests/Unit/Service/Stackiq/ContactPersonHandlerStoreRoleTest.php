<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
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

/**
 * Covers the role branch of ContactPersonHandler::storeContactNameFields that
 * the profile suite does not reach: when the account has no role property yet,
 * the handler creates one (local scope, not verified) and saves the account
 * once; it also only writes the name fields that are present.
 *
 * @spec openspec/specs/user-provisioning/spec.md
 */
class ContactPersonHandlerStoreRoleTest extends TestCase {
	private IConfig&MockObject $config;

	private ContainerInterface&MockObject $container;

	private ContactPersonHandler $handler;

	protected function setUp(): void {
		$this->config    = $this->createMock(IConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$this->handler = new ContactPersonHandler(
			_userManager: $this->createMock(IUserManager::class),
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $this->container,
			_appManager: $this->createMock(IAppManager::class),
			_logger: $this->createMock(LoggerInterface::class),
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->config,
		);
	}

	private function user(string $uid, ?string $email = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}

	public function testOnlyThePresentNameFieldsAreWrittenAndNoRoleMeansNoAccountLookup(): void {
		$user = $this->user(uid: 'kees', email: null);

		$writes = [];
		$this->config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $value) use (&$writes): void {
				$writes[] = [$uid, $app, $key, $value];
			}
		);
		// With no role, the AccountManager is never resolved from the container.
		$this->container->expects(self::never())->method('get');
		// A new email on a user without one is synced.
		$user->expects(self::once())->method('setEMailAddress')->with('kees@gemeente.nl');

		$this->handler->storeContactNameFields(
			$user,
			['voornaam' => 'Kees', 'email' => 'kees@gemeente.nl'],
		);

		self::assertSame([['kees', 'core', 'firstName', 'Kees']], $writes);
	}

	public function testAnEmailTooLongForAUsernameFallsBackToTheNameCandidate(): void {
		// The sanitised local part is 70 chars, so the email strategy is rejected
		// on length and the firstname.lastname candidate wins instead.
		$longLocal = str_repeat('a', 70);
		$username  = $this->handler->generateUsernameFromContactData([
			'email'      => $longLocal . '@example.org',
			'voornaam'   => 'Jan',
			'achternaam' => 'Bakker',
		]);

		self::assertSame('jan.bakker', $username);
	}

	public function testAnEmailThatCannotStartAUsernameFallsBackToTheNameCandidate(): void {
		// A leading underscore survives sanitisation but is not an allowed first
		// character, so the email strategy is rejected and the name wins.
		$username = $this->handler->generateUsernameFromContactData([
			'email'      => '_tag@example.org',
			'voornaam'   => 'Eva',
			'achternaam' => 'de Wit',
		]);

		self::assertSame('eva.dewit', $username);
	}
}
