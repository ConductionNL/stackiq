<?php

/**
 * Contract of StackiqContactSyncService
 *
 * The public surface of StackiqContactSyncService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of StackiqContactSyncService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StackiqContactSyncServiceInterface {

	/**
	 * Find a Nextcloud contact by its exact UID.
	 *
	 * @param string $uid The contact UID.
	 *
	 * @return ?array<string, mixed> The contact, or null when not found.
	 *
	 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
	 */
	public function findContactByUid(string $uid): ?array;

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
	public function findContactForRecord(string $objectType, array $record): ?array;

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
	public function findOwnContactByUid(string $uid): ?array;

	/**
	 * Whether the Nextcloud Contacts integration is available.
	 *
	 * @return boolean True when Contacts is enabled.
	 *
	 * @spec exclude Availability probe, no functional requirement.
	 */
	public function isAvailable(): bool;

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
	public function syncToContacts(string $objectType, array $record): ?string;
}//end interface
