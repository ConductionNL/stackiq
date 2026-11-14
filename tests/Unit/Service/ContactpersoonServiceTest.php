<?php

/**
 * Unit tests for converting a contact person through ContactpersoonService.
 *
 * A contact person made with the current form links a Nextcloud contact by
 * contactsUid and carries no email address itself; the conversion reads the
 * address from the linked contact.
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

use OCA\Stackiq\Kernel\Contracts\StackiqContactSyncServiceInterface;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\Stackiq\ContactPersonEmail;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Converting a contact person reads the email address of its linked contact.
 */
#[CoversClass(ContactpersoonService::class)]
#[UsesClass(ContactPersonEmail::class)]
class ContactpersoonServiceTest extends TestCase {

	/**
	 * The uids the user manager was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The contact person handler mock.
	 *
	 * @var ContactPersonHandler&MockObject
	 */
	private ContactPersonHandler&MockObject $handler;

	/**
	 * The Nextcloud Contacts lookups.
	 *
	 * @var StackiqContactSyncServiceInterface&MockObject
	 */
	private StackiqContactSyncServiceInterface&MockObject $contacts;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handler  = $this->createMock(ContactPersonHandler::class);
		$this->contacts = $this->createMock(StackiqContactSyncServiceInterface::class);
	}//end setUp()

	/**
	 * A contact person as the event listeners pass it.
	 *
	 * @param string $id   The id.
	 * @param array  $data The object data.
	 *
	 * @return object
	 */
	private function contactPerson(string $id, array $data): object {
		return new class($id, $data) {
			/**
			 * @param string $id   The id.
			 * @param array  $data The data.
			 */
			public function __construct(private string $id, private array $data) {
			}

			/**
			 * @return string
			 */
			public function getId(): string {
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
	 * The service under test, over a user manager that knows these users.
	 *
	 * @param array<string, IUser> $users The users by uid.
	 *
	 * @return ContactpersoonService
	 */
	private function service(array $users): ContactpersoonService {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid) use ($users): ?IUser {
				$this->asked[] = $uid;
				return ($users[$uid] ?? null);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCP\IUserManager' => $userManager,
			}
		);

		return new ContactpersoonService(
			contactPersonHandler: $this->handler,
			groupHandler: $this->createMock(GroupHandler::class),
			hierarchyHandler: $this->createMock(HierarchyHandler::class),
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			config: $this->createMock(IAppConfig::class),
			settingsService: $this->createMock(RegisterConfigReaderInterface::class),
			contactPersonEmail: new ContactPersonEmail($this->contacts)
		);
	}//end service()

	/**
	 * A contact made with the current form is processed under its linked contact's address.
	 *
	 * @return void
	 */
	public function testTheLinkedContactsAddressIsTheAccount(): void {
		$this->contacts->method('findContactByUid')->with('uid-anna')
			->willReturn(['UID' => 'uid-anna', 'EMAIL' => ['anna@leverancier.nl']]);
		$anna = $this->createMock(IUser::class);
		$anna->method('getUID')->willReturn('anna@leverancier.nl');

		$this->handler->expects($this->once())->method('updateUserGroupsFromContactData')
			->with($anna, $this->anything());

		$processed = $this->service(['anna@leverancier.nl' => $anna])->processContactpersoon(
			$this->contactPerson('cp-1', ['contactsUid' => 'uid-anna', 'organisation' => 'org-1', 'username' => 'anna@leverancier.nl'])
		);

		$this->assertTrue($processed);
		$this->assertSame('anna@leverancier.nl', $this->asked[0]);
	}//end testTheLinkedContactsAddressIsTheAccount()

	/**
	 * A contact whose linked contact has no address is not converted.
	 *
	 * @return void
	 */
	public function testAContactWithoutAnAddressIsNotConverted(): void {
		$this->contacts->method('findContactByUid')->willReturn(['UID' => 'uid-x', 'FN' => 'Zonder Adres']);
		$this->handler->expects($this->never())->method('updateUserGroupsFromContactData');
		$this->handler->expects($this->never())->method('createUserAccount');

		$processed = $this->service([])->processContactpersoon(
			$this->contactPerson('cp-2', ['contactsUid' => 'uid-x', 'organisation' => 'org-1'])
		);

		$this->assertFalse($processed);
		$this->assertSame([], $this->asked);
	}//end testAContactWithoutAnAddressIsNotConverted()
}//end class
