<?php

/**
 * Writes the progress of a long operation as Server-Sent Events.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\Service\ProgressTracker;
use Psr\Log\LoggerInterface;

/**
 * Polls the progress tracker once a second for at most five minutes and
 * writes a progress event on each change, a heartbeat every ten polls, and
 * an error, completed and close event as the operation ends.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */
final class ProgressEventStream {
	/**
	 * Polls before the stream ends: 5 minutes with 1-second intervals.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 300;

	/**
	 * Constructor.
	 *
	 * @param ProgressTracker $progressTracker The progress tracker service.
	 * @param LoggerInterface $logger The logger instance.
	 */
	public function __construct(
		private readonly ProgressTracker $progressTracker,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stream the progress of an operation until it ends, then send the close event.
	 *
	 * @param string $operationId The operation ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-admin-controller/spec.md
	 */
	public function stream(string $operationId): void {
		$run = new ProgressStreamRun(operationId: $operationId);
		while ($run->attempts < self::MAX_ATTEMPTS && $this->poll(run: $run) === true) {
			$run->attempts++;
		}

		// Send final close event.
		$this->emit(event: 'close', data: ['reason' => 'Stream ended']);
		flush();
	}//end stream()

	/**
	 * Poll once; a failure is logged and sent as an error event.
	 *
	 * @param ProgressStreamRun $run The stream.
	 *
	 * @return bool Whether to poll again.
	 */
	private function poll(ProgressStreamRun $run): bool {
		try {
			return $this->sendProgress(run: $run);
		} catch (\Exception $e) {
			$this->logger->error(
				'Progress streaming error',
				[
					'operation_id' => $run->operationId,
					'error' => $e->getMessage(),
				]
			);

			$this->emit(event: 'error', data: ['error' => $e->getMessage()]);
			return false;
		}
	}//end poll()

	/**
	 * Read the progress and send what changed, a heartbeat when due, then wait a second.
	 *
	 * @param ProgressStreamRun $run The stream.
	 *
	 * @return bool Whether to poll again: false once the operation is unknown or completed.
	 */
	private function sendProgress(ProgressStreamRun $run): bool {
		$progress = $this->progressTracker->getProgress($run->operationId);
		if ($progress === null) {
			// Operation not found, send error and close.
			$this->emit(event: 'error', data: ['error' => 'Operation not found']);
			return false;
		}

		if ($this->sendChange(run: $run, progress: $progress) === false) {
			return false;
		}

		$this->sendHeartbeat(run: $run);
		flush();
		sleep(1);
		return true;
	}//end sendProgress()

	/**
	 * Send the progress when it changed, and the completed event once it completed.
	 *
	 * @param ProgressStreamRun $run The stream.
	 * @param array $progress The progress read.
	 *
	 * @return bool Whether to poll again: false once the operation completed.
	 */
	private function sendChange(ProgressStreamRun $run, array $progress): bool {
		// Only send update if progress changed.
		if ($progress === $run->lastProgress) {
			return true;
		}

		$this->emit(event: 'progress', data: $progress);
		$run->lastProgress = $progress;

		// If operation completed, send final event and close.
		if ($progress['phase'] === 'completed') {
			$this->emit(event: 'completed', data: $progress);
			return false;
		}

		return true;
	}//end sendChange()

	/**
	 * Send a heartbeat every 10 polls.
	 *
	 * @param ProgressStreamRun $run The stream.
	 *
	 * @return void
	 */
	private function sendHeartbeat(ProgressStreamRun $run): void {
		if ($run->attempts % 10 === 0) {
			$this->emit(event: 'heartbeat', data: ['timestamp' => time()]);
		}
	}//end sendHeartbeat()

	/**
	 * Write one event.
	 *
	 * @param string $event The event name.
	 * @param array $data The event data, sent as JSON.
	 *
	 * @return void
	 */
	private function emit(string $event, array $data): void {
		echo 'event: ' . $event . "\n";
		echo 'data: ' . json_encode($data) . "\n\n";
	}//end emit()
}//end class
