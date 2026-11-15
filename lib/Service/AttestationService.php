<?php

/**
 * Starts confirmation rounds: one request per entry and owner.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 * @spec      openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates a confirmation round over an organisation's usages or products.
 *
 * Each entry's owners are resolved to Nextcloud users: a usage's business and
 * technical owner, a product's contact person. Every entry with at least one
 * such user gets one request per user; an entry without one is reported as
 * unassigned. The objects are written without RBAC, so the caller must have
 * been checked with AttestationRoundAccess first.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 */
class AttestationService {

	/**
	 * Per entry type: the field naming the organisation, and the owner fields.
	 *
	 * @var array<string, array{organisation: string, owners: array<int, string>}>
	 */
	public const SCOPES = [
		'usage'  => ['organisation' => 'consumer', 'owners' => ['businessOwner', 'technicalOwner']],
		'module' => ['organisation' => 'provider', 'owners' => ['contactPerson']],
	];

	/**
	 * The most entries one round reads.
	 */
	private const ENTRY_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectTypeLocatorInterface  $locator   The register and schema lookups.
	 * @param MaintenanceRecipientService $owners    Resolves contact persons to users.
	 * @param ContainerInterface          $container The DI container, for OpenRegister's ObjectService.
	 * @param LoggerInterface             $logger    The logger.
	 */
	public function __construct(
		private readonly ObjectTypeLocatorInterface $locator,
		private readonly MaintenanceRecipientService $owners,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister's ObjectService, once resolved.
	 *
	 * @var ObjectServiceInterface|null
	 */
	private ?ObjectServiceInterface $resolvedObjectService = null;

	/**
	 * Start a round and create its requests.
	 *
	 * @param string $scopeSchema    `usage` or `module`.
	 * @param string $organisationId The organisation whose entries the round covers.
	 * @param string $deadline       The deadline, as YYYY-MM-DD.
	 * @param string $name           The round's name.
	 * @param string $startedBy      The user who starts it.
	 *
	 * @return array{round: array<string, mixed>, requestCount: int, unassigned: array<int, array{entryId: string, entryName: string}>}
	 *
	 * @throws InvalidArgumentException When the scope, organisation or deadline is not valid.
	 * @throws RuntimeException         When OpenRegister or the schemas are not available.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
	 */
	public function start(string $scopeSchema, string $organisationId, string $deadline, string $name, string $startedBy): array {
		$round = [
			'name'              => $name,
			'deadline'          => $deadline,
			'scopeSchema'       => $scopeSchema,
			'scopeOrganisation' => $organisationId,
			'startedBy'         => $startedBy,
			'status'            => 'open',
			'requestCount'      => 0,
			'answeredCount'     => 0,
			'unassigned'        => [],
		];

		return $this->open(round: $round);
	}//end start()

	/**
	 * Validate a new round, then save it and one request per entry and owner user.
	 *
	 * @param array<string, mixed> $round The round as started, before its counts are known.
	 *
	 * @return array{round: array<string, mixed>, requestCount: int, unassigned: array<int, array{entryId: string, entryName: string}>}
	 *
	 * @throws InvalidArgumentException When the scope, organisation or deadline is not valid.
	 * @throws RuntimeException         When OpenRegister or the schemas are not available.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
	 */
	private function open(array $round): array {
		self::assertValid(round: $round);
		$this->objectService();

		[$pairs, $unassigned] = $this->assign(round: $round);
		if ($round['name'] === '') {
			$round['name'] = $round['deadline'];
		}

		$round['requestCount'] = count($pairs);
		$round['unassigned']   = $unassigned;
		$round['id']           = $this->save(type: 'attestationRound', object: $round);

		foreach ($pairs as $pair) {
			$this->save(type: 'attestationRequest', object: self::requestRecord(round: $round, pair: $pair));
		}

		return ['round' => $round, 'requestCount' => count($pairs), 'unassigned' => $unassigned];
	}//end open()

	/**
	 * Pair every entry of the round's scope with its owner users.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: array<int, array{entryId: string, entryName: string}>}
	 *     The pairs (entryId, entryName, uid, contact), and the entries without an owner user.
	 */
	private function assign(array $round): array {
		$scopeSchema = (string) $round['scopeSchema'];
		$entries     = $this->entries(scopeSchema: $scopeSchema, organisationId: (string) $round['scopeOrganisation']);
		$owners      = self::ownerContactIds(entries: $entries, fields: self::SCOPES[$scopeSchema]['owners']);
		$contactIds  = array_values(array_unique(array_merge([], ...array_values($owners))));
		$userIds     = $this->owners->contactUserIds(objectService: $this->objectService(), contactIds: $contactIds);

		$pairs      = [];
		$unassigned = [];
		foreach ($entries as $entryId => $entry) {
			$entryName = self::entryName(entry: $entry, entryId: (string) $entryId);
			$assignees = self::assignees(contactIds: $owners[$entryId], userIds: $userIds);
			if ($assignees === []) {
				$unassigned[] = ['entryId' => $entryId, 'entryName' => $entryName];
				continue;
			}

			foreach ($assignees as $uid => $contactId) {
				$pairs[] = ['entryId' => $entryId, 'entryName' => $entryName, 'uid' => (string) $uid, 'contact' => $contactId];
			}
		}

		return [$pairs, $unassigned];
	}//end assign()

	/**
	 * The users an entry's owner contacts resolve to, each with the first contact that named them.
	 *
	 * @param array<int, string>    $contactIds The entry's owner contact ids.
	 * @param array<string, string> $userIds    User id per contact id.
	 *
	 * @return array<string, string> Contact id per user id.
	 */
	private static function assignees(array $contactIds, array $userIds): array {
		$assignees = [];
		foreach ($contactIds as $contactId) {
			$uid = ($userIds[$contactId] ?? null);
			if ($uid !== null && isset($assignees[$uid]) === false) {
				$assignees[$uid] = $contactId;
			}
		}

		return $assignees;
	}//end assignees()

	/**
	 * The request object for one entry and owner user of a saved round.
	 *
	 * @param array<string, mixed>  $round The saved round, with its id.
	 * @param array<string, string> $pair  The entry and owner user.
	 *
	 * @return array<string, mixed> The request.
	 */
	private static function requestRecord(array $round, array $pair): array {
		return [
			'round'             => $round['id'],
			'scopeOrganisation' => $round['scopeOrganisation'],
			'entrySchema'       => $round['scopeSchema'],
			'entryId'           => $pair['entryId'],
			'entryName'         => $pair['entryName'],
			'assigneeUserId'    => $pair['uid'],
			'assigneeContact'   => $pair['contact'],
			'deadline'          => $round['deadline'],
			'status'            => 'pending',
		];
	}//end requestRecord()

	/**
	 * Refuse a scope, organisation or deadline the round cannot use.
	 *
	 * @param array<string, mixed> $round The round, with its scopeSchema, scopeOrganisation and deadline.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming what is not valid.
	 */
	private static function assertValid(array $round): void {
		if (array_key_exists($round['scopeSchema'], self::SCOPES) === false) {
			throw new InvalidArgumentException('scopeSchema must be usage or module');
		}

		if ($round['scopeOrganisation'] === '') {
			throw new InvalidArgumentException('organisation is required');
		}

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $round['deadline'], $parts) !== 1
			|| checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) === false
		) {
			throw new InvalidArgumentException('deadline must be a date as YYYY-MM-DD');
		}
	}//end assertValid()

	/**
	 * OpenRegister's ObjectService, resolved once.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): ObjectServiceInterface {
		if ($this->resolvedObjectService !== null) {
			return $this->resolvedObjectService;
		}

		try {
			$service = $this->container->get(ObjectServiceInterface::class);
		} catch (Throwable $e) {
			$this->logger->warning('[AttestationService] ObjectService not resolvable: ' . get_class($e));
			$service = null;
		}

		if (($service instanceof ObjectServiceInterface) === false) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$this->resolvedObjectService = $service;
		return $service;
	}//end objectService()

	/**
	 * The organisation's entries of the scope, by id.
	 *
	 * @param string $scopeSchema    The entry type.
	 * @param string $organisationId The organisation.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws RuntimeException When the entry type is not configured.
	 */
	private function entries(string $scopeSchema, string $organisationId): array {
		[$register, $schema] = $this->location(type: $scopeSchema);
		$query = ['register' => $register, 'schema' => $schema, '_limit' => self::ENTRY_LIMIT];
		$query[self::SCOPES[$scopeSchema]['organisation']] = $organisationId;
		$found = $this->objectService()->searchObjects(
			query: $query,
			_rbac: false,
			_multitenancy: false
		);

		$entries = [];
		foreach ((array) $found as $object) {
			$data = $object->getObject();
			$id   = (string) ($object->getUuid() ?? ($data['id'] ?? ''));
			if ($id !== '') {
				$entries[$id] = $data;
			}
		}

		return $entries;
	}//end entries()

	/**
	 * The owner contact ids of each entry, in field order, each once.
	 *
	 * @param array<string, array<string, mixed>> $entries The entries by id.
	 * @param array<int, string>                  $fields  The owner fields.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function ownerContactIds(array $entries, array $fields): array {
		$owners = [];
		foreach ($entries as $entryId => $entry) {
			$ids = [];
			foreach ($fields as $field) {
				$id = MaintenanceRecipientService::referenceId(value: ($entry[$field] ?? null));
				if ($id !== null && in_array($id, $ids, true) === false) {
					$ids[] = $id;
				}
			}

			$owners[$entryId] = $ids;
		}

		return $owners;
	}//end ownerContactIds()

	/**
	 * A name for an entry in lists and notifications.
	 *
	 * @param array<string, mixed> $entry   The entry.
	 * @param string               $entryId Its id.
	 *
	 * @return string
	 */
	private static function entryName(array $entry, string $entryId): string {
		foreach (['name', 'title'] as $field) {
			if (is_string($entry[$field] ?? null) === true && trim($entry[$field]) !== '') {
				return trim($entry[$field]);
			}
		}

		return (self::moduleName(entry: $entry) ?? $entryId);
	}//end entryName()

	/**
	 * The name of the product an entry points to, when it carries one.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string|null
	 */
	private static function moduleName(array $entry): ?string {
		$module = ($entry['module'] ?? null);
		if (is_array($module) === true && is_string($module['name'] ?? null) === true && $module['name'] !== '') {
			return $module['name'];
		}

		return null;
	}//end moduleName()

	/**
	 * Save one object without RBAC and return its id.
	 *
	 * @param string               $type   The object type.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The saved object's id.
	 *
	 * @throws RuntimeException When the type is not configured.
	 */
	private function save(string $type, array $object): string {
		[$register, $schema] = $this->location(type: $type);
		$saved = $this->objectService()->saveObject(object: $object, register: $register, schema: $schema, _rbac: false, _multitenancy: false);

		return self::savedId(saved: $saved);
	}//end save()

	/**
	 * The id of a saved object.
	 *
	 * @param ObjectEntityInterface $saved The saved object.
	 *
	 * @return string
	 */
	private static function savedId(ObjectEntityInterface $saved): string {
		return (string) ($saved->getUuid() ?? ($saved->getObject()['id'] ?? ''));
	}//end savedId()

	/**
	 * The register and schema ids of an object type.
	 *
	 * @param string $type The object type.
	 *
	 * @return array{0: int, 1: int}
	 *
	 * @throws RuntimeException When either is not configured.
	 */
	private function location(string $type): array {
		$register = $this->locator->getRegisterIdForObjectType($type);
		$schema   = $this->locator->getSchemaIdForObjectType($type);
		if ($register === null || $schema === null) {
			throw new RuntimeException($type . ' is not configured');
		}

		return [$register, $schema];
	}//end location()
}//end class
