<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Kernel\Contracts\StackiqContactSyncServiceInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonEmail;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the ContactPersonHandler paths the other suites leave open: the
 * account address taken from the linked Nextcloud contact when the
 * contact-email resolver is wired, a failure while assigning groups to an
 * existing user (logged, the user still returned).
 *
 * @spec openspec/specs/sc-handlers/spec.md
 * @spec openspec/specs/user-provisioning/spec.md
 */
#[CoversClass(ContactPersonHandler::class)]
#[UsesClass(ContactPersonEmail::class)]
class ContactPersonHandlerRemainingPathsTest extends TestCase {
	private IUserManager&MockObject $userManager;

	private IConfig&MockObject $config;

	private ContainerInterface&MockObject $container;

	private LoggerInterface&MockObject $logger;

	private ContactPersonHandler $handler;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config      = $this->createMock(IConfig::class);
		$this->container   = $this->createMock(ContainerInterface::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$this->handler = $this->handler();
	}

	private function handler(?ContactPersonEmail $contactPersonEmail = null): ContactPersonHandler {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn([]);

		return new ContactPersonHandler(
			_userManager: $this->userManager,
			_secureRandom: $this->createMock(ISecureRandom::class),
			_groupManager: $this->createMock(IGroupManager::class),
			_config: $this->createMock(IAppConfig::class),
			_container: $this->container,
			_appManager: $appManager,
			_logger: $this->logger,
			_emailService: $this->createMock(SymfonyEmailService::class),
			config: $this->config,
			contactPersonEmail: $contactPersonEmail,
		);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn('existing@gemeente.nl');
		return $user;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function contactPerson(array $data): object {
		return new class($data) {
			/**
			 * @param array<string, mixed> $data
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			public function getId(): string {
				return 'cp-1';
			}
		};
	}

	public function testAWiredResolverLooksTheUserUpByTheLinkedContactsAddress(): void {
		$contacts = $this->createMock(StackiqContactSyncServiceInterface::class);
		$contacts->expects(self::once())->method('findContactByUid')->with('uid-7')
			->willReturn(['EMAIL' => [['value' => 'anna@contacts.nl']]]);
		$handler = $this->handler(contactPersonEmail: new ContactPersonEmail(contacts: $contacts));

		$existing = $this->user(uid: 'anna');
		$this->userManager->expects(self::once())->method('userExists')->with('anna@contacts.nl')->willReturn(true);
		$this->userManager->expects(self::once())->method('get')->with('anna@contacts.nl')->willReturn($existing);

		$result = $handler->createUserAccount(
			$this->contactPerson(['email' => 'anna@record.nl', 'contactsUid' => 'uid-7', 'username' => 'anna']),
		);

		self::assertSame($existing, $result);
	}

	public function testAGroupAssignmentFailureIsLoggedAndTheExistingUserIsStillReturned(): void {
		$existing = $this->user(uid: 'bram');
		$this->userManager->method('userExists')->willReturn(true);
		$this->userManager->method('get')->willReturn($existing);

		// The organisation type cannot be read (no OpenRegister), so no role
		// mapping exists; the warning about it is where the assignment fails.
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message): void {
				if (str_starts_with($message, 'No role mapping found') === true) {
					throw new RuntimeException('log sink down');
				}
			}
		);
		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context['username'] ?? null];
			}
		);

		$result = $this->handler->createUserAccount(
			$this->contactPerson(['email' => 'bram@gemeente.nl', 'username' => 'bram', 'organisation' => 'org-1']),
		);

		self::assertSame($existing, $result);
		self::assertSame([['Failed to assign user groups: log sink down', 'bram']], $errors);
	}
}
