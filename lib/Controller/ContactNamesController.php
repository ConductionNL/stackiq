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
 * each `contactsUid` up in the address books Nextcloud gives the caller. A
 * name is therefore returned only to someone who can already read both the
 * contact person and the contact. Nothing is stored, and there is no public
 * route.
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
	 * as is one whose contact is not in the caller's address books.
	 *
	 * @return JSONResponse 200 with the names, 400 for more than MAX_IDS ids, 401 when not signed in.
	 *
	 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['message' => $this->l10n->t('Not signed in'), 'error' => 'not-authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$ids = $this->requestedIds();
		if (count($ids) > self::MAX_IDS) {
			return new JSONResponse(
				['message' => $this->l10n->t('At most %s contact persons per request', [self::MAX_IDS]), 'error' => 'too-many-ids'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($ids === [] || $this->contactSync->isAvailable() === false) {
			return new JSONResponse(['names' => (object)[]], Http::STATUS_OK);
		}

		$names = [];
		foreach ($this->contactsUidsOf(ids: $ids) as $uuid => $contactsUid) {
			$contact = $this->contactSync->findContactByUid(uid: $contactsUid);
			$name = self::firstValue(value: ($contact['FN'] ?? ''));
			if ($name !== '') {
				$names[$uuid] = $name;
			}
		}

		return new JSONResponse(['names' => (object)$names], Http::STATUS_OK);
	}//end index()

	/**
	 * The distinct, well-formed uuids in the `ids` query parameter, lower case.
	 *
	 * @return array<int, string>
	 */
	private function requestedIds(): array {
		$raw = $this->request->getParam('ids', []);
		if (is_string($raw) === true) {
			$raw = [$raw];
		}

		if (is_array($raw) === false) {
			return [];
		}

		$ids = [];
		foreach ($raw as $id) {
			if (is_string($id) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
				$ids[strtolower($id)] = true;
			}
		}

		return array_keys($ids);
	}//end requestedIds()

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
		$config = $this->settingsService->getVoorzieningenConfig();
		$register = ($config['register'] ?? null);
		$schema = ($config[SettingsService::LEGACY_SCHEMA_KEY['contactPerson']] ?? null);
		if (empty($register) === true || empty($schema) === true) {
			$this->logger->warning('[ContactNames] The register or the contact person schema is not configured; no names are given');
			return [];
		}

		try {
			// RBAC and multitenancy stay at their defaults: the caller's own rights.
			$result = $this->objectService->searchObjectsPaginated(
				query: [
					'@self' => ['register' => (int)$register, 'schema' => (int)$schema],
					'_limit' => self::MAX_IDS,
				],
				ids: $ids
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ContactNames] The contact persons could not be read', ['exception' => $e]);
			return [];
		}

		$uids = [];
		foreach (($result['results'] ?? []) as $entry) {
			$data = self::objectData(entry: $entry);
			$uuid = strtolower((string)($data['id'] ?? ($data['@self']['id'] ?? '')));
			$contactsUid = trim((string)($data['contactsUid'] ?? ''));
			// The ids filter is OpenRegister's; a record it did not ask for is never named.
			if ($contactsUid !== '' && in_array($uuid, $ids, true) === true) {
				$uids[$uuid] = $contactsUid;
			}
		}

		return $uids;
	}//end contactsUidsOf()

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
