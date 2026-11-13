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
}//end class
