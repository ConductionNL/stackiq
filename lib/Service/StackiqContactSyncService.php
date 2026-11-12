<?php

/**
 * Stackiq Contact-sync service.
 *
 * Bridges stackiq catalog relationship/role records (contactpersoon,
 * organisatie) to the Nextcloud addressbook through OCP\Contacts\IManager.
 * Identity (name, e-mail, phone, website, logo, CBS/KvK code) lives in
 * Nextcloud Contacts keyed by `contactsUid`; this service never re-implements
 * an app-local identity store and never uses bespoke HTTP (ADR-019, ADR-022,
 * cross-app interface contract #2). Modeled on the canonical
 * pipelinq/lib/Service/ContactSyncService.php and
 * zaakafhandelapp/lib/Service/KlantContactSyncService.php.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Search, import and create Nextcloud contacts for stackiq
 * relationship/role records.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Overall complexity 56 (threshold 50). The
 * class maps several distinct stackiq relationship types (organisation, contactpersoon,
 * …) onto Nextcloud's vCard contact model, and each mapping needs per-field presence checks
 * because the legacy identity fields are optional and inconsistently populated across records.
 * The Contacts API is also an optional dependency, so every entry point carries an availability
 * guard. Both are breadth of mapping rather than depth of logic.
 */
class StackiqContactSyncService {

	/**
	 * Nextcloud's CardDAV backend, which creates an address book (not part of OCP).
	 */
	public const CARDDAV_BACKEND_CLASS = 'OCA\DAV\CardDAV\CardDavBackend';

	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager The Nextcloud contacts manager.
	 * @param LoggerInterface $logger The logger.
	 * @param ContainerInterface|null $container Resolves the CardDAV backend, for a named address book.
	 * @param IUserSession|null $userSession The user whose named address book is written.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function __construct(
		private readonly IContactsManager $contactsManager,
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container = null,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Whether the Nextcloud Contacts integration is available.
	 *
	 * @return boolean True when Contacts is enabled.
	 */
	public function isAvailable(): bool {
		return $this->contactsManager->isEnabled() === true;
	}//end isAvailable()

	/**
	 * Search the user's accessible Nextcloud addressbooks for contacts
	 * matching a free-text query.
	 *
	 * @param string $query The search query.
	 *
	 * @return array<int, array<string, mixed>> The matching contacts.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function searchContacts(string $query): array {
		if ($this->isAvailable() === false) {
			return [];
		}

		$results = $this->contactsManager->search(
			$query,
			['FN', 'EMAIL', 'TEL', 'ORG'],
			['limit' => 50]
		);

		$vCard    = new RecordVCard();
		$contacts = [];
		foreach ($results as $result) {
			$uid = ($result['UID'] ?? null);
			if ($uid === null) {
				continue;
			}

			$contacts[] = [
				'uid' => $uid,
				'name' => $vCard->firstValue(value: ($result['FN'] ?? '')),
				'email' => $vCard->firstValue(value: ($result['EMAIL'] ?? '')),
				'phone' => $vCard->firstValue(value: ($result['TEL'] ?? '')),
				'org' => $vCard->firstValue(value: ($result['ORG'] ?? '')),
				'addressBookKey' => ($result['addressbook-key'] ?? ''),
			];
		}

		return $contacts;
	}//end searchContacts()

	/*
	 * NO importContact() HERE.
	 *
	 * It validated that a UID resolved and returned that same UID unchanged —
	 * no import, no write. It had no caller: the two live consumers,
	 * `BackgroundJob\OrganizationContactSyncJob` and `Repair\MigrateContactsToNc`,
	 * use `isAvailable()`, `findContactByUid()`, `findContactForRecord()` and
	 * `syncToContacts()`, which together already serve the capability
	 * ("resolve or create the Nextcloud Contact for a record and return its
	 * UID"). A third entry point would have duplicated `syncToContacts()`
	 * without its create path.
	 */

	/**
	 * Resolve (or create) the Nextcloud Contact for a catalog relationship
	 * record and return its UID.
	 *
	 * Resolution order: existing `contactsUid` on the record → e-mail match
	 * (and `cbsCode`/KvK for organisations) → create from identity fields.
	 * Never bespoke HTTP — only OCP\Contacts\IManager (ADR-019, ADR-022).
	 *
	 * @param string $objectType The relationship type ('contactPerson'|'organization').
	 * @param array<string, mixed> $record The relationship record (may still carry legacy identity fields).
	 *
	 * @return ?string The contacts UID, or null when it could not be resolved or created.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function syncToContacts(string $objectType, array $record): ?string {
		if ($this->isAvailable() === false) {
			$this->logger->info('[StackiqContactSync] Contacts disabled, cannot resolve UID', ['objectType' => $objectType]);
			return null;
		}

		// Already linked — idempotent no-op.
		$existingUid = $this->linkedContactUid(record: $record);
		if ($existingUid !== null) {
			return $existingUid;
		}

		// Resolve by e-mail (and cbsCode for organisations).
		$matched = $this->findContactForRecord(objectType: $objectType, record: $record);
		if ($matched !== null) {
			return (string)($matched['UID'] ?? '');
		}

		// Create a fresh Contact from the identity fields.
		return $this->createContactForRecord(objectType: $objectType, record: $record);
	}//end syncToContacts()

	/**
	 * The contacts UID a record is already linked to, when that contact still exists.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return string|null The linked UID, or null when the record is not linked.
	 */
	private function linkedContactUid(array $record): ?string {
		$existingUid = (string)($record['contactsUid'] ?? '');
		if ($existingUid !== '' && $this->findContactByUid(uid: $existingUid) !== null) {
			return $existingUid;
		}

		return null;
	}//end linkedContactUid()

	/**
	 * Resolve the contact of a record, creating it in a dedicated address book of the signed-in user.
	 *
	 * A contact the record already links (`contactsUid`) is kept. Otherwise,
	 * without a fixed UID, a contact with the record's e-mail address is
	 * reused only when it is in the address book with this URI: a contact
	 * in another address book of the user is never matched, so the user's
	 * own contacts are never linked to imported records. A new contact goes into that address book,
	 * which is created with the display name when the user has none, and
	 * never into the user's own first writable address book.
	 *
	 * @param string $objectType The relationship type ('contactPerson'|'organization').
	 * @param array<string, mixed> $record The relationship record.
	 * @param string $addressBookUri The address book's URI, stable across languages.
	 * @param string $displayName The display name for a new address book.
	 * @param string|null $fixedUid The UID for a new contact, so the caller can find it again by UID; a random one when null.
	 *                              With a fixed UID the record's e-mail address matches no existing contact: the
	 *                              caller identifies the contact by that UID, and a shared e-mail address must not
	 *                              merge two of them.
	 *
	 * @return ?string The contacts UID, or null when it could not be resolved or created.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function syncToNamedAddressBook(
		string $objectType,
		array $record,
		string $addressBookUri,
		string $displayName,
		?string $fixedUid = null,
	): ?string {
		$sync = new NamedAddressBookSync(objectType: $objectType, record: $record);
		$sync->addressBookUri = $addressBookUri;
		$sync->displayName    = $displayName;
		$sync->fixedUid       = $fixedUid;
		return $this->syncRecordToNamedAddressBook(sync: $sync);
	}//end syncToNamedAddressBook()

	/**
	 * Resolve the contact of a record in a dedicated address book of the
	 * signed-in user, as syncToNamedAddressBook() describes.
	 *
	 * @param NamedAddressBookSync $sync The record, the address book and the optional fixed UID.
	 *
	 * @return ?string The contacts UID, or null when it could not be resolved or created.
	 */
	private function syncRecordToNamedAddressBook(NamedAddressBookSync $sync): ?string {
		if ($this->isAvailable() === false) {
			return null;
		}

		$knownUid = $this->knownNamedAddressBookUid(sync: $sync);
		if ($knownUid !== null) {
			return $knownUid;
		}

		$properties = (new RecordVCard())->properties(objectType: $sync->objectType, record: $sync->record);
		if (($properties['FN'] ?? '') === '') {
			$this->logger->warning('[StackiqContactSync] Record has no identity to create a contact from', ['objectType' => $sync->objectType]);
			return null;
		}

		return $this->createNamedAddressBookContact(sync: $sync, properties: $properties);
	}//end syncRecordToNamedAddressBook()

	/**
	 * The contact the record already links, else the one its e-mail address
	 * matches in the named address book.
	 *
	 * @param NamedAddressBookSync $sync The sync request.
	 *
	 * @return string|null The contacts UID, or null when neither exists.
	 */
	private function knownNamedAddressBookUid(NamedAddressBookSync $sync): ?string {
		$existingUid = (string)($sync->record['contactsUid'] ?? '');
		if ($existingUid !== '' && $this->findContactByUid(uid: $existingUid) !== null) {
			return $existingUid;
		}

		return $this->namedAddressBookContactByEmail(record: $sync->record, addressBookUri: $sync->addressBookUri, fixedUid: $sync->fixedUid);
	}//end knownNamedAddressBookUid()

	/**
	 * Create the contact in the named address book; a failure is logged and resolves nothing.
	 *
	 * @param NamedAddressBookSync  $sync       The sync request.
	 * @param array<string, mixed>  $properties The vCard properties.
	 *
	 * @return string|null The new contacts UID, or null.
	 */
	private function createNamedAddressBookContact(NamedAddressBookSync $sync, array $properties): ?string {
		try {
			return $this->createCardInNamedAddressBook(sync: $sync, properties: $properties);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[StackiqContactSync] The contact could not be created in the named address book',
				['objectType' => $sync->objectType, 'exception' => get_class($e)]
			);
			return null;
		}
	}//end createNamedAddressBookContact()

	/**
	 * Write the card into the user's named address book, creating the book when needed.
	 *
	 * @param NamedAddressBookSync  $sync       The sync request.
	 * @param array<string, mixed>  $properties The vCard properties.
	 *
	 * @return string|null The new contacts UID, or null without a CardDAV backend or user.
	 */
	private function createCardInNamedAddressBook(NamedAddressBookSync $sync, array $properties): ?string {
		$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
		$uid     = $this->userSession?->getUser()?->getUID();
		if (is_object($backend) === false || $uid === null) {
			$this->logger->warning('[StackiqContactSync] No CardDAV backend or no user; cannot create the contact', ['objectType' => $sync->objectType]);
			return null;
		}

		$addressBookId = $this->namedAddressBookId(
			backend: $backend,
			principal: 'principals/users/' . $uid,
			uri: $sync->addressBookUri,
			displayName: $sync->displayName
		);
		$contactUid    = ($sync->fixedUid ?? self::newUid());
		$backend->createCard($addressBookId, $contactUid . '.vcf', self::serialiseVCard(uid: $contactUid, properties: $properties));
		return $contactUid;
	}//end createCardInNamedAddressBook()

	/**
	 * Search the signed-in user's address book with this URI, and no other.
	 *
	 * @param string $query The search query.
	 * @param string $addressBookUri The address book's URI.
	 * @param array<int, string> $properties The vCard properties to search, such as FN or EMAIL.
	 *
	 * @return array<int, array<string, mixed>> The matching contacts, as searchContacts() returns them,
	 *                                          plus `emails`: every e-mail address of the contact;
	 *                                          none when the user has no such address book.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function searchNamedAddressBook(string $query, string $addressBookUri, array $properties): array {
		$vCard    = new RecordVCard();
		$contacts = [];
		foreach ($this->namedAddressBookResults(query: $query, addressBookUri: $addressBookUri, properties: $properties) as $result) {
			$contacts[] = [
				'uid' => (string)$result['UID'],
				'name' => $vCard->firstValue(value: ($result['FN'] ?? '')),
				'email' => $vCard->firstValue(value: ($result['EMAIL'] ?? '')),
				'emails' => array_map(
					fn (mixed $email): string => $vCard->firstValue(value: [$email]),
					array_values((array)($result['EMAIL'] ?? []))
				),
				'addressBookKey' => (string)($result['addressbook-key'] ?? ''),
			];
		}

		return $contacts;
	}//end searchNamedAddressBook()

	/**
	 * The contact in the named address book with the record's e-mail address, or null.
	 *
	 * With a fixed UID the e-mail address matches nothing: the caller
	 * identifies the contact by that UID, and a shared e-mail address must
	 * not merge two of them. Only a card that already has that UID (by URI)
	 * is returned.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 * @param string $addressBookUri The address book's URI.
	 * @param string|null $fixedUid The UID the caller gives a new contact, or null.
	 *
	 * @return string|null The contact UID.
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	private function namedAddressBookContactByEmail(array $record, string $addressBookUri, ?string $fixedUid): ?string {
		if ($fixedUid !== null) {
			// A card that already has the fixed UID is the contact: Nextcloud
			// refuses a second one (UidConflict), and the contacts search may not
			// show it yet. Looked up by URI, not by search.
			$key = $this->existingNamedAddressBookKey(addressBookUri: $addressBookUri);
			$backend = null;
			if ($key !== null) {
				$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
			}

			if (is_object($backend) === true && is_array($backend->getCard((int)$key, $fixedUid . '.vcf')) === true) {
				return $fixedUid;
			}

			return null;
		}

		$email = trim((string)($record['e-mailadres'] ?? $record['email'] ?? ''));
		foreach ($this->namedAddressBookResults(query: $email, addressBookUri: $addressBookUri, properties: ['EMAIL']) as $result) {
			if ((new RecordVCard())->valueMatches(value: ($result['EMAIL'] ?? ''), needle: $email) === true) {
				return (string)$result['UID'];
			}
		}

		return null;
	}//end namedAddressBookContactByEmail()

	/**
	 * The raw search results that lie in the user's address book with this URI.
	 *
	 * The contacts manager searches every address book of the user and tags
	 * each result with its address book's key, the CardDAV address book id;
	 * only results with the named address book's id are kept.
	 *
	 * @param string $query The search query.
	 * @param string $addressBookUri The address book's URI.
	 * @param array<int, string> $properties The vCard properties to search.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function namedAddressBookResults(string $query, string $addressBookUri, array $properties): array {
		if ($this->isAvailable() === false || trim($query) === '') {
			return [];
		}

		$key = $this->existingNamedAddressBookKey(addressBookUri: $addressBookUri);
		if ($key === null) {
			return [];
		}

		$found = [];
		foreach ($this->contactsManager->search($query, $properties, ['limit' => 50]) as $result) {
			if (isset($result['UID']) === true && (string)($result['addressbook-key'] ?? '') === $key) {
				$found[] = $result;
			}
		}

		return $found;
	}//end namedAddressBookResults()

	/**
	 * The key of the signed-in user's address book with this URI, without creating it.
	 *
	 * @param string $addressBookUri The address book's URI.
	 *
	 * @return string|null The CardDAV address book id as a string, or null when there is none.
	 */
	private function existingNamedAddressBookKey(string $addressBookUri): ?string {
		$book = $this->readNamedAddressBook(addressBookUri: $addressBookUri);
		if (is_array($book) === false || isset($book['id']) === false) {
			return null;
		}

		return (string)$book['id'];
	}//end existingNamedAddressBookKey()

	/**
	 * Read the signed-in user's address book with this URI from the CardDAV backend.
	 *
	 * @param string $addressBookUri The address book's URI.
	 *
	 * @return mixed What the backend returns, or null without a backend, a user or on failure.
	 */
	private function readNamedAddressBook(string $addressBookUri): mixed {
		try {
			$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
			$uid = $this->userSession?->getUser()?->getUID();
			if (is_object($backend) === false || $uid === null) {
				return null;
			}

			return $backend->getAddressBooksByUri('principals/users/' . $uid, $addressBookUri);
		} catch (Throwable $e) {
			$this->logger->warning('[StackiqContactSync] The named address book could not be read', ['exception' => get_class($e)]);
			return null;
		}
	}//end readNamedAddressBook()

	/**
	 * The id of a principal's address book with this URI, created when absent.
	 *
	 * @param object $backend The CardDAV backend.
	 * @param string $principal The principal URI.
	 * @param string $uri The address book URI.
	 * @param string $displayName The display name for a new address book.
	 *
	 * @return int
	 */
	private function namedAddressBookId(object $backend, string $principal, string $uri, string $displayName): int {
		$book = $backend->getAddressBooksByUri($principal, $uri);
		if (is_array($book) === true && isset($book['id']) === true) {
			return (int)$book['id'];
		}

		return (int)$backend->createAddressBook($principal, $uri, ['{DAV:}displayname' => $displayName]);
	}//end namedAddressBookId()

	/**
	 * A vCard 3.0 for a new contact, from the property set RecordVCard::properties() builds.
	 *
	 * Text values are escaped as RFC 6350 requires; `N` is structured, so only
	 * its components are escaped. Lines are folded at 75 octets.
	 *
	 * @param string $uid The contact UID.
	 * @param array<string, mixed> $properties The vCard property set.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public static function serialiseVCard(string $uid, array $properties): string {
		$lines = ['BEGIN:VCARD', 'VERSION:3.0', 'UID:' . self::escapeVCardText(text: $uid)];
		foreach ($properties as $name => $value) {
			$line = self::vCardLine(name: $name, value: $value);
			if ($line !== null) {
				$lines[] = $line;
			}
		}

		$lines[] = 'END:VCARD';

		return implode("\r\n", $lines) . "\r\n";
	}//end serialiseVCard()

	/**
	 * The folded content line of one vCard property, or null when it is not written.
	 *
	 * Non-scalar and empty values are left out, and so are UID and VERSION,
	 * which serialiseVCard() writes itself.
	 *
	 * @param int|string $name The property name.
	 * @param mixed $value The property value.
	 *
	 * @return string|null The content line, or null.
	 */
	private static function vCardLine(int|string $name, mixed $value): ?string {
		if (self::isWrittenVCardProperty(name: $name, value: $value) === false) {
			return null;
		}

		$text = self::escapeVCardText(text: (string)$value);
		if ($name === 'N') {
			$text = implode(';', array_map(static fn (string $part): string => self::escapeVCardText(text: $part), explode(';', (string)$value)));
		}

		return self::foldVCardLine(line: strtoupper((string)$name) . ':' . $text);
	}//end vCardLine()

	/**
	 * Whether a vCard property is written: a non-empty scalar other than UID and VERSION.
	 *
	 * @param int|string $name The property name.
	 * @param mixed $value The property value.
	 *
	 * @return bool True when the property gets a content line.
	 */
	private static function isWrittenVCardProperty(int|string $name, mixed $value): bool {
		return is_scalar($value) === true
			&& (string)$value !== ''
			&& in_array($name, ['UID', 'VERSION'], true) === false;
	}//end isWrittenVCardProperty()

	/**
	 * Escape a vCard text value: backslash, comma, semicolon and line breaks.
	 *
	 * @param string $text The value.
	 *
	 * @return string
	 */
	private static function escapeVCardText(string $text): string {
		return str_replace(["\\", ',', ';', "\r\n", "\n", "\r"], ["\\\\", '\\,', '\\;', '\\n', '\\n', '\\n'], $text);
	}//end escapeVCardText()

	/**
	 * Fold a vCard content line at 75 octets, never inside a UTF-8 character.
	 *
	 * @param string $line The unfolded line.
	 *
	 * @return string
	 */
	private static function foldVCardLine(string $line): string {
		$folded = [];
		while (strlen($line) > 75) {
			$cut = mb_strcut($line, 0, 75, 'UTF-8');
			$folded[] = $cut;
			$line = ' ' . substr($line, strlen($cut));
		}

		$folded[] = $line;

		return implode("\r\n", $folded);
	}//end foldVCardLine()

	/**
	 * A random UUID v4 for a new contact.
	 *
	 * @return string
	 */
	private static function newUid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newUid()

	/**
	 * Find a Nextcloud contact by its exact UID.
	 *
	 * @param string $uid The contact UID.
	 *
	 * @return ?array<string, mixed> The contact, or null when not found.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function findContactByUid(string $uid): ?array {
		if ($uid === '' || $this->isAvailable() === false) {
			return null;
		}

		$results = $this->contactsManager->search($uid, ['UID'], ['limit' => 5]);
		return $this->firstWithUid(results: $results, uid: $uid);
	}//end findContactByUid()

	/**
	 * The first search result whose UID is exactly this one.
	 *
	 * @param iterable $results The contacts search results.
	 * @param string $uid The contact UID.
	 *
	 * @return ?array<string, mixed> The contact, or null when none matches exactly.
	 */
	private function firstWithUid(iterable $results, string $uid): ?array {
		foreach ($results as $result) {
			if (($result['UID'] ?? '') === $uid) {
				return $result;
			}
		}

		return null;
	}//end firstWithUid()

	/**
	 * Find a contact by its exact UID in the signed-in user's own and shared address books only.
	 *
	 * A result counts only when it comes from an address book the CardDAV
	 * backend lists for the user's principal (own and shared); every
	 * address book of the system principal is left out, the local system
	 * address book (one card per local user) and the books synced from
	 * trusted servers alike. The UID is matched exactly, not as a substring.
	 * This is the lookup for a name shown to a user: a contactsUid is free
	 * text on a contactPerson, so a lookup that read the system books would
	 * name any local or federated user for whoever may write a contactPerson.
	 * Without a CardDAV backend or a signed-in user nothing is found.
	 *
	 * @param string $uid The contact UID.
	 *
	 * @return ?array<string, mixed> The contact, or null when not found there.
	 *
	 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
	 */
	public function findOwnContactByUid(string $uid): ?array {
		if ($uid === '' || $this->isAvailable() === false) {
			return null;
		}

		$keys = $this->userAddressBookKeys();
		if ($keys === []) {
			return null;
		}

		// With enumeration and fullmatch both off, the contacts manager skips the
		// local system address book; strict_search turns the substring match off.
		$options = ['limit' => 5, 'strict_search' => true, 'enumeration' => false, 'fullmatch' => false];
		$results = $this->contactsManager->search($uid, ['UID'], $options);
		return $this->firstWithUid(results: $this->inAddressBooks(results: $results, keys: $keys), uid: $uid);
	}//end findOwnContactByUid()

	/**
	 * The search results that come from one of these address books, in order.
	 *
	 * @param iterable $results The contacts search results.
	 * @param array<int, string> $keys The address book keys a result may come from.
	 *
	 * @return array<int, array<string, mixed>> The results from those address books.
	 */
	private function inAddressBooks(iterable $results, array $keys): array {
		$inBooks = [];
		foreach ($results as $result) {
			$key = (string)($result['addressbook-key'] ?? '');
			if (in_array($key, $keys, true) === true) {
				$inBooks[] = $result;
			}
		}

		return $inBooks;
	}//end inAddressBooks()

	/**
	 * The keys of the signed-in user's own and shared address books.
	 *
	 * @return array<int, string> CardDAV address book ids as strings; empty without a backend or a user.
	 */
	private function userAddressBookKeys(): array {
		$books = $this->readUserAddressBooks();
		if (is_array($books) === false) {
			return [];
		}

		$keys = [];
		foreach ($books as $book) {
			if (is_array($book) === true && isset($book['id']) === true) {
				$keys[] = (string)$book['id'];
			}
		}

		return $keys;
	}//end userAddressBookKeys()

	/**
	 * Read the signed-in user's own and shared address books from the CardDAV backend.
	 *
	 * @return mixed What the backend returns, or null without a backend, a user or on failure.
	 */
	private function readUserAddressBooks(): mixed {
		try {
			$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
			$uid = $this->userSession?->getUser()?->getUID();
			if (is_object($backend) === false || $uid === null) {
				return null;
			}

			return $backend->getAddressBooksForUser('principals/users/' . $uid);
		} catch (Throwable $e) {
			$this->logger->warning('[StackiqContactSync] The address books of the user could not be read', ['exception' => get_class($e)]);
			return null;
		}
	}//end readUserAddressBooks()

	/**
	 * Find a Nextcloud contact matching a relationship record's identity, by
	 * e-mail first and — for organisations — by CBS/KvK code as a fallback.
	 *
	 * @param string $objectType The relationship type.
	 * @param array<string, mixed> $record The relationship record carrying legacy identity fields.
	 *
	 * @return ?array<string, mixed> The matched contact, or null.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function findContactForRecord(string $objectType, array $record): ?array {
		if ($this->isAvailable() === false) {
			return null;
		}

		$match = $this->contactByEmail(record: $record);

		// Organisations: fall back to CBS/KvK code stored in the ORG/X-KvK field.
		if ($match === null && $objectType === 'organization') {
			$match = $this->organisationContactByCode(record: $record);
		}

		return $match;
	}//end findContactForRecord()

	/**
	 * The contact whose e-mail address equals the record's.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return ?array<string, mixed> The matched contact, or null.
	 */
	private function contactByEmail(array $record): ?array {
		$email = trim((string)($record['e-mailadres'] ?? $record['email'] ?? ''));
		if ($email === '') {
			return null;
		}

		$results = $this->contactsManager->search($email, ['EMAIL'], ['limit' => 25]);
		foreach ($results as $result) {
			if ((new RecordVCard())->valueMatches(value: ($result['EMAIL'] ?? ''), needle: $email) === true) {
				return $result;
			}
		}

		return null;
	}//end contactByEmail()

	/**
	 * The organisation contact whose KvK field or nickname equals the record's CBS code.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return ?array<string, mixed> The matched contact, or null.
	 */
	private function organisationContactByCode(array $record): ?array {
		$cbsCode = trim((string)($record['cbsCode'] ?? ''));
		if ($cbsCode === '') {
			return null;
		}

		$results = $this->contactsManager->search($cbsCode, ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]);
		return $this->firstWithCbsCode(results: $results, cbsCode: $cbsCode);
	}//end organisationContactByCode()

	/**
	 * The first search result whose KvK field or nickname equals the CBS code.
	 *
	 * @param iterable $results The contacts search results.
	 * @param string $cbsCode The CBS code.
	 *
	 * @return ?array<string, mixed> The matched contact, or null.
	 */
	private function firstWithCbsCode(iterable $results, string $cbsCode): ?array {
		$vCard = new RecordVCard();
		foreach ($results as $result) {
			if ($vCard->valueMatches(value: ($result['X-KVK'] ?? ''), needle: $cbsCode) === true
				|| $vCard->valueMatches(value: ($result['NICKNAME'] ?? ''), needle: $cbsCode) === true
			) {
				return $result;
			}
		}

		return null;
	}//end firstWithCbsCode()

	/**
	 * Create a Nextcloud contact from a relationship record's legacy identity
	 * fields, returning the new UID.
	 *
	 * @param string $objectType The relationship type ('contactPerson'|'organization').
	 * @param array<string, mixed> $record The relationship record carrying legacy identity fields.
	 *
	 * @return ?string The new contacts UID, or null when no writable addressbook
	 *                 is available or the record has no usable identity.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function createContactForRecord(string $objectType, array $record): ?string {
		if ($this->isAvailable() === false) {
			return null;
		}

		$addressBookKey = $this->writableAddressBookKeyFor(objectType: $objectType);
		if ($addressBookKey === null) {
			return null;
		}

		$properties = (new RecordVCard())->properties(objectType: $objectType, record: $record);
		if (($properties['FN'] ?? '') === '') {
			$this->logger->warning('[StackiqContactSync] Record has no identity to create a contact from', ['objectType' => $objectType]);
			return null;
		}

		return $this->createContact(properties: $properties, addressBookKey: $addressBookKey);
	}//end createContactForRecord()

	/**
	 * The first writable address book key; logs that none is available.
	 *
	 * @param string $objectType The relationship type, for the log.
	 *
	 * @return ?string The writable addressbook key, or null.
	 */
	private function writableAddressBookKeyFor(string $objectType): ?string {
		$addressBookKey = $this->firstWritableAddressBookKey();
		if ($addressBookKey === null) {
			$this->logger->warning(
				'[StackiqContactSync] No writable addressbook available; cannot create contact',
				['objectType' => $objectType]
			);
		}

		return $addressBookKey;
	}//end writableAddressBookKeyFor()

	/**
	 * Create the contact and return its UID.
	 *
	 * @param array<string, mixed> $properties The vCard property set.
	 * @param string $addressBookKey The writable addressbook key.
	 *
	 * @return ?string The new UID, or null when the contact was not created.
	 */
	private function createContact(array $properties, string $addressBookKey): ?string {
		$created = $this->contactsManager->createOrUpdate($properties, $addressBookKey);
		if (is_array($created) === false) {
			return null;
		}

		return (string)($created['UID'] ?? ($properties['UID'] ?? ''));
	}//end createContact()

	/**
	 * Return the key of the first writable addressbook, or null when none is
	 * available.
	 *
	 * @return ?string The writable addressbook key, or null.
	 */
	private function firstWritableAddressBookKey(): ?string {
		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			if (($book->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
				continue;
			}

			return (string)$book->getKey();
		}

		return null;
	}//end firstWritableAddressBookKey()
}//end class
