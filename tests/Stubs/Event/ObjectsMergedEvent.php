<?php

/**
 * Test stub of OpenRegister's ObjectsMergedEvent.
 *
 * A copy of `lib/Event/ObjectsMergedEvent.php` in ConductionNL/openregister at
 * development 753562d0 (30 Sep 2026): the same constructor and the same
 * accessors, so a listener test that calls a method the real event lacks fails
 * here as it would in production.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired after a merge (or a merge reversal) completes.
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 */
class ObjectsMergedEvent extends Event {
	/**
	 * Capture the merge participants for downstream listeners.
	 *
	 * @param string $survivorUuid UUID of the surviving object.
	 * @param array<int, string> $mergedFromUuids UUIDs of the merged-away objects.
	 * @param string $mergeOperationId UUID of the persisted `mergeOperation` row.
	 * @param bool $isReversal True when this event represents a reversal, not a merge.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Immutable read-only event
	 *   data, not a control-flow switch: `isReversal` is a fact about what
	 *   already happened (a merge vs. its reversal), the shape the spec
	 *   requires so a single subscriber can distinguish the two without a
	 *   second event class.
	 */
	public function __construct(
		private readonly string $survivorUuid,
		private readonly array $mergedFromUuids,
		private readonly string $mergeOperationId,
		private readonly bool $isReversal = false,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Read the surviving object's uuid.
	 *
	 * @return string Survivor uuid.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function getSurvivorUuid(): string {
		return $this->survivorUuid;
	}//end getSurvivorUuid()

	/**
	 * Read the merged-away object uuids.
	 *
	 * @return array<int, string> Merged-from uuids.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function getMergedFromUuids(): array {
		return $this->mergedFromUuids;
	}//end getMergedFromUuids()

	/**
	 * Read the persisted `mergeOperation` row id.
	 *
	 * @return string Merge-operation uuid.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function getMergeOperationId(): string {
		return $this->mergeOperationId;
	}//end getMergeOperationId()

	/**
	 * Whether this event represents a reversal rather than a merge.
	 *
	 * @return bool True when this is a reversal.
	 *
	 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function isReversal(): bool {
		return $this->isReversal;
	}//end isReversal()
}//end class
