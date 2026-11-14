<?php

/**
 * SyncProgress
 *
 * Reports a run of the organisation sync to the progress tracker and builds
 * the record of its last run.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * One run of the organisation sync as an `organisation_sync` operation.
 *
 * Progress is a courtesy to whoever watches: without a tracker every call is
 * a no-op, and a tracker that throws never reaches the sync.
 */
class SyncProgress {

	/**
	 * The operation type of the organisation sync.
	 *
	 * @var string
	 */
	public const OPERATION_TYPE = 'organisation_sync';

	/**
	 * The counts the sync returns that the last-run record keeps.
	 *
	 * @var string[]
	 */
	private const COUNTS = [
		'organizationsProcessed',
		'entitiesCreated',
		'entitiesUpdated',
		'contactPersonsProcessed',
		'usersCreated',
	];

	/**
	 * When this run started, `Y-m-d H:i:s`.
	 *
	 * @var string
	 */
	private string $startedAt;

	/**
	 * Constructor.
	 *
	 * @param ProgressTracker|null $tracker The tracker, or null when there is none.
	 * @param string               $trigger `scheduled` or `manual`.
	 */
	public function __construct(
		private readonly ?ProgressTracker $tracker,
		private readonly string $trigger,
	) {
		$this->startedAt = date('Y-m-d H:i:s');
	}//end __construct()

	/**
	 * A run reported to the container's progress tracker, when it has one.
	 *
	 * @param ContainerInterface $container The container.
	 * @param string             $trigger   `scheduled` or `manual`.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
	 */
	public static function start(ContainerInterface $container, string $trigger): self {
		$tracker = null;
		try {
			$candidate = $container->get(ProgressTracker::class);
			if ($candidate instanceof ProgressTracker) {
				$tracker = $candidate;
			}
		} catch (Throwable) {
			$tracker = null;
		}

		$run = new self(tracker: $tracker, trigger: $trigger);
		$run->call(
			call: fn (ProgressTracker $t) => $t->startOperation(
				operationType: self::OPERATION_TYPE,
				options: ['statistics' => ['trigger' => $trigger]]
			)
		);

		return $run;
	}//end start()

	/**
	 * Move the run to a phase: `processing_organizations`, `processing_contacts` or `processing_users`.
	 *
	 * @param string               $phase      The phase.
	 * @param array<string, mixed> $statistics The counts so far.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
	 */
	public function phase(string $phase, array $statistics = []): void {
		$this->call(
			call: function (ProgressTracker $t) use ($phase, $statistics): void {
				$t->setPhase(phase: $phase);
				if ($statistics !== []) {
					$t->updateStatistics(statistics: $this->countsOf(results: $statistics));
				}
			}
		);
	}//end phase()

	/**
	 * Complete the run with its counts.
	 *
	 * @param array<string, mixed> $results The sync's results.
	 *
	 * @return void
	 */
	public function complete(array $results): void {
		$this->call(call: fn (ProgressTracker $t) => $t->completeOperation(finalStatistics: $this->countsOf(results: $results)));
	}//end complete()

	/**
	 * Fail the run.
	 *
	 * @param string $message Why it failed.
	 *
	 * @return void
	 */
	public function fail(string $message): void {
		$this->call(call: fn (ProgressTracker $t) => $t->failOperation(message: $message));
	}//end fail()

	/**
	 * The record of this run as `last_sync_result` keeps it.
	 *
	 * @param array<string, mixed> $results The sync's results.
	 *
	 * @return array<string, mixed> startedAt, finishedAt, trigger, the counts and errorCount.
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
	 */
	public function lastResult(array $results): array {
		$errors     = ($results['errors'] ?? []);
		$errorCount = 0;
		if (is_array($errors) === true) {
			$errorCount = count($errors);
		}

		return [
			'startedAt'  => $this->startedAt,
			'finishedAt' => date('Y-m-d H:i:s'),
			'trigger'    => $this->trigger,
		] + $this->countsOf(results: $results) + [
			'errorCount' => $errorCount,
		];
	}//end lastResult()

	/**
	 * The counts of a result, zero where the sync did not report one.
	 *
	 * @param array<string, mixed> $results The sync's results.
	 *
	 * @return array<string, int>
	 */
	private function countsOf(array $results): array {
		$counts = [];
		foreach (self::COUNTS as $key) {
			$counts[$key] = (int) ($results[$key] ?? 0);
		}

		return $counts;
	}//end countsOf()

	/**
	 * Call the tracker, when there is one, swallowing what it throws.
	 *
	 * @param callable $call What to do with the tracker.
	 *
	 * @return void
	 */
	private function call(callable $call): void {
		if ($this->tracker === null) {
			return;
		}

		try {
			$call($this->tracker);
		} catch (Throwable) {
			// Progress is for whoever watches; it never stops the sync.
			return;
		}
	}//end call()
}//end class
