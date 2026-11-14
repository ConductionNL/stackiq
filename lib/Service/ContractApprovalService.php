<?php

/**
 * Contract Approval Delegation Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Delegates the contract approval / sign-off / renewal DECISION (the
 * `In onderhandeling -> Actief` transition, and the re-approval of an
 * expiring/`Verlopen` contract) to decidesk — the canonical fleet decision
 * authority (cross-app interface contract #1) — through the in-process
 * `IEventDispatcher` event contract (`OCA\Decidesk\Event\DecisionRequestedEvent`
 * / `DecisionConcludedEvent`). stackiq keeps the contract RECORD locally
 * and PROJECTS the decidesk outcome onto two catalog-local fields
 * (`approvalDecisionId`, `approvalState`).
 *
 * FAIL-CLOSED CONTRACT (mirrors the hydra-gate-unsafe-auth-resolver pattern):
 * a "decidesk not installed" / "listener did not handle" condition NEVER
 * results in an auto-approval. Delegation is wired entirely in-process: when
 * the decidesk app (and its `DecisionRequestedEvent` class) is absent, or its
 * synchronous listener reports `isHandled() === false` / a null decision id,
 * this service throws and the contract stays `In onderhandeling`; `status` is
 * never set to `Actief` on local authority.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ContractApprovalServiceInterface;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raises and projects contract approval decisions delegated to decidesk.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Overall complexity 56 (threshold 50). This
 * class is the fail-closed boundary to an optional cross-app dependency (decidesk): every
 * delegation path has to check that the event contract exists, that the listener actually handled
 * the request, and that a decision id came back, and refuse on each. Fail-open here would silently
 * auto-approve contracts, so that defensive branching is the point of the class and the
 * complexity is inherent rather than accidental.
 */
class ContractApprovalService implements ContractApprovalServiceInterface {
	/**
	 * The fully-qualified request-event class spellings, NEWEST FIRST — the
	 * in-process existence guard (the decision app installed + autoloaded) for
	 * delegation.
	 *
	 * TWO SPELLINGS because a cross-app event class name is a RUNTIME lookup
	 * this app can only follow, never move. The decision app renamed from
	 * OCA\Decidesk to OCA\Decidiq with no compatibility alias, and this constant
	 * named only the old one — so `isDelegationConfigured()` returned false on an
	 * instance where the app was installed, and contract approvals silently
	 * stopped delegating. Measured: OCA\Decidiq\Event\DecisionRequestedEvent
	 * EXISTS, OCA\Decidesk\Event\DecisionRequestedEvent MISSING.
	 *
	 * @var array<int, string>
	 */
	public const DECISION_REQUESTED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\DecisionRequestedEvent',
		'\\OCA\\Decidesk\\Event\\DecisionRequestedEvent',
	];

	/**
	 * The decisionType raised for a first activation of an `In onderhandeling`
	 * contract (matches the decidesk Decision `decisionType` enum value).
	 */
	public const DECISION_TYPE_APPROVAL = 'contract';

	/**
	 * The decisionType raised for re-approval of an expiring/`Verlopen`
	 * contract (additive decidesk enum value, decidesk-contract-decision-hub).
	 */
	public const DECISION_TYPE_RENEWAL = 'contract-renewal';

	/**
	 * The catalog register slug that owns the contract record.
	 */
	public const SUBJECT_REGISTER = 'stackiq';

	/**
	 * The contract schema slug.
	 */
	public const SUBJECT_SCHEMA = 'catalogContract';

	/**
	 * Catalog lifecycle status: in negotiation (the pre-approval state).
	 */
	public const STATUS_NEGOTIATION = 'In negotiation';

	/**
	 * Catalog lifecycle status: active (only ever reached via an `approved`
	 * decidesk outcome — never set on local authority).
	 */
	public const STATUS_ACTIVE = 'Active';

	/**
	 * Projection state: decidesk reported an adopting outcome.
	 */
	public const APPROVAL_APPROVED = 'approved';

	/**
	 * Projection state: decidesk rejected or withdrew the decision.
	 */
	public const APPROVAL_REJECTED = 'rejected';

	/**
	 * The fields each terminal decidesk outcome projects onto a contract.
	 *
	 * `approved` is the ONLY outcome that sets status=Actief (the
	 * In onderhandeling -> Actief transition); `rejected`/`withdrawn` leave the
	 * status in onderhandeling, never forced. Any other outcome projects nothing.
	 */
	private const OUTCOME_PROJECTIONS = [
		'approved' => ['approvalState' => self::APPROVAL_APPROVED, 'status' => self::STATUS_ACTIVE],
		'rejected' => ['approvalState' => self::APPROVAL_REJECTED],
		'withdrawn' => ['approvalState' => self::APPROVAL_REJECTED],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param ObjectTypeLocatorInterface $settingsService Resolves register/schema ids.
	 * @param IEventDispatcher $eventDispatcher Dispatches the decidesk request event in-process.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectTypeLocatorInterface $settingsService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the decidesk event contract is available on this instance.
	 *
	 * Drives the ContractDetail Approval panel: when false, the panel hides its
	 * submit action ("approval delegation not configured") so no fail-open path
	 * exists. The check is purely in-process — the decidesk app's
	 * `DecisionRequestedEvent` class must be installed and autoloadable.
	 *
	 * @return bool True when decidesk's event contract is present.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function isDelegationConfigured(): bool {
		return ($this->resolveRequestEventClass() !== null);
	}//end isDelegationConfigured()

	/**
	 * The first request-event class that actually exists, or null.
	 *
	 * Resolving rather than assuming: this app can only follow the decision
	 * app's namespace, and a hard-coded spelling turns a rename over there into
	 * a silently disabled feature over here.
	 *
	 * @return string|null The event FQN, or null when the decision app is absent.
	 */
	private function resolveRequestEventClass(): ?string {
		foreach (self::DECISION_REQUESTED_EVENTS as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end resolveRequestEventClass()

	/**
	 * Raise a decidesk Decision for a contract approval or renewal.
	 *
	 * FAIL-CLOSED: when decidesk's event class is absent, or its synchronous
	 * listener does not handle the dispatched request (`isHandled() === false`
	 * or a null decision id), this throws a RuntimeException and the caller
	 * leaves the contract `In onderhandeling` with `approvalState` unchanged. On
	 * success it persists the returned decision id to `approvalDecisionId`, sets
	 * `approvalState = pending`, and returns the decision id. `status` is NEVER
	 * set to `Actief` here.
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 * @param bool $isRenewal When true, raise decisionType=contract-renewal.
	 *
	 * @return string The decidesk decision id.
	 *
	 * @throws RuntimeException When delegation is not available or the listener did not handle it.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$isRenewal` selects the `decisionType`
	 * (`contract-approval` vs `contract-renewal`) carried on the raised decidesk event; the
	 * fail-closed logic, persistence and return value are byte-for-byte identical on both paths.
	 * It is a payload discriminator, not a second responsibility.
	 */
	public function submitForApproval(string $contractUuid, bool $isRenewal = false): string {
		$eventClass = $this->requestEventClassOrFail();

		$request = $this->approvalRequestFor(contractUuid: $contractUuid);
		if ($isRenewal === true) {
			$request->decisionType = self::DECISION_TYPE_RENEWAL;
		}

		$event = $this->newDecisionRequestEvent(eventClass: $eventClass, request: $request);
		$this->dispatchDecisionRequest(event: $event, contractUuid: $contractUuid);
		$decisionId = self::answeredDecisionId(event: $event);
		$this->recordPendingApproval(request: $request, decisionId: $decisionId);
		return $decisionId;
	}//end submitForApproval()

	/**
	 * The decision app's request event class.
	 *
	 * Resolve ONCE and narrow here, rather than calling
	 * isDelegationConfigured() and resolving again below. Two lookups of the
	 * same thing can disagree — and the second one has no guard, so a null
	 * would reach `new $eventClass(...)` as a call on null. Psalm caught
	 * exactly that shape.
	 *
	 * @return string The event class.
	 *
	 * @throws RuntimeException When delegation is not available.
	 */
	private function requestEventClassOrFail(): string {
		$eventClass = $this->resolveRequestEventClass();
		if ($eventClass === null) {
			// Fail closed — never auto-approve when the decision app is absent.
			throw new RuntimeException(
				'Contract approval delegation is not available (decision event contract not installed).'
			);
		}

		return $eventClass;
	}//end requestEventClassOrFail()

	/**
	 * The approval request for a contract: the contract, its data and the approval decision type.
	 *
	 * @param string $contractUuid The contract uuid.
	 *
	 * @return ContractApprovalRequest The request.
	 *
	 * @throws RuntimeException When the contract cannot be loaded.
	 */
	private function approvalRequestFor(string $contractUuid): ContractApprovalRequest {
		$request = new ContractApprovalRequest();
		$request->contractUuid = $contractUuid;
		$request->contract = $this->contractOrFail(contractUuid: $contractUuid);
		$request->data = $request->contract->getObject();
		$request->decisionType = self::DECISION_TYPE_APPROVAL;
		return $request;
	}//end approvalRequestFor()

	/**
	 * The contract to submit.
	 *
	 * @param string $contractUuid The contract uuid.
	 *
	 * @return object The contract entity.
	 *
	 * @throws RuntimeException When the contract cannot be loaded.
	 */
	private function contractOrFail(string $contractUuid): object {
		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			throw new RuntimeException('Contract not found: ' . $contractUuid);
		}

		return $contract;
	}//end contractOrFail()

	/**
	 * The decision request event for a submission.
	 *
	 * @param string                  $eventClass The decision app's request event class.
	 * @param ContractApprovalRequest $request    The submission.
	 *
	 * @return object The event.
	 */
	private function newDecisionRequestEvent(string $eventClass, ContractApprovalRequest $request): object {
		return new $eventClass(
			self::SOURCE_APP,
			self::SUBJECT_REGISTER,
			self::SUBJECT_SCHEMA,
			$request->contractUuid,
			$this->buildSubjectLabel(data: $request->data),
			$request->decisionType,
			'',
			['title' => $this->buildSubjectLabel(data: $request->data)],
			(string)($request->data['contractNumber'] ?? ''),
			$request->contractUuid
		);
	}//end newDecisionRequestEvent()

	/**
	 * Dispatch the decision request in-process.
	 *
	 * @param object $event        The decision request event.
	 * @param string $contractUuid The contract uuid.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a listener fails.
	 */
	private function dispatchDecisionRequest(object $event, string $contractUuid): void {
		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			// Fail closed — a listener error never advances the contract.
			$this->logger->error(
				'ContractApprovalService: dispatching decidesk decision request failed — contract left in negotiation',
				['contractUuid' => $contractUuid, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to raise the approval decision in decidesk.', 0, $e);
		}//end try
	}//end dispatchDecisionRequest()

	/**
	 * The decision id the decision app answered the request with.
	 *
	 * @param object $event The dispatched decision request event.
	 *
	 * @return string The decision id.
	 *
	 * @throws RuntimeException When the request was not handled or no decision id came back.
	 */
	private static function answeredDecisionId(object $event): string {
		if ($event->isHandled() === false) {
			throw new RuntimeException('decidesk did not handle the approval request; contract left in negotiation.');
		}

		$decisionId = (string)($event->getDecisionId() ?? '');
		if ($decisionId === '') {
			throw new RuntimeException('decidesk did not return a decision id; contract left in negotiation.');
		}

		return $decisionId;
	}//end answeredDecisionId()

	/**
	 * Store the decision id and the pending state on the contract.
	 *
	 * @param ContractApprovalRequest $request    The submission.
	 * @param string                  $decisionId The decision id.
	 *
	 * @return void
	 */
	private function recordPendingApproval(ContractApprovalRequest $request, string $decisionId): void {
		$data = $request->data;
		$data['approvalDecisionId'] = $decisionId;
		$data['approvalState'] = self::APPROVAL_PENDING;
		// NOTE: status is intentionally NOT touched here — it stays In onderhandeling.
		$this->persistContract(contract: $request->contract, data: $data);

		$this->logger->info(
			'ContractApprovalService: contract submitted for approval',
			['contractUuid' => $request->contractUuid, 'decisionId' => $decisionId, 'decisionType' => $request->decisionType]
		);
	}//end recordPendingApproval()

	/**
	 * Per-object ownership guard (IDOR guard) for the submit / submitRenewal seam.
	 *
	 * Mirrors `PublicationController::authorizeEntry()`: an admin may always
	 * submit; a non-admin MUST be an `aanbod-beheerder` whose active organisation
	 * matches the contract's owning organisation (the OR-stamped `_organisation`
	 * multitenancy field, falling back to a schema-declared `aanbieder` field if
	 * ever present). Returns false (fail-closed) when the contract cannot be
	 * loaded, when the owning organisation cannot be resolved, or on any mismatch.
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 * @param array $groupNames The caller's NC group ids.
	 * @param string $activeOrgUuid The caller's active organisation uuid (may be empty).
	 *
	 * @return bool True when the caller may submit/submitRenewal this contract.
	 *
	 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
	 */
	public function authorizeSubmit(string $contractUuid, array $groupNames, string $activeOrgUuid): bool {
		$request = new SubmitAuthorisation();
		$request->contractUuid = $contractUuid;
		$request->groupNames = $groupNames;
		$request->activeOrgUuid = $activeOrgUuid;
		return $this->maySubmit(request: $request);
	}//end authorizeSubmit()

	/**
	 * Whether the caller may submit/submitRenewal the contract: an admin always, an
	 * aanbod-beheerder only for a contract of its active organisation.
	 *
	 * @param SubmitAuthorisation $request The contract, the caller's groups and active organisation.
	 *
	 * @return bool True when the caller may submit/submitRenewal this contract.
	 */
	private function maySubmit(SubmitAuthorisation $request): bool {
		if (in_array('admin', $request->groupNames, true) === true) {
			return true;
		}

		if (in_array('aanbod-beheerder', $request->groupNames, true) === false) {
			return false;
		}

		return $this->callerOwnsContract(request: $request);
	}//end maySubmit()

	/**
	 * Whether the contract's owning organisation is the caller's active organisation (fail-closed).
	 *
	 * @param SubmitAuthorisation $request The contract and the caller's active organisation.
	 *
	 * @return bool False when the contract cannot be loaded or either organisation is blank.
	 */
	private function callerOwnsContract(SubmitAuthorisation $request): bool {
		$contract = $this->loadContract(contractUuid: $request->contractUuid);
		if ($contract === null) {
			return false;
		}

		$data = $contract->getObject();
		$ownerOrg = (string)($data['_organisation'] ?? $data['provider'] ?? '');

		return $request->activeOrgUuid !== '' && $ownerOrg !== '' && $request->activeOrgUuid === $ownerOrg;
	}//end callerOwnsContract()

	/**
	 * IDOR guard: whether the given decision id is the one this contract carries.
	 *
	 * Used when projecting an outcome so a caller cannot project an arbitrary
	 * outcome onto an arbitrary contract. A blank stored or supplied id never
	 * matches (fail-closed).
	 *
	 * @param string $contractUuid The contract uuid.
	 * @param string $decisionId The decision id supplied by the caller.
	 *
	 * @return bool True when the decision id matches the contract's stored id.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function isDecisionForContract(string $contractUuid, string $decisionId): bool {
		if ($decisionId === '') {
			return false;
		}

		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			return false;
		}

		$stored = (string)($contract->getObject()['approvalDecisionId'] ?? '');
		return $stored !== '' && hash_equals($stored, $decisionId);
	}//end isDecisionForContract()

	/**
	 * Project a decidesk outcome status onto a contract, idempotently.
	 *
	 * `approved` -> approvalState=approved AND status=Actief (the ONLY path
	 * that activates a contract). `rejected`/`withdrawn` ->
	 * approvalState=rejected, status stays In onderhandeling. Any other
	 * (`pending`) status is a no-op. Re-applying the same terminal outcome is
	 * a no-op (idempotent).
	 *
	 * @param string $contractUuid The contract uuid.
	 * @param string $outcomeStatus The decidesk-derived status (approved|rejected|withdrawn|pending).
	 *
	 * @return bool True when the contract was changed.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function projectOutcome(string $contractUuid, string $outcomeStatus): bool {
		$contract = $this->loadContract(contractUuid: $contractUuid);
		if ($contract === null) {
			return $this->ignoreOutcomeForUnknownContract(contractUuid: $contractUuid);
		}

		$data = $this->projectedContractData(data: $contract->getObject(), outcomeStatus: $outcomeStatus);
		if ($data === null) {
			// Pending / unknown outcome, or an idempotent re-receipt — nothing to write.
			return false;
		}

		$this->persistContract(contract: $contract, data: $data);
		$this->logProjectedOutcome(contractUuid: $contractUuid, outcomeStatus: $outcomeStatus);
		return true;
	}//end projectOutcome()

	/**
	 * Log and ignore an outcome for a contract that cannot be loaded.
	 *
	 * @param string $contractUuid The contract uuid.
	 *
	 * @return bool Always false: the contract was not changed.
	 */
	private function ignoreOutcomeForUnknownContract(string $contractUuid): bool {
		$this->logger->warning(
			'ContractApprovalService: outcome for unknown contract ignored',
			['contractUuid' => $contractUuid]
		);
		return false;
	}//end ignoreOutcomeForUnknownContract()

	/**
	 * Apply the projection of an outcome to the contract data.
	 *
	 * @param array  $data          The contract object data.
	 * @param string $outcomeStatus The decidesk-derived status.
	 *
	 * @return array|null The changed data, or null when the outcome changes nothing.
	 */
	private function projectedContractData(array $data, string $outcomeStatus): ?array {
		$changed = false;
		foreach ((self::OUTCOME_PROJECTIONS[$outcomeStatus] ?? []) as $field => $value) {
			if (($data[$field] ?? null) !== $value) {
				$data[$field] = $value;
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $data;
	}//end projectedContractData()

	/**
	 * Log that an outcome was projected onto a contract.
	 *
	 * @param string $contractUuid  The contract uuid.
	 * @param string $outcomeStatus The projected status.
	 *
	 * @return void
	 */
	private function logProjectedOutcome(string $contractUuid, string $outcomeStatus): void {
		$this->logger->info(
			'ContractApprovalService: outcome projected onto contract',
			['contractUuid' => $contractUuid, 'outcomeStatus' => $outcomeStatus]
		);
	}//end logProjectedOutcome()

	/**
	 * Resolve the catalog contract that a concluded decidesk outcome addresses.
	 *
	 * Prefers the carried `subjectId` (the OR object uuid stamped on the request
	 * event), falling back to `externalReference` (the `contractNummer`). The
	 * resolved uuid is only returned when the carried `decisionId` matches the
	 * contract's stored `approvalDecisionId` (IDOR guard), so a spoofed event
	 * cannot project an outcome onto an unrelated contract.
	 *
	 * @param string $subjectId The carried OR object uuid (may be blank).
	 * @param string $externalReference The carried contractNummer (fallback, may be blank).
	 * @param string $decisionId The concluded decision id (IDOR guard).
	 *
	 * @return string|null The matched contract uuid, or null when none matches.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function resolveContractForOutcome(string $subjectId, string $externalReference, string $decisionId): ?string {
		if ($subjectId !== '' && $this->isDecisionForContract(contractUuid: $subjectId, decisionId: $decisionId) === true) {
			return $subjectId;
		}

		$match = $this->findContractByExternalReference(externalReference: $externalReference, decisionId: $decisionId);
		if ($match !== null) {
			return $match;
		}

		$this->logger->warning(
			'ContractApprovalService: concluded outcome did not match any contract (decision/contract mismatch)',
			['subjectId' => $subjectId, 'externalReference' => $externalReference, 'decisionId' => $decisionId]
		);
		return null;
	}//end resolveContractForOutcome()

	/**
	 * Find a pending contract whose contractNummer + decisionId match.
	 *
	 * @param string $externalReference The carried contractNummer.
	 * @param string $decisionId The concluded decision id (IDOR guard).
	 *
	 * @return string|null The matched contract uuid, or null.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function findContractByExternalReference(string $externalReference, string $decisionId): ?string {
		if ($externalReference === '' || $decisionId === '') {
			return null;
		}

		$contracts = $this->searchContractsByDecision(decisionId: $decisionId);
		return $this->contractUuidWithNumber(contracts: $contracts, externalReference: $externalReference);
	}//end findContractByExternalReference()

	/**
	 * Search the contracts that carry a decision id.
	 *
	 * @param string $decisionId The concluded decision id.
	 *
	 * @return array|int The contracts, or [] when OpenRegister, the register or the schema is unavailable.
	 */
	private function searchContractsByDecision(string $decisionId): array|int {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$query = $this->contractDecisionQuery(decisionId: $decisionId);
		if ($query === null) {
			return [];
		}

		return $this->searchContracts(objectService: $objectService, query: $query);
	}//end searchContractsByDecision()

	/**
	 * Build the contract search for a decision id.
	 *
	 * @param string $decisionId The concluded decision id.
	 *
	 * @return array|null The query, or null when the contract register or schema is not configured.
	 */
	private function contractDecisionQuery(string $decisionId): ?array {
		$registerId = $this->settingsService->getRegisterIdForObjectType(objectType: 'catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: 'catalogContract');
		if ($registerId === null || $schemaId === null) {
			return null;
		}

		return [
			'register' => $registerId,
			'schema' => $schemaId,
			'approvalDecisionId' => $decisionId,
			'_limit' => 50,
		];
	}//end contractDecisionQuery()

	/**
	 * Run a contract search, logging a failure as an empty result.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param array                  $query         The contract search.
	 *
	 * @return array|int The contracts found, as the object service answers them.
	 */
	private function searchContracts(ObjectServiceInterface $objectService, array $query): array|int {
		try {
			return $objectService->searchObjects(query: SearchBounds::bounded(query: $query), _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ContractApprovalService: failed to query contract by decision id',
				['error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end searchContracts()

	/**
	 * Find the uuid of the first contract whose contractNumber equals the external reference.
	 *
	 * @param array|int $contracts         The contracts of the decision.
	 * @param string    $externalReference The carried contractNummer.
	 *
	 * @return string|null The matched contract uuid, or null.
	 */
	private function contractUuidWithNumber(array|int $contracts, string $externalReference): ?string {
		foreach ($contracts as $contract) {
			$data = $contract->getObject();
			if ((string)($data['contractNumber'] ?? '') === $externalReference) {
				return $contract->getUuid();
			}
		}

		return null;
	}//end contractUuidWithNumber()

	/**
	 * Build the human-readable subject label for the decision provenance.
	 *
	 * @param array $data The contract object data.
	 *
	 * @return string The subject label.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function buildSubjectLabel(array $data): string {
		$number = (string)($data['contractNumber'] ?? '');
		$type = (string)($data['contractType'] ?? '');
		if ($number !== '' && $type !== '') {
			return $number . ' — ' . $type;
		}

		if ($number !== '') {
			return $number;
		}

		return 'Contract';
	}//end buildSubjectLabel()

	/**
	 * Load a contract OR object by uuid.
	 *
	 * @param string $contractUuid The contract uuid.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface|null The object, or null.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function loadContract(string $contractUuid) {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$registerId = $this->settingsService->getRegisterIdForObjectType(objectType: 'catalogContract');
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: 'catalogContract');
		if ($registerId === null || $schemaId === null) {
			return null;
		}

		try {
			return $objectService->find(
				id: $contractUuid,
				_extend: [],
				files: false,
				register: $registerId,
				schema: $schemaId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ContractApprovalService: contract lookup failed',
				['contractUuid' => $contractUuid, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end loadContract()

	/**
	 * Persist a mutated contract object back to the OR store.
	 *
	 * @param \OCA\OpenRegister\Contract\ObjectEntityInterface $contract The contract entity.
	 * @param array $data The mutated object data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function persistContract($contract, array $data): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		$objectService->saveObject(
			object: $data,
			extend: [],
			register: $contract->getRegister(),
			schema: $contract->getSchema(),
			uuid: $contract->getUuid()
		);

	}//end persistContract()

	/**
	 * Lazily resolve the OpenRegister ObjectService.
	 *
	 * @return ObjectServiceInterface|null The service, or null when OpenRegister is absent.
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			// Ask for the CONTRACT and narrow on the CONTRACT (ADR-084). Asking
			// for the concrete class and gating on `instanceof ObjectService`
			// made this method return null for anything that satisfies the
			// published interface without being that exact class — which is
			// every double a leaf app can build, so the ownership check silently
			// fell through to its fail-closed arm and refused an owner.
			$service = $this->container->get(ObjectServiceInterface::class);
			if ($service instanceof ObjectServiceInterface) {
				return $service;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('ContractApprovalService: ObjectService not resolvable', ['error' => $e->getMessage()]);
		}//end try

		return null;
	}//end getObjectService()
}//end class
