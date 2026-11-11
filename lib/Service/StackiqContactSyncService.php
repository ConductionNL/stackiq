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
		$existingUid = (string)($record['contactsUid'] ?? '');
		if ($existingUid !== '' && $this->findContactByUid(uid: $existingUid) !== null) {
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

		return $this->namedAddressBookContactByEmail(sync: $sync);
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
		$search = new NamedAddressBookSearch(query: $query, addressBookUri: $addressBookUri);
		$search->properties = $properties;
		return $this->namedAddressBookContacts(search: $search);
	}//end searchNamedAddressBook()

	/**
	 * The contacts one named address book search finds, as searchNamedAddressBook() returns them.
	 *
	 * @param NamedAddressBookSearch $search The search.
	 *
	 * @return array<int, array<string, mixed>> The matching contacts.
	 */
	private function namedAddressBookContacts(NamedAddressBookSearch $search): array {
		$vCard    = new RecordVCard();
		$contacts = [];
		foreach ($this->namedAddressBookResults(search: $search) as $result) {
			$contacts[] = $this->contactSummary(vCard: $vCard, result: $result);
		}

		return $contacts;
	}//end namedAddressBookContacts()

	/**
	 * One search result as a contact: uid, name, first and every e-mail address, address book key.
	 *
	 * @param RecordVCard          $vCard  The vCard value reader.
	 * @param array<string, mixed> $result The search result.
	 *
	 * @return array<string, mixed> The contact.
	 */
	private function contactSummary(RecordVCard $vCard, array $result): array {
		return [
			'uid' => (string)$result['UID'],
			'name' => $vCard->firstValue(value: ($result['FN'] ?? '')),
			'email' => $vCard->firstValue(value: ($result['EMAIL'] ?? '')),
			'emails' => array_map(
				fn (mixed $email): string => $vCard->firstValue(value: [$email]),
				array_values((array)($result['EMAIL'] ?? []))
			),
			'addressBookKey' => (string)($result['addressbook-key'] ?? ''),
		];
	}//end contactSummary()

	/**
	 * The contact in the named address book with the record's e-mail address, or null.
	 *
	 * With a fixed UID the e-mail address matches nothing: the caller
	 * identifies the contact by that UID, and a shared e-mail address must
	 * not merge two of them. Only a card that already has that UID (by URI)
	 * is returned.
	 *
	 * @param NamedAddressBookSync $sync The sync request.
	 *
	 * @return string|null The contact UID.
	 *
	 * @spec openspec/changes/archive/2026-10-30-cmdb-import-owner-identity/tasks.md#task-2
	 */
	private function namedAddressBookContactByEmail(NamedAddressBookSync $sync): ?string {
		if ($sync->fixedUid !== null) {
			return $this->namedAddressBookCardUid(addressBookUri: $sync->addressBookUri, fixedUid: $sync->fixedUid);
		}

		$email = trim((string)($sync->record['e-mailadres'] ?? $sync->record['email'] ?? ''));
		$search = new NamedAddressBookSearch(query: $email, addressBookUri: $sync->addressBookUri);
		$search->properties = ['EMAIL'];
		foreach ($this->namedAddressBookResults(search: $search) as $result) {
			if ((new RecordVCard())->valueMatches(value: ($result['EMAIL'] ?? ''), needle: $email) === true) {
				return (string)$result['UID'];
			}
		}

		return null;
	}//end namedAddressBookContactByEmail()

	/**
	 * The fixed UID, when the named address book already has a card with it.
	 *
	 * A card that already has the fixed UID is the contact: Nextcloud refuses a
	 * second one (UidConflict), and the contacts search may not show it yet.
	 * Looked up by URI, not by search.
	 *
	 * @param string $addressBookUri The address book's URI.
	 * @param string $fixedUid       The UID the caller gives the contact.
	 *
	 * @return string|null The fixed UID, or null when there is no such card.
	 */
	private function namedAddressBookCardUid(string $addressBookUri, string $fixedUid): ?string {
		$key = $this->existingNamedAddressBookKey(addressBookUri: $addressBookUri);
		$backend = null;
		if ($key !== null) {
			$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
		}

		if (is_object($backend) === true && is_array($backend->getCard((int)$key, $fixedUid . '.vcf')) === true) {
			return $fixedUid;
		}

		return null;
	}//end namedAddressBookCardUid()

	/**
	 * The raw search results that lie in the user's address book with this URI.
	 *
	 * The contacts manager searches every address book of the user and tags
	 * each result with its address book's key, the CardDAV address book id;
	 * only results with the named address book's id are kept.
	 *
	 * @param NamedAddressBookSearch $search The search.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function namedAddressBookResults(NamedAddressBookSearch $search): array {
		if ($this->isAvailable() === false || trim($search->query) === '') {
			return [];
		}

		$key = $this->existingNamedAddressBookKey(addressBookUri: $search->addressBookUri);
		if ($key === null) {
			return [];
		}

		$results = $this->contactsManager->search($search->query, $search->properties, ['limit' => 50]);
		return $this->resultsInAddressBook(results: $results, key: $key);
	}//end namedAddressBookResults()

	/**
	 * The results with a UID that come from the address book with this key.
	 *
	 * @param iterable $results The contacts search results.
	 * @param string   $key     The CardDAV address book id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function resultsInAddressBook(iterable $results, string $key): array {
		$found = [];
		foreach ($results as $result) {
			if (isset($result['UID']) === true && (string)($result['addressbook-key'] ?? '') === $key) {
				$found[] = $result;
			}
		}

		return $found;
	}//end resultsInAddressBook()

	/**
	 * The key of the signed-in user's address book with this URI, without creating it.
	 *
	 * @param string $addressBookUri The address book's URI.
	 *
	 * @return string|null The CardDAV address book id as a string, or null when there is none.
	 */
	private function existingNamedAddressBookKey(string $addressBookUri): ?string {
		try {
			$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
			$uid = $this->userSession?->getUser()?->getUID();
			if (is_object($backend) === false || $uid === null) {
				return null;
			}

			$book = $backend->getAddressBooksByUri('principals/users/' . $uid, $addressBookUri);
		} catch (Throwable $e) {
			$this->logger->warning('[StackiqContactSync] The named address book could not be read', ['exception' => get_class($e)]);
			return null;
		}

		if (is_array($book) === false || isset($book['id']) === false) {
			return null;
		}

		return (string)$book['id'];
	}//end existingNamedAddressBookKey()

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
			if (is_scalar($value) === false || (string)$value === '' || in_array($name, ['UID', 'VERSION'], true) === true) {
				continue;
			}

			$text = self::escapeVCardText(text: (string)$value);
			if ($name === 'N') {
				$text = implode(';', array_map(static fn (string $part): string => self::escapeVCardText(text: $part), explode(';', (string)$value)));
			}

			$lines[] = self::foldVCardLine(line: strtoupper((string)$name) . ':' . $text);
		}

		$lines[] = 'END:VCARD';

		return implode("\r\n", $lines) . "\r\n";
	}//end serialiseVCard()

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
		foreach ($results as $result) {
			if (($result['UID'] ?? '') === $uid) {
				return $result;
			}
		}

		return null;
	}//end findContactByUid()

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
		foreach ($this->contactsManager->search($uid, ['UID'], $options) as $result) {
			$key = (string)($result['addressbook-key'] ?? '');
			if (($result['UID'] ?? '') === $uid && in_array($key, $keys, true) === true) {
				return $result;
			}
		}

		return null;
	}//end findOwnContactByUid()

	/**
	 * The keys of the signed-in user's own and shared address books.
	 *
	 * @return array<int, string> CardDAV address book ids as strings; empty without a backend or a user.
	 */
	private function userAddressBookKeys(): array {
		try {
			$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
			$uid = $this->userSession?->getUser()?->getUID();
			if (is_object($backend) === false || $uid === null) {
				return [];
			}

			$books = $backend->getAddressBooksForUser('principals/users/' . $uid);
		} catch (Throwable $e) {
			$this->logger->warning('[StackiqContactSync] The address books of the user could not be read', ['exception' => get_class($e)]);
			return [];
		}

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

		$vCard   = new RecordVCard();
		$results = $this->contactsManager->search($cbsCode, ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]);
		foreach ($results as $result) {
			if ($vCard->valueMatches(value: ($result['X-KVK'] ?? ''), needle: $cbsCode) === true
				|| $vCard->valueMatches(value: ($result['NICKNAME'] ?? ''), needle: $cbsCode) === true
			) {
				return $result;
			}
		}

		return null;
	}//end organisationContactByCode()

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

		$addressBookKey = $this->firstWritableAddressBookKey();
		if ($addressBookKey === null) {
			$this->logger->warning(
				'[StackiqContactSync] No writable addressbook available; cannot create contact',
				['objectType' => $objectType]
			);
			return null;
		}

		$properties = (new RecordVCard())->properties(objectType: $objectType, record: $record);
		if (($properties['FN'] ?? '') === '') {
			$this->logger->warning('[StackiqContactSync] Record has no identity to create a contact from', ['objectType' => $objectType]);
			return null;
		}

		$created = $this->contactsManager->createOrUpdate($properties, $addressBookKey);
		if (is_array($created) === false) {
			return null;
		}

		return (string)($created['UID'] ?? ($properties['UID'] ?? ''));
	}//end createContactForRecord()

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
