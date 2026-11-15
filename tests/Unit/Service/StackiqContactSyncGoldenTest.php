<?php

/**
 * Golden test for the contact lookups of StackiqContactSyncService.
 *
 * Pins findContactForRecord (e-mail match, then the organisation code
 * fallback) and the refusal paths of syncToNamedAddressBook: the searches
 * issued, the result, and the log lines.
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
use Psr\Log\AbstractLogger;

/**
 * Records every log call as [level, message, context].
 */
class ContactSyncLogSpy extends AbstractLogger {
	/**
	 * Recorded log calls.
	 *
	 * @var array
	 */
	public array $records = [];

	/**
	 * Record one log call.
	 *
	 * @param mixed              $level   The level.
	 * @param string|\Stringable $message The message.
	 * @param array              $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->records[] = [(string)$level, (string)$message, $context];
	}
}

/**
 * Golden contact lookups.
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */
class StackiqContactSyncGoldenTest extends TestCase {
	/**
	 * Searches issued by the last service.
	 *
	 * @var array
	 */
	private array $searches = [];

	/**
	 * The log spy of the last service.
	 *
	 * @var ContactSyncLogSpy
	 */
	private ContactSyncLogSpy $log;

	/**
	 * A service whose contacts manager answers searches from a fixed list.
	 *
	 * @param array       $contacts  The contacts the manager finds, by pattern.
	 * @param array       $options   enabled, backend (object|null), user (bool).
	 *
	 * @return StackiqContactSyncService
	 */
	private function service(array $contacts, array $options=[]): StackiqContactSyncService {
		$this->searches = [];
		$this->log      = new ContactSyncLogSpy();
		$manager        = $this->createMock(IManager::class);
		$manager->method('isEnabled')->willReturn($options['enabled'] ?? true);
		$manager->method('search')->willReturnCallback(
			function (string $pattern, array $properties, array $searchOptions) use ($contacts): array {
				$this->searches[] = [$pattern, $properties, $searchOptions];
				return ($contacts[$pattern] ?? []);
			}
		);
		$backend   = ($options['backend'] ?? null);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $backend);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($options['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('admin');
		}

		$session->method('getUser')->willReturn($user);
		return new StackiqContactSyncService(contactsManager: $manager, logger: $this->log, container: $container, userSession: $session);
	}

	/**
	 * The e-mail match, then the organisation code fallback.
	 *
	 * @return void
	 */
	public function testFindContactForRecord(): void {
		$contacts = [
			'a@example.org'  => [['UID' => 'no', 'EMAIL' => 'other@example.org'], ['UID' => 'yes', 'EMAIL' => [['value' => ' A@Example.org ']]]],
			'b@example.org'  => [['UID' => 'no', 'EMAIL' => 'x@example.org']],
			'12345'          => [['UID' => 'org-n', 'NICKNAME' => '12345'], ['UID' => 'org-k', 'X-KVK' => '12345']],
			'777'            => [['UID' => 'kvk', 'X-KVK' => ['777']]],
			'888'            => [['UID' => 'none', 'ORG' => '888']],
		];
		$cases    = [
			['contactPerson', ['e-mailadres' => ' a@example.org ']],
			['contactPerson', ['email' => 'a@example.org']],
			['contactPerson', ['e-mailadres' => 'b@example.org', 'cbsCode' => '12345']],
			['organization', ['e-mailadres' => 'b@example.org', 'cbsCode' => '12345']],
			['organization', ['email' => 'a@example.org', 'cbsCode' => '12345']],
			['organization', ['cbsCode' => ' 777 ']],
			['organization', ['cbsCode' => '888']],
			['organization', ['cbsCode' => '   ']],
			['organization', []],
			['contactPerson', ['e-mailadres' => '', 'email' => 'a@example.org']],
		];
		$out      = [];
		foreach ($cases as [$type, $record]) {
			$result = $this->service(contacts: $contacts)->findContactForRecord($type, $record);
			$out[]  = [$result['UID'] ?? null, $this->searches];
		}

		$this->assertNull($this->service(contacts: $contacts, options: ['enabled' => false])->findContactForRecord('contactPerson', ['email' => 'a@example.org']));
		$this->assertSame([], $this->searches);
		$this->assertSame(
			[
				['yes', [['a@example.org', ['EMAIL'], ['limit' => 25]]]],
				['yes', [['a@example.org', ['EMAIL'], ['limit' => 25]]]],
				[null, [['b@example.org', ['EMAIL'], ['limit' => 25]]]],
				['org-n', [['b@example.org', ['EMAIL'], ['limit' => 25]], ['12345', ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]]]],
				['yes', [['a@example.org', ['EMAIL'], ['limit' => 25]]]],
				['kvk', [['777', ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]]]],
				[null, [['888', ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]]]],
				[null, []],
				[null, []],
				[null, []],
			],
			$out
		);
	}

	/**
	 * The paths on which syncToNamedAddressBook resolves no contact.
	 *
	 * @return void
	 */
	public function testSyncToNamedAddressBookRefusals(): void {
		$failingBackend = new class {
			/**
			 * No address book yet.
			 *
			 * @return null
			 */
			public function getAddressBooksByUri(string $principal, string $uri): ?array {
				return null;
			}

			/**
			 * Creating the address book fails.
			 *
			 * @return int
			 */
			public function createAddressBook(string $principal, string $uri, array $properties): int {
				throw new \RuntimeException('dav down');
			}
		};
		$record = ['voornaam' => 'Jan', 'achternaam' => 'Jansen'];
		$cases  = [
			'disabled'   => [['enabled' => false], $record],
			'no-fn'      => [['backend' => $failingBackend], []],
			'no-backend' => [['backend' => null], $record],
			'no-user'    => [['backend' => $failingBackend, 'user' => false], $record],
			'dav-fails'  => [['backend' => $failingBackend], $record],
		];
		$out    = [];
		foreach ($cases as $name => [$options, $data]) {
			$uid        = $this->service(contacts: [], options: $options)->syncToNamedAddressBook(
				objectType: 'contactPerson',
				record: $data,
				addressBookUri: 'stackiq-cmdb-owners',
				displayName: 'Stackiq CMDB owners'
			);
			$out[$name] = [$uid, $this->searches, $this->log->records];
		}

		$this->assertSame(
			[
				'disabled'   => [null, [], []],
				'no-fn'      => [null, [], [['warning', '[StackiqContactSync] Record has no identity to create a contact from', ['objectType' => 'contactPerson']]]],
				'no-backend' => [null, [], [['warning', '[StackiqContactSync] No CardDAV backend or no user; cannot create the contact', ['objectType' => 'contactPerson']]]],
				'no-user'    => [null, [], [['warning', '[StackiqContactSync] No CardDAV backend or no user; cannot create the contact', ['objectType' => 'contactPerson']]]],
				'dav-fails'  => [null, [], [['warning', '[StackiqContactSync] The contact could not be created in the named address book', ['objectType' => 'contactPerson', 'exception' => 'RuntimeException']]]],
			],
			$out
		);
	}
}
