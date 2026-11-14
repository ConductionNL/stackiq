<?php

/**
 * StackIQ McpContractTools
 *
 * The contract tools: whether decision delegation is configured, and the two
 * submit seams, each behind McpContractGate, so an agent can raise a decidesk
 * decision only where the caller could through the REST route
 * (mcp-full-action-surface tasks 4.1 and 5.1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\Stackiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Mcp;

use OCA\Stackiq\Kernel\Contracts\ContractApprovalServiceInterface;
use RuntimeException;

/**
 * Contract approval tools.
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class McpContractTools {

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate                     $session   The signed-in caller.
	 * @param McpContractGate                  $gate      The per-contract ownership check.
	 * @param McpArgumentValidator             $validator Argument checks.
	 * @param ContractApprovalServiceInterface $approvals The service the REST routes call.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly McpContractGate $gate,
		private readonly McpArgumentValidator $validator,
		private readonly ContractApprovalServiceInterface $approvals,
	) {
	}//end __construct()

	/**
	 * Whether contracts can be sent to decidesk for a decision.
	 *
	 * @param array<string, mixed> $args No arguments.
	 *
	 * @return array<string, mixed> `{configured}`, or a refusal.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function getContractApprovalConfig(array $args): array {
		$refusal = $this->session->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		return ['configured' => $this->approvals->isDelegationConfigured()];
	}//end getContractApprovalConfig()

	/**
	 * Send a contract to decidesk for approval.
	 *
	 * @param array<string, mixed> $args `contractUuid`.
	 *
	 * @return array<string, mixed> `{decisionId, approvalState}`, or a refusal.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function submitContractApproval(array $args): array {
		return $this->submit(args: $args, isRenewal: false);
	}//end submitContractApproval()

	/**
	 * Send a contract renewal to decidesk for approval.
	 *
	 * @param array<string, mixed> $args `contractUuid`.
	 *
	 * @return array<string, mixed> `{decisionId, approvalState}`, or a refusal.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function submitContractRenewal(array $args): array {
		return $this->submit(args: $args, isRenewal: true);
	}//end submitContractRenewal()

	/**
	 * Validate, check ownership, then raise the decision; fail closed when delegation is unavailable.
	 *
	 * @param array<string, mixed> $args      `contractUuid`.
	 * @param bool                 $isRenewal Whether this is a renewal.
	 *
	 * @return array<string, mixed> The outcome, or a refusal.
	 */
	private function submit(array $args, bool $isRenewal): array {
		$refusal = ($this->validator->uuid(args: $args, name: 'contractUuid')
			?? $this->gate->authorise(contractUuid: (string)$args['contractUuid']));
		if ($refusal !== null) {
			return $refusal;
		}

		$contractUuid = (string)$args['contractUuid'];
		try {
			$decisionId = $this->approvals->submitForApproval(contractUuid: $contractUuid, isRenewal: $isRenewal);
		} catch (RuntimeException $e) {
			return McpArgumentValidator::error(code: 'unavailable', message: $e->getMessage());
		}

		return [
			'contractUuid' => $contractUuid,
			'decisionId' => $decisionId,
			'approvalState' => ContractApprovalServiceInterface::APPROVAL_PENDING,
		];
	}//end submit()
}//end class
