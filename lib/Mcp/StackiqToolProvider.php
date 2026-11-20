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
use OCA\Stackiq\Service\ArchitectureViewDraftService;
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;

/**
 * The MCP tool provider for StackIQ.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) McpArgumentValidator::error() is the one pure builder of the structured MCP error.
 * @spec openspec/changes/mcp-full-action-surface/specs/mcp-tool-surface/spec.md
 */
class StackiqToolProvider implements IMcpToolProvider {

	public const APP_ID = 'stackiq';

	private const READ_HINTS = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true];

	private const WRITE_HINTS = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];

	private const IDEMPOTENT_WRITE_HINTS = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];

	private const DESTRUCTIVE_HINTS = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];

	private const LIMIT = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100];

	private const PAGE = ['type' => 'integer', 'minimum' => 1];

	private const CONTRACT_HINTS = ['readOnlyHint' => false, 'destructiveHint' => false];

	private const READ_TOOL = ['scope' => 'read', 'reach' => 'user', 'annotations' => self::READ_HINTS];

	private const EXTERNAL_UPDATE_TOOL = ['scope' => 'update', 'reach' => 'external', 'annotations' => self::IDEMPOTENT_WRITE_HINTS];

	private const UPDATE_TOOL = ['scope' => 'update', 'reach' => 'instance', 'annotations' => self::IDEMPOTENT_WRITE_HINTS];

	private const CREATE_TOOL = ['scope' => 'create', 'reach' => 'instance', 'annotations' => self::WRITE_HINTS];

	private const DELETE_TOOL = ['scope' => 'delete', 'reach' => 'instance', 'annotations' => self::DESTRUCTIVE_HINTS];

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
		'annotations' => self::CONTRACT_HINTS,
		'inputSchema' => self::CONTRACT_INPUT,
	], [
		'id' => 'stackiq.submitContractRenewal',
		'name' => 'Submit contract renewal',
		'description' => 'Send a contract renewal to decidesk for an approval decision. Same permission as submitting the contract.',
		'scope' => 'update',
		'reach' => 'instance',
		'annotations' => self::CONTRACT_HINTS,
		'inputSchema' => self::CONTRACT_INPUT,
	], [
		'id' => 'stackiq.getMyContactProfile',
		'name' => 'My contact profile',
		'description' => 'The signed-in user\'s own contact person (name, role, e-mail) and their active and other organisations.',
		...self::READ_TOOL,
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.listOffers',
		'name' => 'Pending offers',
		'description' => 'The offers (usages, services, applications, connections) waiting for the caller\'s active '
			. 'organisation to accept or decline.',
		...self::READ_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'limit' => self::LIMIT,
				'page' => self::PAGE,
			],
			'required' => [],
		],
	], [
		'id' => 'stackiq.listOfferedUsages',
		'name' => 'Offered usages',
		'description' => 'Usage records offered to the caller\'s organisation as consumer (as: consumer), or shared with '
			. 'it as a participant (as: participant).',
		...self::READ_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'as' => [
					'type' => 'string',
					'enum' => ['consumer', 'participant'],
				],
				'limit' => self::LIMIT,
				'page' => self::PAGE,
			],
			'required' => ['as'],
		],
	], [
		'id' => 'stackiq.getPortfolioReport',
		'name' => 'Portfolio report',
		'description' => 'The application portfolio report (TIME classification, lifecycle, costs) of one organisation. '
			. 'Only for an organisation the caller may read; administrators may read any.',
		...self::READ_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'organisationUuid' => self::UUID,
			],
			'required' => ['organisationUuid'],
		],
	], [
		'id' => 'stackiq.listPendingModerations',
		'name' => 'Pending moderations',
		'description' => 'The organisation registrations (type: organization, the default) or reviews (type: '
			. 'software-review) waiting for moderation. Administrators only.',
		...self::READ_TOOL,
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
		...self::READ_TOOL,
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
		...self::READ_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'moduleVersionUuid' => self::UUID,
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
		...self::READ_TOOL,
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.getView',
		'name' => 'Architecture view',
		'description' => 'One architecture (ArchiMate) view by id, with its elements and relations.',
		...self::READ_TOOL,
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
		'id' => 'stackiq.searchArchitectureElements',
		'name' => 'Find architecture elements',
		'description' => 'Architecture (ArchiMate) elements matching a text, as uuid, identifier, name, ArchiMate type and '
			. 'GEMMA type, so a drafted view reuses existing elements instead of inventing new ones. At most 50 rows.',
		...self::READ_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'query' => ['type' => 'string', 'maxLength' => 200],
				'types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ArchitectureViewDraftService::ELEMENT_TYPES]],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ArchitectureViewDraftService::MAX_SEARCH],
			],
			'required' => ['query'],
		],
	], [
		'id' => 'stackiq.draftView',
		'name' => 'Draft an architecture view',
		'description' => 'Write a new architecture view in status draft from elements and relations, for a person to review '
			. 'in the view editor. An element names an existing element by uuid, or a new one by ArchiMate type and name; a '
			. 'relation names two element keys and an ArchiMate relation type. At most 60 elements and 120 relations. '
			. 'Returns the view uuid and the link to open it.',
		...self::CREATE_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'name' => ['type' => 'string', 'maxLength' => 255],
				'description' => ['type' => 'string', 'maxLength' => 2000],
				'elements' => [
					'type' => 'array',
					'minItems' => 1,
					'maxItems' => ArchitectureViewDraftService::MAX_ELEMENTS,
					'items' => [
						'type' => 'object',
						'properties' => [
							'key' => ['type' => 'string'],
							'uuid' => self::UUID,
							'type' => ['type' => 'string', 'enum' => ArchitectureViewDraftService::ELEMENT_TYPES],
							'name' => ['type' => 'string'],
						],
						'required' => ['key'],
					],
				],
				'relations' => [
					'type' => 'array',
					'maxItems' => ArchitectureViewDraftService::MAX_RELATIONS,
					'items' => [
						'type' => 'object',
						'properties' => [
							'source' => ['type' => 'string'],
							'target' => ['type' => 'string'],
							'type' => ['type' => 'string', 'enum' => ArchitectureViewDraftService::RELATION_TYPES],
						],
						'required' => ['source', 'target', 'type'],
					],
				],
			],
			'required' => ['name', 'elements'],
		],
	], [
		'id' => 'stackiq.previewOrganisationMerge',
		'name' => 'Preview organisation merge',
		'description' => 'What merging the source organisation into the target would move and change, without changing '
			. 'anything. Administrators only.',
		...self::READ_TOOL,
		'inputSchema' => self::MERGE_INPUT,
	], [
		'id' => 'stackiq.getEolSyncStatus',
		'name' => 'End-of-life sync status',
		'description' => 'When the end-of-life data was last synced from endoflife.date and with what result. Administrators only.',
		...self::READ_TOOL,
		'inputSchema' => self::NO_INPUT,
	], [
		'id' => 'stackiq.publishObject',
		'name' => 'Publish catalogue entry',
		'description' => 'Publish an application, service, connection or organisation in the public catalogue, now or '
			. 'from a date (when). Only an administrator, or an aanbod-beheerder whose organisation owns the '
			. 'entry.',
		...self::EXTERNAL_UPDATE_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'objectType' => [
					'type' => 'string',
					'enum' => ['catalogService', 'module', 'connection', 'organization'],
				],
				'uuid' => self::UUID,
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
		...self::EXTERNAL_UPDATE_TOOL,
		'inputSchema' => self::PUBLICATION_INPUT,
	], [
		'id' => 'stackiq.approveRegistration',
		'name' => 'Approve registration',
		'description' => 'Approve a pending organisation registration (type: organization, the default) or review (type: '
			. 'software-review). Administrators only.',
		...self::UPDATE_TOOL,
		'inputSchema' => self::MODERATION_INPUT,
	], [
		'id' => 'stackiq.rejectRegistration',
		'name' => 'Reject registration',
		'description' => 'Reject a pending organisation registration or review. Administrators only.',
		...self::UPDATE_TOOL,
		'inputSchema' => self::MODERATION_INPUT,
	], [
		'id' => 'stackiq.submitReview',
		'name' => 'Submit review',
		'description' => 'Review an application (subjectType: module) or service (subjectType: catalogService): review '
			. 'holds rating (1 to 10), name and text. The review is always stored as pending until a moderator '
			. 'approves it.',
		...self::CREATE_TOOL,
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
		...self::CREATE_TOOL,
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
		...self::UPDATE_TOOL,
		'inputSchema' => self::OFFER_INPUT,
	], [
		'id' => 'stackiq.declineOffer',
		'name' => 'Decline offer',
		'description' => 'Decline an offer made to the caller\'s active organisation; the offer is removed.',
		...self::DELETE_TOOL,
		'inputSchema' => self::OFFER_INPUT,
	], [
		'id' => 'stackiq.claimUsage',
		'name' => 'Claim usage',
		'description' => 'Take an offered usage record over as the caller\'s active organisation.',
		...self::UPDATE_TOOL,
		'inputSchema' => self::USAGE_INPUT,
	], [
		'id' => 'stackiq.declineUsage',
		'name' => 'Decline usage',
		'description' => 'Decline a usage record offered to the caller\'s organisation as consumer; the record is removed.',
		...self::DELETE_TOOL,
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
		...self::CREATE_TOOL,
		'inputSchema' => [
			'type' => 'object',
			'properties' => [
				'moduleVersionUuid' => self::UUID,
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
		...self::EXTERNAL_UPDATE_TOOL,
		'inputSchema' => self::NO_INPUT,
	]];

	/**
	 * Constructor.
	 *
	 * @param McpContractTools $contractTools The contract tools.
	 * @param McpReadTools     $readTools     The other read tools.
	 * @param McpWriteTools    $writeTools    The other write tools.
	 * @param McpDraftTools    $draftTools    The tools an assistant drafts an architecture view with.
	 */
	public function __construct(
		private readonly McpContractTools $contractTools,
		private readonly McpReadTools $readTools,
		private readonly McpWriteTools $writeTools,
		private readonly McpDraftTools $draftTools,
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
			'stackiq.searchArchitectureElements' => $this->draftTools->searchArchitectureElements(args: $arguments),
			'stackiq.draftView' => $this->draftTools->draftView(args: $arguments),
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
