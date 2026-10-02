<?php

/**
 * ProgressTracker Service
 *
 * Tracks and reports progress for long-running operations like ArchiMate import/export.
 * Supports real-time streaming via Server-Sent Events.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for tracking and reporting progress of long-running operations
 *
 * Progress lives in Nextcloud's distributed cache, not in the user's session,
 * so a background job can write it and any other request (another login, an
 * admin, the request after a cron run) can read it. Who may read an operation
 * is decided by SettingsController::getProgress(), not by where it is stored.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each public method is one step of an
 * operation's life (start, phase, progress, warning, error, statistics, complete, fail,
 * cancel) that an import calls on the same snapshot; splitting them would hand that
 * snapshot from class to class.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 */
class ProgressTracker {
	/**
	 * Operation phases and their relative weights for progress calculation
	 */
	private const PHASES = [
		'initializing' => ['weight' => 5, 'description' => 'Initializing'],
		'validating' => ['weight' => 5, 'description' => 'Validating file'],
		'parsing' => ['weight' => 10, 'description' => 'Parsing ArchiMate file'],
		'analyzing' => ['weight' => 5, 'description' => 'Analyzing structure'],
		'caching' => ['weight' => 10, 'description' => 'Loading existing objects'],
		'processing_elements' => ['weight' => 30, 'description' => 'Processing elements'],
		'processing_relationships' => ['weight' => 15, 'description' => 'Processing relationships'],
		'processing_organizations' => ['weight' => 10, 'description' => 'Processing organizations'],
		'processing_views' => ['weight' => 10, 'description' => 'Processing views'],
		'finalizing' => ['weight' => 5, 'description' => 'Finalizing import'],
		'completed' => ['weight' => 0, 'description' => 'Completed'],
	];

	/**
	 * Current progress state
	 *
	 * @var array
	 */
	private array $progress = [
		'operation_id' => null,
		'operation_type' => null,
		'phase' => 'initializing',
		'phase_description' => 'Initializing',
		'total_items' => 0,
		'processed_items' => 0,
		'current_item_type' => null,
		'current_item_name' => null,
		'percentage' => 0,
		'start_time' => null,
		'estimated_completion' => null,
		'errors' => [],
		'warnings' => [],
		'statistics' => [],
	];

	/**
	 * How long a stored snapshot lives after its last write, in seconds.
	 */
	private const STORE_TTL = 3600;

	/**
	 * The shared store for progress snapshots.
	 *
	 * @var ICache
	 */
	private ICache $store;

	/**
	 * Constructor for ProgressTracker
	 *
	 * @param ICacheFactory $cacheFactory Cache factory; progress goes into its distributed cache
	 * @param IUserSession $userSession The signed-in user, the default owner of a new operation
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		$this->store = $cacheFactory->createDistributed(prefix: 'stackiq_progress');
	}//end __construct()

	/**
	 * Start tracking a new operation
	 *
	 * @param string $operationType Type of operation (import, export)
	 * @param array $options Operation options and metadata
	 * @param string|null $ownerUid UID of the user who owns this operation; when null, the
	 *                              signed-in user of this request, or no owner in a background job
	 * @param string|null $operationId Id the caller chose, so a page can follow an operation
	 *                                 whose request has not returned yet; when null, a new id
	 *
	 * @return string Unique operation ID
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
	 */
	public function startOperation(
		string $operationType,
		array $options = [],
		?string $ownerUid = null,
		?string $operationId = null
	): string {
		if ($operationId === null) {
			$operationId = uniqid(prefix: $operationType . '_', more_entropy: true);
		}

		if ($ownerUid === null) {
			$ownerUid = $this->userSession->getUser()?->getUID();
		}

		$this->progress = [
			'operation_id' => $operationId,
			'operation_type' => $operationType,
			'owner_uid' => $ownerUid,
			'status' => 'running',
			'phase' => 'initializing',
			'phase_description' => self::PHASES['initializing']['description'],
			'total_items' => $options['total_items'] ?? 0,
			'processed_items' => 0,
			'current_item_type' => null,
			'current_item_name' => null,
			'percentage' => 0,
			'start_time' => time(),
			'estimated_completion' => null,
			'errors' => [],
			'warnings' => [],
			'statistics' => $options['statistics'] ?? [],
		];

		$this->saveProgress();

		$this->logger->info(
			'Started progress tracking',
			[
				'operation_id' => $operationId,
				'operation_type' => $operationType,
				'total_items' => $this->progress['total_items'],
			]
		);

		return $operationId;
	}//end startOperation()

	/**
	 * Set the current phase of the operation
	 *
	 * @param string $phase Phase identifier
	 * @param array $data Additional phase data
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function setPhase(string $phase, array $data = []): void {
		if (isset(self::PHASES[$phase]) === false) {
			$this->logger->warning('Unknown progress phase', ['phase' => $phase]);
			return;
		}

		$this->progress['phase'] = $phase;
		$this->progress['phase_description'] = self::PHASES[$phase]['description'];

		// Update total items if provided.
		if (isset($data['total_items']) === true) {
			$this->progress['total_items'] = $data['total_items'];
		}

		// Reset processed items for new phase if specified.
		if (isset($data['reset_progress']) === true && $data['reset_progress'] === true) {
			$this->progress['processed_items'] = 0;
		}

		$this->updateProgress();

		$this->logger->debug(
			'Progress phase updated',
			[
				'operation_id' => $this->progress['operation_id'],
				'phase' => $phase,
				'total_items' => $this->progress['total_items'],
			]
		);
	}//end setPhase()

	/**
	 * Update progress within the current phase
	 *
	 * @param int $processedItems Number of items processed
	 * @param string $currentItem Name of current item being processed
	 * @param string $itemType Type of current item
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function updateProgress(?int $processedItems = null, ?string $currentItem = null, ?string $itemType = null): void {
		if ($processedItems !== null) {
			$this->progress['processed_items'] = $processedItems;
		}

		if ($currentItem !== null) {
			$this->progress['current_item_name'] = $currentItem;
		}

		if ($itemType !== null) {
			$this->progress['current_item_type'] = $itemType;
		}

		// Calculate overall percentage based on phase weights and current progress.
		$this->progress['percentage'] = $this->calculateOverallPercentage();

		// Calculate estimated completion time.
		$this->progress['estimated_completion'] = $this->calculateEstimatedCompletion();

		$this->saveProgress();
	}//end updateProgress()

	/**
	 * Increment the processed items counter by one
	 *
	 * @param string $currentItem Name of current item being processed
	 * @param string $itemType Type of current item
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function incrementProgress(?string $currentItem = null, ?string $itemType = null): void {
		$this->updateProgress(
			processedItems: $this->progress['processed_items'] + 1,
			currentItem: $currentItem,
			itemType: $itemType
		);
	}//end incrementProgress()

	/**
	 * Add an error to the progress tracking
	 *
	 * @param string $message Error message
	 * @param array $context Error context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function addError(string $message, array $context = []): void {
		$this->progress['errors'][] = [
			'message' => $message,
			'context' => $context,
			'timestamp' => time(),
		];

		$this->saveProgress();

		$this->logger->error(
			'Progress tracking error',
			[
				'operation_id' => $this->progress['operation_id'],
				'message' => $message,
				'context' => $context,
			]
		);
	}//end addError()

	/**
	 * Add a warning to the progress tracking
	 *
	 * @param string $message Warning message
	 * @param array $context Warning context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function addWarning(string $message, array $context = []): void {
		$this->progress['warnings'][] = [
			'message' => $message,
			'context' => $context,
			'timestamp' => time(),
		];

		$this->saveProgress();
	}//end addWarning()

	/**
	 * Update operation statistics
	 *
	 * @param array $statistics Statistics to merge
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function updateStatistics(array $statistics): void {
		$this->progress['statistics'] = array_merge($this->progress['statistics'], $statistics);
		$this->saveProgress();
	}//end updateStatistics()

	/**
	 * Complete the operation
	 *
	 * @param array $finalStatistics Final operation statistics
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function completeOperation(array $finalStatistics = []): void {
		$this->progress['phase'] = 'completed';
		$this->progress['phase_description'] = self::PHASES['completed']['description'];
		$this->progress['status'] = 'completed';
		$this->progress['percentage'] = 100;
		$this->progress['processed_items'] = $this->progress['total_items'];
		$this->progress['estimated_completion'] = time();

		if (empty($finalStatistics) === false) {
			$this->progress['statistics'] = array_merge($this->progress['statistics'], $finalStatistics);
		}

		$this->saveProgress();

		$this->logger->info(
			'Operation completed',
			[
				'operation_id' => $this->progress['operation_id'],
				'duration' => time() - $this->progress['start_time'],
				'total_items' => $this->progress['total_items'],
				'errors' => count($this->progress['errors']),
				'warnings' => count($this->progress['warnings']),
			]
		);
	}//end completeOperation()

	/**
	 * Mark the current operation as failed, keeping the percentage it reached.
	 *
	 * A page following the operation then sees it stop as failed rather than
	 * as running until the snapshot expires, or as completed at 100%.
	 *
	 * @param string $message Why the operation failed
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md
	 */
	public function failOperation(string $message): void {
		$this->progress['errors'][] = [
			'message' => $message,
			'context' => [],
			'timestamp' => time(),
		];
		$this->progress['phase_description'] = 'Failed';
		$this->progress['status'] = 'failed';
		$this->progress['estimated_completion'] = time();
		$this->saveProgress();

		if ($this->progress['operation_id'] !== null) {
			$this->store->remove(key: 'cancel_' . $this->progress['operation_id']);
		}

		$this->logger->error(
			'Operation failed',
			[
				'operation_id' => $this->progress['operation_id'],
				'message' => $message,
			]
		);
	}//end failOperation()

	/**
	 * Ask a running operation to stop.
	 *
	 * The operation runs in another request, which PHP cannot interrupt, so the
	 * request is a flag in the shared store that the operation reads between
	 * its batches.
	 *
	 * @param string $operationId The operation to cancel
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function setCancelRequested(string $operationId): void {
		$this->store->set(key: 'cancel_' . $operationId, value: true, ttl: self::STORE_TTL);
	}//end setCancelRequested()

	/**
	 * Whether a cancel was requested for an operation, from any request.
	 *
	 * @param string $operationId The operation to check
	 *
	 * @return bool True when a cancel was requested
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function isCancelRequested(string $operationId): bool {
		return $this->store->get(key: 'cancel_' . $operationId) === true;
	}//end isCancelRequested()

	/**
	 * Mark the current operation as stopped on a cancel, keeping the counts it reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function cancelOperation(): void {
		$this->progress['phase'] = 'completed';
		$this->progress['phase_description'] = 'Cancelled';
		$this->progress['status'] = 'cancelled';
		$this->progress['estimated_completion'] = time();
		$this->saveProgress();

		if ($this->progress['operation_id'] !== null) {
			$this->store->remove(key: 'cancel_' . $this->progress['operation_id']);
		}
	}//end cancelOperation()

	/**
	 * Get current progress state
	 *
	 * @param string $operationId Operation ID to get progress for
	 *
	 * @return array|null Progress data or null if not found
	 *
	 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
	 */
	public function getProgress(?string $operationId = null): ?array {
		if ($operationId !== null && $operationId !== $this->progress['operation_id']) {
			// Load an operation another request or a background job wrote.
			$storedProgress = $this->store->get(key: 'progress_' . $operationId);
			if (is_array($storedProgress) === true) {
				return $storedProgress;
			}

			return null;
		}

		if ($this->progress['operation_id'] !== null) {
			return $this->progress;
		}

		return null;
	}//end getProgress()

	/**
	 * Calculate overall percentage based on phase weights and current progress
	 *
	 * @return int Overall percentage (0-100)
	 */
	private function calculateOverallPercentage(): int {
		$totalWeight = array_sum(array_column(self::PHASES, 'weight'));
		$completedWeight = 0;
		$currentPhaseWeight = 0;
		$currentPhaseProgress = 0;

		$phases = array_keys(self::PHASES);
		$currentPhaseIndex = array_search($this->progress['phase'], $phases);

		// Add weight of all completed phases.
		for ($i = 0; $i < $currentPhaseIndex; $i++) {
			$completedWeight += self::PHASES[$phases[$i]]['weight'];
		}

		// Calculate progress within current phase.
		if ($currentPhaseIndex !== false) {
			$currentPhaseWeight = self::PHASES[$this->progress['phase']]['weight'];

			// If no items to process, consider phase as complete.
			$currentPhaseProgress = $currentPhaseWeight;
			if ($this->progress['total_items'] > 0) {
				$itemRatio = $this->progress['processed_items'] / $this->progress['total_items'];
				$currentPhaseProgress = $itemRatio * $currentPhaseWeight;
			}
		}

		$overallProgress = $completedWeight + $currentPhaseProgress;
		$percentage = 0;
		if ($totalWeight > 0) {
			$percentage = ($overallProgress / $totalWeight) * 100;
		}

		return min(100, max(0, (int)$percentage));
	}//end calculateOverallPercentage()

	/**
	 * Calculate estimated completion time
	 *
	 * @return int|null Estimated completion timestamp or null if cannot calculate
	 */
	private function calculateEstimatedCompletion(): ?int {
		if ($this->progress['start_time'] === null || $this->progress['percentage'] <= 0) {
			return null;
		}

		$elapsed = time() - $this->progress['start_time'];
		$estimatedTotal = ($elapsed / $this->progress['percentage']) * 100;

		return $this->progress['start_time'] + intval($estimatedTotal);
	}//end calculateEstimatedCompletion()

	/**
	 * Save progress to the shared store.
	 *
	 * Each write renews the entry for STORE_TTL seconds.
	 *
	 * @return void
	 */
	private function saveProgress(): void {
		if ($this->progress['operation_id'] !== null) {
			$this->store->set(
				key: 'progress_' . $this->progress['operation_id'],
				value: $this->progress,
				ttl: self::STORE_TTL
			);
		}
	}//end saveProgress()

	/**
	 * Clean up old progress entries.
	 *
	 * Nothing to do: every entry in the shared store expires STORE_TTL seconds
	 * after its last write.
	 *
	 * @param int $maxAge Maximum age in seconds (default: 1 hour)
	 *
	 * @return void
	 * @spec   openspec/specs/progress-tracking/spec.md
	 */
	public function cleanupOldProgress(int $maxAge = 3600): void {
		$this->logger->debug('Progress cleanup requested; stored entries expire on their own', ['max_age' => $maxAge]);
	}//end cleanupOldProgress()
}//end class
