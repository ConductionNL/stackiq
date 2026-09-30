<?php

/**
 * Catalogue Merge Relink Job.
 *
 * Runs the relinker for one merge, outside the request that merged.
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 */

declare(strict_types=1);

namespace OCA\Stackiq\BackgroundJob;

use OCA\Stackiq\Service\CatalogueMergeRelinker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Queued once per merge by CatalogueMergeListener.
 */
class CatalogueMergeRelinkJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time     The time factory.
	 * @param CatalogueMergeRelinker $relinker The relinker.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CatalogueMergeRelinker $relinker,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Relink one merge.
	 *
	 * @param mixed $argument survivor, mergedFrom and operation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	protected function run($argument): void {
		if (is_array($argument) === false || is_string($argument['survivor'] ?? null) === false) {
			return;
		}

		$this->relinker->relink(
			survivorUuid: $argument['survivor'],
			mergedFromUuids: array_values(array_map('strval', (array) ($argument['mergedFrom'] ?? []))),
			operationId: (string) ($argument['operation'] ?? '')
		);
	}//end run()
}//end class
