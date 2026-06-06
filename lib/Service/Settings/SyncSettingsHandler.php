<?php

/**
 * Sync Settings Handler for SoftwareCatalog
 *
 * Extracted from SettingsService to reduce ExcessiveClassLength and TooManyMethods
 * on that service. Handles sync-domain configuration operations.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\Settings;

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Handles sync-domain settings: time windows, cron configuration, and sync toggles.
 *
 * SettingsService delegates all sync-config methods to this handler, keeping its
 * own class below ExcessiveClassLength and TooManyMethods thresholds.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */
class SyncSettingsHandler
{

    /**
     * The application name used as the config namespace.
     *
     * @var string
     */
    private const APP_NAME = 'softwarecatalog';

    /**
     * Constructor.
     *
     * @param IAppConfig      $config The application configuration service.
     * @param LoggerInterface $logger Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get the sync time window in minutes.
     *
     * @return string The sync time window value (default: '10').
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function getSyncTimeWindow(): string
    {
        return $this->config->getValueString(self::APP_NAME, 'syncTimeWindow', '10');

    }//end getSyncTimeWindow()

    /**
     * Set the sync time window in minutes.
     *
     * @param string $minutes The time window value in minutes.
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function setSyncTimeWindow(string $minutes): void
    {
        $this->validateSyncConfig(minutes: $minutes);
        $this->config->setValueString(self::APP_NAME, 'syncTimeWindow', $minutes);

        $this->logger->info(
            'SyncSettingsHandler: Updated syncTimeWindow',
            ['minutes' => $minutes]
        );

    }//end setSyncTimeWindow()

    /**
     * Get the cron-job configuration array.
     *
     * @return array<string,mixed> Cron-job configuration.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function getCronjobConfig(): array
    {
        return [
            'syncTimeWindow'          => $this->getSyncTimeWindow(),
            'syncEnabled'             => $this->config->getValueString(self::APP_NAME, 'syncEnabled', 'true') === 'true',
            'cronjobInterval'         => $this->config->getValueString(self::APP_NAME, 'cronjobInterval', '*/5 * * * *'),
            'lastSyncTime'            => $this->config->getValueString(self::APP_NAME, 'lastSyncTime', ''),
            'organizationSyncEnabled' => $this->config->getValueString(self::APP_NAME, 'organizationSyncEnabled', 'true') === 'true',
        ];

    }//end getCronjobConfig()

    /**
     * Update the cron-job configuration from a data array.
     *
     * @param array<string,mixed> $data Cron-job config fields to update.
     *
     * @return array<string,mixed> The updated cron-job configuration.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    public function updateCronjobConfig(array $data): array
    {
        if (isset($data['syncTimeWindow']) === true) {
            $this->setSyncTimeWindow(minutes: (string) $data['syncTimeWindow']);
        }

        if (isset($data['syncEnabled']) === true) {
            $syncEnabledStr = 'false';
            if ($data['syncEnabled'] === true) {
                $syncEnabledStr = 'true';
            }

            $this->config->setValueString(self::APP_NAME, 'syncEnabled', $syncEnabledStr);
        }

        if (isset($data['cronjobInterval']) === true) {
            $this->config->setValueString(self::APP_NAME, 'cronjobInterval', (string) $data['cronjobInterval']);
        }

        if (isset($data['organizationSyncEnabled']) === true) {
            $orgSyncEnabledStr = 'false';
            if ($data['organizationSyncEnabled'] === true) {
                $orgSyncEnabledStr = 'true';
            }

            $this->config->setValueString(self::APP_NAME, 'organizationSyncEnabled', $orgSyncEnabledStr);
        }

        $this->logger->info('SyncSettingsHandler: Updated cron-job configuration', ['data' => $data]);

        return $this->getCronjobConfig();

    }//end updateCronjobConfig()

    /**
     * Validate sync configuration values.
     *
     * Guard clause: throws if the minutes value is non-numeric or out of range.
     *
     * @param string $minutes The time window value to validate.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the value is invalid.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-1
     */
    private function validateSyncConfig(string $minutes): void
    {
        if (is_numeric($minutes) === false) {
            throw new InvalidArgumentException(
                sprintf('syncTimeWindow must be numeric, got "%s".', $minutes)
            );
        }

        $value = (int) $minutes;
        if ($value < 1 || $value > 10080) {
            throw new InvalidArgumentException(
                sprintf('syncTimeWindow must be between 1 and 10080 minutes, got %d.', $value)
            );
        }

    }//end validateSyncConfig()
}//end class
