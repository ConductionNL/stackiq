<?php

/**
 * Contract of ContractApprovalService
 *
 * The public surface of ContractApprovalService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ContractApprovalService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContractApprovalServiceInterface {

	/**
	 * The fully-qualified conclusion-event class spellings, NEWEST FIRST — what
	 * {@see \OCA\Stackiq\AppInfo\Application} attaches the inbound listener to.
	 *
	 * THE INBOUND HALF OF THE SAME PROBLEM AS THE CONSTANT ABOVE, and it stayed
	 * broken after that one was fixed. Application.php imported
	 * `OCA\Decidesk\Event\DecisionConcludedEvent` and registered `::class`,
	 * which resolves at COMPILE TIME to a string nothing dispatches any more, so
	 * the listener attached to a name that never fires. Nothing errors: an
	 * event with no listener and a listener on no event look identical from
	 * here, and the contract simply never leaves `In onderhandeling`.
	 *
	 * Measured 2026-09-09 against decidiq development d72839c:
	 * OCA\Decidiq\Event\DecisionConcludedEvent EXISTS with the full getter
	 * surface this app reads (getSourceApp, getSubjectId, getExternalReference,
	 * getDecisionId, getStatus); OCA\Decidesk\Event\DecisionConcludedEvent is
	 * MISSING. The old spelling is kept regardless — an instance pinned to a
	 * release from before that rename still dispatches it, and dropping it here
	 * re-breaks the integration in the other direction.
	 *
	 * @var array<int, string>
	 */
	public const DECISION_CONCLUDED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\DecisionConcludedEvent',
		'\\OCA\\Decidesk\\Event\\DecisionConcludedEvent',
	];

	/**
	 * This consumer app id, stamped on the request event as `sourceApp` and
	 * used by the conclusion listener to filter inbound events.
	 *
	 * FROZEN at `stackiq` through the app-id rename. This value is not
	 * a name we own at read time — it is PERSISTED on decidesk's Decision rows
	 * when the approval is raised, and `DecisionConcludedListener` matches
	 * inbound conclusions against it. Every decision already open was stamped
	 * `stackiq`; moving the constant makes the filter miss them, so
	 * their outcomes are dropped on the floor and the contract never leaves
	 * `In onderhandeling`. Nothing errors — a filtered-out event looks exactly
	 * like an event that was never sent.
	 *
	 * It can only move in a coordinated change that also rewrites the stored
	 * `sourceApp` on decidesk's existing rows, or that accepts both spellings
	 * on the read side first.
	 */
	public const SOURCE_APP = 'stackiq';

	/**
	 * Projection state: no decision raised.
	 */
	public const APPROVAL_NONE = 'none';

	/**
	 * Projection state: a decidesk Decision is open.
	 */
	public const APPROVAL_PENDING = 'pending';

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
	public function authorizeSubmit(string $contractUuid, array $groupNames, string $activeOrgUuid): bool;

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
	public function isDelegationConfigured(): bool;

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
	public function projectOutcome(string $contractUuid, string $outcomeStatus): bool;

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
	public function resolveContractForOutcome(string $subjectId, string $externalReference, string $decisionId): ?string;

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
	public function submitForApproval(string $contractUuid, bool $isRenewal = false): string;
}//end interface
