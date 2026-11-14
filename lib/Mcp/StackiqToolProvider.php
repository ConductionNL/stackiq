<?php

/**
 * StackIQ StackiqToolProvider
 *
 * StackIQ's MCP tool provider: a dispatcher over a constant catalogue, with no
 * business logic (mcp-full-action-surface task 2.1). Registered under the
 * string alias OCA\OpenRegister\Mcp\IMcpToolProvider::stackiq, which
 * OpenRegister enumerates; without OpenRegister nothing resolves it.
 *
 * Every descriptor carries the grant annotations hermiq reads: scope (read,
 * create, update, delete), reach (self, user, instance, external) and hints.
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

use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * The MCP tool provider for StackIQ.
 *
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class StackiqToolProvider implements IMcpToolProvider {

	public const APP_ID = 'stackiq';

	private const CONTRACT_INPUT = [
		'type' => 'object',
		'properties' => ['contractUuid' => ['type' => 'string', 'format' => 'uuid']],
		'required' => ['contractUuid'],
	];

	/**
	 * The tool catalogue. A plain constant so tests assert against it directly.
	 *
	 * @var list<array<string, mixed>>
	 */
	public const TOOL_DESCRIPTORS = [
		[
			'id' => 'stackiq.getContractApprovalConfig',
			'name' => 'Contract approval configured',
			'description' => 'Whether contracts can be sent to decidesk for a decision, so you know if the submit tools can work.',
			'scope' => 'read',
			'reach' => 'user',
			'annotations' => ['readOnlyHint' => true],
			'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
		],
		[
			'id' => 'stackiq.submitContractApproval',
			'name' => 'Submit contract for approval',
			'description' => 'Send a contract to decidesk for an approval decision. '
				. 'Only an administrator or an aanbod-beheerder of the contract\'s organisation may.',
			'scope' => 'update',
			'reach' => 'instance',
			'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
			'inputSchema' => self::CONTRACT_INPUT,
		],
		[
			'id' => 'stackiq.submitContractRenewal',
			'name' => 'Submit contract renewal',
			'description' => 'Send a contract renewal to decidesk for an approval decision. Same permission as submitting the contract.',
			'scope' => 'update',
			'reach' => 'instance',
			'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
			'inputSchema' => self::CONTRACT_INPUT,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param McpContractTools $contractTools The contract tools.
	 */
	public function __construct(
		private readonly McpContractTools $contractTools,
	) {
	}//end __construct()

	/**
	 * The app id OpenRegister lists the tools under.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function getAppId(): string {
		return self::APP_ID;
	}//end getAppId()

	/**
	 * The tool catalogue.
	 *
	 * @return list<array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function getTools(): array {
		return self::TOOL_DESCRIPTORS;
	}//end getTools()

	/**
	 * Run one tool.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed> The result, or a structured error.
	 *
	 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		return match ($toolId) {
			'stackiq.getContractApprovalConfig' => $this->contractTools->getContractApprovalConfig(args: $arguments),
			'stackiq.submitContractApproval' => $this->contractTools->submitContractApproval(args: $arguments),
			'stackiq.submitContractRenewal' => $this->contractTools->submitContractRenewal(args: $arguments),
			default => McpArgumentValidator::error(
				code: 'unknown_tool',
				message: "Unknown tool id '{$toolId}'. Available tools: "
					. implode(separator: ', ', array: array_column(self::TOOL_DESCRIPTORS, 'id')) . '.'
			),
		};
	}//end invokeTool()
}//end class
