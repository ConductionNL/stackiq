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
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;

/**
 * The MCP tool provider for StackIQ.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator::error() is the one pure builder of the structured MCP error.
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class StackiqToolProvider implements IMcpToolProvider {

	public const APP_ID = 'stackiq';

	private const NO_INPUT = ['type' => 'object', 'properties' => [], 'required' => []];

	private const UUID = ['type' => 'string', 'format' => 'uuid'];

	private const CONTRACT_INPUT = ['type' => 'object', 'properties' => ['contractUuid' => self::UUID], 'required' => ['contractUuid']];

	private const MERGE_INPUT = [
		'type' => 'object',
		'properties' => ['sourceUuid' => self::UUID, 'targetUuid' => self::UUID],
		'required' => ['sourceUuid', 'targetUuid'],
	];

	private const PUBLICATION_INPUT = [
		'type' => 'object',
		'properties' => [
			'objectType' => ['type' => 'string', 'enum' => ['catalogService', 'module', 'connection', 'organization']],
			'uuid' => self::UUID,
		],
		'required' => ['objectType', 'uuid'],
	];

	private const MODERATION_INPUT = [
		'type' => 'object',
		'properties' => ['uuid' => self::UUID, 'type' => ['type' => 'string', 'enum' => McpWriteTools::MODERATION_TYPES]],
		'required' => ['uuid'],
	];

	private const OFFER_INPUT = ['type' => 'object', 'properties' => ['offerUuid' => self::UUID], 'required' => ['offerUuid']];

	private const USAGE_INPUT = ['type' => 'object', 'properties' => ['usageUuid' => self::UUID], 'required' => ['usageUuid']];

	/**
	 * The tool catalogue, asserted as a fixture by the unit tests.
	 *
	 * @var list<array<string, mixed>>
	 */
	public const TOOL_DESCRIPTORS = [[
		'id' => 'stackiq.getContractApprovalConfig',
		'name' => 'Contract approval configured',
		'description' => 'Whether contracts can be sent to decidesk for a decision, so you know if the submit tools can work.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
		],
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.submitContractApproval',
		'name' => 'Submit contract for approval',
		'description' => 'Send a contract to decidesk for an approval decision. Only an administrator or an '
			. 'aanbod-beheerder of the contract\'s organisation may.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
		],
		'inputSchema' => self::CONTRACT_INPUT,
	], [
		'id' => 'stackiq.submitContractRenewal',
		'name' => 'Submit contract renewal',
		'description' => 'Send a contract renewal to decidesk for an approval decision. Same permission as submitting the contract.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
		],
		'inputSchema' => self::CONTRACT_INPUT,
	], [
		'id' => 'stackiq.getMyContactProfile',
		'name' => 'My contact profile',
		'description' => 'The signed-in user\'s own contact person (name, role, e-mail) and their active and other organisations.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.listOffers',
		'name' => 'Pending offers',
		'description' => 'The offers (usages, services, applications, connections) waiting for the caller\'s active '
			. 'organisation to accept or decline.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				],
				'page' => [
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'required' => [],
		],
	], [
		'id' => 'stackiq.listOfferedUsages',
		'name' => 'Offered usages',
		'description' => 'Usage records offered to the caller\'s organisation as consumer (as: consumer), or shared with '
			. 'it as a participant (as: participant).',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'as' => [
					'type' => 'string',
					'enum' => ['consumer', 'participant'],
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				],
				'page' => [
					'type' => 'integer',
					'minimum' => 1,
				],
			],
			'required' => ['as'],
		],
	], [
		'id' => 'stackiq.getPortfolioReport',
		'name' => 'Portfolio report',
		'description' => 'The application portfolio report (TIME classification, lifecycle, costs) of one organisation. '
			. 'Only for an organisation the caller may read; administrators may read any.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'organisationUuid' => [
					'type' => 'string',
					'format' => 'uuid',
				],
			],
			'required' => ['organisationUuid'],
		],
	], [
		'id' => 'stackiq.listPendingModerations',
		'name' => 'Pending moderations',
		'description' => 'The organisation registrations (type: organization, the default) or reviews (type: '
			. 'software-review) waiting for moderation. Administrators only.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'type' => [
					'type' => 'string',
					'enum' => McpWriteTools::MODERATION_TYPES,
				],
			],
			'required' => [],
		],
	], [
		'id' => 'stackiq.getReviewAggregate',
		'name' => 'Review aggregate',
		'description' => 'The average rating, the number of approved reviews and the reviews themselves for one '
			. 'application (subjectType: module) or service (subjectType: catalogService).',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'subjectType' => [
					'type' => 'string',
					'enum' => McpWriteTools::REVIEW_SUBJECTS,
				],
				'subjectId' => [
					'type' => 'string',
				],
			],
			'required' => ['subjectType', 'subjectId'],
		],
	], [
		'id' => 'stackiq.getSbomImportStatus',
		'name' => 'SBOM import status',
		'description' => 'The state of the SBOM import of one application version, or of one import operation. Needs read access to the application.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'moduleVersionUuid' => [
					'type' => 'string',
					'format' => 'uuid',
				],
				'operationId' => [
					'type' => 'string',
				],
			],
			'required' => ['moduleVersionUuid'],
		],
	], [
		'id' => 'stackiq.listViews',
		'name' => 'Architecture views',
		'description' => 'Every architecture (ArchiMate) view, enriched as the views API serves them.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.getView',
		'name' => 'Architecture view',
		'description' => 'One architecture (ArchiMate) view by id, with its elements and relations.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'viewId' => [
					'type' => 'string',
				],
			],
			'required' => ['viewId'],
		],
	], [
		'id' => 'stackiq.previewOrganisationMerge',
		'name' => 'Preview organisation merge',
		'description' => 'What merging the source organisation into the target would move and change, without changing '
			. 'anything. Administrators only.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::MERGE_INPUT,
	], [
		'id' => 'stackiq.getEolSyncStatus',
		'name' => 'End-of-life sync status',
		'description' => 'When the end-of-life data was last synced from endoflife.date and with what result. Administrators only.',
		'scope' => 'read',
		'reach' => 'user',
		'annotations' => [
			'readOnlyHint' => true,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.publishObject',
		'name' => 'Publish catalogue entry',
		'description' => 'Publish an application, service, connection or organisation in the public catalogue, now or '
			. 'from a date (when). Only an administrator, or an aanbod-beheerder whose organisation owns the '
			. 'entry.',
		'scope' => 'update',
		'reach' => 'external',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'objectType' => [
					'type' => 'string',
					'enum' => ['catalogService', 'module', 'connection', 'organization'],
				],
				'uuid' => [
					'type' => 'string',
					'format' => 'uuid',
				],
				'when' => [
					'type' => 'string',
					'format' => 'date-time',
				],
			],
			'required' => ['objectType', 'uuid'],
		],
	], [
		'id' => 'stackiq.depublishObject',
		'name' => 'Depublish catalogue entry',
		'description' => 'Withdraw an entry from the public catalogue. Same permission as publishing.',
		'scope' => 'update',
		'reach' => 'external',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::PUBLICATION_INPUT,
	], [
		'id' => 'stackiq.approveRegistration',
		'name' => 'Approve registration',
		'description' => 'Approve a pending organisation registration (type: organization, the default) or review (type: '
			. 'software-review). Administrators only.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::MODERATION_INPUT,
	], [
		'id' => 'stackiq.rejectRegistration',
		'name' => 'Reject registration',
		'description' => 'Reject a pending organisation registration or review. Administrators only.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::MODERATION_INPUT,
	], [
		'id' => 'stackiq.submitReview',
		'name' => 'Submit review',
		'description' => 'Review an application (subjectType: module) or service (subjectType: catalogService): review '
			. 'holds rating (1 to 10), name and text. The review is always stored as pending until a moderator '
			. 'approves it.',
		'scope' => 'create',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'subjectType' => [
					'type' => 'string',
					'enum' => McpWriteTools::REVIEW_SUBJECTS,
				],
				'subjectId' => [
					'type' => 'string',
				],
				'review' => [
					'type' => 'object',
				],
			],
			'required' => ['subjectType', 'subjectId', 'review'],
		],
	], [
		'id' => 'stackiq.registerOrganisation',
		'name' => 'Register organisation',
		'description' => 'Register a new organisation in the catalogue (organisation holds its name, type and contact '
			. 'details). It waits for moderation before it is admitted.',
		'scope' => 'create',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'organisation' => [
					'type' => 'object',
				],
			],
			'required' => ['organisation'],
		],
	], [
		'id' => 'stackiq.acceptOffer',
		'name' => 'Accept offer',
		'description' => 'Accept an offer made to the caller\'s active organisation.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::OFFER_INPUT,
	], [
		'id' => 'stackiq.declineOffer',
		'name' => 'Decline offer',
		'description' => 'Decline an offer made to the caller\'s active organisation; the offer is removed.',
		'scope' => 'delete',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => true,
			'idempotentHint' => true,
		],
		'inputSchema' => self::OFFER_INPUT,
	], [
		'id' => 'stackiq.claimUsage',
		'name' => 'Claim usage',
		'description' => 'Take an offered usage record over as the caller\'s active organisation.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::USAGE_INPUT,
	], [
		'id' => 'stackiq.declineUsage',
		'name' => 'Decline usage',
		'description' => 'Decline a usage record offered to the caller\'s organisation as consumer; the record is removed.',
		'scope' => 'delete',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => true,
			'idempotentHint' => true,
		],
		'inputSchema' => self::USAGE_INPUT,
	], [
		'id' => 'stackiq.mergeOrganisations',
		'name' => 'Merge organisations',
		'description' => 'Merge the source organisation into the target: its records move to the target and the source is '
			. 'marked as merged. Preview it first with stackiq.previewOrganisationMerge. Administrators only.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => true,
			'idempotentHint' => false,
		],
		'inputSchema' => self::MERGE_INPUT,
	], [
		'id' => 'stackiq.importSbom',
		'name' => 'Import SBOM',
		'description' => 'Import a software bill of materials for an application version, passed inline as JSON text '
			. '(content, at most 10 MB) in CycloneDX (cyclonedx-json, the default) or SPDX (spdx-json) format. '
			. 'Needs an administrator or a manage role with access to the application.',
		'scope' => 'create',
		'reach' => 'instance',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
		],
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'moduleVersionUuid' => [
					'type' => 'string',
					'format' => 'uuid',
				],
				'content' => [
					'type' => 'string',
				],
				'format' => [
					'type' => 'string',
					'enum' => SbomImportServiceInterface::SUPPORTED_FORMATS,
				],
				'fileName' => [
					'type' => 'string',
				],
			],
			'required' => ['moduleVersionUuid', 'content'],
		],
	], [
		'id' => 'stackiq.triggerEolSync',
		'name' => 'Run end-of-life sync',
		'description' => 'Fetch the end-of-life dates from endoflife.date now and update the application versions. Administrators only.',
		'scope' => 'update',
		'reach' => 'external',
		'annotations' => [
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => true,
		],
		'inputSchema' => self::NO_INPUT,
	]];

	/**
	 * Constructor.
	 *
	 * @param McpContractTools $contractTools The contract tools.
	 * @param McpReadTools     $readTools     The other read tools.
	 * @param McpWriteTools    $writeTools    The other write tools.
	 */
	public function __construct(
		private readonly McpContractTools $contractTools,
		private readonly McpReadTools $readTools,
		private readonly McpWriteTools $writeTools,
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
			'stackiq.getMyContactProfile' => $this->readTools->getMyContactProfile(args: $arguments),
			'stackiq.listOffers' => $this->readTools->listOffers(args: $arguments),
			'stackiq.listOfferedUsages' => $this->readTools->listOfferedUsages(args: $arguments),
			'stackiq.getPortfolioReport' => $this->readTools->getPortfolioReport(args: $arguments),
			'stackiq.listPendingModerations' => $this->readTools->listPendingModerations(args: $arguments),
			'stackiq.getReviewAggregate' => $this->readTools->getReviewAggregate(args: $arguments),
			'stackiq.getSbomImportStatus' => $this->readTools->getSbomImportStatus(args: $arguments),
			'stackiq.listViews' => $this->readTools->listViews(args: $arguments),
			'stackiq.getView' => $this->readTools->getView(args: $arguments),
			'stackiq.previewOrganisationMerge' => $this->readTools->previewOrganisationMerge(args: $arguments),
			'stackiq.getEolSyncStatus' => $this->readTools->getEolSyncStatus(args: $arguments),
			'stackiq.publishObject' => $this->writeTools->publishObject(args: $arguments),
			'stackiq.depublishObject' => $this->writeTools->depublishObject(args: $arguments),
			'stackiq.approveRegistration' => $this->writeTools->approveRegistration(args: $arguments),
			'stackiq.rejectRegistration' => $this->writeTools->rejectRegistration(args: $arguments),
			'stackiq.submitReview' => $this->writeTools->submitReview(args: $arguments),
			'stackiq.registerOrganisation' => $this->writeTools->registerOrganisation(args: $arguments),
			'stackiq.acceptOffer' => $this->writeTools->acceptOffer(args: $arguments),
			'stackiq.declineOffer' => $this->writeTools->declineOffer(args: $arguments),
			'stackiq.claimUsage' => $this->writeTools->claimUsage(args: $arguments),
			'stackiq.declineUsage' => $this->writeTools->declineUsage(args: $arguments),
			'stackiq.mergeOrganisations' => $this->writeTools->mergeOrganisations(args: $arguments),
			'stackiq.importSbom' => $this->writeTools->importSbom(args: $arguments),
			'stackiq.triggerEolSync' => $this->writeTools->triggerEolSync(args: $arguments),
			default => McpArgumentValidator::error(
				code: 'unknown_tool',
				message: "Unknown tool id '{$toolId}'. Available tools: "
					. implode(separator: ', ', array: array_column(self::TOOL_DESCRIPTORS, 'id')) . '.'
			),
		};
	}//end invokeTool()
}//end class
