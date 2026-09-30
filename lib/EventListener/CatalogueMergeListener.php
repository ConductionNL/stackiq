<?php

/**
 * Catalogue Merge Listener.
 *
 * On OpenRegister's ObjectsMergedEvent, queues the relinker for that merge.
 * It only queues: the walk over the catalogue runs in a background job
 * (ADR-078), not inside the request that merged.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Stackiq\BackgroundJob\CatalogueMergeRelinkJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Queues the relink after a merge. A reversal queues nothing: OpenRegister
 * restores the two records, and the references stay on the survivor (the
 * documented gap until OpenRegister relinks inside its merge unit).
 *
 * @template-implements IEventListener<Event>
 */
class CatalogueMergeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IJobList        $jobList The job list.
	 * @param LoggerInterface $logger  Logger.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the relink for one merge.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectsMergedEvent) === false || $event->isReversal() === true) {
			return;
		}

		try {
			$this->jobList->add(
				CatalogueMergeRelinkJob::class,
				[
					'survivor' => $event->getSurvivorUuid(),
					'mergedFrom' => $event->getMergedFromUuids(),
					'operation' => $event->getMergeOperationId(),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CatalogueMergeListener: could not queue the relink',
				['operation' => $event->getMergeOperationId(), 'error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
