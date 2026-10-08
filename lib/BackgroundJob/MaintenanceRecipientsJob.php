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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One owner resolution for one maintenance window.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceRecipientsJob extends QueuedJob {

	/**
	 * How many times one resolution is tried before it is given up and logged as critical.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Seconds between a failed resolution and the next try.
	 */
	public const RETRY_DELAY = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                $time       The time factory.
	 * @param MaintenanceRecipientService $recipients The owner resolution.
	 * @param IJobList                    $jobList    Queues the job again after a failure.
	 * @param LoggerInterface             $logger     The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MaintenanceRecipientService $recipients,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Resolve and record the owners for the window in the argument.
	 *
	 * A queued job is removed before it runs, so a resolution that fails (the
	 * window or the product cannot be read, or the window cannot be written)
	 * is queued again a few minutes later, up to MAX_ATTEMPTS tries.
	 *
	 * @param mixed $argument `{uuid, register, schema, attempt?}` of the window.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
	 */
	protected function run($argument): void {
		if (is_array($argument) === false || is_string($argument['uuid'] ?? null) === false) {
			return;
		}

		try {
			$this->recipients->recordRecipientsFor(
				uuid: $argument['uuid'],
				register: ($argument['register'] ?? null),
				schema: ($argument['schema'] ?? null)
			);
		} catch (DoesNotExistException $e) {
			// The window was deleted before its owners were resolved: nothing to do.
			return;
		} catch (Throwable $e) {
			$attempt = (int) ($argument['attempt'] ?? 1);
			$context = ['uuid' => $argument['uuid'], 'attempt' => $attempt, 'error' => $e->getMessage()];
			if ($attempt >= self::MAX_ATTEMPTS) {
				$this->logger->critical('MaintenanceRecipientsJob: gave up recording the owners to notify; nothing was written', $context);
				return;
			}

			$this->logger->error('MaintenanceRecipientsJob: could not record the owners to notify; tried again later', $context);
			$argument['attempt'] = ($attempt + 1);
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAY, $argument);
		}
	}//end run()
}//end class
