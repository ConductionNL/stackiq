<?php

/**
 * Sync Handler for SettingsController
 *
 * Extracted from SettingsController to reduce ExcessiveClassLength, TooManyMethods,
 * and CouplingBetweenObjects on that controller.
 *
 * @category  Handler
 * @package   OCA\SoftwareCatalog\Controller\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller\Settings;

use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Handles synchronisation operations for the SettingsController.
 *
 * Inject this handler into SettingsController to replace direct calls to
 * OrganizationSyncService from within controller action methods, reducing
 * the controller's constructor coupling below the PHPMD threshold.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-3
 */
class SyncHandler
{
    /**
     * Constructor.
     *
     * @param OrganizationSyncService $orgSyncService  The organisation sync service.
     * @param SettingsService         $settingsService The settings service.
     * @param LoggerInterface         $logger          Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-3
     */
    public function __construct(
        private readonly OrganizationSyncService $orgSyncService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Perform an organisation sync with the given options.
     *
     * @param array<string,mixed> $config Request config array containing optional
     *                                    'minutesBack' (int) for incremental sync.
     *
     * @return array<string,mixed> Sync result with 'success' (bool) and 'message' (string).
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-3
     */
    public function handle(array $config): array
    {
        $validated = $this->validateSyncConfig(config: $config);
        $data      = $this->prepareSyncData(config: $validated);
        $result    = $this->executeSyncBatch(data: $data);
        return $this->buildSyncResponse(result: $result);

    }//end handle()

    /**
     * Validate sync config input, applying defaults where needed.
     *
     * @param array<string,mixed> $config Raw config from the request.
     *
     * @return array<string,mixed> Validated and normalised config.
     */
    private function validateSyncConfig(array $config): array
    {
        $minutesBack = 0;
        if (isset($config['minutesBack']) === true) {
            $minutesBack = (int) $config['minutesBack'];
        }

        if ($minutesBack < 0) {
            $this->logger->warning('SyncHandler: minutesBack < 0, defaulting to 0.');
            $minutesBack = 0;
        }

        return ['minutesBack' => $minutesBack];

    }//end validateSyncConfig()

    /**
     * Prepare sync data from validated config.
     *
     * @param array<string,mixed> $config Validated config array.
     *
     * @return array<string,mixed> Prepared sync data.
     */
    private function prepareSyncData(array $config): array
    {
        return [
            'minutesBack' => $config['minutesBack'],
            'isFullSync'  => $config['minutesBack'] === 0,
        ];

    }//end prepareSyncData()

    /**
     * Execute the appropriate sync batch depending on the sync type.
     *
     * @param array<string,mixed> $data Prepared sync data from prepareSyncData().
     *
     * @return array<string,mixed> Raw sync result.
     */
    private function executeSyncBatch(array $data): array
    {
        if ($data['isFullSync'] === true) {
            return $this->orgSyncService->performOptimizedManualSync(maxRounds: 15, batchSize: 75);
        }

        return $this->orgSyncService->performManualSync($data['minutesBack']);

    }//end executeSyncBatch()

    /**
     * Build the normalised sync response array.
     *
     * @param array<string,mixed> $result Raw sync result.
     *
     * @return array<string,mixed> Normalised response suitable for JSONResponse.
     */
    private function buildSyncResponse(array $result): array
    {
        return [
            'success'     => $result['success'] ?? true,
            'results'     => $result,
            'message'     => $result['message'] ?? 'Synchronization completed successfully',
            'isOptimized' => true,
        ];

    }//end buildSyncResponse()
}//end class
