<?php
/**
 * ProgressTracker Service
 *
 * Tracks and reports progress for long-running operations like ArchiMate import/export.
 * Supports real-time streaming via Server-Sent Events.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: 1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCP\ISession;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use Psr\Log\LoggerInterface;

/**
 * Service for tracking and reporting progress of long-running operations
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: 1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class ProgressTracker
{
    /**
     * Operation phases and their relative weights for progress calculation
     */
    private const PHASES = [
        'initializing'             => ['weight' => 5, 'description' => 'Initializing'],
        'validating'               => ['weight' => 5, 'description' => 'Validating file'],
        'parsing'                  => ['weight' => 10, 'description' => 'Parsing ArchiMate file'],
        'analyzing'                => ['weight' => 5, 'description' => 'Analyzing structure'],
        'caching'                  => ['weight' => 10, 'description' => 'Loading existing objects'],
        'processing_elements'      => ['weight' => 30, 'description' => 'Processing elements'],
        'processing_relationships' => ['weight' => 15, 'description' => 'Processing relationships'],
        'processing_organizations' => ['weight' => 10, 'description' => 'Processing organizations'],
        'processing_views'         => ['weight' => 10, 'description' => 'Processing views'],
        'finalizing'               => ['weight' => 5, 'description' => 'Finalizing import'],
        'completed'                => ['weight' => 0, 'description' => 'Completed'],
    ];

    /**
     * Current progress state
     *
     * @var array
     */
    private array $progress = [
        'operation_id'         => null,
        'operation_type'       => null,
        'phase'                => 'initializing',
        'phase_description'    => 'Initializing',
        'total_items'          => 0,
        'processed_items'      => 0,
        'current_item_type'    => null,
        'current_item_name'    => null,
        'percentage'           => 0,
        'start_time'           => null,
        'estimated_completion' => null,
        'errors'               => [],
        'warnings'             => [],
        'statistics'           => [],
    ];

    /**
     * Constructor for ProgressTracker
     *
     * @param ISession        $session The session service for storing progress
     * @param LoggerInterface $logger  The logger interface
     */
    public function __construct(
        private readonly ISession $session,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Start tracking a new operation
     *
     * @param string $operationType Type of operation (import, export)
     * @param array  $options       Operation options and metadata
     *
     * @return string Unique operation ID
     */
    public function startOperation(string $operationType, array $options=[]): string
    {
        $operationId = uniqid(prefix: $operationType.'_', more_entropy: true);

        $this->progress = [
            'operation_id'         => $operationId,
            'operation_type'       => $operationType,
            'phase'                => 'initializing',
            'phase_description'    => self::PHASES['initializing']['description'],
            'total_items'          => $options['total_items'] ?? 0,
            'processed_items'      => 0,
            'current_item_type'    => null,
            'current_item_name'    => null,
            'percentage'           => 0,
            'start_time'           => time(),
            'estimated_completion' => null,
            'errors'               => [],
            'warnings'             => [],
            'statistics'           => $options['statistics'] ?? [],
        ];

        $this->saveProgress();

        $this->logger->info(
                'Started progress tracking',
                [
                    'operation_id'   => $operationId,
                    'operation_type' => $operationType,
                    'total_items'    => $this->progress['total_items'],
                ]
                );

        return $operationId;
    }//end startOperation()

    /**
     * Set the current phase of the operation
     *
     * @param string $phase Phase identifier
     * @param array  $data  Additional phase data
     *
     * @return void
     */
    public function setPhase(string $phase, array $data=[]): void
    {
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
                    'phase'        => $phase,
                    'total_items'  => $this->progress['total_items'],
                ]
                );
    }//end setPhase()

    /**
     * Update progress within the current phase
     *
     * @param int    $processedItems Number of items processed
     * @param string $currentItem    Name of current item being processed
     * @param string $itemType       Type of current item
     *
     * @return void
     */
    public function updateProgress(int $processedItems=null, string $currentItem=null, string $itemType=null): void
    {
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
     * @param string $itemType    Type of current item
     *
     * @return void
     */
    public function incrementProgress(string $currentItem=null, string $itemType=null): void
    {
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
     * @param array  $context Error context
     *
     * @return void
     */
    public function addError(string $message, array $context=[]): void
    {
        $this->progress['errors'][] = [
            'message'   => $message,
            'context'   => $context,
            'timestamp' => time(),
        ];

        $this->saveProgress();

        $this->logger->error(
                'Progress tracking error',
                [
                    'operation_id' => $this->progress['operation_id'],
                    'message'      => $message,
                    'context'      => $context,
                ]
                );
    }//end addError()

    /**
     * Add a warning to the progress tracking
     *
     * @param string $message Warning message
     * @param array  $context Warning context
     *
     * @return void
     */
    public function addWarning(string $message, array $context=[]): void
    {
        $this->progress['warnings'][] = [
            'message'   => $message,
            'context'   => $context,
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
     */
    public function updateStatistics(array $statistics): void
    {
        $this->progress['statistics'] = array_merge($this->progress['statistics'], $statistics);
        $this->saveProgress();
    }//end updateStatistics()

    /**
     * Complete the operation
     *
     * @param array $finalStatistics Final operation statistics
     *
     * @return void
     */
    public function completeOperation(array $finalStatistics=[]): void
    {
        $this->progress['phase'] = 'completed';
        $this->progress['phase_description']    = self::PHASES['completed']['description'];
        $this->progress['percentage']           = 100;
        $this->progress['processed_items']      = $this->progress['total_items'];
        $this->progress['estimated_completion'] = time();

        if (empty($finalStatistics) === false) {
            $this->progress['statistics'] = array_merge($this->progress['statistics'], $finalStatistics);
        }

        $this->saveProgress();

        $this->logger->info(
                'Operation completed',
                [
                    'operation_id' => $this->progress['operation_id'],
                    'duration'     => time() - $this->progress['start_time'],
                    'total_items'  => $this->progress['total_items'],
                    'errors'       => count($this->progress['errors']),
                    'warnings'     => count($this->progress['warnings']),
                ]
                );
    }//end completeOperation()

    /**
     * Get current progress state
     *
     * @param string $operationId Operation ID to get progress for
     *
     * @return array|null Progress data or null if not found
     */
    public function getProgress(string $operationId=null): ?array
    {
        if ($operationId !== null && $operationId !== $this->progress['operation_id']) {
            // Load progress from session for different operation.
            $sessionKey     = 'progress_'.$operationId;
            $storedProgress = $this->session->get($sessionKey);
            if ($storedProgress !== null && $storedProgress !== false) {
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
    private function calculateOverallPercentage(): int
    {
        $totalWeight          = array_sum(array_column(self::PHASES, 'weight'));
        $completedWeight      = 0;
        $currentPhaseWeight   = 0;
        $currentPhaseProgress = 0;

        $phases            = array_keys(self::PHASES);
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
                $itemRatio            = $this->progress['processed_items'] / $this->progress['total_items'];
                $currentPhaseProgress = $itemRatio * $currentPhaseWeight;
            }
        }

        $overallProgress = $completedWeight + $currentPhaseProgress;
            $percentage  = 0;
        if ($totalWeight > 0) {
        }

        return min(100, max(0, $percentage));
    }//end calculateOverallPercentage()

    /**
     * Calculate estimated completion time
     *
     * @return int|null Estimated completion timestamp or null if cannot calculate
     */
    private function calculateEstimatedCompletion(): ?int
    {
        if ($this->progress['start_time'] === null || $this->progress['percentage'] <= 0) {
            return null;
        }

        $elapsed        = time() - $this->progress['start_time'];
        $estimatedTotal = ($elapsed / $this->progress['percentage']) * 100;

        return $this->progress['start_time'] + intval($estimatedTotal);
    }//end calculateEstimatedCompletion()

    /**
     * Save progress to session
     *
     * @return void
     */
    private function saveProgress(): void
    {
        if ($this->progress['operation_id'] !== null) {
            $sessionKey = 'progress_'.$this->progress['operation_id'];
            $this->session->set($sessionKey, $this->progress);
        }
    }//end saveProgress()

    /**
     * Clean up old progress entries from session
     *
     * @param int $maxAge Maximum age in seconds (default: 1 hour)
     *
     * @return void
     */
    public function cleanupOldProgress(int $maxAge=3600): void
    {
        // Note: This would need to iterate through session keys to find and clean old progress entries.
        // Implementation depends on session storage capabilities.
        $this->logger->debug('Progress cleanup requested', ['max_age' => $maxAge]);
    }//end cleanupOldProgress()
}//end class
