<?php

/**
 * Maintenance Recipients Job.
 *
 * Records the owners of every usage of a product on a newly announced
 * maintenance window, queued by MaintenanceRecipientsListener so the reads and
 * the write run off the supplier's request.
 *
 * @category  BackgroundJob
 * @package   OCA\Stackiq\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\BackgroundJob;

use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * One owner resolution for one maintenance window.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceRecipientsJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                $time       The time factory.
	 * @param MaintenanceRecipientService $recipients The owner resolution.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MaintenanceRecipientService $recipients,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Resolve and record the owners for the window in the argument.
	 *
	 * @param mixed $argument `{uuid, register, schema}` of the window.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	protected function run($argument): void {
		if (is_array($argument) === false || is_string($argument['uuid'] ?? null) === false) {
			return;
		}

		$this->recipients->recordRecipientsFor(
			uuid: $argument['uuid'],
			register: ($argument['register'] ?? null),
			schema: ($argument['schema'] ?? null)
		);
	}//end run()
}//end class
