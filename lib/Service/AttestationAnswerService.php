<?php

/**
 * Answers confirmation requests: confirm, corrected by an edit, overdue.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 * @spec      openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DomainException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Stackiq\Kernel\Contracts\AttestationAnswerServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * The three ways a request is answered (landscape-owner-attestation D3).
 *
 * Confirm: the request's own assignee sets the entry's `lastConfirmedAt`
 * through a normal save under their own rights, then the request reads
 * confirmed. Corrected: when the assignee saves the entry themselves, their
 * open request for it reads corrected and the entry's `lastConfirmedAt` is
 * set. Overdue: a pending request past its deadline reads overdue; nothing
 * else moves. Each answer updates the round's `answeredCount`. Requests and
 * rounds are written without RBAC: the assignee check, or the job, stands in.
 *
 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */
class AttestationAnswerService implements AttestationAnswerServiceInterface {

	/**
	 * The statuses a request can still be answered in.
	 */
	public const OPEN = ['pending', 'overdue'];

	/**
	 * The statuses that count as answered.
	 */
	public const ANSWERED = ['confirmed', 'corrected'];

	/**
	 * Fields a save writes by itself (the confirmation, the derived scores and
	 * stamps, the metadata). A save that changes only these is no correction.
	 */
	public const DERIVED_FIELDS = [
		'lastConfirmedAt',
		'qualityScore',
		'qualityStatus',
		'bbnLevel',
		'classifiedAt',
		'classifiedBy',
		'@self',
		'id',
		'uuid',
		'updated',
		'created',
	];

	/**
	 * The most requests one search reads.
	 */
	private const REQUEST_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectTypeLocatorInterface $locator   Register and schema per object type.
	 * @param ContainerInterface         $container Resolves OpenRegister's ObjectService.
	 * @param ITimeFactory               $time      The clock.
	 * @param LoggerInterface            $logger    The logger.
	 */
	public function __construct(
		private readonly ObjectTypeLocatorInterface $locator,
		private readonly ContainerInterface $container,
		private readonly ITimeFactory $time,
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
	 * Confirm a request: the entry's lastConfirmedAt is now and the request reads confirmed.
	 *
	 * @param string $requestId The request id.
	 * @param string $uid       The signed-in user.
	 *
	 * @return array<string, mixed> The confirmed request.
	 *
	 * @throws OutOfBoundsException      When the request or its entry does not exist.
	 * @throws DomainException           When the user is not the request's assignee.
	 * @throws UnexpectedValueException  When the request was already answered.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function confirm(string $requestId, string $uid): array {
		$this->objectService();
		$request = $this->findRequest(requestId: $requestId);

		if ($uid === '' || (string) ($request['assigneeUserId'] ?? '') !== $uid) {
			throw new DomainException('Only the owner this request was sent to can answer it');
		}

		if (self::isOpen(request: $request) === false) {
			throw new UnexpectedValueException('This request was already answered');
		}

		$now = $this->now();
		$this->confirmEntry(request: $request, now: $now);

		$request['status']      = 'confirmed';
		$request['respondedAt'] = $now;
		$saved = $this->save(type: 'attestationRequest', object: $request, uuid: $requestId);
		$this->recount(roundId: (string) ($request['round'] ?? ''));

		return $saved;
	}//end confirm()

	/**
	 * The assignee saved the entry themselves: their open requests for it read corrected.
	 *
	 * The entry's lastConfirmedAt is set when at least one request was marked;
	 * that write changes only a derived field, so it answers nothing again.
	 *
	 * @param string $entrySchema The entry type (usage or module).
	 * @param string $entryId     The entry id.
	 * @param string $uid         The user who saved.
	 *
	 * @return int How many requests were marked corrected.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function markCorrected(string $entrySchema, string $entryId, string $uid): int {
		if ($uid === '' || $entryId === '' || array_key_exists($entrySchema, AttestationService::SCOPES) === false) {
			return 0;
		}

		$this->objectService();
		$now = $this->now();
		$ref = ['entrySchema' => $entrySchema, 'entryId' => $entryId, 'assigneeUserId' => $uid];
		[$marked, $rounds] = $this->correctOpenRequests(ref: $ref, now: $now);
		if ($marked === 0) {
			return 0;
		}

		try {
			$this->stampEntry(ref: $ref, now: $now);
		} catch (Throwable $e) {
			$this->logger->warning('[AttestationAnswerService] could not set lastConfirmedAt on ' . $entrySchema . ' ' . $entryId . ': ' . $e->getMessage());
		}

		$this->recountAll(rounds: $rounds);
		return $marked;
	}//end markCorrected()

	/**
	 * Every pending request past its deadline reads overdue. Idempotent: only pending moves.
	 *
	 * @return int How many requests became overdue.
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function markOverdue(): int {
		$this->objectService();
		$today  = $this->time->now()->format('Y-m-d');
		$rounds = [];
		$moved  = 0;
		foreach ($this->requests(filters: ['status' => 'pending']) as $id => $request) {
			$deadline = substr((string) ($request['deadline'] ?? ''), 0, 10);
			if ((string) ($request['status'] ?? '') !== 'pending' || $deadline === '' || $deadline >= $today) {
				continue;
			}

			$request['status'] = 'overdue';
			$this->save(type: 'attestationRequest', object: $request, uuid: $id);
			$rounds[(string) ($request['round'] ?? '')] = true;
			$moved++;
		}

		$this->recountAll(rounds: $rounds);
		return $moved;
	}//end markOverdue()

	/**
	 * Whether an update changed anything a person typed, for callers that hold the contract.
	 *
	 * @param array<string, mixed> $before The entry before the save.
	 * @param array<string, mixed> $after  The entry after the save.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public function isChangedByHand(array $before, array $after): bool {
		return self::changedByHand(before: $before, after: $after);
	}//end isChangedByHand()

	/**
	 * Whether an update changed anything a person typed: a field outside DERIVED_FIELDS.
	 *
	 * @param array<string, mixed> $before The entry before the save.
	 * @param array<string, mixed> $after  The entry after the save.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
	 */
	public static function changedByHand(array $before, array $after): bool {
		foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
			if (in_array($field, self::DERIVED_FIELDS, true) === true) {
				continue;
			}

			if (json_encode($before[$field] ?? null) !== json_encode($after[$field] ?? null)) {
				return true;
			}
		}

		return false;
	}//end changedByHand()

	/**
	 * Whether a request still waits for an answer.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return bool
	 */
	private static function isOpen(array $request): bool {
		return in_array((string) ($request['status'] ?? ''), self::OPEN, true);
	}//end isOpen()

	/**
	 * Mark the assignee's open requests for one entry corrected.
	 *
	 * @param array{entrySchema: string, entryId: string, assigneeUserId: string} $ref The entry and the assignee.
	 * @param string                                                             $now The answer time.
	 *
	 * @return array{0: int, 1: array<string, bool>} How many were marked, and the rounds they belong to.
	 */
	private function correctOpenRequests(array $ref, string $now): array {
		$rounds = [];
		$marked = 0;
		foreach ($this->requests(filters: ['entryId' => $ref['entryId'], 'assigneeUserId' => $ref['assigneeUserId']]) as $id => $request) {
			if ((string) ($request['entrySchema'] ?? '') !== $ref['entrySchema'] || self::isOpen(request: $request) === false) {
				continue;
			}

			$request['status']      = 'corrected';
			$request['respondedAt'] = $now;
			$this->save(type: 'attestationRequest', object: $request, uuid: $id);
			$rounds[(string) ($request['round'] ?? '')] = true;
			$marked++;
		}

		return [$marked, $rounds];
	}//end correctOpenRequests()

	/**
	 * Set the entry's lastConfirmedAt as the assignee (RBAC applies).
	 *
	 * @param array<string, mixed> $request The request, naming the entry by entrySchema and entryId.
	 * @param string               $now     The confirmation time.
	 *
	 * @return void
	 *
	 * @throws OutOfBoundsException When the entry is not known or not found.
	 */
	private function confirmEntry(array $request, string $now): void {
		$entrySchema = (string) ($request['entrySchema'] ?? '');
		$entryId     = (string) ($request['entryId'] ?? '');
		if (array_key_exists($entrySchema, AttestationService::SCOPES) === false || $entryId === '') {
			throw new OutOfBoundsException('The entry of this request is not known');
		}

		[$register, $schema] = $this->location(type: $entrySchema);
		$entry = $this->findOrNull(location: [$register, $schema, true], id: $entryId);
		if ($entry === null) {
			throw new OutOfBoundsException('The entry of this request was not found');
		}

		$data = (array) $entry->getObject();
		$data['lastConfirmedAt'] = $now;
		$this->objectService()->saveObject(
			object: $data,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $entryId),
			_rbac: true,
			_multitenancy: true
		);
	}//end confirmEntry()

	/**
	 * Set the entry's lastConfirmedAt without RBAC (after the assignee's own save).
	 *
	 * @param array{entrySchema: string, entryId: string} $ref The entry.
	 * @param string                                       $now The answer time.
	 *
	 * @return void
	 */
	private function stampEntry(array $ref, string $now): void {
		[$register, $schema] = $this->location(type: $ref['entrySchema']);
		$entry = $this->findOrNull(location: [$register, $schema, false], id: $ref['entryId']);
		if ($entry === null) {
			return;
		}

		$data = (array) $entry->getObject();
		$data['lastConfirmedAt'] = $now;
		$this->objectService()->saveObject(
			object: $data,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $ref['entryId']),
			_rbac: false,
			_multitenancy: false
		);
	}//end stampEntry()

	/**
	 * Recount each round named in the set.
	 *
	 * @param array<string, bool> $rounds Round ids as keys.
	 *
	 * @return void
	 */
	private function recountAll(array $rounds): void {
		foreach (array_keys($rounds) as $roundId) {
			$this->recount(roundId: (string) $roundId);
		}
	}//end recountAll()

	/**
	 * Recount a round's answered requests; a failure is logged, never thrown.
	 *
	 * @param string $roundId The round id.
	 *
	 * @return void
	 */
	private function recount(string $roundId): void {
		if ($roundId === '') {
			return;
		}

		try {
			$round = $this->findOrNull(location: [...$this->location(type: 'attestationRound'), false], id: $roundId);
			if ($round === null) {
				return;
			}

			$answered = $this->answeredIn(roundId: $roundId);
			$data     = (array) $round->getObject();
			if (($data['answeredCount'] ?? null) === $answered) {
				return;
			}

			$data['answeredCount'] = $answered;
			$this->save(type: 'attestationRound', object: $data, uuid: $roundId);
		} catch (Throwable $e) {
			$this->logger->warning('[AttestationAnswerService] could not recount round ' . $roundId . ': ' . $e->getMessage());
		}//end try
	}//end recount()

	/**
	 * How many of a round's requests are answered.
	 *
	 * @param string $roundId The round id.
	 *
	 * @return int
	 */
	private function answeredIn(string $roundId): int {
		$answered = 0;
		foreach ($this->requests(filters: ['round' => $roundId]) as $request) {
			if (in_array((string) ($request['status'] ?? ''), self::ANSWERED, true) === true) {
				$answered++;
			}
		}

		return $answered;
	}//end answeredIn()

	/**
	 * A request by id.
	 *
	 * @param string $requestId The request id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OutOfBoundsException When it does not exist.
	 */
	private function findRequest(string $requestId): array {
		$location = [...$this->location(type: 'attestationRequest'), false];
		$request  = null;
		if ($requestId !== '') {
			$request = $this->findOrNull(location: $location, id: $requestId);
		}

		if ($request === null) {
			throw new OutOfBoundsException('Confirmation request not found');
		}

		return (array) $request->getObject();
	}//end findRequest()

	/**
	 * Requests matching the filters, by id, without RBAC.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function requests(array $filters): array {
		[$register, $schema] = $this->location(type: 'attestationRequest');
		$found = $this->objectService()->searchObjects(
			query: array_merge(['register' => $register, 'schema' => $schema, '_limit' => self::REQUEST_LIMIT], $filters),
			_rbac: false,
			_multitenancy: false
		);

		$requests = [];
		foreach ((array) $found as $object) {
			if (($object instanceof ObjectEntityInterface) === false) {
				continue;
			}

			$data = (array) $object->getObject();
			$id   = (string) ($object->getUuid() ?? ($data['id'] ?? ''));
			if ($id !== '') {
				$requests[$id] = $data;
			}
		}

		return $requests;
	}//end requests()

	/**
	 * One object, or null when it does not exist or cannot be read.
	 *
	 * @param array{0: int, 1: int, 2: bool} $location The register id, the schema id and whether RBAC and multitenancy apply.
	 * @param string                         $id       The object id.
	 *
	 * @return ObjectEntityInterface|null
	 */
	private function findOrNull(array $location, string $id): ?ObjectEntityInterface {
		[$register, $schema, $rbac] = $location;
		try {
			return $this->objectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $register, schema: $schema),
				_rbac: $rbac,
				_multitenancy: $rbac
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end findOrNull()

	/**
	 * Save one object without RBAC and return it with its id.
	 *
	 * @param string               $type   The object type.
	 * @param array<string, mixed> $object The object.
	 * @param string               $uuid   Its id.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $type, array $object, string $uuid): array {
		[$register, $schema] = $this->location(type: $type);
		$saved = $this->objectService()->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $uuid),
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => $uuid] + (array) $saved->getObject();
	}//end save()

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
			$service = null;
		}

		if (($service instanceof ObjectServiceInterface) === false) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$this->resolvedObjectService = $service;
		return $service;
	}//end objectService()

	/**
	 * The current time, ISO 8601 in UTC.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * The register and schema ids of an object type.
	 *
	 * @param string $type The object type.
	 *
	 * @return array{0: int, 1: int}
	 *
	 * @throws RuntimeException When the type is not configured.
	 */
	private function location(string $type): array {
		$register = $this->locator->getRegisterIdForObjectType($type);
		$schema   = $this->locator->getSchemaIdForObjectType($type);
		if ($register === null || $schema === null) {
			throw new RuntimeException($type . ' is not configured');
		}

		return [(int) $register, (int) $schema];
	}//end location()
}//end class
