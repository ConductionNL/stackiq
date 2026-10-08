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
	 * The service for user "admin", with the given contacts (none by default) across their address books.
	 *
	 * @param object $backend The CardDAV backend.
	 * @param array<int, array<string, mixed>> $contacts What the contacts manager's search finds, each with its addressbook-key.
	 *
	 * @return StackiqContactSyncService
	 */
	private function service(object $backend, array $contacts = []): StackiqContactSyncService {
		$manager = $this->createMock(IManager::class);
		$manager->method('isEnabled')->willReturn(true);
		$manager->method('search')->willReturnCallback(
			function (string $pattern, array $properties) use ($contacts): array {
				return array_values(
					array_filter(
						$contacts,
						static function (array $contact) use ($pattern, $properties): bool {
							foreach ($properties as $property) {
								if (str_contains(mb_strtolower((string)($contact[$property] ?? '')), mb_strtolower($pattern)) === true) {
									return true;
								}
							}

							return false;
						}
					)
				);
			}
		);
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
	 * An e-mail address matches only a contact in the named address book; a personal contact with it is not linked.
	 *
	 * @return void
	 */
	public function testAnExistingContactIsMatchedOnlyInTheNamedAddressBook(): void {
		$backend = $this->backend();
		$backend->books['principals/users/admin|stackiq-cmdb-owners'] = ['id' => 7, 'displayname' => 'Stackiq CMDB owners'];
		$contacts = [
			['UID' => 'personal-1', 'FN' => 'Voornaam Achternaam', 'EMAIL' => 'owner@example.org', 'addressbook-key' => '3'],
			['UID' => 'owners-1', 'FN' => 'Functioneel Beheer', 'EMAIL' => 'beheer@example.org', 'addressbook-key' => '7'],
		];
		$service = $this->service(backend: $backend, contacts: $contacts);

		$matched = $service->syncToNamedAddressBook(objectType: 'contactPerson', record: ['achternaam' => 'Functioneel Beheer', 'email' => 'beheer@example.org'], addressBookUri: 'stackiq-cmdb-owners', displayName: 'Stackiq CMDB owners');
		$this->assertSame('owners-1', $matched);
		$this->assertSame([], $backend->cards, 'a match creates nothing');

		$created = $service->syncToNamedAddressBook(objectType: 'contactPerson', record: ['voornaam' => 'Voornaam', 'achternaam' => 'Achternaam', 'email' => 'owner@example.org'], addressBookUri: 'stackiq-cmdb-owners', displayName: 'Stackiq CMDB owners');
		$this->assertNotSame('personal-1', $created, 'the personal contact with that address is not linked');
		$this->assertCount(1, $backend->cards);
		$this->assertSame(7, $backend->cards[0][0], 'the new contact goes into the named address book');

		$this->assertSame(['owners-1'], array_column($service->searchNamedAddressBook(query: 'e', addressBookUri: 'stackiq-cmdb-owners', properties: ['FN']), 'uid'));
	}//end testAnExistingContactIsMatchedOnlyInTheNamedAddressBook()

	/**
	 * Without the named address book a search finds nothing and creates no address book.
	 *
	 * @return void
	 */
	public function testSearchingAMissingNamedAddressBookFindsNothing(): void {
		$backend = $this->backend();
		$contacts = [['UID' => 'personal-1', 'FN' => 'Voornaam Achternaam', 'EMAIL' => '', 'addressbook-key' => '3']];

		$found = $this->service(backend: $backend, contacts: $contacts)->searchNamedAddressBook(query: 'Voornaam Achternaam', addressBookUri: 'stackiq-cmdb-owners', properties: ['FN']);

		$this->assertSame([], $found);
		$this->assertSame([], $backend->books);
	}//end testSearchingAMissingNamedAddressBookFindsNothing()

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
