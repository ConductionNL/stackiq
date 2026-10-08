<?php

/**
 * Maintenance Recipients Listener.
 *
 * When a supplier announces a maintenance window, queues the job that records
 * the owners of every usage of the product on it, so the schema's
 * notification rules can reach them. The listener only queues: reading the
 * usages and writing the window happen in the background job, off the
 * supplier's request (ADR-078).
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Stackiq\BackgroundJob\MaintenanceRecipientsJob;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Queues the owner resolution for a newly created maintenance window.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceRecipientsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param MaintenanceRecipientService $recipients The schema check.
	 * @param IJobList                    $jobList    The background job list.
	 * @param LoggerInterface             $logger     The logger.
	 */
	public function __construct(
		private readonly MaintenanceRecipientService $recipients,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object created event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		try {
			if ($this->recipients->isMaintenanceWindow(object: $object) === false) {
				return;
			}

			$this->jobList->add(
				MaintenanceRecipientsJob::class,
				[
					'uuid'     => $object->getUuid(),
					'register' => $object->getRegister(),
					'schema'   => $object->getSchema(),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MaintenanceRecipientsListener: could not queue the owner resolution',
				['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
