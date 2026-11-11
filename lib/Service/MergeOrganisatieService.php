<?php

/**
 * Merge Organisatie Service.
 *
 * Admin-triggered organisation-merge capability (VNG Softwarecatalogus #141):
 * folds a source organisation into a target organisation for gemeentelijke
 * herindeling (municipal mergers) or leveranciersovername (supplier
 * takeovers). Re-points every relation that references the source
 * organisation onto the target, migrates Nextcloud group membership, and
 * soft-retires the source with a tombstone (status = 'merged' +
 * mergedInto) rather than deleting it.
 *
 * `walkRelations()` is the single relation-enumeration routine shared by
 * `dryRun()` (commit: false — enumerate only) and `execute()` (commit: true
 * — enumerate AND re-point), guaranteeing dry-run/execute parity by
 * construction rather than by two independently-maintained implementations.
 *
 * Idempotency/resumability falls out of that same shared walk: once an
 * object has been re-pointed its organisation-reference field(s) no longer
 * equal `sourceUuid`, so a re-run's enumeration naturally finds nothing left
 * to do for that relation type — no separate "completed types" ledger is
 * needed. Every re-point reads the object's full current payload
 * (`ObjectEntity::getObject()`) and mutates only the organisation-reference
 * field(s) before re-saving the complete payload, because OpenRegister's
 * `saveObject()` is PUT-semantic (omitting a field nulls it).
 *
 * Relation-field mapping (design.md "Database Changes"):
 * - usage: `afnemer` (scalar) and `deelnemers` (array) business fields.
 * - contactPerson: `organisatie` (scalar) business field.
 * - aanbod/koppeling: `koppeling.aanbieder` (scalar) business field. The
 *   `aanbod` bucket name is the domain umbrella term (see
 *   AanbodController); `koppeling` is the schema that actually carries an
 *   `aanbieder` ownership field pointing at an organisatie.
 * - contract: the `contract` schema has no business-level organisation
 *   field (no `$ref: organisatie` property), so ownership is carried by
 *   OpenRegister's system-level `@self.organisation` (the same mechanism
 *   design.md documents explicitly for compliancy). Re-pointed via
 *   `@self.organisation` in the save payload, matching OpenRegister's
 *   `SaveObject::setSelfMetadata()` acceptance path, which honours a
 *   caller-supplied `@self.organisation` when the caller is an admin or a
 *   verified member of the target organisation; a merge is admin-triggered, so
 *   the admin arm applies. (An earlier revision of this docblock named
 *   `SaveObject::applyCallerSuppliedFields()`. No such method exists anywhere
 *   in OpenRegister — grepped across the whole tree with a positive control.)
 * - compliancy: `@self.organisation` (system-level owning organisation).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service orchestrating organisation-merge dry-run and execute.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coupling 15 (threshold 13). An organisation
 * merge legitimately touches every system that can hold an organisation reference: OpenRegister
 * objects, Nextcloud groups and users, the app's own organisation handler, the audit log
 * (`CriticalActionPerformedEvent`), the event dispatcher and the progress tracker. The count is
 * the breadth of the merge itself, not an accidental dependency tangle.
 */
class MergeOrganisatieService {
	/**
	 * The tombstone status value written to a merged-away source organisation.
	 *
	 * @var string
	 */
	private const TOMBSTONE_STATUS = 'merged';

	/**
	 * Relation types re-pointed via a business-level object field (scalar and/or array).
	 *
	 * The optional `schema` key overrides the OpenRegister schema slug when it
	 * differs from the relation-type key (`aanbod` lives on the `koppeling`
	 * schema); `walkRelations()` falls back to the key via `?? $type`.
	 *
	 * @var array<string, array{field: string, arrayField: string|null, schema?: string}>
	 */
	private const FIELD_RELATION_TYPES = [
		'usage' => ['field' => 'consumer', 'arrayField' => 'participants'],
		'contactPerson' => ['field' => 'organization', 'arrayField' => null],
		'aanbod' => ['field' => 'provider', 'arrayField' => null, 'schema' => 'connection'],
		// The supplier's own applications and services (`provider`, a Vendor
		// $ref to organization): after a takeover they belong to the target.
		'module' => ['field' => 'provider', 'arrayField' => null],
		'catalogService' => ['field' => 'provider', 'arrayField' => null],
	];

	/**
	 * The message of each merge request blocker, by blocker type.
	 *
	 * @var array<string, string>
	 */
	private const BLOCKER_MESSAGES = [
		'self-merge' => 'Source and target organisation cannot be the same.',
		'source-not-found' => 'Source organisation not found.',
		'target-not-found' => 'Target organisation not found.',
		'source-already-merged' => 'Source organisation has already been merged into a different target.',
		'target-already-merged' => 'Target organisation has already been merged into another organisation.',
	];

	/**
	 * Relation types re-pointed via the OpenRegister system-level `@self.organisation` field,
	 * as count key => schema slug. Modules and services carry a `provider`
	 * field too, so their ownership counts under its own key.
	 *
	 * @var array<string, string>
	 */
	private const SELF_ORGANISATION_RELATION_TYPES = [
		'catalogContract' => 'catalogContract',
		'compliancy' => 'compliancy',
		'moduleOwnership' => 'module',
		'catalogServiceOwnership' => 'catalogService',
	];

	/**
	 * MergeOrganisatieService constructor.
	 *
	 * @param ContainerInterface $container Container interface (lazy OpenRegister service resolution).
	 * @param IAppManager $appManager App manager (checks openregister is installed).
	 * @param IGroupManager $groupManager Group manager (NC group membership migration).
	 * @param LoggerInterface $logger Logger interface (structured audit + diagnostic entries).
	 * @param IEventDispatcher $eventDispatcher Event dispatcher (NC's `CriticalActionPerformedEvent` audit mechanism).
	 * @param SettingsService $settingsService Settings service (register/schema id resolution).
	 * @param OrganisatieService $organisationService Organisatie service (keeps the OR core Organisation.active flag in sync via mapStatus).
	 * @param ProgressTracker $progressTracker Progress tracker (SSE progress-tracking mechanism).
	 * @param OrganizationHandler $organizationHandler Organization handler (NC group creation/lookup for the target org).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly SettingsService $settingsService,
		private readonly OrganisatieService $organisationService,
		private readonly ProgressTracker $progressTracker,
		private readonly OrganizationHandler $organizationHandler,
	) {
	}//end __construct()

	/**
	 * Preview a merge: per-relation-type counts, no writes.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return array{sourceUuid: string, targetUuid: string, counts: array<string, int>, blockers: array<int, array{type: string, message: string}>}
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	public function dryRun(string $sourceUuid, string $targetUuid): array {
		$run = $this->mergeRun(sourceUuid: $sourceUuid, targetUuid: $targetUuid);
		$blockers = $this->validateMergeRequest(run: $run);

		$preview = [
			'sourceUuid' => $sourceUuid,
			'targetUuid' => $targetUuid,
			'counts' => $this->previewCounts(run: $run, blockers: $blockers),
			'blockers' => $blockers,
		];
		$this->auditLog(action: 'organisation-merge.dry-run', context: $preview);

		return $preview;
	}//end dryRun()

	/**
	 * The counts a dry run previews: zero when the merge is blocked.
	 *
	 * @param MergeRun                                       $run      The dry run.
	 * @param array<int, array{type: string, message: string}> $blockers The blockers found.
	 *
	 * @return array<string, int> Per-relation-type counts, with the source group's members.
	 */
	private function previewCounts(MergeRun $run, array $blockers): array {
		$counts = $this->emptyCounts();
		if (empty($blockers) === true) {
			$counts = $this->walkRelations(run: $run);
			$counts['groupMembers'] = $this->countGroupMembers(sourceUuid: $run->sourceUuid);
		}

		return $counts;
	}//end previewCounts()

	/**
	 * A merge run with its source and target organisations resolved.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return MergeRun The run (counting only, until an execution switches on writing).
	 */
	private function mergeRun(string $sourceUuid, string $targetUuid): MergeRun {
		$run = new MergeRun();
		$run->sourceUuid = $sourceUuid;
		$run->targetUuid = $targetUuid;
		$run->sourceEntity = $this->findOrganisation(uuid: $sourceUuid);
		$run->targetEntity = $this->findOrganisation(uuid: $targetUuid);
		return $run;
	}//end mergeRun()

	/**
	 * Execute a merge: re-point every relation type, migrate group
	 * membership, tombstone the source. Idempotent — a re-run against a
	 * partially or fully completed merge only processes what remains (see
	 * class docblock).
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 * @param string|null $actorUid The acting admin's UID (for progress/audit attribution).
	 *
	 * @return array{ok: bool, operationId?: string, sourceUuid: string, targetUuid: string,
	 *   status?: string, counts?: array<string, int>,
	 *   blockers?: array<int, array{type: string, message: string}>}
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function execute(string $sourceUuid, string $targetUuid, ?string $actorUid = null): array {
		$run = $this->mergeRun(sourceUuid: $sourceUuid, targetUuid: $targetUuid);
		$run->actorUid = $actorUid;
		return $this->executeMerge(run: $run);
	}//end execute()

	/**
	 * Execute a merge run: refuse it with its blockers, or re-point, migrate and tombstone.
	 *
	 * @param MergeRun $run The run, with its organisations resolved and its actor.
	 *
	 * @return array The execute result (see execute()).
	 */
	private function executeMerge(MergeRun $run): array {
		$blockers = $this->validateMergeRequest(run: $run);
		if (empty($blockers) === false) {
			return $this->refuseMerge(run: $run, blockers: $blockers);
		}

		$run->wasAlreadyTombstoned = $this->isTombstoned(organisation: $run->sourceEntity);
		$run->operationId = $this->startMergeOperation(run: $run);

		$run->commit = true;
		$run->counts = $this->walkRelations(run: $run);
		$run->counts['groupMembers'] = $this->migrateGroupMembership(sourceUuid: $run->sourceUuid, targetUuid: $run->targetUuid);
		$this->tombstoneSource(sourceUuid: $run->sourceUuid, targetUuid: $run->targetUuid);
		$this->progressTracker->completeOperation(finalStatistics: ['counts' => $run->counts]);

		return $this->completeMerge(run: $run);
	}//end executeMerge()

	/**
	 * Audit a blocked execution and answer its blockers.
	 *
	 * @param MergeRun                                         $run      The run.
	 * @param array<int, array{type: string, message: string}> $blockers The blockers found.
	 *
	 * @return array The refused execute result.
	 */
	private function refuseMerge(MergeRun $run, array $blockers): array {
		$this->auditLog(
			action: 'organisation-merge.execute.blocked',
			context: [
				'sourceUuid' => $run->sourceUuid,
				'targetUuid' => $run->targetUuid,
				'blockers' => $blockers,
				'actor' => $run->actorUid,
			]
		);

		return ['ok' => false, 'sourceUuid' => $run->sourceUuid, 'targetUuid' => $run->targetUuid, 'blockers' => $blockers];
	}//end refuseMerge()

	/**
	 * Start the progress operation of an execution, and audit the start.
	 *
	 * @param MergeRun $run The run.
	 *
	 * @return string The progress operation id.
	 */
	private function startMergeOperation(MergeRun $run): string {
		$operationId = $this->progressTracker->startOperation(
			operationType: 'org_merge',
			options: [
				'total_items' => count(self::FIELD_RELATION_TYPES) + count(self::SELF_ORGANISATION_RELATION_TYPES),
				'statistics' => [],
			],
			ownerUid: $run->actorUid
		);

		$this->auditLog(
			action: 'organisation-merge.execute.start',
			context: ['sourceUuid' => $run->sourceUuid, 'targetUuid' => $run->targetUuid, 'actor' => $run->actorUid, 'operationId' => $operationId]
		);

		return $operationId;
	}//end startMergeOperation()

	/**
	 * Audit a finished execution and answer its result.
	 *
	 * @param MergeRun $run The run, with its operation and counts.
	 *
	 * @return array The execute result.
	 */
	private function completeMerge(MergeRun $run): array {
		$status = $this->mergeStatus(run: $run);
		$this->logCompletedMerge(run: $run, status: $status);

		return [
			'ok' => true,
			'operationId' => $run->operationId,
			'sourceUuid' => $run->sourceUuid,
			'targetUuid' => $run->targetUuid,
			'status' => $status,
			'counts' => $run->counts,
		];
	}//end completeMerge()

	/**
	 * Whether the execution only found a merge that was already complete.
	 *
	 * @param MergeRun $run The run, with its counts.
	 *
	 * @return string already_completed when the source was tombstoned and nothing was re-pointed, completed otherwise.
	 */
	private function mergeStatus(MergeRun $run): string {
		$relationSum = 0;
		foreach (array_merge(array_keys(self::FIELD_RELATION_TYPES), array_keys(self::SELF_ORGANISATION_RELATION_TYPES)) as $type) {
			$relationSum += ($run->counts[$type] ?? 0);
		}

		if ($run->wasAlreadyTombstoned === true && $relationSum === 0) {
			return 'already_completed';
		}

		return 'completed';
	}//end mergeStatus()

	/**
	 * Audit a finished execution.
	 *
	 * @param MergeRun $run    The run, with its operation and counts.
	 * @param string   $status The execution status.
	 *
	 * @return void
	 */
	private function logCompletedMerge(MergeRun $run, string $status): void {
		$this->auditLog(
			action: 'organisation-merge.execute.omschrijving',
			context: [
				'sourceUuid' => $run->sourceUuid,
				'targetUuid' => $run->targetUuid,
				'actor' => $run->actorUid,
				'operationId' => $run->operationId,
				'status' => $status,
				'counts' => $run->counts,
			]
		);
	}//end logCompletedMerge()

	/**
	 * Whether an organisation is tombstoned.
	 *
	 * @param object|null $organisation The organisation entity, or null.
	 *
	 * @return bool False when there is no organisation.
	 */
	private function isTombstoned(?object $organisation): bool {
		return $organisation !== null
			&& (($organisation->getObject()['status'] ?? null) === self::TOMBSTONE_STATUS);
	}//end isTombstoned()

	/**
	 * The shared relation-enumeration routine. A dry run (`commit` false)
	 * enumerates and counts without writing; an execution (`commit` true)
	 * enumerates AND re-points. Both run the identical per-type logic below,
	 * which is what guarantees dry-run/execute parity.
	 *
	 * @param MergeRun $run The run: source, target and whether to write.
	 *
	 * @return array<string, int> Per-relation-type counts.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-dry-run-and-execute-must-report-structurally-identical-counts-for-the-same-unchanged-input
	 */
	private function walkRelations(MergeRun $run): array {
		$counts = $this->emptyCounts();

		foreach (self::FIELD_RELATION_TYPES as $type => $mapping) {
			$repoint = $run->fieldRepoint(objectType: ($mapping['schema'] ?? $type), mapping: $mapping);
			$counts[$type] = $this->repointByField(run: $run, repoint: $repoint);
			$this->reportTypeProgress(type: $type, count: $counts[$type], commit: $run->commit);
		}

		foreach (self::SELF_ORGANISATION_RELATION_TYPES as $type => $schemaType) {
			$counts[$type] = $this->repointBySelfOrganisation(run: $run, objectType: $schemaType);
			$this->reportTypeProgress(type: $type, count: $counts[$type], commit: $run->commit);
		}

		return $counts;
	}//end walkRelations()

	/**
	 * Report per-type progress during execute (no-op during dry-run — a
	 * dry-run does not own a progress-tracking operation).
	 *
	 * @param string $type Relation type identifier.
	 * @param int $count Number of objects processed for this type.
	 * @param bool $commit Whether this is an execute pass (progress is execute-only).
	 *
	 * @return void
	 */
	private function reportTypeProgress(string $type, int $count, bool $commit): void {
		if ($commit === false) {
			return;
		}

		$this->progressTracker->incrementProgress(currentItem: $type, itemType: 'relationType');
		$this->progressTracker->updateStatistics(statistics: [$type => $count]);
	}//end reportTypeProgress()

	/**
	 * Re-point objects of a schema via a business-level scalar field and/or array field.
	 *
	 * @param MergeRun     $run     The run: whether to write (true) or only count (false).
	 * @param FieldRepoint $repoint The schema, its fields, and the source and target organisations.
	 *
	 * @return int The number of distinct objects that reference (or referenced) the source.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	private function repointByField(MergeRun $run, FieldRepoint $repoint): int {
		$entities = $this->findAllForType(objectType: $repoint->objectType);
		$count = 0;

		foreach ($entities as $entity) {
			$data = $repoint->apply(data: $entity->getObject());
			if ($data === null) {
				continue;
			}

			$count++;

			if ($run->commit === true) {
				$this->saveFull(entity: $entity, data: $data, objectType: $repoint->objectType);
			}
		}

		return $count;
	}//end repointByField()

	/**
	 * Re-point objects of a schema via the OpenRegister system-level
	 * `@self.organisation` (owning organisation) field.
	 *
	 * @param MergeRun $run        The run: source, target and whether to write (true) or only count (false).
	 * @param string   $objectType The OpenRegister object type/schema slug.
	 *
	 * @return int The number of distinct objects that reference (or referenced) the source.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	private function repointBySelfOrganisation(MergeRun $run, string $objectType): int {
		$entities = $this->findAllForType(objectType: $objectType);
		$count = 0;

		foreach ($entities as $entity) {
			$owningOrganisation = $this->readOwningOrganisation(entity: $entity);

			if ($owningOrganisation !== $run->sourceUuid) {
				continue;
			}

			$count++;

			if ($run->commit === true) {
				$this->saveFull(entity: $entity, data: self::ownedBy(data: $entity->getObject(), organisation: $run->targetUuid), objectType: $objectType);
			}
		}

		return $count;
	}//end repointBySelfOrganisation()

	/**
	 * An object's data with its `@self.organisation` set to an organisation.
	 *
	 * @param array<string, mixed> $data         The object's data.
	 * @param string               $organisation The owning organisation UUID.
	 *
	 * @return array<string, mixed> The data.
	 */
	private static function ownedBy(array $data, string $organisation): array {
		$data['@self'] = ($data['@self'] ?? []) + ['organisation' => $organisation];
		return $data;
	}//end ownedBy()

	/**
	 * Read an OpenRegister object's system-level owning organisation
	 * (`@self.organisation`).
	 *
	 * `ObjectEntity` declares `getOrganisation()` ONLY as an `@method` docblock
	 * tag over `protected ?string $organisation`, so the accessor is reached
	 * through `OCP\AppFramework\Db\Entity::__call()`. Two probes are therefore
	 * wrong here, and both fail silently:
	 *
	 * - `method_exists()` is **false** for every such accessor. That was
	 *   stackiq#490: the caller's re-point branch never ran, so a merge
	 *   re-pointed nothing for `contract`/`compliancy` while still tombstoning
	 *   the source organisation.
	 * - `is_callable()` is **true** for ANY name on a class with `__call()`, so
	 *   swapping the probe would make the branch unconditionally true and move
	 *   the failure into a runtime `BadFunctionCallException`.
	 *
	 * `Entity::getter()` itself decides on `property_exists()`, so that is the
	 * primary instrument below; `method_exists()` is kept as a second arm for
	 * an entity that genuinely declares the accessor. The call is still
	 * wrapped, because `$entity` comes from `ObjectService::findAll()` and is
	 * not type-guaranteed to be an `Entity` subclass.
	 *
	 * Deliberately NOT read from `jsonSerialize()`: `ObjectEntity::getObjectArray()`
	 * types `organisation` as `array|string|null`, so an expanded organisation
	 * would silently fail the UUID comparison in the caller. The property holds
	 * the raw `?string`.
	 *
	 * @param object $entity The OpenRegister ObjectEntity to read.
	 *
	 * @return string|null The owning organisation UUID, or null when the entity carries none.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	private function readOwningOrganisation(object $entity): ?string {
		if (property_exists($entity, 'organisation') === false
			&& method_exists($entity, 'getOrganisation') === false
		) {
			return null;
		}

		try {
			$owningOrganisation = $entity->getOrganisation();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MergeOrganisatieService: could not read @self.organisation from object entity',
				['exception' => $e->getMessage(), 'entity' => $entity::class]
			);
			return null;
		}

		if (is_string($owningOrganisation) === false) {
			return null;
		}

		return $owningOrganisation;
	}//end readOwningOrganisation()

	/**
	 * Save the full existing payload (only the organisation-reference field(s)
	 * mutated) back via OpenRegister's `ObjectService::saveObject()` —
	 * PUT-semantic, so the full payload must always be carried forward.
	 *
	 * @param object $entity The existing ObjectEntity (source of the UUID).
	 * @param array $data The full object payload, with only the organisation-reference field(s) changed.
	 * @param string $objectType The OpenRegister object type/schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	private function saveFull(object $entity, array $data, string $objectType): void {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: $objectType);

		if ($objectService === null || $registerId === null || $schemaId === null) {
			$this->logger->error(
				'MergeOrganisatieService: cannot save re-pointed object, register/schema not configured',
				['objectType' => $objectType, 'uuid' => $entity->getUuid()]
			);
			return;
		}

		$objectService->saveObject(
			object: $data,
			extend: [],
			register: (int)$registerId,
			schema: (int)$schemaId,
			uuid: $entity->getUuid()
		);
	}//end saveFull()

	/**
	 * Migrate Nextcloud group membership from the source organisation's
	 * group to the target organisation's group. Idempotent — a user already
	 * in the target group is skipped without error.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return int The number of source-group members processed.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-nc-group-membership-must-be-migrated-from-source-to-target
	 */
	private function migrateGroupMembership(string $sourceUuid, string $targetUuid): int {
		$sourceGroup = $this->resolveSourceGroup(sourceUuid: $sourceUuid);
		if ($sourceGroup === null) {
			return 0;
		}

		$targetGroup = $this->resolveTargetGroup(targetUuid: $targetUuid);
		if ($targetGroup === null) {
			$this->logger->warning(
				'MergeOrganisatieService: could not resolve/create target group for membership migration',
				['targetUuid' => $targetUuid]
			);
			return 0;
		}

		$members = $sourceGroup->getUsers();
		foreach ($members as $user) {
			if ($targetGroup->inGroup($user) === false) {
				$targetGroup->addUser($user);
			}
		}

		return count($members);
	}//end migrateGroupMembership()

	/**
	 * Count the source organisation's NC group members (for dry-run's
	 * `groupMembers` count) without migrating anything.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 *
	 * @return int The number of source-group members.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
	 */
	private function countGroupMembers(string $sourceUuid): int {
		$sourceGroup = $this->resolveSourceGroup(sourceUuid: $sourceUuid);
		if ($sourceGroup === null) {
			return 0;
		}

		return count($sourceGroup->getUsers());
	}//end countGroupMembers()

	/**
	 * Resolve the source organisation's NC group, if any.
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 *
	 * @return \OCP\IGroup|null The source group, or null when unresolvable.
	 */
	private function resolveSourceGroup(string $sourceUuid): ?\OCP\IGroup {
		$sourceEntity = $this->findOrganisation(uuid: $sourceUuid);
		if ($sourceEntity === null) {
			return null;
		}

		$sourceGroupId = $sourceEntity->getObject()['group'] ?? null;
		if (empty($sourceGroupId) === true) {
			return null;
		}

		return $this->groupManager->get($sourceGroupId);
	}//end resolveSourceGroup()

	/**
	 * Resolve (or, via `OrganizationHandler`, create) the target
	 * organisation's NC group.
	 *
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return \OCP\IGroup|null The target group, or null when unresolvable.
	 */
	private function resolveTargetGroup(string $targetUuid): ?\OCP\IGroup {
		$targetEntity = $this->findOrganisation(uuid: $targetUuid);
		if ($targetEntity === null) {
			return null;
		}

		$targetData = $targetEntity->getObject();
		$targetGroupId = $targetData['group'] ?? null;

		if (empty($targetGroupId) === false) {
			$group = $this->groupManager->get($targetGroupId);
			if ($group !== null) {
				return $group;
			}
		}

		$targetGroupId = $this->organizationHandler->ensureOrganizationGroup(organizationObject: $targetEntity, objectData: $targetData);
		if ($targetGroupId === null) {
			return null;
		}

		return $this->groupManager->get($targetGroupId);
	}//end resolveTargetGroup()

	/**
	 * Tombstone the source organisation: PUT-semantic full re-save with
	 * `status = 'merged'` and `mergedInto = targetUuid`, plus keeping
	 * the OR core Organisation.active flag in sync via
	 * `OrganisatieService::updateOrganizationStatus()` (organisatie-service
	 * spec delta).
	 *
	 * @param string $sourceUuid The source organisation UUID.
	 * @param string $targetUuid The target organisation UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
	 */
	private function tombstoneSource(string $sourceUuid, string $targetUuid): void {
		$entity = $this->findOrganisation(uuid: $sourceUuid);
		if ($entity === null) {
			$this->logger->error('MergeOrganisatieService: cannot tombstone, source organisation not found', ['sourceUuid' => $sourceUuid]);
			return;
		}

		$data = $entity->getObject();
		$data['status'] = self::TOMBSTONE_STATUS;
		$data['mergedInto'] = $targetUuid;

		$this->saveFull(entity: $entity, data: $data, objectType: 'organization');

		// Keep the separate OR core Organisation.active flag in sync (organisatie-service spec delta).
		$this->organisationService->updateOrganizationStatus(organizationUuid: $sourceUuid, objectData: ['beoordeling' => self::TOMBSTONE_STATUS]);
	}//end tombstoneSource()

	/**
	 * Validate a merge request, producing a `blockers` array shared by
	 * dry-run and execute so the two paths can never structurally disagree
	 * on whether a merge is legal.
	 *
	 * A source already tombstoned into the SAME requested target is
	 * deliberately NOT a blocker — it is the idempotent re-run case
	 * (execute reports `already_completed`; dry-run reports zero counts).
	 *
	 * @param MergeRun $run The run, with its source and target organisations resolved.
	 *
	 * @return array<int, array{type: string, message: string}> Blockers (empty when the merge may proceed).
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
	 */
	private function validateMergeRequest(MergeRun $run): array {
		if ($run->sourceUuid === $run->targetUuid) {
			return [self::blocker(type: 'self-merge')];
		}

		return array_merge(self::missingOrganisationBlockers(run: $run), $this->tombstoneBlockers(run: $run));
	}//end validateMergeRequest()

	/**
	 * The blockers for a source or target organisation that cannot be found.
	 *
	 * @param MergeRun $run The run, with its source and target organisations resolved.
	 *
	 * @return array<int, array{type: string, message: string}> The blockers.
	 */
	private static function missingOrganisationBlockers(MergeRun $run): array {
		$blockers = [];
		if ($run->sourceEntity === null) {
			$blockers[] = self::blocker(type: 'source-not-found');
		}

		if ($run->targetEntity === null) {
			$blockers[] = self::blocker(type: 'target-not-found');
		}

		return $blockers;
	}//end missingOrganisationBlockers()

	/**
	 * The blockers for a source merged into a different target, and a target that was merged itself.
	 *
	 * @param MergeRun $run The run, with its source and target organisations resolved.
	 *
	 * @return array<int, array{type: string, message: string}> The blockers.
	 */
	private function tombstoneBlockers(MergeRun $run): array {
		$blockers = [];
		if (self::isMergedElsewhere(run: $run) === true) {
			$blockers[] = self::blocker(type: 'source-already-merged');
		}

		if ($this->isTombstoned(organisation: $run->targetEntity) === true) {
			$blockers[] = self::blocker(type: 'target-already-merged');
		}

		return $blockers;
	}//end tombstoneBlockers()

	/**
	 * A blocker of a merge request.
	 *
	 * @param string $type The blocker type, a key of BLOCKER_MESSAGES.
	 *
	 * @return array{type: string, message: string} The blocker.
	 */
	private static function blocker(string $type): array {
		return ['type' => $type, 'message' => self::BLOCKER_MESSAGES[$type]];
	}//end blocker()

	/**
	 * Whether the source was already merged into another organisation than the target.
	 *
	 * @param MergeRun $run The run, with its source organisation resolved.
	 *
	 * @return bool False when there is no source.
	 */
	private static function isMergedElsewhere(MergeRun $run): bool {
		if ($run->sourceEntity === null) {
			return false;
		}

		$sourceData = $run->sourceEntity->getObject();
		return ($sourceData['status'] ?? null) === self::TOMBSTONE_STATUS
			&& ($sourceData['mergedInto'] ?? null) !== $run->targetUuid;
	}//end isMergedElsewhere()

	/**
	 * Find an organisatie object by UUID.
	 *
	 * @param string $uuid The organisation UUID.
	 *
	 * @return object|null The ObjectEntity, or null when not found/unresolvable.
	 */
	private function findOrganisation(string $uuid): ?object {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: 'organization');

		if ($objectService === null || $registerId === null || $schemaId === null) {
			return null;
		}

		try {
			return $objectService->find(id: $uuid, register: (int)$registerId, schema: (int)$schemaId);
		} catch (\Throwable $e) {
			$this->logger->debug('MergeOrganisatieService: organisation not found', ['uuid' => $uuid, 'error' => $e->getMessage()]);
			return null;
		}
	}//end findOrganisatie()

	/**
	 * Find all objects of a given type in the voorzieningen register.
	 *
	 * @param string $objectType The OpenRegister object type/schema slug.
	 *
	 * @return array<int, object> The matching ObjectEntity instances (empty when unresolvable).
	 */
	private function findAllForType(string $objectType): array {
		$objectService = $this->getObjectService();
		$registerId = $this->settingsService->getVoorzieningenRegisterId();
		$schemaId = $this->settingsService->getSchemaIdForObjectType(objectType: $objectType);

		if ($objectService === null || $registerId === null || $schemaId === null) {
			return [];
		}

		return $objectService->findAll(
			config: [
				'_register' => (int)$registerId,
				'_schema' => (int)$schemaId,
				'limit' => 10000,
			]
		);
	}//end findAllForType()

	/**
	 * Gets the OpenRegister ObjectService if available.
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null when openregister is not installed.
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error('MergeOrganisatieService: Failed to get ObjectService: ' . $e->getMessage());
			return null;
		}
	}//end getObjectService()

	/**
	 * The empty (all-zero) per-relation-type counts shape.
	 *
	 * @return array<string, int>
	 */
	private function emptyCounts(): array {
		$counts = ['groupMembers' => 0];
		foreach (array_keys(self::FIELD_RELATION_TYPES) as $type) {
			$counts[$type] = 0;
		}

		foreach (array_keys(self::SELF_ORGANISATION_RELATION_TYPES) as $type) {
			$counts[$type] = 0;
		}

		return $counts;
	}//end emptyCounts()

	/**
	 * Write an audit log entry for a dry-run or execute call: a structured
	 * logger entry (queryable/testable) plus Nextcloud's own
	 * `CriticalActionPerformedEvent` (the existing admin_audit mechanism —
	 * no app-local notification/audit dispatch, per ADR-031 precedent).
	 *
	 * @param string $action The audit action identifier.
	 * @param array $context Structured context (actor, source/target uuids, counts, ...).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry
	 */
	private function auditLog(string $action, array $context): void {
		$this->logger->info('OrganisationMerge audit: ' . $action, $context + ['audit' => true]);

		$this->eventDispatcher->dispatchTyped(
			new CriticalActionPerformedEvent(
				'OrganisationMerge: %s (source: %s, target: %s)',
				[
					$action,
					$context['sourceUuid'] ?? 'unknown',
					$context['targetUuid'] ?? 'unknown',
				]
			)
		);
	}//end auditLog()
}//end class
