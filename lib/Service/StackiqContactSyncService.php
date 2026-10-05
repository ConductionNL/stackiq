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

		$contacts = [];
		foreach ($results as $result) {
			$uid = ($result['UID'] ?? null);
			if ($uid === null) {
				continue;
			}

			$contacts[] = [
				'uid' => $uid,
				'name' => $this->firstValue(value: ($result['FN'] ?? '')),
				'email' => $this->firstValue(value: ($result['EMAIL'] ?? '')),
				'phone' => $this->firstValue(value: ($result['TEL'] ?? '')),
				'org' => $this->firstValue(value: ($result['ORG'] ?? '')),
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
	 * Like syncToContacts(), an existing contact (by `contactsUid`, then by
	 * e-mail) is reused wherever it lives. A new contact goes into the
	 * address book with this URI, which is created with the display name
	 * when the user has none, and never into the user's own first writable
	 * address book.
	 *
	 * @param string $objectType The relationship type ('contactPerson'|'organization').
	 * @param array<string, mixed> $record The relationship record.
	 * @param string $addressBookUri The address book's URI, stable across languages.
	 * @param string $displayName The display name for a new address book.
	 *
	 * @return ?string The contacts UID, or null when it could not be resolved or created.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function syncToNamedAddressBook(string $objectType, array $record, string $addressBookUri, string $displayName): ?string {
		if ($this->isAvailable() === false) {
			return null;
		}

		$existingUid = (string)($record['contactsUid'] ?? '');
		if ($existingUid !== '' && $this->findContactByUid(uid: $existingUid) !== null) {
			return $existingUid;
		}

		$matched = $this->findContactForRecord(objectType: $objectType, record: $record);
		if ($matched !== null) {
			return (string)($matched['UID'] ?? '');
		}

		$properties = $this->recordToVCard(objectType: $objectType, record: $record);
		if (($properties['FN'] ?? '') === '') {
			$this->logger->warning('[StackiqContactSync] Record has no identity to create a contact from', ['objectType' => $objectType]);
			return null;
		}

		try {
			$backend = $this->container?->get(static::CARDDAV_BACKEND_CLASS);
			$uid = $this->userSession?->getUser()?->getUID();
			if (is_object($backend) === false || $uid === null) {
				$this->logger->warning('[StackiqContactSync] No CardDAV backend or no user; cannot create the contact', ['objectType' => $objectType]);
				return null;
			}

			$addressBookId = $this->namedAddressBookId(
				backend: $backend,
				principal: 'principals/users/' . $uid,
				uri: $addressBookUri,
				displayName: $displayName
			);
			$contactUid = self::newUid();
			$backend->createCard($addressBookId, $contactUid . '.vcf', self::serialiseVCard(uid: $contactUid, properties: $properties));
		} catch (Throwable $e) {
			$this->logger->warning(
				'[StackiqContactSync] The contact could not be created in the named address book',
				['objectType' => $objectType, 'exception' => get_class($e)]
			);
			return null;
		}

		return $contactUid;
	}//end syncToNamedAddressBook()

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
	 * A vCard 3.0 for a new contact, from the property set recordToVCard() builds.
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
	 * The contacts whose display name (FN) is exactly this one, case-insensitive.
	 *
	 * @param string $displayName The display name.
	 *
	 * @return array<int, array<string, mixed>> The contacts as IManager::search() returns them.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	public function findContactsByDisplayName(string $displayName): array {
		$needle = mb_strtolower(trim($displayName));
		if ($needle === '' || $this->isAvailable() === false) {
			return [];
		}

		$found = [];
		foreach ($this->contactsManager->search($displayName, ['FN'], ['limit' => 50]) as $result) {
			if (mb_strtolower(trim($this->firstValue(value: ($result['FN'] ?? '')))) === $needle) {
				$found[] = $result;
			}
		}

		return $found;
	}//end findContactsByDisplayName()

	/**
	 * Add the e-mail address and phone number a contact lacks; a value it has is never replaced.
	 *
	 * The contact is one IManager::search() returned, so it carries its URI
	 * and address book key. A contact in an address book that cannot be
	 * written (the system address book) is left as it is.
	 *
	 * @param array<string, mixed> $contact The contact.
	 * @param array{EMAIL?: string, TEL?: string} $channels The values to add when the contact has none.
	 *
	 * @return bool Whether the contact was updated.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
	 */
	public function completeContact(array $contact, array $channels): bool {
		$uri = (string)($contact['URI'] ?? '');
		$addressBookKey = (string)($contact['addressbook-key'] ?? '');
		if ($uri === '' || $addressBookKey === '' || $this->isAvailable() === false) {
			return false;
		}

		$properties = [];
		foreach (['EMAIL', 'TEL'] as $name) {
			$value = trim((string)($channels[$name] ?? ''));
			if ($value !== '' && $this->firstValue(value: ($contact[$name] ?? '')) === '') {
				$properties[$name] = $value;
			}
		}

		if ($properties === []) {
			return false;
		}

		try {
			$this->contactsManager->createOrUpdate(array_merge(['URI' => $uri], $properties), $addressBookKey);
		} catch (Throwable $e) {
			$this->logger->info('[StackiqContactSync] The contact could not be completed', ['exception' => get_class($e)]);
			return false;
		}

		return true;
	}//end completeContact()

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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complexity 10, exactly at the threshold. The
	 * branches are the documented identity-match cascade: Contacts API availability, then e-mail,
	 * then — for organisations only — CBS and KvK code as fallbacks, each with an
	 * empty/absent-value guard so a blank legacy field can never match an unrelated contact. The
	 * cascade order is the specified behaviour and is clearer read top-to-bottom in one method.
	 */
	public function findContactForRecord(string $objectType, array $record): ?array {
		if ($this->isAvailable() === false) {
			return null;
		}

		$email = trim((string)($record['e-mailadres'] ?? $record['email'] ?? ''));
		if ($email !== '') {
			$results = $this->contactsManager->search($email, ['EMAIL'], ['limit' => 25]);
			foreach ($results as $result) {
				if ($this->valueMatches(value: ($result['EMAIL'] ?? ''), needle: $email) === true) {
					return $result;
				}
			}
		}

		// Organisations: fall back to CBS/KvK code stored in the ORG/X-KvK field.
		if ($objectType === 'organization') {
			$cbsCode = trim((string)($record['cbsCode'] ?? ''));
			if ($cbsCode !== '') {
				$results = $this->contactsManager->search($cbsCode, ['ORG', 'X-KVK', 'NICKNAME'], ['limit' => 25]);
				foreach ($results as $result) {
					if ($this->valueMatches(value: ($result['X-KVK'] ?? ''), needle: $cbsCode) === true
						|| $this->valueMatches(value: ($result['NICKNAME'] ?? ''), needle: $cbsCode) === true
					) {
						return $result;
					}
				}
			}
		}

		return null;
	}//end findContactForRecord()

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

		$properties = $this->recordToVCard(objectType: $objectType, record: $record);
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
	 * Map a relationship record's legacy identity fields to vCard properties.
	 *
	 * @param string $objectType The relationship type.
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The vCard property set.
	 */
	private function recordToVCard(string $objectType, array $record): array {
		// The identity block differs per kind; the contact channels below are
		// shared by both.
		$properties = $this->identityProperties(objectType: $objectType, record: $record);

		$email = trim((string)($record['e-mailadres'] ?? $record['email'] ?? ''));
		if ($email !== '') {
			$properties['EMAIL'] = $email;
		}

		$phone = trim((string)($record['telefoonnummer'] ?? ''));
		if ($phone !== '') {
			$properties['TEL'] = $phone;
		}

		return $properties;
	}//end recordToVCard()

	/**
	 * Select and build the kind-specific vCard identity properties.
	 *
	 * @param string $objectType The relationship type.
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The identity property set.
	 */
	private function identityProperties(string $objectType, array $record): array {
		if ($objectType === 'organization') {
			return $this->organisationIdentityProperties(record: $record);
		}

		return $this->personIdentityProperties(record: $record);
	}//end identityProperties()

	/**
	 * Build the organisation-kind vCard identity properties.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The identity property set.
	 */
	private function organisationIdentityProperties(array $record): array {
		$name = trim((string)($record['name'] ?? ''));
		$properties = [
			'FN' => $name,
			'ORG' => $name,
			'KIND' => 'org',
		];

		$cbsCode = trim((string)($record['cbsCode'] ?? ''));
		if ($cbsCode !== '') {
			$properties['X-KVK'] = $cbsCode;
			$properties['NICKNAME'] = $cbsCode;
		}

		$website = trim((string)($record['website'] ?? ''));
		if ($website !== '') {
			$properties['URL'] = $website;
		}

		return $properties;
	}//end organisationIdentityProperties()

	/**
	 * Build the person-kind vCard identity properties.
	 *
	 * @param array<string, mixed> $record The relationship record.
	 *
	 * @return array<string, mixed> The identity property set.
	 */
	private function personIdentityProperties(array $record): array {
		$voornaam = trim((string)($record['voornaam'] ?? ''));
		$tussenvoegsel = trim((string)($record['tussenvoegsel'] ?? ''));
		$lastName = trim((string)($record['achternaam'] ?? ''));
		$family = trim(trim($tussenvoegsel . ' ' . $lastName));

		$properties = [
			'FN' => trim($voornaam . ' ' . $family),
			// N = Family;Given;Additional;Prefix;Suffix.
			'N' => $family . ';' . $voornaam . ';;;',
		];

		$role = trim((string)($record['role'] ?? ''));
		if ($role !== '') {
			$properties['TITLE'] = $role;
		}

		return $properties;
	}//end personIdentityProperties()

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

	/**
	 * Whether a (possibly multi-valued) vCard property contains the given
	 * needle, case-insensitively.
	 *
	 * @param mixed $value The raw vCard property value.
	 * @param string $needle The needle to compare against.
	 *
	 * @return boolean True when the value equals the needle.
	 */
	private function valueMatches(mixed $value, string $needle): bool {
		$needle = strtolower(trim($needle));
		if ($needle === '') {
			return false;
		}

		if (is_array($value) === true) {
			foreach ($value as $candidate) {
				if (is_array($candidate) === true) {
					$candidate = ($candidate['value'] ?? '');
				}

				if (strtolower(trim((string)$candidate)) === $needle) {
					return true;
				}
			}

			return false;
		}

		return strtolower(trim((string)$value)) === $needle;
	}//end valueMatches()

	/**
	 * Extract the first scalar value from a vCard property that may be an
	 * array (multi-valued / typed) or a string.
	 *
	 * @param mixed $value The raw property value.
	 *
	 * @return string The first scalar value as a string.
	 */
	private function firstValue(mixed $value): string {
		if (is_array($value) === true) {
			$first = ($value[0] ?? '');
			if (is_array($first) === true) {
				return (string)($first['value'] ?? '');
			}

			return (string)$first;
		}

		return (string)$value;
	}//end firstValue()
}//end class
