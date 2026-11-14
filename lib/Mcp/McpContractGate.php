<?php

/**
 * StackIQ McpContractGate
 *
 * The per-contract check for the MCP contract tools: the same ownership guard
 * the REST routes run (ContractApprovalService::authorizeSubmit: an admin, or
 * an aanbod-beheerder of the contract's organisation), fed with the caller's
 * groups and active organisation exactly as ContractApprovalController feeds
 * it (mcp-full-action-surface task 3.1).
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
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Ownership check for contract tools.
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class McpContractGate {

	/**
	 * Constructor.
	 *
	 * @param McpAdminGate                     $session      The signed-in caller.
	 * @param ContractApprovalServiceInterface $approvals    The ownership guard and the submit seam.
	 * @param IGroupManager                    $groupManager The caller's groups.
	 * @param IConfig                          $config       The caller's active organisation.
	 */
	public function __construct(
		private readonly McpAdminGate $session,
		private readonly ContractApprovalServiceInterface $approvals,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Refuse unless the signed-in caller may submit this contract.
	 *
	 * @param string $contractUuid The contract.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the caller may proceed.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function authorise(string $contractUuid): ?array {
		$user = $this->session->currentUser();
		if ($user === null) {
			return McpArgumentValidator::error(code: 'unauthenticated', message: 'This tool needs a signed-in user.');
		}

		$allowed = $this->approvals->authorizeSubmit(
			contractUuid: $contractUuid,
			groupNames: $this->groupNamesOf(user: $user),
			activeOrgUuid: (string)$this->config->getUserValue($user->getUID(), 'core', 'organisation')
		);
		if ($allowed !== true) {
			return McpArgumentValidator::error(
				code: 'forbidden',
				message: 'You do not have permission to submit this contract for approval.'
			);
		}

		return null;
	}//end authorise()

	/**
	 * The ids of the user's groups.
	 *
	 * @param IUser $user The user.
	 *
	 * @return list<string> The group ids.
	 */
	private function groupNamesOf(IUser $user): array {
		return array_values(
			array_map(
				static fn (IGroup $group): string => $group->getGID(),
				$this->groupManager->getUserGroups($user)
			)
		);
	}//end groupNamesOf()
}//end class
