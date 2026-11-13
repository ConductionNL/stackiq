<?php

/**
 * Characterisation tests for MaintenanceRecipientsJob::run.
 *
 * Pins, per argument, whether the job records the owners of a maintenance
 * window, when it queues itself again or gives up, and every call it makes.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\BackgroundJob;

use OCA\Stackiq\BackgroundJob\MaintenanceRecipientsJob;
use OCA\Stackiq\Service\MaintenanceRecipientService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Cases for the owner recording job.
 *
 * @covers \OCA\Stackiq\BackgroundJob\MaintenanceRecipientsJob
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
class MaintenanceRecipientsJobRunTest extends TestCase {

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Run the job.
	 *
	 * @param mixed           $argument The job argument.
	 * @param \Throwable|null $failure  What recording throws, if anything.
	 *
	 * @return array The trace.
	 */
	private function runJob(mixed $argument, ?\Throwable $failure): array {
		$this->trace = [];

		$recipients = $this->createMock(originalClassName: MaintenanceRecipientService::class);
		$recipients->method('recordRecipientsFor')->willReturnCallback(
			function (string $uuid, $register, $schema) use ($failure) {
				$this->trace[] = ['recordRecipientsFor', $uuid, $register, $schema];
				if ($failure !== null) {
					throw $failure;
				}

				return ['alice'];
			}
		);

		$jobs = $this->createMock(originalClassName: IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) {
				$this->trace[] = ['scheduleAfter', $job, $runAfter, $argument];
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$job = new MaintenanceRecipientsJob($time, $recipients, $jobs, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);

		return $this->trace;

	}//end runJob()

	/**
	 * An argument without a window id does nothing.
	 *
	 * @return void
	 */
	public function testArgumentWithoutWindowDoesNothing(): void {
		foreach ([null, 'w1', [], ['uuid' => 5], ['register' => '7']] as $argument) {
			$this->assertSame([], $this->runJob(argument: $argument, failure: null), var_export($argument, true));
		}

	}//end testArgumentWithoutWindowDoesNothing()

	/**
	 * The owners of the window are recorded; missing register and schema are passed as null.
	 *
	 * @return void
	 */
	public function testTheOwnersAreRecorded(): void {
		$this->assertSame([['recordRecipientsFor', 'w1', '7', '40']], $this->runJob(argument: ['uuid' => 'w1', 'register' => '7', 'schema' => '40'], failure: null));
		$this->assertSame([['recordRecipientsFor', 'w1', null, null]], $this->runJob(argument: ['uuid' => 'w1'], failure: null));

	}//end testTheOwnersAreRecorded()

	/**
	 * A deleted window ends the job quietly.
	 *
	 * @return void
	 */
	public function testADeletedWindowEndsQuietly(): void {
		$this->assertSame([['recordRecipientsFor', 'w1', null, null]], $this->runJob(argument: ['uuid' => 'w1'], failure: new DoesNotExistException('gone')));

	}//end testADeletedWindowEndsQuietly()

	/**
	 * A failure is queued again until the last try, then given up.
	 *
	 * @return void
	 */
	public function testAFailureIsTriedAgainThenGivenUp(): void {
		$this->assertSame(
			[
				['recordRecipientsFor', 'w1', '7', null],
				['log', 'error', 'MaintenanceRecipientsJob: could not record the owners to notify; tried again later', ['uuid' => 'w1', 'attempt' => 1, 'error' => 'read failed']],
				['scheduleAfter', MaintenanceRecipientsJob::class, 1300, ['uuid' => 'w1', 'register' => '7', 'attempt' => 2]],
			],
			$this->runJob(argument: ['uuid' => 'w1', 'register' => '7'], failure: new \RuntimeException('read failed'))
		);

		$this->assertSame(
			[
				['recordRecipientsFor', 'w1', null, null],
				['log', 'error', 'MaintenanceRecipientsJob: could not record the owners to notify; tried again later', ['uuid' => 'w1', 'attempt' => 2, 'error' => 'type']],
				['scheduleAfter', MaintenanceRecipientsJob::class, 1300, ['uuid' => 'w1', 'attempt' => 3]],
			],
			$this->runJob(argument: ['uuid' => 'w1', 'attempt' => '2'], failure: new \TypeError('type'))
		);

		$this->assertSame(
			[
				['recordRecipientsFor', 'w1', null, null],
				['log', 'critical', 'MaintenanceRecipientsJob: gave up recording the owners to notify; nothing was written', ['uuid' => 'w1', 'attempt' => 3, 'error' => 'read failed']],
			],
			$this->runJob(argument: ['uuid' => 'w1', 'attempt' => 3], failure: new \RuntimeException('read failed'))
		);

	}//end testAFailureIsTriedAgainThenGivenUp()
}//end class
