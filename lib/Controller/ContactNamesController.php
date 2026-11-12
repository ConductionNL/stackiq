<?php

/**
 * Contact Names Controller for Stackiq
 *
 * Gives the display names of contact persons, read from the signed-in user's
 * own Nextcloud address books, for the owner column of the usage list.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The display names of contact persons, for a signed-in user.
 *
 * A contact person in OpenRegister carries only a `contactsUid`; the name
 * lives in Nextcloud Contacts (the CMDB import puts its owners in the
 * importing admin's "Stackiq CMDB owners" address book). This endpoint reads
 * the contact persons with the caller's own OpenRegister rights, then looks
 * each `contactsUid` up, by exact UID, in the caller's own and shared address
 * books only; the system principal's books (the local system address book
 * and the books synced from trusted servers) never count, so a contactsUid
 * set to a local or remote user id names nobody. A name is therefore returned only to someone who can
 * already read both the contact person and the contact. Nothing is stored,
 * and there is no public route.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
 */
class ContactNamesController extends Controller {

	/**
	 * The most contact persons one request may name; a list page shows at most this many rows.
	 */
	public const MAX_IDS = 100;

	/**
	 * Constructor for ContactNamesController.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n Translations for the error messages.
	 * @param SettingsService $settingsService Gives the register and contact person schema.
	 * @param StackiqContactSyncService $contactSync Reads Nextcloud Contacts as the signed-in user.
	 * @param ObjectServiceInterface $objectService OpenRegister object access, with the caller's rights.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly SettingsService $settingsService,
		private readonly StackiqContactSyncService $contactSync,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The display names of the given contact persons.
	 *
	 * API Endpoint: GET /api/contact-names?ids[]=<contact person uuid>
	 *
	 * Answers `{"names": {"<uuid>": "<display name>"}}`. The per-object
	 * check is OpenRegister's: the contact persons are read with the caller's
	 * RBAC and organisation scope, so one the caller may not read is left out,
	 * as is one whose contact is not in the caller's own or shared address
	 * books. At most 120 requests a minute per user (one per list page).
	 *
	 * @return JSONResponse 200 with the names, 400 for more than MAX_IDS ids, 401 when not signed in.
	 *
	 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['message' => $this->l10n->t('Not signed in'), 'error' => 'not-authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$ids = $this->requestedIds();
		if (count($ids) > self::MAX_IDS) {
			return $this->tooManyIds();
		}

		return new JSONResponse(['names' => (object)$this->namesOf(ids: $ids)], Http::STATUS_OK);
	}//end index()

	/**
	 * The 400 answer for more than MAX_IDS ids.
	 *
	 * @return JSONResponse
	 */
	private function tooManyIds(): JSONResponse {
		return new JSONResponse(
			['message' => $this->l10n->t('At most %s contact persons per request', [self::MAX_IDS]), 'error' => 'too-many-ids'],
			Http::STATUS_BAD_REQUEST
		);
	}//end tooManyIds()

	/**
	 * The display names of the requested contact persons the caller may read; none without Contacts.
	 *
	 * @param array<int, string> $ids Contact person uuids, lower case.
	 *
	 * @return array<string, string> uuid => display name.
	 */
	private function namesOf(array $ids): array {
		if ($ids === [] || $this->contactSync->isAvailable() === false) {
			return [];
		}

		$names = [];
		foreach ($this->contactsUidsOf(ids: $ids) as $uuid => $contactsUid) {
			$name = $this->contactName(contactsUid: $contactsUid);
			if ($name !== '') {
				$names[$uuid] = $name;
			}
		}

		return $names;
	}//end namesOf()

	/**
	 * The display name of a contact in the caller's own or shared address books, or ''.
	 *
	 * @param string $contactsUid The contact's UID.
	 *
	 * @return string
	 */
	private function contactName(string $contactsUid): string {
		$contact = $this->contactSync->findOwnContactByUid(uid: $contactsUid);

		return self::firstValue(value: ($contact['FN'] ?? ''));
	}//end contactName()

	/**
	 * The distinct, well-formed uuids in the `ids` query parameter, lower case.
	 *
	 * @return array<int, string>
	 */
	private function requestedIds(): array {
		$ids = [];
		foreach (self::rawIds(raw: $this->request->getParam('ids', [])) as $id) {
			if (is_string($id) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
				$ids[strtolower($id)] = true;
			}
		}

		return array_keys($ids);
	}//end requestedIds()

	/**
	 * The `ids` query parameter as a list: one string becomes a list of one, anything else but a list none.
	 *
	 * @param mixed $raw The parameter.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function rawIds(mixed $raw): array {
		if (is_string($raw) === true) {
			return [$raw];
		}

		if (is_array($raw) === false) {
			return [];
		}

		return $raw;
	}//end rawIds()

	/**
	 * The `contactsUid` of each requested contact person the caller may read.
	 *
	 * Fails closed: without a configured register and contact person schema,
	 * or when OpenRegister cannot be read, no contact person is resolved.
	 *
	 * @param array<int, string> $ids Contact person uuids, lower case.
	 *
	 * @return array<string, string> uuid => contactsUid.
	 */
	private function contactsUidsOf(array $ids): array {
		$source = $this->contactPersonSource();
		if ($source === null) {
			return [];
		}

		$results = $this->readContactPersons(source: $source, ids: $ids);
		if ($results === null) {
			return [];
		}

		return self::requestedUids(results: $results, ids: $ids);
	}//end contactsUidsOf()

	/**
	 * The register and contact person schema, or null after warning that they are not configured.
	 *
	 * @return array{register: int, schema: int}|null
	 */
	private function contactPersonSource(): ?array {
		$config   = $this->settingsService->getVoorzieningenConfig();
		$register = ($config['register'] ?? null);
		$schema   = ($config[SettingsService::LEGACY_SCHEMA_KEY['contactPerson']] ?? null);
		if (empty($register) === true || empty($schema) === true) {
			$this->logger->warning('[ContactNames] The register or the contact person schema is not configured; no names are given');
			return null;
		}

		return ['register' => (int)$register, 'schema' => (int)$schema];
	}//end contactPersonSource()

	/**
	 * Read the requested contact persons with the caller's own rights.
	 *
	 * @param array{register: int, schema: int} $source The register and contact person schema.
	 * @param array<int, string>                $ids    Contact person uuids, lower case.
	 *
	 * @return array<int, mixed>|null The records, or null when OpenRegister cannot be read.
	 */
	private function readContactPersons(array $source, array $ids): ?array {
		try {
			// RBAC and multitenancy stay at their defaults: the caller's own rights.
			// The ids go in the query as `_ids`: OpenRegister's single
			// register+schema path reads that key and ignores the `ids` argument.
			$result = $this->objectService->searchObjectsPaginated(
				query: ['@self' => $source, '_ids' => $ids, '_limit' => self::MAX_IDS]
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ContactNames] The contact persons could not be read', ['exception' => $e]);
			return null;
		}

		return ($result['results'] ?? []);
	}//end readContactPersons()

	/**
	 * The contactsUid of each requested contact person among the records.
	 *
	 * The ids filter is OpenRegister's; a record it did not ask for is never named.
	 *
	 * @param iterable<mixed>    $results The records read.
	 * @param array<int, string> $ids     Contact person uuids, lower case.
	 *
	 * @return array<string, string> uuid => contactsUid.
	 */
	private static function requestedUids(iterable $results, array $ids): array {
		$uids = [];
		foreach ($results as $entry) {
			[$uuid, $contactsUid] = self::uuidAndContactsUid(data: self::objectData(entry: $entry));
			if ($contactsUid !== '' && in_array($uuid, $ids, true) === true) {
				$uids[$uuid] = $contactsUid;
			}
		}

		return $uids;
	}//end requestedUids()

	/**
	 * A contact person record's uuid, lower case, and its trimmed contactsUid.
	 *
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function uuidAndContactsUid(array $data): array {
		return [
			strtolower((string)($data['id'] ?? ($data['@self']['id'] ?? ''))),
			trim((string)($data['contactsUid'] ?? '')),
		];
	}//end uuidAndContactsUid()

	/**
	 * The data of one search result, an entity or an array.
	 *
	 * @param mixed $entry The search result.
	 *
	 * @return array<string, mixed>
	 */
	private static function objectData(mixed $entry): array {
		if (is_array($entry) === true) {
			return $entry;
		}

		if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
			$data = $entry->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end objectData()

	/**
	 * The first value of a vCard property, which the contacts manager may give as a list.
	 *
	 * @param mixed $value The property value.
	 *
	 * @return string
	 */
	private static function firstValue(mixed $value): string {
		if (is_array($value) === true) {
			$value = (reset($value) ?? '');
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end firstValue()
}//end class
