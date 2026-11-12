<?php

/**
 * Golden tests for the StackiqContactSyncService lookups and vCard writer.
 *
 * Pins findContactByUid(), findOwnContactByUid() with the address book keys
 * of the signed-in user (allowed and refused results), the named address book
 * key, syncToContacts(), the organisation match by CBS code,
 * createContactForRecord() and serialiseVCard(), so splitting them keeps every
 * outcome and log call.
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

use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\Constants;
use OCP\Contacts\IManager;
use OCP\IAddressBook;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden tests for the contact lookups of StackiqContactSyncService.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class StackiqContactSyncLookupGoldenTest extends TestCase {

	/**
	 * Every logger call as [level, message, context].
	 *
	 * @var array<int, array>
	 */
	private array $logs = [];

	/**
	 * Every search as [pattern, properties, options].
	 *
	 * @var array<int, array>
	 */
	private array $searches = [];

	/**
	 * What a search returns, per pattern.
	 *
	 * @var array<string, array>
	 */
	private array $results = [];

	/**
	 * Whether the contacts integration is enabled.
	 *
	 * @var boolean
	 */
	private bool $enabled = true;

	/**
	 * The user address books for getUserAddressBooks().
	 *
	 * @var array<int, IAddressBook>
	 */
	private array $userBooks = [];

	/**
	 * What createOrUpdate() returns, and the calls it got.
	 *
	 * @var mixed
	 */
	private mixed $created = null;

	/**
	 * The createOrUpdate() calls as [properties, key].
	 *
	 * @var array<int, array>
	 */
	private array $creates = [];

	/**
	 * Build the service.
	 *
	 * @param mixed $backend The CardDAV backend the container returns, or a Throwable to throw.
	 * @param string|null $uid The signed-in user's uid, or null for nobody.
	 * @param boolean $withContainer Whether the service gets a container and a session.
	 *
	 * @return StackiqContactSyncService
	 */
	private function service(mixed $backend = null, ?string $uid = 'admin', bool $withContainer = true): StackiqContactSyncService {
		$manager = $this->createMock(IManager::class);
		$manager->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$manager->method('search')->willReturnCallback(
			function (string $pattern, array $properties, array $options): array {
				$this->searches[] = [$pattern, $properties, $options];
				return $this->results[$pattern] ?? [];
			}
		);
		$manager->method('getUserAddressBooks')->willReturnCallback(fn (): array => $this->userBooks);
		$manager->method('createOrUpdate')->willReturnCallback(
			function (array $properties, string $key): mixed {
				$this->creates[] = [$properties, $key];
				return $this->created;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		if ($withContainer === false) {
			return new StackiqContactSyncService(contactsManager: $manager, logger: $logger);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function () use ($backend): mixed {
				if ($backend instanceof \Throwable) {
					throw $backend;
				}

				return $backend;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new StackiqContactSyncService(contactsManager: $manager, logger: $logger, container: $container, userSession: $session);
	}//end service()

	/**
	 * A CardDAV backend double.
	 *
	 * @param mixed $books What getAddressBooksForUser() returns.
	 * @param mixed $book What getAddressBooksByUri() returns.
	 *
	 * @return object
	 */
	private function backend(mixed $books, mixed $book = null): object {
		return new class($books, $book) {
			/**
			 * The calls as [method, args].
			 *
			 * @var array<int, array>
			 */
			public array $calls = [];

			/**
			 * Build the double.
			 *
			 * @param mixed $books The user's books.
			 * @param mixed $book The named book.
			 */
			public function __construct(private mixed $books, private mixed $book) {
			}

			/**
			 * The user's books.
			 *
			 * @param string $principal The principal.
			 *
			 * @return mixed
			 */
			public function getAddressBooksForUser(string $principal): mixed {
				$this->calls[] = ['getAddressBooksForUser', [$principal]];
				return $this->books;
			}

			/**
			 * The named book.
			 *
			 * @param string $principal The principal.
			 * @param string $uri The uri.
			 *
			 * @return mixed
			 */
			public function getAddressBooksByUri(string $principal, string $uri): mixed {
				$this->calls[] = ['getAddressBooksByUri', [$principal, $uri]];
				return $this->book;
			}
		};
	}//end backend()

	/**
	 * Call a private method.
	 *
	 * @param StackiqContactSyncService $service The service.
	 * @param string $method The method name.
	 * @param array $args The arguments.
	 *
	 * @return mixed
	 */
	private function call(StackiqContactSyncService $service, string $method, array $args = []): mixed {
		$target = (new ReflectionClass(objectOrClass: StackiqContactSyncService::class))->getMethod(name: $method);
		$target->setAccessible(accessible: true);
		return $target->invokeArgs($service, $args);
	}//end call()

	/**
	 * findContactByUid(): exact UID only, nothing for an empty UID or disabled contacts.
	 *
	 * @return void
	 */
	public function testFindContactByUidMatchesExactly(): void {
		$this->results['uid-1'] = [['UID' => 'uid-10'], ['FN' => 'no uid'], ['UID' => 'uid-1', 'FN' => 'one'], ['UID' => 'uid-1', 'FN' => 'two']];
		$service = $this->service();

		$this->assertSame(['UID' => 'uid-1', 'FN' => 'one'], $service->findContactByUid('uid-1'));
		$this->assertNull($service->findContactByUid('uid-2'));
		$this->assertNull($service->findContactByUid(''));
		$this->enabled = false;
		$this->assertNull($service->findContactByUid('uid-1'));
		$this->assertSame([['uid-1', ['UID'], ['limit' => 5]], ['uid-2', ['UID'], ['limit' => 5]]], $this->searches);
	}//end testFindContactByUidMatchesExactly()

	/**
	 * findOwnContactByUid(): only results from the user's own books are allowed.
	 *
	 * @return void
	 */
	public function testOwnContactLookupAllowsOnlyTheUsersBooks(): void {
		$backend = $this->backend(books: [['id' => 7], 'not-a-book', ['uri' => 'no-id'], ['id' => '8']]);
		$this->results['uid-1'] = [
			['UID' => 'uid-1', 'FN' => 'system', 'addressbook-key' => '1'],
			['UID' => 'uid-1', 'FN' => 'no key'],
			['UID' => 'uid-1x', 'FN' => 'longer', 'addressbook-key' => '7'],
			['UID' => 'uid-1', 'FN' => 'shared', 'addressbook-key' => '8'],
			['UID' => 'uid-1', 'FN' => 'own', 'addressbook-key' => '7'],
		];
		$service = $this->service(backend: $backend);

		$this->assertSame('shared', $service->findOwnContactByUid('uid-1')['FN']);
		$this->assertNull($service->findOwnContactByUid('uid-2'));
		$this->assertSame(['7', '8'], $this->call(service: $service, method: 'userAddressBookKeys'));
		$this->assertSame(['getAddressBooksForUser', ['principals/users/admin']], $backend->calls[0]);
		$this->assertSame(
			['uid-1', ['UID'], ['limit' => 5, 'strict_search' => true, 'enumeration' => false, 'fullmatch' => false]],
			$this->searches[0]
		);
		$this->assertSame([], $this->logs);
	}//end testOwnContactLookupAllowsOnlyTheUsersBooks()

	/**
	 * findOwnContactByUid() refuses without books, user, backend or contacts.
	 *
	 * @return void
	 */
	public function testOwnContactLookupRefusesWithoutTheUsersBooks(): void {
		$this->results['uid-1'] = [['UID' => 'uid-1', 'addressbook-key' => '7']];
		$cases = [
			$this->service(backend: $this->backend(books: [])),
			$this->service(backend: $this->backend(books: 'not a list')),
			$this->service(backend: $this->backend(books: [['id' => 7]]), uid: null),
			$this->service(backend: 'not-an-object'),
			$this->service(withContainer: false),
		];
		foreach ($cases as $service) {
			$this->assertNull($service->findOwnContactByUid('uid-1'));
		}

		$this->enabled = false;
		$this->assertNull($this->service(backend: $this->backend(books: [['id' => 7]]))->findOwnContactByUid('uid-1'));
		$this->assertSame([], $this->searches);
		$this->assertSame([], $this->logs);

		$this->enabled = true;
		$failing = $this->service(backend: new \RuntimeException('down'));
		$this->assertNull($failing->findOwnContactByUid('uid-1'));
		$this->assertSame(
			[['warning', '[StackiqContactSync] The address books of the user could not be read', ['exception' => \RuntimeException::class]]],
			$this->logs
		);
	}//end testOwnContactLookupRefusesWithoutTheUsersBooks()

	/**
	 * existingNamedAddressBookKey(): the id of the named book, or null.
	 *
	 * @return void
	 */
	public function testNamedAddressBookKey(): void {
		$backend = $this->backend(books: [], book: ['id' => 12, 'uri' => 'stackiq']);
		$this->assertSame('12', $this->call(service: $this->service(backend: $backend), method: 'existingNamedAddressBookKey', args: ['stackiq']));
		$this->assertSame([['getAddressBooksByUri', ['principals/users/admin', 'stackiq']]], $backend->calls);

		$misses = [
			$this->service(backend: $this->backend(books: [], book: null)),
			$this->service(backend: $this->backend(books: [], book: ['uri' => 'no-id'])),
			$this->service(backend: $this->backend(books: [], book: ['id' => 3]), uid: null),
			$this->service(backend: 'not-an-object'),
			$this->service(withContainer: false),
		];
		foreach ($misses as $service) {
			$this->assertNull($this->call(service: $service, method: 'existingNamedAddressBookKey', args: ['stackiq']));
		}

		$this->assertSame([], $this->logs);
		$failing = $this->service(backend: new \LogicException('broken'));
		$this->assertNull($this->call(service: $failing, method: 'existingNamedAddressBookKey', args: ['stackiq']));
		$this->assertSame(
			[['warning', '[StackiqContactSync] The named address book could not be read', ['exception' => \LogicException::class]]],
			$this->logs
		);
	}//end testNamedAddressBookKey()

	/**
	 * syncToContacts(): disabled, already linked, matched, created, in that order.
	 *
	 * @return void
	 */
	public function testSyncToContactsResolvesInOrder(): void {
		$this->enabled = false;
		$this->assertNull($this->service()->syncToContacts('contactPerson', ['contactsUid' => 'uid-1']));
		$this->assertSame(
			[['info', '[StackiqContactSync] Contacts disabled, cannot resolve UID', ['objectType' => 'contactPerson']]],
			$this->logs
		);

		$this->enabled = true;
		$this->results['uid-1'] = [['UID' => 'uid-1']];
		$this->results['a@example.org'] = [['UID' => 'by-mail', 'EMAIL' => 'a@example.org']];
		$service = $this->service();
		$this->assertSame('uid-1', $service->syncToContacts('contactPerson', ['contactsUid' => 'uid-1', 'email' => 'a@example.org']));
		$this->assertSame('by-mail', $service->syncToContacts('contactPerson', ['contactsUid' => 'gone', 'email' => 'a@example.org']));
		$this->assertSame('by-mail', $service->syncToContacts('contactPerson', ['email' => 'a@example.org']));
		$this->assertNull($service->syncToContacts('contactPerson', ['contactsUid' => '', 'email' => 'nouid@example.org']));
	}//end testSyncToContactsResolvesInOrder()

	/**
	 * An organisation falls back to its CBS code in the KvK field or nickname.
	 *
	 * @return void
	 */
	public function testOrganisationMatchesByCbsCode(): void {
		$this->results['GM0001'] = [
			['UID' => 'other', 'ORG' => 'GM0001 BV'],
			['UID' => 'nick', 'NICKNAME' => 'GM0001'],
			['UID' => 'kvk', 'X-KVK' => 'GM0001'],
		];
		$service = $this->service();

		$this->assertSame('nick', $service->findContactForRecord('organization', ['cbsCode' => ' GM0001 '])['UID']);
		$this->assertNull($service->findContactForRecord('organization', ['cbsCode' => '  ']));
		$this->assertNull($service->findContactForRecord('contactPerson', ['cbsCode' => 'GM0001']));
		$this->assertSame([['GM0001', ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]]], $this->searches);
	}//end testOrganisationMatchesByCbsCode()

	/**
	 * createContactForRecord(): every refusal and the created UID.
	 *
	 * @return void
	 */
	public function testCreateContactForRecord(): void {
		$readOnly = $this->createMock(IAddressBook::class);
		$readOnly->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$readOnly->method('getKey')->willReturn('1');
		$writable = $this->createMock(IAddressBook::class);
		$writable->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$writable->method('getKey')->willReturn('5');
		$record = ['voornaam' => 'Ada', 'achternaam' => 'Lovelace'];

		$this->enabled = false;
		$this->assertNull($this->service()->createContactForRecord('contactPerson', $record));
		$this->enabled = true;
		$this->userBooks = [$readOnly];
		$this->assertNull($this->service()->createContactForRecord('contactPerson', $record));
		$this->userBooks = [$readOnly, $writable];
		$this->assertNull($this->service()->createContactForRecord('contactPerson', []));
		$this->assertNull($this->service()->createContactForRecord('contactPerson', $record));
		$this->created = ['UID' => 'new-uid'];
		$this->assertSame('new-uid', $this->service()->createContactForRecord('contactPerson', $record));
		$this->created = ['FN' => 'no uid'];
		$fallback = $this->service()->createContactForRecord('contactPerson', $record);

		$this->assertSame((string) ($this->creates[2][0]['UID'] ?? ''), $fallback);
		$this->assertCount(3, $this->creates);
		$this->assertSame('5', $this->creates[0][1]);
		$this->assertSame(
			[
				['warning', '[StackiqContactSync] No writable addressbook available; cannot create contact', ['objectType' => 'contactPerson']],
				['warning', '[StackiqContactSync] Record has no identity to create a contact from', ['objectType' => 'contactPerson']],
			],
			$this->logs
		);
	}//end testCreateContactForRecord()

	/**
	 * serialiseVCard(): skipped values, escaping, the structured N and folding.
	 *
	 * @return void
	 */
	public function testSerialiseVCard(): void {
		$vCard = StackiqContactSyncService::serialiseVCard(
			'u;1',
			[
				'UID' => 'ignored',
				'VERSION' => '4.0',
				'fn' => 'Ada, Countess; of\\Lovelace',
				'N' => 'Love,lace;Ada;;;',
				'EMPTY' => '',
				'LIST' => ['x'],
				'NOTE' => "line one\nline two",
				'TEL' => 12345,
				'X-LONG' => str_repeat('é', 50),
				'FLAG' => false,
			]
		);

		$expected = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u\\;1\r\n"
			. "FN:Ada\\, Countess\\; of\\\\Lovelace\r\n"
			. "N:Love\\,lace;Ada;;;\r\n"
			. "NOTE:line one\\nline two\r\n"
			. "TEL:12345\r\n"
			. 'X-LONG:' . str_repeat('é', 34) . "\r\n " . str_repeat('é', 16) . "\r\n"
			. "END:VCARD\r\n";
		$this->assertSame($expected, $vCard);
	}//end testSerialiseVCard()

}//end class
