<?php

/**
 * Decides whether a contract write may make the contract Active.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * A contract becomes Active only through an approved decision (REQ-SCCD-007).
 *
 * A write that sets the status to Active, on a create or on an edit of a contract
 * that was not Active, is refused unless its approvalState is `approved`. The
 * approved decision outcome writes both fields together, so it passes. A contract
 * that is already Active keeps that status on later edits (existing Active
 * contracts were grandfathered by BackfillContractApprovalState).
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
 */
class ContractApprovalGuard {
	/**
	 * Whether the write must be refused.
	 *
	 * @param array<string, mixed>      $data    The contract as it will be saved.
	 * @param array<string, mixed>|null $oldData The stored contract, or null on a create.
	 *
	 * @return bool True when the write makes the contract Active without an approved decision.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
	 */
	public function refuses(array $data, ?array $oldData): bool {
		if (self::isActive(contract: $data) === false) {
			return false;
		}

		if ($oldData !== null && self::isActive(contract: $oldData) === true) {
			return false;
		}

		return (string)($data['approvalState'] ?? '') !== ContractApprovalService::APPROVAL_APPROVED;
	}//end refuses()

	/**
	 * Whether a contract's status is Active.
	 *
	 * @param array<string, mixed> $contract The contract data.
	 *
	 * @return bool
	 */
	private static function isActive(array $contract): bool {
		return ($contract['status'] ?? null) === ContractApprovalService::STATUS_ACTIVE;
	}//end isActive()
}//end class
