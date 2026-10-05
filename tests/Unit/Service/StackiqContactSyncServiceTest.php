<?php

/**
 * Tests for the named address book of StackiqContactSyncService.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\Contacts\IManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A new contact goes into the user's named address book, created once.
 */
class StackiqContactSyncServiceTest extends TestCase {
	/**
	 * A CardDAV backend over memory.
	 *
	 * @return object
	 */
	private function backend(): object {
		return new class {
			/**
			 * Address books: principal|uri => [id, displayname].
			 *
			 * @var array<string, array{id: int, displayname: string}>
			 */
			public array $books = [];

			/**
			 * Cards: [addressBookId, uri, data].
			 *
			 * @var array<int, array{0: int, 1: string, 2: string}>
			 */
			public array $cards = [];

			public function getAddressBooksByUri(string $principal, string $uri): ?array {
				$book = ($this->books[$principal . '|' . $uri] ?? null);
				return $book === null ? null : ['id' => $book['id'], 'uri' => $uri];
			}

			public function createAddressBook(string $principal, string $uri, array $properties): int {
				$id = (count($this->books) + 7);
				$this->books[$principal . '|' . $uri] = ['id' => $id, 'displayname' => (string)$properties['{DAV:}displayname']];
				return $id;
			}

			public function createCard(int $addressBookId, string $cardUri, string $cardData): string {
				$this->cards[] = [$addressBookId, $cardUri, $cardData];
				return 'etag';
			}
		};
	}//end backend()

	/**
	 * The service for user "admin", with no existing contact anywhere.
	 *
	 * @param object $backend The CardDAV backend.
	 *
	 * @return StackiqContactSyncService
	 */
	private function service(object $backend): StackiqContactSyncService {
		$manager = $this->createMock(IManager::class);
		$manager->method('isEnabled')->willReturn(true);
		$manager->method('search')->willReturn([]);
		$manager->expects($this->never())->method('createOrUpdate');
		$manager->expects($this->never())->method('getUserAddressBooks');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === StackiqContactSyncService::CARDDAV_BACKEND_CLASS ? $backend : new \stdClass());
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new StackiqContactSyncService(contactsManager: $manager, logger: new NullLogger(), container: $container, userSession: $session);
	}//end service()

	/**
	 * The first contact creates the address book; the second reuses it; neither touches another address book.
	 *
	 * @return void
	 */
	public function testNewContactsGoIntoTheNamedAddressBook(): void {
		$backend = $this->backend();
		$service = $this->service(backend: $backend);

		$first = $service->syncToNamedAddressBook(objectType: 'contactPerson', record: ['voornaam' => 'Voornaam', 'achternaam' => 'Achternaam', 'role' => 'Hoofd; ICT, beheer'], addressBookUri: 'stackiq-cmdb-owners', displayName: 'Stackiq CMDB owners');
		$second = $service->syncToNamedAddressBook(objectType: 'contactPerson', record: ['achternaam' => 'Functioneel Beheer'], addressBookUri: 'stackiq-cmdb-owners', displayName: 'Stackiq CMDB owners');

		$this->assertSame(['principals/users/admin|stackiq-cmdb-owners' => ['id' => 7, 'displayname' => 'Stackiq CMDB owners']], $backend->books);
		$this->assertCount(2, $backend->cards);
		$this->assertSame([7, 7], array_column($backend->cards, 0));
		$this->assertSame($first . '.vcf', $backend->cards[0][1]);
		$this->assertNotSame($first, $second);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$first);

		$card = $backend->cards[0][2];
		$this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:" . $first . "\r\n", $card);
		$this->assertStringContainsString("\r\nFN:Voornaam Achternaam\r\n", $card);
		$this->assertStringContainsString("\r\nN:Achternaam;Voornaam;;;\r\n", $card);
		$this->assertStringContainsString("\r\nTITLE:Hoofd\; ICT\\, beheer\r\n", $card, 'text values are escaped');
		$this->assertStringEndsWith("END:VCARD\r\n", $card);
	}//end testNewContactsGoIntoTheNamedAddressBook()

	/**
	 * A long value is folded at 75 octets without splitting a character.
	 *
	 * @return void
	 */
	public function testLongLinesAreFolded(): void {
		$card = StackiqContactSyncService::serialiseVCard(uid: 'u-1', properties: ['FN' => str_repeat('é', 60)]);

		foreach (explode("\r\n", $card) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line));
			$this->assertTrue(mb_check_encoding($line, 'UTF-8'));
		}

		$unfolded = str_replace("\r\n ", '', $card);
		$this->assertStringContainsString('FN:' . str_repeat('é', 60), $unfolded);
	}//end testLongLinesAreFolded()
}//end class
