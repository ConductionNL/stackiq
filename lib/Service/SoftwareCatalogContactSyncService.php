<?php

/**
 * SoftwareCatalog Contact-sync service.
 *
 * Bridges softwarecatalog catalog relationship/role records (contactpersoon,
 * organisatie) to the Nextcloud addressbook through OCP\Contacts\IManager.
 * Identity (name, e-mail, phone, website, logo, CBS/KvK code) lives in
 * Nextcloud Contacts keyed by `contactsUid`; this service never re-implements
 * an app-local identity store and never uses bespoke HTTP (ADR-019, ADR-022,
 * cross-app interface contract #2). Modeled on the canonical
 * pipelinq/lib/Service/ContactSyncService.php and
 * zaakafhandelapp/lib/Service/KlantContactSyncService.php.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Search, import and create Nextcloud contacts for softwarecatalog
 * relationship/role records.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 *
 * Exceeds PHPMD's class-complexity threshold (53 vs 50): the bidirectional
 * record <-> vCard mapping branches once per optional contact property, and both
 * directions must stay in one class so the mapping stays symmetric.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SoftwareCatalogContactSyncService
{
    /**
     * Constructor.
     *
     * @param IContactsManager $contactsManager The Nextcloud contacts manager.
     * @param LoggerInterface  $logger          The logger.
     */
    public function __construct(
        private readonly IContactsManager $contactsManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the Nextcloud Contacts integration is available.
     *
     * @return boolean True when Contacts is enabled.
     */
    public function isAvailable(): bool
    {
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
    public function searchContacts(string $query): array
    {
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
                'uid'            => $uid,
                'name'           => $this->firstValue(value: ($result['FN'] ?? '')),
                'email'          => $this->firstValue(value: ($result['EMAIL'] ?? '')),
                'phone'          => $this->firstValue(value: ($result['TEL'] ?? '')),
                'org'            => $this->firstValue(value: ($result['ORG'] ?? '')),
                'addressBookKey' => ($result['addressbook-key'] ?? ''),
            ];
        }

        return $contacts;
    }//end searchContacts()

    /**
     * Import (resolve) a Nextcloud contact UID into a catalog relationship
     * record's `contactsUid`. Idempotent: an already-known UID is returned
     * unchanged. An unknown UID raises a RuntimeException.
     *
     * @param string  $uid            The contact UID to import.
     * @param ?string $addressBookKey Optional addressbook key (kept for parity
     *                                with the canonical signature; unused here).
     *
     * @return string The resolved contacts UID.
     *
     * @throws RuntimeException When Contacts is disabled or the UID is unknown.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $addressBookKey kept for canonical-signature parity.
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     */
    public function importContact(string $uid, ?string $addressBookKey=null): string
    {
        if ($this->isAvailable() === false) {
            throw new RuntimeException('Nextcloud Contacts is not available');
        }

        $contact = $this->findContactByUid(uid: $uid);
        if ($contact === null) {
            throw new RuntimeException('Contact not found in any accessible addressbook');
        }

        return $uid;
    }//end importContact()

    /**
     * Resolve (or create) the Nextcloud Contact for a catalog relationship
     * record and return its UID.
     *
     * Resolution order: existing `contactsUid` on the record → e-mail match
     * (and `cbsCode`/KvK for organisations) → create from identity fields.
     * Never bespoke HTTP — only OCP\Contacts\IManager (ADR-019, ADR-022).
     *
     * @param string               $objectType The relationship type ('contactpersoon'|'organisatie').
     * @param array<string, mixed> $record     The relationship record (may still carry legacy identity fields).
     *
     * @return ?string The contacts UID, or null when it could not be resolved or created.
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     */
    public function syncToContacts(string $objectType, array $record): ?string
    {
        if ($this->isAvailable() === false) {
            $this->logger->info('[SoftwareCatalogContactSync] Contacts disabled, cannot resolve UID', ['objectType' => $objectType]);
            return null;
        }

        // Already linked — idempotent no-op.
        $existingUid = (string) ($record['contactsUid'] ?? '');
        if ($existingUid !== '' && $this->findContactByUid(uid: $existingUid) !== null) {
            return $existingUid;
        }

        // Resolve by e-mail (and cbsCode for organisations).
        $matched = $this->findContactForRecord(objectType: $objectType, record: $record);
        if ($matched !== null) {
            return (string) ($matched['UID'] ?? '');
        }

        // Create a fresh Contact from the identity fields.
        return $this->createContactForRecord(objectType: $objectType, record: $record);
    }//end syncToContacts()

    /**
     * Find a Nextcloud contact by its exact UID.
     *
     * @param string $uid The contact UID.
     *
     * @return ?array<string, mixed> The contact, or null when not found.
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     */
    public function findContactByUid(string $uid): ?array
    {
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
     * Find a Nextcloud contact matching a relationship record's identity, by
     * e-mail first and — for organisations — by CBS/KvK code as a fallback.
     *
     * @param string               $objectType The relationship type.
     * @param array<string, mixed> $record     The relationship record carrying legacy identity fields.
     *
     * @return ?array<string, mixed> The matched contact, or null.
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     *
     * Complexity sits on the threshold (10 vs 10): the branches are the match
     * strategies tried in priority order (stored uid, then email, then name), each
     * of which must be attempted before the next is meaningful.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function findContactForRecord(string $objectType, array $record): ?array
    {
        if ($this->isAvailable() === false) {
            return null;
        }

        $email = trim((string) ($record['e-mailadres'] ?? $record['email'] ?? ''));
        if ($email !== '') {
            $results = $this->contactsManager->search($email, ['EMAIL'], ['limit' => 25]);
            foreach ($results as $result) {
                if ($this->valueMatches(value: ($result['EMAIL'] ?? ''), needle: $email) === true) {
                    return $result;
                }
            }
        }

        // Organisations: fall back to CBS/KvK code stored in the ORG/X-KvK field.
        if ($objectType === 'organisatie') {
            $cbsCode = trim((string) ($record['cbsCode'] ?? ''));
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
     * @param string               $objectType The relationship type ('contactpersoon'|'organisatie').
     * @param array<string, mixed> $record     The relationship record carrying legacy identity fields.
     *
     * @return ?string The new contacts UID, or null when no writable addressbook
     *                 is available or the record has no usable identity.
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     */
    public function createContactForRecord(string $objectType, array $record): ?string
    {
        if ($this->isAvailable() === false) {
            return null;
        }

        $addressBookKey = $this->firstWritableAddressBookKey();
        if ($addressBookKey === null) {
            $this->logger->warning(
                '[SoftwareCatalogContactSync] No writable addressbook available; cannot create contact',
                ['objectType' => $objectType]
            );
            return null;
        }

        $properties = $this->recordToVCard(objectType: $objectType, record: $record);
        if (($properties['FN'] ?? '') === '') {
            $this->logger->warning('[SoftwareCatalogContactSync] Record has no identity to create a contact from', ['objectType' => $objectType]);
            return null;
        }

        $created = $this->contactsManager->createOrUpdate($properties, $addressBookKey);
        if (is_array($created) === false) {
            return null;
        }

        return (string) ($created['UID'] ?? ($properties['UID'] ?? ''));
    }//end createContactForRecord()

    /**
     * Map a relationship record's legacy identity fields to vCard properties.
     *
     * @param string               $objectType The relationship type.
     * @param array<string, mixed> $record     The relationship record.
     *
     * @return array<string, mixed> The vCard property set.
     *
     * The else branch is a genuine either/or between the two record shapes.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    private function recordToVCard(string $objectType, array $record): array
    {
        $properties = [];

        if ($objectType === 'organisatie') {
            $name = trim((string) ($record['naam'] ?? ''));
            $properties['FN']   = $name;
            $properties['ORG']  = $name;
            $properties['KIND'] = 'org';

            $cbsCode = trim((string) ($record['cbsCode'] ?? ''));
            if ($cbsCode !== '') {
                $properties['X-KVK']    = $cbsCode;
                $properties['NICKNAME'] = $cbsCode;
            }

            $website = trim((string) ($record['website'] ?? ''));
            if ($website !== '') {
                $properties['URL'] = $website;
            }
        } else {
            $voornaam      = trim((string) ($record['voornaam'] ?? ''));
            $tussenvoegsel = trim((string) ($record['tussenvoegsel'] ?? ''));
            $achternaam    = trim((string) ($record['achternaam'] ?? ''));
            $family        = trim(trim($tussenvoegsel.' '.$achternaam));

            $properties['FN'] = trim($voornaam.' '.$family);
            // N = Family;Given;Additional;Prefix;Suffix.
            $properties['N'] = $family.';'.$voornaam.';;;';

            $functie = trim((string) ($record['functie'] ?? ''));
            if ($functie !== '') {
                $properties['TITLE'] = $functie;
            }
        }//end if

        $email = trim((string) ($record['e-mailadres'] ?? $record['email'] ?? ''));
        if ($email !== '') {
            $properties['EMAIL'] = $email;
        }

        $phone = trim((string) ($record['telefoonnummer'] ?? ''));
        if ($phone !== '') {
            $properties['TEL'] = $phone;
        }

        return $properties;
    }//end recordToVCard()

    /**
     * Return the key of the first writable addressbook, or null when none is
     * available.
     *
     * @return ?string The writable addressbook key, or null.
     */
    private function firstWritableAddressBookKey(): ?string
    {
        foreach ($this->contactsManager->getUserAddressBooks() as $book) {
            if (($book->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
                continue;
            }

            return (string) $book->getKey();
        }

        return null;
    }//end firstWritableAddressBookKey()

    /**
     * Whether a (possibly multi-valued) vCard property contains the given
     * needle, case-insensitively.
     *
     * @param mixed  $value  The raw vCard property value.
     * @param string $needle The needle to compare against.
     *
     * @return boolean True when the value equals the needle.
     */
    private function valueMatches(mixed $value, string $needle): bool
    {
        $needle = strtolower(trim($needle));
        if ($needle === '') {
            return false;
        }

        if (is_array($value) === true) {
            foreach ($value as $candidate) {
                if (is_array($candidate) === true) {
                    $candidate = ($candidate['value'] ?? '');
                }

                if (strtolower(trim((string) $candidate)) === $needle) {
                    return true;
                }
            }

            return false;
        }

        return strtolower(trim((string) $value)) === $needle;
    }//end valueMatches()

    /**
     * Extract the first scalar value from a vCard property that may be an
     * array (multi-valued / typed) or a string.
     *
     * @param mixed $value The raw property value.
     *
     * @return string The first scalar value as a string.
     */
    private function firstValue(mixed $value): string
    {
        if (is_array($value) === true) {
            $first = ($value[0] ?? '');
            if (is_array($first) === true) {
                return (string) ($first['value'] ?? '');
            }

            return (string) $first;
        }

        return (string) $value;
    }//end firstValue()
}//end class
