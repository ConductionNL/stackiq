<?php

/**
 * Organisation merge run
 *
 * The state of one dry run or execution of an organisation merge, built and
 * read only by MergeOrganisatieService.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * One merge: the source and target, who runs it, whether it writes, and what it found and did.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */
final class MergeRun {

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
	 * The source organisation UUID.
	 *
	 * @var string
	 */
	public string $sourceUuid = '';

	/**
	 * The target organisation UUID.
	 *
	 * @var string
	 */
	public string $targetUuid = '';

	/**
	 * The acting admin's UID (for progress/audit attribution).
	 *
	 * @var string|null
	 */
	public ?string $actorUid = null;

	/**
	 * Whether to write (execute) or only count (dry run).
	 *
	 * @var bool
	 */
	public bool $commit = false;

	/**
	 * The resolved source organisation entity, or null.
	 *
	 * @var object|null
	 */
	public ?object $sourceEntity = null;

	/**
	 * The resolved target organisation entity, or null.
	 *
	 * @var object|null
	 */
	public ?object $targetEntity = null;

	/**
	 * Whether the source was tombstoned before this execution.
	 *
	 * @var bool
	 */
	public bool $wasAlreadyTombstoned = false;

	/**
	 * The progress operation of the execution.
	 *
	 * @var string
	 */
	public string $operationId = '';

	/**
	 * Per-relation-type counts.
	 *
	 * @var array<string, int>
	 */
	public array $counts = [];

	/**
	 * The re-pointing of one business-level organisation field of a schema.
	 *
	 * @param string $objectType The OpenRegister object type/schema slug.
	 * @param array  $mapping    The relation type: `field` and `arrayField`.
	 *
	 * @return FieldRepoint The re-pointing from this run's source to its target.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function fieldRepoint(string $objectType, array $mapping): FieldRepoint {
		$repoint             = new FieldRepoint();
		$repoint->objectType = $objectType;
		$repoint->field      = $mapping['field'];
		$repoint->arrayField = $mapping['arrayField'];
		$repoint->source     = $this->sourceUuid;
		$repoint->target     = $this->targetUuid;
		return $repoint;
	}//end fieldRepoint()

	/**
	 * Validate the merge request. A source already tombstoned INTO THIS TARGET is
	 * deliberately NOT a blocker — it is the idempotent re-run case (execute reports
	 * `already_completed`; dry-run reports zero counts).
	 *
	 * @return array<int, array{type: string, message: string}> Blockers (empty when the merge may proceed).
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
	 */
	public function blockers(): array {
		if ($this->sourceUuid === $this->targetUuid) {
			return [self::blocker(type: 'self-merge')];
		}

		return array_merge($this->missingOrganisationBlockers(), $this->tombstoneBlockers());
	}//end blockers()

	/**
	 * Whether the execution only found a merge that was already complete.
	 *
	 * @param array<int, string> $relationTypes The relation type count keys.
	 *
	 * @return string already_completed when the source was tombstoned and nothing was re-pointed, completed otherwise.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function status(array $relationTypes): string {
		$relationSum = 0;
		foreach ($relationTypes as $type) {
			$relationSum += ($this->counts[$type] ?? 0);
		}

		if ($this->wasAlreadyTombstoned === true && $relationSum === 0) {
			return 'already_completed';
		}

		return 'completed';
	}//end status()

	/**
	 * An object's data with its `@self.organisation` set to the target.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function ownedByTarget(array $data): array {
		$data['@self'] = ($data['@self'] ?? []) + ['organisation' => $this->targetUuid];
		return $data;
	}//end ownedByTarget()

	/**
	 * Whether the source organisation is tombstoned.
	 *
	 * @return bool False when there is no source.
	 *
	 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
	 */
	public function sourceIsTombstoned(): bool {
		return self::isTombstoned(organisation: $this->sourceEntity);
	}//end sourceIsTombstoned()

	/**
	 * Whether an organisation is tombstoned.
	 *
	 * @param object|null $organisation The organisation entity, or null.
	 *
	 * @return bool False when there is no organisation.
	 */
	private static function isTombstoned(?object $organisation): bool {
		return $organisation !== null
			&& (($organisation->getObject()['status'] ?? null) === MergeOrganisatieService::TOMBSTONE_STATUS);
	}//end isTombstoned()

	/**
	 * The blockers for a source or target organisation that cannot be found.
	 *
	 * @return array<int, array{type: string, message: string}> The blockers.
	 */
	private function missingOrganisationBlockers(): array {
		$blockers = [];
		if ($this->sourceEntity === null) {
			$blockers[] = self::blocker(type: 'source-not-found');
		}

		if ($this->targetEntity === null) {
			$blockers[] = self::blocker(type: 'target-not-found');
		}

		return $blockers;
	}//end missingOrganisationBlockers()

	/**
	 * The blockers for a source merged into a different target, and a target that was merged itself.
	 *
	 * @return array<int, array{type: string, message: string}> The blockers.
	 */
	private function tombstoneBlockers(): array {
		$blockers = [];
		if ($this->isMergedElsewhere() === true) {
			$blockers[] = self::blocker(type: 'source-already-merged');
		}

		if (self::isTombstoned(organisation: $this->targetEntity) === true) {
			$blockers[] = self::blocker(type: 'target-already-merged');
		}

		return $blockers;
	}//end tombstoneBlockers()

	/**
	 * Whether the source was already merged into another organisation than the target.
	 *
	 * @return bool False when there is no source.
	 */
	private function isMergedElsewhere(): bool {
		if ($this->sourceEntity === null) {
			return false;
		}

		$sourceData = $this->sourceEntity->getObject();
		return ($sourceData['status'] ?? null) === MergeOrganisatieService::TOMBSTONE_STATUS
			&& ($sourceData['mergedInto'] ?? null) !== $this->targetUuid;
	}//end isMergedElsewhere()

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
}//end class
